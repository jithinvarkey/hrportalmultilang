<?php

namespace App\Services;

use App\Mail\LeaveStatusMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\LeaveRequest;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\DepartmentExcuseLimit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class LeaveService {

    private const WORKING_DAYS = [0, 1, 2, 3, 4]; // Sun–Thu
    const BUSINESS_EXCUSE_CODE = 'BE';
    const PERSONAL_EXCUSE_CODE = 'PE';
    const WORK_START = '08:00';
    const WORK_END = '16:00';
    const DEFAULT_MONTHLY_CAP_HOURS = 12.0;

    // ── Working days count ────────────────────────────────────────────────
    public function calculateWorkingDays(string $start, string $end): float {
        $days = 0;
        $period = CarbonPeriod::create($start, $end);
        $holidays = $this->holidayKeys($start, $end);

        foreach ($period as $date) {
            if (in_array($date->dayOfWeek, self::WORKING_DAYS) && !isset($holidays[$date->format('Y-m-d')])) {
                $days++;
            }
        }

        return $days;
    }

    private function holidayKeys(string $start, string $end): array {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();
        $years = range((int) $startDate->year, (int) $endDate->year);
        $keys = [];

        $holidays = Holiday::query()
                ->where(function ($query) use ($startDate, $endDate, $years) {
                    $query->whereRaw('date <= ? AND COALESCE(end_date, date) >= ?', [
                                $endDate->toDateString(),
                                $startDate->toDateString(),
                            ])
                            ->orWhere(function ($recurring) use ($years) {
                                $recurring->where('is_recurring', true)
                                        ->whereIn(DB::raw('YEAR(date)'), $years);
                            })
                            ->orWhere('is_recurring', true);
                })
                ->get();

        foreach ($holidays as $holiday) {
            $holidayDate = Carbon::parse($holiday->date)->startOfDay();
            $holidayEndDate = Carbon::parse($holiday->end_date ?: $holiday->date)->startOfDay();
            $durationDays = max(0, $holidayDate->diffInDays($holidayEndDate));

            if ($holiday->is_recurring) {
                foreach ($years as $year) {
                    $date = Carbon::create($year, $holidayDate->month, $holidayDate->day)->startOfDay();
                    $recurringEnd = $date->copy()->addDays($durationDays);
                    foreach (CarbonPeriod::create($date, $recurringEnd) as $holidayDay) {
                        if ($holidayDay->betweenIncluded($startDate, $endDate)) {
                            $keys[$holidayDay->toDateString()] = true;
                        }
                    }
                }
                continue;
            }

            foreach (CarbonPeriod::create($holidayDate, $holidayEndDate) as $holidayDay) {
                if ($holidayDay->betweenIncluded($startDate, $endDate)) {
                    $keys[$holidayDay->toDateString()] = true;
                }
            }
        }

        return $keys;
    }

    // ── Calculate hours for a Business Excuse ────────────────────────────
    public function calculateExcuseHours(string $date, string $startTime, string $endTime): float {
        $start = Carbon::parse("$date $startTime");
        $end = Carbon::parse("$date $endTime");
        $workStart = Carbon::parse("$date " . self::WORK_START);
        $workEnd = Carbon::parse("$date " . self::WORK_END);

        $start = $start->max($workStart);
        $end = $end->min($workEnd);

        if ($end <= $start)
            return 0;
        return round($end->diffInMinutes($start) / 60, 2);
    }

    // ── Resolve the limit for a department + leave type from DB ──────────

    /**
     * Returns: ['is_limited' => bool, 'limit_hours' => float|null]
     * is_limited  = false  → unlimited
     * limit_hours = null   → unlimited (when is_limited=false)
     * limit_hours = X      → cap at X hours/month
     */
    public function getDepartmentLimit(int $departmentId, int $leaveTypeId): array {
        $row = DepartmentExcuseLimit::where('department_id', $departmentId)
                ->where('leave_type_id', $leaveTypeId)
                ->first();

        if (!$row) {
            // No config saved yet → apply default 12h cap
            return ['is_limited' => true, 'limit_hours' => self::DEFAULT_MONTHLY_CAP_HOURS];
        }

        if (!$row->is_limited) {
            return ['is_limited' => false, 'limit_hours' => null];
        }

        return ['is_limited' => true, 'limit_hours' => $row->monthly_hours_limit ?? self::DEFAULT_MONTHLY_CAP_HOURS];
    }

    // ── Validate hourly excuses ───────────────────────────────────────────
    public function validateHourlyExcuse(
            Employee $employee,
            LeaveType $leaveType,
            string $date,
            string $startTime,
            string $endTime,
            float $hours,
            ?int $excludeRequestId = null
    ): ?string {
        $dow = Carbon::parse($date)->dayOfWeek;
        if (!in_array($dow, self::WORKING_DAYS)) {
            return "{$leaveType->name} can only be submitted for working days (Sun-Thu).";
        }
        if ($startTime < self::WORK_START || $endTime > self::WORK_END) {
            return 'Times must be within working hours (08:00 - 16:00).';
        }
        if ($startTime >= $endTime) {
            return 'End time must be after start time.';
        }
        if ($hours <= 0) {
            return 'Calculated hours must be greater than zero.';
        }

        // Overlap check
        $overlap = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereIn('status', ['pending', 'approved'])
                ->where('start_date', $date)
                ->when($excludeRequestId, fn($q) => $q->where('id', '!=', $excludeRequestId))
                ->exists();

        if ($overlap) {
            return "You already have a {$leaveType->name} request on this date.";
        }

        // Resolve department limit from DB
        $deptId = $employee->department_id;
        $limitConf = $this->getDepartmentLimit($deptId, $leaveType->id);

        if (!$limitConf['is_limited']) {
            return null; // unlimited
        }

        $capHours = $limitConf['limit_hours'];

        // Monthly usage check
        $monthStart = Carbon::parse($date)->startOfMonth()->toDateString();
        $monthEnd = Carbon::parse($date)->endOfMonth()->toDateString();

        $usedThisMonth = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereBetween('start_date', [$monthStart, $monthEnd])
                ->when($excludeRequestId, fn($q) => $q->where('id', '!=', $excludeRequestId))
                ->sum('total_hours');

        $remaining = $capHours - $usedThisMonth;

        if ($hours > $remaining) {
            $usedFmt = number_format($usedThisMonth, 1);
            $capFmt = number_format($capHours, 1);
            $remainingFmt = number_format(max(0, $remaining), 1);
            return "Monthly limit exceeded. Used: {$usedFmt}h / {$capFmt}h. Remaining: {$remainingFmt}h.";
        }

        return null;
    }

    public function validateBusinessExcuse(
            Employee $employee,
            string $date,
            string $startTime,
            string $endTime,
            float $hours,
            ?int $excludeRequestId = null
    ): ?string {
        $leaveType = LeaveType::where('code', self::BUSINESS_EXCUSE_CODE)->first();
        if (!$leaveType) {
            return 'Business Excuse leave type is not configured.';
        }

        return $this->validateHourlyExcuse($employee, $leaveType, $date, $startTime, $endTime, $hours, $excludeRequestId);
    }

    // ── Monthly usage summary ─────────────────────────────────────────────
    public function monthlyExcuseUsage(int $empId, int $year, int $month, ?int $leaveTypeId = null): array {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $leaveType = $leaveTypeId
                ? LeaveType::where('is_hourly', true)->find($leaveTypeId)
                : LeaveType::where('code', self::BUSINESS_EXCUSE_CODE)->first();

        $used = LeaveRequest::where('employee_id', $empId)
                ->where('leave_type_id', $leaveType?->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereBetween('start_date', [$monthStart, $monthEnd])
                ->sum('total_hours');

        $employee = Employee::with('department')->find($empId);
        $deptId = $employee?->department_id;
        $limitConf = $this->getDepartmentLimit($deptId ?? 0, $leaveType?->id ?? 0);

        $isUnlimited = !$limitConf['is_limited'];
        $capHours = $limitConf['limit_hours'];

        return [
            'used_hours' => round($used, 2),
            'limit_hours' => $isUnlimited ? null : $capHours,
            'remaining_hours' => $isUnlimited ? null : max(0, $capHours - $used),
            'is_unlimited' => $isUnlimited,
            'month' => Carbon::create($year, $month)->format('F Y'),
            'department' => $employee?->department?->name,
            'leave_type' => $leaveType?->name,
        ];
    }

    // ── Leave balance update ──────────────────────────────────────────────
    public function updateLeaveBalance(LeaveRequest $leave, string $action): void {
        if ($leave->leaveType && $this->isAnnualLeaveType($leave->leaveType)) {
            // Rebuild from request statuses so the second approval cannot count twice.
            $allocations = LeaveAllocation::with(['employee', 'leaveType'])
                ->where('employee_id', $leave->employee_id)
                ->where('leave_type_id', $leave->leave_type_id)->get();
            foreach ($allocations as $allocation) {
                $start = $allocation->accrual_year_start
                    ? Carbon::parse($allocation->accrual_year_start)->startOfDay()
                    : Carbon::create((int) $allocation->year, 1, 1)->startOfDay();
                $end = $start->copy()->addYear()->subDay();
                $base = LeaveRequest::where('employee_id', $leave->employee_id)
                    ->where('leave_type_id', $leave->leave_type_id)
                    ->whereDate('start_date', '<=', $end->toDateString())
                    ->whereDate('end_date', '>=', $start->toDateString());
                $allocation->used_days = (clone $base)->approvedForBalance()->sum('total_days');
                $allocation->pending_days = (clone $base)->pendingForBalance()->sum('total_days');
                $allocation->remaining_days = $this->remainingDays($allocation);
                $allocation->save();
            }
            return;
        }

        $allocation = LeaveAllocation::with(['employee', 'leaveType'])->where([
                    'employee_id' => $leave->employee_id,
                    'leave_type_id' => $leave->leave_type_id,
                    'year' => Carbon::parse($leave->start_date)->year,
                ])->first();

        if (!$allocation)
            return;

        $days = (float) ($leave->total_days ?? 0);
        $hours = (float) ($leave->total_hours ?? 0);

        if ($action === 'approve') {
            $legacyAlreadyCounted = (float) $allocation->pending_days < 0;

            if ($legacyAlreadyCounted) {
                $allocation->pending_days = 0;
            } else {
                $allocation->pending_days = max(0, (float) $allocation->pending_days - $days);
                $allocation->used_days = (float) $allocation->used_days + $days;
            }

            if ($hours) {
                $legacyHoursAlreadyCounted = (float) $allocation->pending_hours < 0;
                if ($legacyHoursAlreadyCounted) {
                    $allocation->pending_hours = 0;
                } else {
                    $allocation->pending_hours = max(0, (float) $allocation->pending_hours - $hours);
                    $allocation->used_hours = (float) $allocation->used_hours + $hours;
                }
            }

            $allocation->remaining_days = $this->remainingDays($allocation);
            $allocation->save();
        } elseif ($action === 'submit') {
            $allocation->pending_days = (float) $allocation->pending_days + $days;
            if ($hours) {
                $allocation->pending_hours = (float) $allocation->pending_hours + $hours;
            }
            $allocation->remaining_days = $this->remainingDays($allocation);
            $allocation->save();
        } elseif ($action === 'cancel') {
            if ($leave->status === 'approved') {
                $allocation->used_days = max(0, (float) $allocation->used_days - $days);
                if ($hours) {
                    $allocation->used_hours = max(0, (float) $allocation->used_hours - $hours);
                }
            } else {
                $allocation->pending_days = max(0, (float) $allocation->pending_days - $days);
                if ($hours) {
                    $allocation->pending_hours = max(0, (float) $allocation->pending_hours - $hours);
                }
            }
            $allocation->remaining_days = $this->remainingDays($allocation);
            $allocation->save();
        }
    }

    private function remainingDays(LeaveAllocation $allocation): float {
        if ($allocation->leaveType && $this->isAnnualLeaveType($allocation->leaveType)) {
            return $this->remainingAnnualDays($allocation, now('Asia/Riyadh')->startOfDay());
        }

        $available = (float) $allocation->allocated_days + (float) ($allocation->carried_forward_days ?? 0);
        $used = (float) $allocation->used_days;
        $pending = max(0, (float) $allocation->pending_days);

        return max(0, round($available - $used - $pending, 2));
    }

    private function remainingAnnualDays(LeaveAllocation $allocation, Carbon $asOf): float {
        $periodStart = $allocation->accrual_year_start
            ? Carbon::parse($allocation->accrual_year_start)->startOfDay()
            : Carbon::create((int) $allocation->year, 1, 1)->startOfDay();
        $periodEnd = $periodStart->copy()->addYear()->subDay()->endOfDay();
        $carriedForward = (float) ($allocation->carried_forward_days ?? 0);
        $usage = $this->annualUsageWithCarryForward($allocation, $periodStart, $periodEnd, $carriedForward);
        $pending = max(0, (float) $allocation->pending_days);

        return max(0, round((float) $allocation->allocated_days + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pending, 2));
    }

    public function annualUsageWithCarryForward(LeaveAllocation $allocation, Carbon $periodStart, Carbon $asOf, float $carriedForward): array {
        // Carry-in does not expire during the year: it is added to the allowance, and
        // annual leave consumes it before the current year's own entitlement.
        $balanceDate = $asOf->copy()->startOfDay();
        $usedDays = 0.0;

        $requests = LeaveRequest::with('leaveType')
                ->where('employee_id', $allocation->employee_id)
                ->approvedForBalance()
                ->whereDate('start_date', '<=', $balanceDate->toDateString())
                ->whereDate('end_date', '>=', $periodStart->toDateString())
                ->whereHas('leaveType', fn($query) =>
                    $query->where('is_annual', true)
                        ->orWhere('code', 'AL')
                        ->orWhere('name', 'like', '%Annual%')
                )
                ->get();

        foreach ($requests as $request) {
            $requestStart = Carbon::parse($request->start_date)->startOfDay()->max($periodStart);
            $requestEnd = Carbon::parse($request->end_date)->startOfDay()->min($balanceDate);
            if ($requestEnd->lt($requestStart)) {
                continue;
            }

            $usedDays += $this->leaveDaysWithin($request, $requestStart, $requestEnd);
        }

        $carryForwardUsed = min($carriedForward, $usedDays);

        return [
            'total_used_days' => round($usedDays, 2),
            'annual_used_days' => round(max(0, $usedDays - $carryForwardUsed), 2),
            'active_carry_forward_remaining' => round(max(0, $carriedForward - $carryForwardUsed), 2),
        ];
    }

    private function leaveDaysWithin(LeaveRequest $request, Carbon $start, Carbon $end): float {
        if ($request->is_half_day && $start->isSameDay($end)) {
            return 0.5;
        }

        return (float) $this->calculateWorkingDays($start->toDateString(), $end->toDateString());
    }

    private function isAnnualLeaveType(LeaveType $leaveType): bool {
        return (bool) $leaveType->is_annual
            || strtoupper((string) $leaveType->code) === 'AL'
            || str_contains(strtolower((string) $leaveType->name), 'annual');
    }

    public function notifyManager(LeaveRequest $leave, string $status, $remarks = null, $conflicts = null): void {
        try {
            // Notify the employee's manager (if set) and all HR managers
            $recipientsQuery = User::whereHas('roles', fn($q) => $q->whereIn('name', ['hr_manager', 'hr_staff']));

            if ($status === 'submitted') {
                $recipientsQuery
                    ->whereHas('roles', fn($q) => $q->where('name', 'hr_manager'))
                    ->whereDoesntHave('roles', fn($q) => $q->where('name', 'hr_staff'));
            }

            $recipients = $recipientsQuery->get();

            // Also add direct manager. employees.manager_id points to employees.id, not users.id.
            $leave->loadMissing('employee.manager.user');
            $manager = $leave->employee?->manager?->user;
            if ($manager && ($status !== 'submitted' || !$manager->roles()->where('name', 'hr_staff')->exists())) {
                $recipients->push($manager);
            }

            foreach ($recipients->unique('id') as $user) {
                if ($user->email) {
                    Mail::to($user->email)->queue(new LeaveStatusMail($leave, $status, $user->name, $remarks, $conflicts));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LeaveService::notifyManager failed: ' . $e->getMessage());
        }
    }

    public function notifyEmployee(LeaveRequest $leave, ?string $status, $remarks = null, $conflicts = null): void {
        try {
            $email = $leave->employee?->email;
            $name = $leave->employee?->first_name;
            if ($email) {
                Mail::to($email)->queue(new LeaveStatusMail($leave, $status, $name, $remarks));
            }
        } catch (\Throwable $e) {
            Log::warning('LeaveService::notifyEmployee failed: ' . $e->getMessage());
        }
    }

    public function getDepartmentLeaveConflicts(LeaveRequest $leave): Collection {
        if (
                !$leave->leaveType ||
                strtolower($leave->leaveType->name) !== 'annual leave'
        ) {
            return collect();
        }

        if ($leave->total_days <= 5) {
            return collect();
        }
        // Employee must belong to a department
        if (!$leave->employee?->department_id) {
            return collect();
        }

        $departmentId = $leave->employee->department_id;

        return LeaveRequest::with([
                            'employee',
                            'leaveType'
                        ])
                        ->where('id', '!=', $leave->id)
                        ->whereHas('employee', function ($q) use ($departmentId) {
                            $q->where('department_id', $departmentId);
                        })
                        ->whereIn('status', [
                            'pending',
                            'manager_approved',
                            'approved'
                        ])
                        ->where(function ($q) use ($leave) {

                            $q->whereBetween('start_date', [
                                $leave->start_date,
                                $leave->end_date
                            ])
                            ->orWhereBetween('end_date', [
                                $leave->start_date,
                                $leave->end_date
                            ])
                            ->orWhere(function ($q2) use ($leave) {

                                $q2->where('start_date', '<=', $leave->start_date)
                                ->where('end_date', '>=', $leave->end_date);
                            });
                        })
                        ->get();
    }
}
