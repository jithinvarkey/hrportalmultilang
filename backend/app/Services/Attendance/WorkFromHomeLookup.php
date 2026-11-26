<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\EmployeeRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Carbon\Carbon;

/**
 * Work From Home days per employee: leave requests of the Work From Home type, plus the older HR_WFH
 * requests (matched by their "Needed By" date). Rejected and cancelled ones are ignored.
 *
 * Every day is returned as ['id', 'reference', 'status', 'details'], where status is
 * pending_manager, pending / in_progress (awaiting HR) or completed, whichever source it came from.
 */
class WorkFromHomeLookup
{
    /** Status of a Work From Home day that has no attendance record. */
    public const STATUS = 'work_from_home';

    /** Request type used before Work From Home became a leave type. */
    public const LEGACY_REQUEST_TYPE_CODE = 'HR_WFH';

    private const LEAVE_STATUS = [
        'pending' => 'pending_manager',
        'manager_approved' => 'pending',
        'approved' => 'completed',
    ];

    public function __construct(private LeaveService $leaveService)
    {
    }

    /**
     * @param  iterable<int>  $employeeIds
     * @return array<string, array{id: int, reference: ?string, status: string, details: ?string}>
     *         keyed "employeeId|Y-m-d"; leave requests win over older requests, then the latest wins
     */
    public function byEmployeeDate(iterable $employeeIds, string $from, string $to): array
    {
        $ids = collect($employeeIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $days = [];

        EmployeeRequest::query()
            ->whereHas('requestType', fn($q) => $q->where('code', self::LEGACY_REQUEST_TYPE_CODE))
            ->whereIn('employee_id', $ids)
            ->whereNotNull('required_by')
            ->whereDate('required_by', '>=', $from)
            ->whereDate('required_by', '<=', $to)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->orderBy('id')
            ->get(['id', 'reference', 'employee_id', 'required_by', 'status', 'details'])
            ->each(function (EmployeeRequest $r) use (&$days): void {
                $days[$r->employee_id . '|' . Carbon::parse($r->required_by)->toDateString()] = [
                    'id' => $r->id,
                    'reference' => $r->reference,
                    'status' => (string) $r->status,
                    'details' => $r->details,
                ];
            });

        $leaves = LeaveRequest::query()
            ->workFromHome()
            ->whereIn('employee_id', $ids)
            ->whereIn('status', array_keys(self::LEAVE_STATUS))
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('id')
            ->get(['id', 'employee_id', 'start_date', 'end_date', 'status', 'reason']);

        if ($leaves->isEmpty()) {
            return $days;
        }

        // Only walk the days the requests cover, however wide the requested period is.
        $from = max($from, $leaves->min(fn(LeaveRequest $l) => $l->start_date->toDateString()));
        $to = min($to, $leaves->max(fn(LeaveRequest $l) => $l->end_date->toDateString()));

        foreach ($this->leaveService->workingDates($from, $to) as $date) {
            foreach ($leaves as $leave) {
                if ($date < $leave->start_date->toDateString() || $date > $leave->end_date->toDateString()) {
                    continue;
                }

                $days[$leave->employee_id . '|' . $date] = [
                    'id' => $leave->id,
                    'reference' => $leave->reference,
                    'status' => self::LEAVE_STATUS[$leave->status],
                    'details' => $leave->reason,
                ];
            }
        }

        return $days;
    }

    /**
     * Attendance rows (not saved) for the Work From Home days nobody punched on, newest day first, so an
     * applied day is listed even when there is no attendance record for it.
     *
     * @param  array<string, array>  $days  result of byEmployeeDate()
     * @return \Illuminate\Support\Collection<int, AttendanceLog>
     */
    public function rowsWithoutAttendance(array $days): \Illuminate\Support\Collection
    {
        if ($days === []) {
            return collect();
        }

        $keys = collect(array_keys($days))->map(fn(string $key) => explode('|', $key));
        $logged = AttendanceLog::query()
            ->whereIn('employee_id', $keys->pluck(0)->unique())
            ->whereDate('date', '>=', $keys->min(1))
            ->whereDate('date', '<=', $keys->max(1))
            ->get(['employee_id', 'date'])
            ->mapWithKeys(fn(AttendanceLog $log) => [$log->employee_id . '|' . Carbon::parse($log->date)->toDateString() => true]);

        $rows = $keys->reject(fn(array $key) => $logged->has($key[0] . '|' . $key[1]))
            ->map(fn(array $key) => (new AttendanceLog())->forceFill([
                'employee_id' => (int) $key[0],
                'date' => $key[1],
                'check_in' => null,
                'check_out' => null,
                'total_minutes' => null,
                'status' => self::STATUS,
                'source' => 'leave',
            ])->setAttribute('wfh', $days[$key[0] . '|' . $key[1]]))
            ->sortBy([fn($a, $b) => Carbon::parse($b->date) <=> Carbon::parse($a->date) ?: $a->employee_id <=> $b->employee_id])
            ->values();

        (new \Illuminate\Database\Eloquent\Collection($rows->all()))->load('employee.department');

        return $rows;
    }

    /** Plain-English approval stage, for exports. */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Approved',
            'pending_manager' => 'Awaiting manager approval',
            'pending', 'in_progress' => 'Awaiting HR approval',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** One-line summary for exports, e.g. "WFH LV-00020 (Approved): reason…". */
    public function describe(array $day): string
    {
        $details = trim(preg_replace('/\s+/', ' ', (string) $day['details']));

        return 'WFH ' . $day['reference'] . ' (' . $this->statusLabel($day['status']) . ')'
            . ($details !== '' ? ': ' . $details : '');
    }
}
