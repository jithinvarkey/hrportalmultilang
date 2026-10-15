<?php

namespace App\Services;

use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;

class AnnualLeaveAllocationService
{
    public function __construct(private AnnualLeaveCarryForwardPolicy $policy, private LeaveService $leaves) {}

    public function carryForwardFor(LeaveAllocation $allocation): float
    {
        $type = $allocation->leaveType;
        if (!$type || !$type->isAnnual()) {
            return 0.0;
        }

        $start = $this->periodStart($allocation);
        $previousStart = $start->copy()->subYear();
        $previous = LeaveAllocation::where('employee_id', $allocation->employee_id)
            ->where('leave_type_id', $allocation->leave_type_id)
            ->where(function ($query) use ($previousStart) {
                $query->whereDate('accrual_year_start', $previousStart->toDateString())
                    ->orWhere(function ($legacy) use ($previousStart) {
                        $legacy->whereNull('accrual_year_start')->where('year', $previousStart->year);
                    });
            })->first();

        if (!$previous) {
            return 0.0;
        }

        $end = $start->copy()->subDay();
        $usage = $this->leaves->annualUsageWithCarryForward(
            $previous, $previousStart, $end, (float) $previous->carried_forward_days
        );
        $pending = $this->pendingDays($allocation, $previousStart, $end);
        // Only unspent annual entitlement survives; carry-in never rolls a second time.
        $remaining = max(0, (float) $previous->allocated_days - $usage['annual_used_days'] - $pending);
        $settings = LeaveType::annualPolicyType() ?? $type;

        return $this->policy->calculate(
            (bool) $settings->carry_forward, $remaining,
            $settings->max_carry_forward, (bool) $settings->carry_forward_all
        );
    }

    public function initialize(LeaveAllocation $allocation): void
    {
        if ($allocation->exists || !$allocation->leaveType?->isAnnual()) {
            return;
        }

        $this->apply($allocation);
    }

    public function periodStart(LeaveAllocation $allocation): Carbon
    {
        if ($allocation->accrual_year_start) {
            return Carbon::parse($allocation->accrual_year_start)->startOfDay();
        }

        $hireDate = $allocation->employee?->hire_date;
        return $hireDate
            ? Carbon::parse($hireDate)->setYear((int) $allocation->year)->startOfDay()
            : Carbon::create((int) $allocation->year, 1, 1)->startOfDay();
    }

    /**
     * Set, or with null clear, a carry-forward figure entered by hand. An entered figure
     * stands until it is cleared: recalculate() re-derives everything around it but leaves
     * the number itself alone, so a bulk reset cannot quietly undo an HR correction.
     */
    public function setCarryForward(LeaveAllocation $allocation, ?float $days, ?int $userId): void
    {
        if ($days === null) {
            $allocation->carry_forward_overridden_at = null;
            $allocation->carry_forward_overridden_by = null;
            $allocation->carried_forward_days = $this->carryForwardFor($allocation);
        } else {
            // The column keeps one decimal place, so half days survive and nothing else does.
            $allocation->carried_forward_days = round($days, 1);
            $allocation->carry_forward_overridden_at = now();
            $allocation->carry_forward_overridden_by = $userId;
        }

        $this->refreshBalances($allocation);
        $allocation->save();
    }

    private function apply(LeaveAllocation $allocation): void
    {
        $allocation->accrual_year_start = $this->periodStart($allocation);

        // A figure entered by hand is only ever re-derived through setCarryForward(null).
        if (!$allocation->carry_forward_overridden_at) {
            $allocation->carried_forward_days = $this->carryForwardFor($allocation);
        }

        $this->refreshBalances($allocation);
    }

    private function refreshBalances(LeaveAllocation $allocation): void
    {
        $start = $this->periodStart($allocation);
        $end = $start->copy()->addYear()->subDay();
        $usage = $this->leaves->annualUsageWithCarryForward(
            $allocation, $start, $end, (float) $allocation->carried_forward_days
        );
        $pending = $this->pendingDays($allocation, $start, $end);
        $allocation->used_days = $usage['total_used_days'];
        $allocation->pending_days = $pending;
        $allocation->remaining_days = max(0, round((float) $allocation->allocated_days
            + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pending, 2));
    }

    private function pendingDays(LeaveAllocation $allocation, Carbon $start, Carbon $end): float
    {
        return (float) LeaveRequest::where('employee_id', $allocation->employee_id)
            ->where('leave_type_id', $allocation->leave_type_id)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->pendingForBalance()->sum('total_days');
    }
}
