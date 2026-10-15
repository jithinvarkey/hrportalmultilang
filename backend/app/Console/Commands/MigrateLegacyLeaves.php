<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MigrateLegacyLeaves extends Command
{
    protected $signature = 'legacy:migrate-leaves
        {file : Full path to the leave CSV file}
        {--dry-run : Validate and show summary without writing leave requests}
        {--skip-missing-employee : Skip rows where the employee cannot be found}
        {--refresh-existing : Update already imported matching leave rows with current CSV values}';

    protected $description = 'Migrate legacy leave request CSV rows into leave_requests';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (!is_file($path) || !is_readable($path)) {
            $this->error("CSV file not found or not readable: {$path}");
            return self::FAILURE;
        }

        $summary = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($this->readCsv($path) as $row) {
            $summary['processed']++;

            try {
                if ($this->rowIsEmpty($row)) {
                    $summary['skipped']++;
                    continue;
                }

                $payload = $this->buildPayload($row);

                if ($dryRun) {
                    $summary['created']++;
                    continue;
                }

                $result = DB::transaction(fn () => $this->saveLeave($payload));
                $summary[$result]++;
            } catch (\Throwable $e) {
                if ($this->option('skip-missing-employee') && str_starts_with($e->getMessage(), 'Employee not found:')) {
                    $summary['skipped']++;
                    continue;
                }

                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $row['_line'] ?? null,
                    'employee' => $this->firstValue($row, ['employeeid', 'employee_id', 'empnum', 'employee_code', 'emailaddress', 'email']),
                    'leave_type' => $this->firstValue($row, ['leavetype_name', 'leave_type', 'leavetype']),
                    'from' => $this->firstValue($row, ['from_date', 'start_date']),
                    'to' => $this->firstValue($row, ['to_date', 'end_date']),
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->line($dryRun ? 'Dry run only. No leave requests were written.' : 'Leave migration completed.');
        $this->table(
            ['Processed', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Failed'],
            [[
                $summary['processed'],
                $summary['created'],
                $summary['updated'],
                $summary['unchanged'],
                $summary['skipped'],
                $summary['failed'],
            ]]
        );

        if ($summary['errors']) {
            $this->warn('Errors:');
            $this->table(['Row', 'Employee', 'Leave Type', 'From', 'To', 'Message'], array_map(fn ($error) => [
                $error['row'],
                $error['employee'],
                $error['leave_type'],
                $error['from'],
                $error['to'],
                $error['message'],
            ], $summary['errors']));
        }

        return $summary['failed'] ? self::FAILURE : self::SUCCESS;
    }

    private function buildPayload(array $row): array
    {
        $employee = $this->findEmployee(
            $this->firstValue($row, ['employeeid', 'employee_id', 'empnum', 'employee_code', 'empcode']),
            $this->firstValue($row, ['emailaddress', 'email', 'email_address'])
        );
        $leaveTypeName = $this->firstValue($row, ['leavetype_name', 'leave_type', 'leavetype']);
        if (!$leaveTypeName) {
            throw new \InvalidArgumentException('Missing leave type.');
        }

        $leaveType = $this->findOrCreateLeaveType($leaveTypeName);
        $startDate = $this->date($this->firstValue($row, ['from_date', 'start_date']));
        $endDate = $this->date($this->firstValue($row, ['to_date', 'end_date']));

        if (!$startDate && $endDate) {
            $startDate = $endDate;
        }
        if (!$startDate) {
            throw new \InvalidArgumentException('Missing from/start date.');
        }
        if (!$endDate || strcmp($endDate, $startDate) < 0) {
            $endDate = $startDate;
        }

        $totalDays = $this->decimal($this->firstValue($row, ['appliedleavescount', 'total_days', 'leave_count']));
        if ($totalDays === null) {
            $totalDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        }

        $leaveDay = strtolower((string) $this->firstValue($row, ['leaveday', 'leave_day']));
        $isHalfDay = str_contains($leaveDay, 'half') || (float) $totalDays === 0.5;
        $status = $this->status(
            $this->firstValue($row, ['leavestatus', 'leave_status', 'status']),
            $this->firstValue($row, ['hr_status'])
        );
        $managerStatus = $this->key($this->firstValue($row, ['leavestatus', 'leave_status', 'status']));
        $hrStatus = $this->key($this->firstValue($row, ['hr_status']));
        $createdAt = $this->datetime($this->firstValue($row, ['createddate', 'created_at'])) ?? now();
        $updatedAt = $this->datetime($this->firstValue($row, ['modifieddate', 'updated_at'])) ?? $createdAt;
        $comments = $this->clean($this->firstValue($row, ['approver_comments', 'comments']));
        $reason = $this->clean($this->firstValue($row, ['reason'])) ?: 'Legacy migration';
        $ticketCount = (int) ($this->decimal($this->firstValue($row, ['ticket_count'])) ?? 0);
        $requiresTicket = $ticketCount > 0;
        $requiresExitReentry = $this->bool($this->firstValue($row, ['exit_entry_flag', 'requires_exit_reentry']));

        return [
            'lookup' => [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDate,
                'start_time' => $this->time($this->firstValue($row, ['from_time', 'start_time'])),
                'end_date' => $endDate,
                'end_time' => $this->time($this->firstValue($row, ['to_time', 'end_time'])),
                'total_days' => (float) $totalDays,
                'status' => $status,
                'reason' => $reason,
            ],
            'values' => [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $startDate,
                'start_time' => $this->time($this->firstValue($row, ['from_time', 'start_time'])),
                'end_date' => $endDate,
                'end_time' => $this->time($this->firstValue($row, ['to_time', 'end_time'])),
                'total_days' => (float) $totalDays,
                'total_hours' => $this->hours($this->firstValue($row, ['from_time', 'start_time']), $this->firstValue($row, ['to_time', 'end_time'])),
                'is_half_day' => $isHalfDay,
                'half_day_period' => $isHalfDay && str_contains($leaveDay, 'second') ? 'afternoon' : ($isHalfDay ? 'morning' : null),
                'requires_exit_reentry' => $requiresExitReentry,
                'requires_ticket' => $requiresTicket,
                'ticket_year' => $requiresTicket ? (int) substr($startDate, 0, 4) : null,
                'ticket_count' => $ticketCount,
                'status' => $status,
                'reason' => $reason,
                'rejection_reason' => $status === 'rejected' ? $comments : null,
                'manager_approved_at' => in_array($managerStatus, ['approved'], true) ? $updatedAt : null,
                'manager_notes' => $comments,
                'hr_notes' => $this->clean($this->firstValue($row, ['hr_status'])) ?: null,
                'rejected_stage' => $status === 'rejected' ? ($managerStatus === 'rejected' ? 'manager' : 'hr') : null,
                'approved_at' => $hrStatus === 'approved' ? $updatedAt : null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ],
        ];
    }

    private function saveLeave(array $payload): string
    {
        $leave = LeaveRequest::query()
            ->where($payload['lookup'])
            ->first();

        if (!$leave && $this->option('refresh-existing')) {
            $leave = LeaveRequest::query()
                ->where([
                    'employee_id' => $payload['lookup']['employee_id'],
                    'leave_type_id' => $payload['lookup']['leave_type_id'],
                    'start_date' => $payload['lookup']['start_date'],
                    'start_time' => $payload['lookup']['start_time'],
                    'end_date' => $payload['lookup']['end_date'],
                    'end_time' => $payload['lookup']['end_time'],
                    'total_days' => $payload['lookup']['total_days'],
                    'reason' => $payload['lookup']['reason'],
                ])
                ->first();
        }

        if (!$leave) {
            $leave = new LeaveRequest();
            $leave->forceFill($payload['values'])->save();
            return 'created';
        }

        if ($this->option('refresh-existing')) {
            $leave->forceFill($payload['values'])->save();
            return 'updated';
        }

        return 'unchanged';
    }

    private function findEmployee(?string $code, ?string $email): Employee
    {
        $code = $this->clean($code);
        $formattedCode = $this->employeeCode($code);
        $email = strtolower((string) $this->clean($email));

        if (!$code && !$email) {
            throw new \InvalidArgumentException('Missing employeeId/emailaddress.');
        }

        $byCode = $code ? Employee::whereIn('employee_code', array_values(array_unique(array_filter([$formattedCode, $code]))))->first() : null;
        $byEmail = $email ? Employee::whereRaw('LOWER(email) = ?', [$email])->first() : null;

        if ($byCode && $byEmail && (int) $byCode->id !== (int) $byEmail->id) {
            throw new \InvalidArgumentException('Conflicting employeeId/emailaddress identify different employees.');
        }

        $employee = $byCode ?: $byEmail;
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found: ' . ($code ?: $email));
        }

        return $employee;
    }

    private function findOrCreateLeaveType(string $name): LeaveType
    {
        $name = trim($name);
        $existing = LeaveType::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        return LeaveType::firstOrCreate(
            ['code' => $this->uniqueLeaveTypeCode($name)],
            [
                'name' => $name,
                'days_allowed' => 0,
                'is_paid' => true,
                'is_active' => true,
                'is_annual' => str_contains(strtolower($name), 'annual'),
            ]
        );
    }

    private function uniqueLeaveTypeCode(string $name): string
    {
        $base = Str::upper(Str::slug($name ?: 'LEAVE', '_'));
        if (!LeaveType::where('code', $base)->exists()) {
            return $base;
        }

        $i = 2;
        while (LeaveType::where('code', "{$base}_{$i}")->exists()) {
            $i++;
        }

        return "{$base}_{$i}";
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $headers = null;
        $rows = [];
        $line = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $line++;
            if ($headers === null) {
                $headers = array_map(fn ($header) => $this->key($header), $data);
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($data[$index] ?? ''));
            }
            $row['_line'] = $line;
            $rows[] = $row;
        }

        fclose($handle);
        return $rows;
    }

    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && !$this->blank($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function status(?string $leaveStatus, ?string $hrStatus): string
    {
        $leave = $this->key($leaveStatus);
        $hr = $this->key($hrStatus);

        if (in_array($leave, ['cancel', 'cancelled', 'canceled'], true) || in_array($hr, ['cancel', 'cancelled', 'canceled'], true)) {
            return 'cancelled';
        }
        if ($leave === 'rejected' || $hr === 'rejected') {
            return 'rejected';
        }
        if ($hr === 'approved') {
            return 'approved';
        }
        if ($leave === 'approved') {
            return 'manager_approved';
        }

        return 'pending';
    }

    private function date(?string $value): ?string
    {
        $value = $this->clean($value);
        if (!$value) {
            return null;
        }

        $date = Carbon::parse($value);

        return $date->year < 1990 ? null : $date->toDateString();
    }

    private function datetime(?string $value): ?Carbon
    {
        $value = $this->clean($value);
        if (!$value) {
            return null;
        }

        return Carbon::parse($value);
    }

    private function time(?string $value): ?string
    {
        $value = $this->clean($value);
        if (!$value || $value === '00:00:00') {
            return null;
        }

        return Carbon::parse($value)->format('H:i:s');
    }

    private function hours(?string $from, ?string $to): ?float
    {
        $start = $this->time($from);
        $end = $this->time($to);
        if (!$start || !$end) {
            return null;
        }

        $startAt = Carbon::parse($start);
        $endAt = Carbon::parse($end);
        if ($endAt->lessThanOrEqualTo($startAt)) {
            return null;
        }

        return round($startAt->diffInMinutes($endAt) / 60, 2);
    }

    private function decimal(?string $value): ?float
    {
        $value = $this->clean($value);
        return $value === null ? null : (float) str_replace(',', '', $value);
    }

    private function bool(?string $value): bool
    {
        return in_array(strtolower((string) $this->clean($value)), ['1', 'true', 'yes', 'y'], true);
    }

    private function employeeCode(?string $value): ?string
    {
        $value = $this->clean($value);
        if (!$value) {
            return null;
        }

        return str_starts_with(strtoupper($value), 'EMP') ? strtoupper($value) : 'EMP' . $value;
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $key => $value) {
            if (!str_starts_with((string) $key, '_') && !$this->blank($value)) {
                return false;
            }
        }

        return true;
    }

    private function key(?string $value): string
    {
        return Str::of((string) $value)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function clean(?string $value): ?string
    {
        return $this->blank($value) ? null : trim((string) $value);
    }

    private function blank($value): bool
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null';
    }
}
