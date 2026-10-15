<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\DeviceAttendanceLog;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZKTeco BioTime 8.x REST API integration.
 *
 * The base URL is built from the device's stored protocol/host/port, and every
 * endpoint path is read from the device record (auth_path, transactions_path,
 * employees_path) — falling back to the BioTime defaults in
 * AttendanceDevice::DEFAULTS when a column is null. Token cache lifetime and
 * page size are likewise per-device configurable. This lets a device on a
 * different firmware/path layout be supported by editing DB values, with no
 * code change.
 *
 * Defaults (AttendanceDevice::DEFAULTS):
 *   auth_path         POST /jwt-api-token-auth/
 *   transactions_path GET  /att/api/transactionHistory/
 *   employees_path    GET  /personnel/api/employees/
 *   token_ttl_minutes 300  (5 hours)
 *   page_size         500
 */
class BioTimeService {

    private ?string $token = null;
    private ?string $baseUrl = null;
    private int $timeout = 30;

    // ── Auth ──────────────────────────────────────────────────────────────

    public function connect(AttendanceDevice $device): array {
        $this->baseUrl = $device->base_url;
        $this->timeout = $device->timeout_seconds ?? 30;

        // If an api_key (JWT token) is stored and not expired, reuse it
        if ($device->api_key && $this->isTokenFresh($device)) {
            $this->token = $device->api_key;
            return ['ok' => true, 'message' => 'Using cached token.'];
        }

        return $this->authenticate($device);
    }

    private function authenticate(AttendanceDevice $device): array {
        try {
            $resp = Http::timeout($this->timeout)
                    ->post($this->baseUrl . $device->endpoint('auth_path'), [
                'username' => $device->username,
                'password' => $device->password,
            ]);

            if ($resp->successful() && $resp->json('token')) {
                $this->token = $resp->json('token');
                // Persist token so next sync skips re-auth
                $device->update(['api_key' => $this->token, 'last_sync_status' => 'connected']);
                return ['ok' => true, 'message' => 'Authenticated with BioTime successfully.'];
            }

            $err = $resp->json('non_field_errors') ?? $resp->json('detail') ?? $resp->body();
            return ['ok' => false, 'message' => "Auth failed ({$resp->status()}): {$err}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: ' . $e->getMessage()];
        }
    }

    private function isTokenFresh(AttendanceDevice $device): bool {
        // Token cache lifetime is configurable per device (defaults to 5 hours).
        $ttl = $device->tokenTtlMinutes();
        return $device->last_synced_at && $device->last_synced_at->gt(now()->subMinutes($ttl));
    }

    // ── Fetch punch transactions ──────────────────────────────────────────

    /**
     * Pull attendance transactions from BioTime.
     *
     * @return array ['ok'=>bool, 'punches'=>array, 'message'=>string]
     */
    public function fetchTransactions_old(AttendanceDevice $device, Carbon $since, ?Carbon $until = null): array {
        $until = $until ?? now();
        $auth = $this->connect($device);

        if (!$auth['ok'])
            return ['ok' => false, 'punches' => [], 'message' => $auth['message']];

        try {
            $page = 1;
            $all = [];
            $pageSize = $device->pageSize();
            $url = $this->baseUrl . $device->endpoint('api_path');

            do {
                $resp = Http::timeout($this->timeout)
                        ->withToken($this->token)
                        ->get($url, [
                    'start_time' => $since->format('Y-m-d\TH:i:s'),
                    'end_time' => $until->format('Y-m-d\TH:i:s'),
                    'page_size' => $pageSize,
                    'page' => $page,
                ]);

                if (!$resp->successful()) {
                  
                    $err = $resp->json('detail') ?? $resp->body();
                    return ['ok' => false, 'punches' => $all, 'message' => "API error ({$resp->status()}): {$err}"];
                }

                $data = $resp->json();
                $records = $data['data'] ?? $data['results'] ?? $data ?? [];
                $count = $data['count'] ?? count($records);

                foreach ($records as $r) {
                    $all[] = $this->mapTransaction($r);
                }

                // Paginate if more pages
                $fetched = $page * $pageSize;
                $page++;
            } while ($fetched < $count);

            return ['ok' => true, 'punches' => array_filter($all, fn($p) => !empty($p['employee_number'])), 'message' => "Fetched " . count($all) . " transactions."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'punches' => [], 'message' => 'Fetch error: ' . $e->getMessage()];
        }
    }

    public function fetchTransactions(AttendanceDevice $device, Carbon $startDate, ?Carbon $endDate = null, ?string $employeeCode = null): array {

        try {
            $endDate = $endDate ?: now();

            $apiPath = $device->api_path ?: '/iclock/api/transactions/';

            $url = sprintf(
                    '%s://%s:%s%s',
                    $device->protocol,
                    $device->ip_address,
                    $device->port,
                    $apiPath
            );

            $params = [
                'start_time' => $startDate->format('Y-m-d H:i:s'),
                'end_time' => $endDate->format('Y-m-d H:i:s'),
                'page_size' => 5000,
            ];

            if (!empty($employeeCode)) {
                $params['emp_code'] = $employeeCode;
            }

            Log::info('BioTime Request', [
                'url' => $url,
                'params' => $params
            ]);

            $response = Http::timeout($device->timeout_seconds ?? 60)
                    ->withBasicAuth(
                            $device->username,
                            $device->password
                    )
                    ->get($url, $params);

            if (!$response->successful()) {

                return [
                    'ok' => false,
                    'message' => 'API error (' . $response->status() . ')',
                    'response' => $response->body()
                ];
            }

            return [
                'ok' => true,
                'data' => $response->json()
            ];
        } catch (\Throwable $e) {

            Log::error('BioTime Error', [
                'message' => $e->getMessage()
            ]);

            return [
                'ok' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // ── Fetch employees from BioTime ──────────────────────────────────────

    /**
     * Pull employee list from BioTime for mapping review in the UI.
     *
     * @return array ['ok'=>bool, 'employees'=>array, 'message'=>string]
     */
    public function fetchEmployees(AttendanceDevice $device): array {
        $auth = $this->connect($device);
        if (!$auth['ok'])
            return ['ok' => false, 'employees' => [], 'message' => $auth['message']];

        try {
            $resp = Http::timeout($this->timeout)
                    ->withToken($this->token)
                    ->get($this->baseUrl . $device->endpoint('employees_path'), [
                'page_size' => $device->pageSize(),
            ]);

            if (!$resp->successful()) {
                return ['ok' => false, 'employees' => [], 'message' => "API error ({$resp->status()})"];
            }

            $data = $resp->json();
            $records = $data['data'] ?? $data['results'] ?? $data ?? [];
            $hrmsMap = $this->buildEmployeeMap();

            $employees = array_map(fn($e) => [
                'biotime_id' => (string) ($e['emp_code'] ?? $e['id'] ?? ''),
                'biotime_name' => $e['first_name'] . ' ' . $e['last_name'],
                'department' => $e['department']['dept_name'] ?? '—',
                'hrms_employee' => $hrmsMap[(string) ($e['emp_code'] ?? '')] ?? null,
                'matched' => isset($hrmsMap[(string) ($e['emp_code'] ?? '')]),
                    ], $records);

            return ['ok' => true, 'employees' => $employees, 'message' => count($employees) . ' employees fetched.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'employees' => [], 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // ── Full sync ─────────────────────────────────────────────────────────

    /**
     * Full sync: fetch → store raw → process into attendance_logs.
     * Used by both manual sync UI and scheduled cron job.
     */
    public function fullSync(AttendanceDevice $device, Carbon $since, ?Carbon $until = null, ?int $userId = null, ?string $employeeCode = null): array {

        $result = [
            'device' => $device->name,
            'period' => $since->toDateString() . ' → ' . ($until ?? now())->toDateString(),
            'fetched' => 0,
            'new_raw' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'skipped_duplicates' => 0,
            'errors' => [],
        ];

        // 1 ─ Fetch from BioTime
        $until = $until ?: now();

        $fetch = $this->fetchTransactions($device, $since, $until, $employeeCode);

        if (!$fetch['ok']) {
            $result['errors'][] = $fetch['message'];
            $device->update(['last_sync_status' => 'failed', 'last_sync_error' => $fetch['message'], 'last_synced_at' => now()]);
            return $result;
        }

        $punches = $this->extractPunches(data_get($fetch, 'data', []));
        $result['fetched'] = count($punches);

        // 2 ─ Store raw punches
        $empMap = $this->buildEmployeeMap();
        $newRaw = 0;
        $unmatched = 0;
        $skippedDuplicates = 0;

        foreach ($punches as $punch) {
            $empNum = (string) ($punch['emp_code'] ?? $punch['employee'] ?? $punch['emp_id'] ?? '');

            if (empty($punch['punch_time'])) {
                $unmatched++;
                continue;
            }

            $punchTime = Carbon::parse($punch['punch_time'])->setMicrosecond(0);
            $empId = $this->matchEmployeeId($empMap, $empNum);
 
            if (!$empId) {
                $unmatched++;
                continue;
            }

            $rawLog = DeviceAttendanceLog::firstOrCreate(
                [
                    'device_id' => $device->id,
                    'device_employee_number' => $empNum,
                    'punch_time' => $punchTime,
                ],
                [
                    'employee_id' => $empId,
                    'punch_type' => (int) ($punch['punch_type'] ?? $punch['punch_state'] ?? $punch['status'] ?? 0),
                    'verification_mode' => $punch['verification_mode'] ?? $punch['verify_type'] ?? null,
                    'processed' => false,
                ]
            );

            if (!$rawLog->wasRecentlyCreated) {
                $rawLog->forceFill([
                    'employee_id' => $rawLog->employee_id ?: $empId,
                    'processed' => false,
                ])->save();
                $skippedDuplicates++;
                continue;
            }

            $newRaw++;
        }

        $result['new_raw'] = $newRaw;
        $result['unmatched'] = $unmatched;
        $result['skipped_duplicates'] = $skippedDuplicates;

        // 3 ─ Process raw → attendance_logs
        $devService = app(AttendanceDeviceService::class);
        $processed = $devService->processDeviceLogs($device);

        $result['processed'] = $processed['processed'] ?? 0;
        $result['created'] = $processed['created'] ?? 0;
        $result['updated'] = $processed['updated'] ?? 0;

        // 4 ─ Update device sync status
        $device->update([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_count' => $result['created'] + $result['updated'],
            'last_sync_error' => null,
        ]);

        Log::info("BioTime sync complete: {$device->name}", $result);
        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function mapTransaction(array $r): array {
        return [
            'employee_number' => (string) ($r['emp_code'] ?? $r['employee'] ?? $r['emp_id'] ?? ''),
            'punch_time' => $r['punch_time'] ?? $r['att_date'] ?? '',
            // BioTime punch_state: 0=check-in, 1=check-out, 2=break-out, 3=break-in, 4=OT-in, 5=OT-out
            'punch_type' => (int) ($r['punch_state'] ?? $r['status'] ?? 0),
            'verification_mode' => $r['verify_type'] ?? $r['verify'] ?? null,
        ];
    }

    private function buildEmployeeMap(): array {
        $map = [];

        Employee::whereIn('status', ['active', 'probation', 'on_leave'])
            ->get(['employee_code', 'id'])
            ->each(function ($e) use (&$map) {
                foreach ($this->employeeCodeCandidates((string) $e->employee_code) as $candidate) {
                    $map[$candidate] = $e->id;
                }
            });

        return $map;
    }

    private function extractPunches(array $data): array
    {
        $records = $data['data'] ?? $data['results'] ?? $data['records'] ?? $data;

        if (isset($records['records']) && is_array($records['records'])) {
            $records = $records['records'];
        }

        return is_array($records) ? array_values(array_filter($records, fn($record) => is_array($record))) : [];
    }

    private function matchEmployeeId(array $map, string $deviceCode): ?int
    {
        foreach ($this->employeeCodeCandidates($deviceCode) as $candidate) {
            if (isset($map[$candidate])) {
                return $map[$candidate];
            }
        }

        return null;
    }

    private function employeeCodeCandidates(string $code): array
    {
        $raw = strtoupper(trim($code));
        if ($raw === '') {
            return [];
        }

        $clean = preg_replace('/[^A-Z0-9]/', '', $raw) ?: '';
        $withoutPrefix = str_starts_with($clean, 'EMP') ? substr($clean, 3) : $clean;
        $numeric = ltrim($withoutPrefix, '0');
        $numeric = $numeric === '' ? '0' : $numeric;

        return array_values(array_unique(array_filter([
            $raw,
            $clean,
            $withoutPrefix,
            $numeric,
            'EMP' . $withoutPrefix,
            ctype_digit($numeric) ? 'EMP' . str_pad($numeric, 4, '0', STR_PAD_LEFT) : null,
            ctype_digit($numeric) ? 'EMP' . $numeric : null,
        ])));
    }
}
