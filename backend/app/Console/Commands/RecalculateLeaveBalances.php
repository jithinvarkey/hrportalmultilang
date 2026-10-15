<?php

namespace App\Console\Commands;

use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateLeaveBalances extends Command
{
    protected $signature = 'leave:recalculate-balances
        {--dry-run : Preview changes without saving}
        {--employee= : Limit to one employee id or employee_code}';

    protected $description = 'Recalculate leave allocation used/pending/remaining values from leave request approval statuses';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $employeeFilter = $this->option('employee');
        $createdAllocations = $this->ensureMissingAllocations($dryRun, $employeeFilter);

        $allocations = LeaveAllocation::query()
            ->with(['employee', 'leaveType'])
            ->when($employeeFilter, function ($query, $value) {
                $query->whereHas('employee', function ($employeeQuery) use ($value) {
                    $employeeQuery->where('id', $value)->orWhere('employee_code', $value);
                });
            })
            ->orderBy('employee_id')
            ->orderBy('year')
            ->get();

        $updated = 0;
        $rows = [];

        foreach ($allocations as $allocation) {
            [$yearStart, $yearEnd] = $this->allocationPeriod($allocation);

            $base = LeaveRequest::query()
                ->where('employee_id', $allocation->employee_id)
                ->where('leave_type_id', $allocation->leave_type_id)
                ->whereDate('start_date', '<=', $yearEnd->toDateString())
                ->whereDate('end_date', '>=', $yearStart->toDateString());

            $usedDays = (float) (clone $base)->approvedForBalance()->sum('total_days');
            $pendingDays = (float) (clone $base)->pendingForBalance()->sum('total_days');
            $usedHours = (float) (clone $base)->approvedForBalance()->sum('total_hours');
            $pendingHours = (float) (clone $base)->pendingForBalance()->sum('total_hours');

            $remaining = $this->isAnnualLeave($allocation->leaveType)
                ? $this->remainingAnnualDays($allocation, now('Asia/Riyadh')->startOfDay(), $pendingDays)
                : max(0, round((float) $allocation->allocated_days + (float) ($allocation->carried_forward_days ?? 0) - $usedDays - $pendingDays, 2));

            $changed = round((float) $allocation->used_days, 2) !== round($usedDays, 2)
                || round((float) $allocation->pending_days, 2) !== round($pendingDays, 2)
                || round((float) $allocation->remaining_days, 2) !== round($remaining, 2)
                || round((float) ($allocation->used_hours ?? 0), 2) !== round($usedHours, 2)
                || round((float) ($allocation->pending_hours ?? 0), 2) !== round($pendingHours, 2);

            if (!$changed) {
                continue;
            }

            $updated++;
            $rows[] = [
                $allocation->employee?->employee_code,
                $allocation->leaveType?->name,
                $allocation->year,
                (float) $allocation->used_days . ' -> ' . $usedDays,
                (float) $allocation->pending_days . ' -> ' . $pendingDays,
                (float) $allocation->remaining_days . ' -> ' . $remaining,
            ];

            if (!$dryRun) {
                $allocation->update([
                    'used_days' => $usedDays,
                    'pending_days' => $pendingDays,
                    'remaining_days' => $remaining,
                    'used_hours' => $usedHours,
                    'pending_hours' => $pendingHours,
                ]);
            }
        }

        $this->table(
            ['Employee', 'Leave Type', 'Year', 'Used Days', 'Pending Days', 'Remaining Days'],
            array_slice($rows, 0, 30)
        );

        if (count($rows) > 30) {
            $this->line('Showing first 30 changed rows only.');
        }

        $this->info(sprintf(
            '%s %d missing leave allocations. %s %d leave allocation balances.',
            $dryRun ? '[DRY RUN] Would create' : 'Created',
            $createdAllocations,
            $dryRun ? '[DRY RUN] Would update' : 'Updated',
            $updated
        ));

        return self::SUCCESS;
    }

    private function ensureMissingAllocations(bool $dryRun, ?string $employeeFilter): int
    {
        $requests = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->whereIn('status', ['approved', 'pending', 'manager_approved'])
            ->when($employeeFilter, function ($query, $value) {
                $query->whereHas('employee', function ($employeeQuery) use ($value) {
                    $employeeQuery->where('id', $value)->orWhere('employee_code', $value);
                });
            })
            ->orderBy('employee_id')
            ->orderBy('leave_type_id')
            ->orderBy('start_date')
            ->get();

        $seen = [];
        $created = 0;

        foreach ($requests as $request) {
            if (!$request->employee || !$request->leaveType || !$request->start_date) {
                continue;
            }

            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $periodStart = $this->periodStartForRequest($request->leaveType, $request->employee, $startDate);
            $year = (int) $periodStart->year;
            $key = $request->employee_id . ':' . $request->leave_type_id . ':' . $year;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $exists = LeaveAllocation::where([
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'year' => $year,
            ])->exists();

            if ($exists) {
                continue;
            }

            $created++;

            if ($dryRun) {
                continue;
            }

            LeaveAllocation::create([
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'year' => $year,
                'allocated_days' => $this->allocatedDaysFor($request->leaveType, $request->employee, $periodStart),
                'used_days' => 0,
                'pending_days' => 0,
                'remaining_days' => 0,
                'carried_forward_days' => 0,
                'used_hours' => 0,
                'pending_hours' => 0,
                'annual_entitlement' => $this->allocatedDaysFor($request->leaveType, $request->employee, $periodStart),
                'accrual_year_start' => $this->isAnnualLeave($request->leaveType) ? $periodStart->toDateString() : null,
                'last_accrual_date' => now('Asia/Riyadh')->toDateString(),
            ]);
        }

        return $created;
    }

    private function allocationPeriod(LeaveAllocation $allocation): array
    {
        if ($allocation->accrual_year_start) {
            $start = Carbon::parse($allocation->accrual_year_start)->startOfDay();
            return [$start, $start->copy()->addYear()->subDay()->endOfDay()];
        }

        $start = Carbon::create((int) $allocation->year, 1, 1)->startOfDay();
        return [$start, $start->copy()->endOfYear()];
    }

    private function periodStartForRequest(LeaveType $leaveType, $employee, Carbon $requestStart): Carbon
    {
        if (!$this->isAnnualLeave($leaveType) || !$employee->hire_date) {
            return Carbon::create((int) $requestStart->year, 1, 1)->startOfDay();
        }

        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        $periodStart = Carbon::create((int) $requestStart->year, (int) $hireDate->month, (int) $hireDate->day)->startOfDay();

        if ($periodStart->gt($requestStart)) {
            $periodStart->subYear();
        }

        return $periodStart;
    }

    private function allocatedDaysFor(LeaveType $leaveType, $employee, Carbon $periodStart): float
    {
        if (!$this->isAnnualLeave($leaveType) || !$employee->hire_date) {
            return (float) ($leaveType->days_allowed ?? 0);
        }

        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        $contractYearsCompleted = max(0, $hireDate->diffInYears($periodStart));

        return $contractYearsCompleted >= 5 ? 30.0 : 22.0;
    }

    private function isAnnualLeave(LeaveType $leaveType): bool
    {
        return (bool) $leaveType->is_annual
            || strtoupper((string) $leaveType->code) === 'AL'
            || str_contains(strtolower((string) $leaveType->name), 'annual');
    }

    private function remainingAnnualDays(LeaveAllocation $allocation, Carbon $asOf, float $pendingDays): float
    {
        [$periodStart, $periodEnd] = $this->allocationPeriod($allocation);
        $balanceDate = $asOf->copy()->min($periodEnd)->max($periodStart);
        $carriedForward = (float) ($allocation->carried_forward_days ?? 0);
        $usage = $this->annualUsageWithCarryForward($allocation, $periodStart, $periodEnd, $carriedForward);

        return max(0, round((float) $allocation->allocated_days + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pendingDays, 2));
    }

    private function annualUsageWithCarryForward(LeaveAllocation $allocation, Carbon $periodStart, Carbon $asOf, float $carriedForward): array
    {
        $balanceDate = $asOf->copy()->startOfDay();
        // Carry-in does not expire during the year; it is consumed before the year's own days.
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
        $carryForwardRemaining = max(0, $carriedForward - $carryForwardUsed);
        $activeCarryForwardRemaining = $carryForwardRemaining;

        return [
            'annual_used_days' => round(max(0, $usedDays - $carryForwardUsed), 2),
            'active_carry_forward_remaining' => round($activeCarryForwardRemaining, 2),
        ];
    }

    private function leaveDaysWithin(LeaveRequest $request, Carbon $start, Carbon $end): float
    {
        if ($request->is_half_day && $start->isSameDay($end)) {
            return 0.5;
        }

        return (float) $this->countWorkingDays($start, $end);
    }

    private function countWorkingDays(Carbon $from, Carbon $to): int
    {
        if ($from->gt($to)) {
            return 0;
        }

        $count = 0;
        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            if (!in_array($current->dayOfWeek, [5, 6], true)) {
                $count++;
            }
            $current->addDay();
        }

        return $count;
    }
}
