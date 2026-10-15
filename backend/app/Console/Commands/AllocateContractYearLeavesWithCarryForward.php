<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AnnualLeaveCarryForwardPolicy;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AllocateContractYearLeavesWithCarryForward extends Command
{
    public function __construct(private AnnualLeaveCarryForwardPolicy $carryForwardPolicy)
    {
        parent::__construct();
    }

    protected $signature = 'leave:allocate-contract-years-carryforward
        {--dry-run : Preview changes without saving}
        {--as-of= : Evaluation date in YYYY-MM-DD format, defaults to today}
        {--employee= : Limit to one employee id or employee_code}';

    protected $description = 'Create/update annual leave allocations for every active employee contract year and carry forward eligible remaining leave';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $asOf = $this->parseAsOfDate();

        if (! $asOf) {
            return self::FAILURE;
        }

        $annualType = LeaveType::annualPolicyType();

        if (! $annualType) {
            $this->error('Annual Leave type not found.');

            return self::FAILURE;
        }

        $employees = Employee::query()
            ->where('status', 'active')
            ->whereNotNull('hire_date')
            ->when($this->option('employee'), function ($query, $value) {
                $query->where(function ($q) use ($value) {
                    $q->where('id', $value)->orWhere('employee_code', $value);
                });
            })
            ->orderBy('employee_code')
            ->get();

        $summary = [
            'employees' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];
        $rows = [];

        $this->info(sprintf(
            '%s contract-year leave allocations with carry-forward for %d active employees as of %s',
            $dryRun ? '[DRY RUN] Previewing' : 'Running',
            $employees->count(),
            $asOf->toDateString()
        ));

        foreach ($employees as $employee) {
            $hireDate = Carbon::parse($employee->hire_date, 'Asia/Riyadh')->startOfDay();

            if ($hireDate->gt($asOf)) {
                $summary['skipped']++;

                continue;
            }

            $summary['employees']++;
            $carryForward = 0.0;

            foreach ($this->contractYears($hireDate, $asOf) as $index => [$startDate, $endDate]) {
                $entitlement = $index >= 5 ? 30.0 : 22.0;
                $usedDays = $this->usedDays($employee->id, $annualType, $startDate, $endDate);
                $pendingDays = $this->pendingDays($employee->id, $annualType, $startDate, $endDate);
                $allocation = $this->existingAllocation($employee->id, $annualType->id, $startDate);
                $existingCarryForward = (float) ($allocation?->carried_forward_days ?? 0);
                if ($allocation) {
                    $carryForward = $existingCarryForward;
                }
                $usageAllocation = $allocation ?: new LeaveAllocation(['employee_id' => $employee->id]);
                $usage = app(LeaveService::class)->annualUsageWithCarryForward(
                    $usageAllocation, $startDate, $endDate, $carryForward
                );
                $remaining = max(0, round($entitlement + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pendingDays, 2));
                $nextCarryForward = $this->eligibleCarryForward($annualType, $remaining);

                $values = [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $annualType->id,
                    'year' => $startDate->year,
                    'allocated_days' => $entitlement,
                    'used_days' => $usedDays,
                    'pending_days' => $pendingDays,
                    'remaining_days' => $remaining,
                    'annual_entitlement' => $entitlement,
                    'accrual_year_start' => $startDate->toDateString(),
                    'last_accrual_date' => $asOf->toDateString(),
                ];

                if (!$allocation) {
                    $values['carried_forward_days'] = $carryForward;
                }

                $changed = ! $allocation || collect($values)->contains(function ($value, $key) use ($allocation) {
                    if (in_array($key, ['accrual_year_start', 'last_accrual_date'], true)) {
                        return optional($allocation->{$key})->toDateString() !== $value;
                    }

                    return round((float) $allocation->{$key}, 2) !== round((float) $value, 2);
                });

                if (! $allocation) {
                    $summary['created']++;
                } elseif ($changed) {
                    $summary['updated']++;
                } else {
                    $summary['unchanged']++;
                }

                if (! $dryRun && (! $allocation || $changed)) {
                    if ($allocation) {
                        $allocation->update($values);
                    } else {
                        LeaveAllocation::create($values);
                    }
                }

                if ($changed || count($rows) < 25) {
                    $rows[] = [
                        $employee->employee_code,
                        $employee->full_name,
                        $startDate->toDateString(),
                        $endDate->toDateString(),
                        $entitlement,
                        $carryForward,
                        $usedDays,
                        $pendingDays,
                        $remaining,
                        $nextCarryForward,
                        $allocation ? ($changed ? 'update' : 'same') : 'create',
                    ];
                }

                $carryForward = $nextCarryForward;
            }
        }

        $this->table(
            ['Employee ID', 'Employee', 'Start', 'End', 'Allocated', 'Carry In', 'Used', 'Pending', 'Remaining', 'Carry Out', 'Action'],
            array_slice($rows, 0, 25)
        );

        if (count($rows) > 25) {
            $this->line('Showing first 25 rows only.');
        }

        $this->info(sprintf(
            '%s Employees: %d | Created: %d | Updated: %d | Unchanged: %d | Skipped: %d',
            $dryRun ? '[DRY RUN]' : 'Done.',
            $summary['employees'],
            $summary['created'],
            $summary['updated'],
            $summary['unchanged'],
            $summary['skipped']
        ));

        return self::SUCCESS;
    }

    private function parseAsOfDate(): ?Carbon
    {
        try {
            return $this->option('as-of')
                ? Carbon::parse($this->option('as-of'), 'Asia/Riyadh')->startOfDay()
                : Carbon::today('Asia/Riyadh');
        } catch (\Throwable) {
            $this->error('Invalid --as-of date. Use YYYY-MM-DD.');

            return null;
        }
    }

    private function contractYears(Carbon $hireDate, Carbon $asOf): array
    {
        $years = [];
        $start = $hireDate->copy();

        while ($start->lte($asOf)) {
            $end = $start->copy()->addYear()->subDay();
            $years[] = [$start->copy(), $end];
            $start->addYear();
        }

        return $years;
    }

    private function usedDays(int $employeeId, LeaveType $annualType, Carbon $startDate, Carbon $endDate): float
    {
        return (float) $this->annualLeaveRequestQuery($employeeId, $annualType, $startDate, $endDate)
            ->approvedForBalance()
            ->sum('total_days');
    }

    private function existingAllocation(int $employeeId, int $leaveTypeId, Carbon $startDate): ?LeaveAllocation
    {
        return LeaveAllocation::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where(function ($query) use ($startDate) {
                $query->where('year', $startDate->year)
                    ->orWhereDate('accrual_year_start', $startDate->toDateString());
            })
            ->orderByRaw('CASE WHEN accrual_year_start = ? THEN 0 ELSE 1 END', [$startDate->toDateString()])
            ->first();
    }

    private function pendingDays(int $employeeId, LeaveType $annualType, Carbon $startDate, Carbon $endDate): float
    {
        return (float) $this->annualLeaveRequestQuery($employeeId, $annualType, $startDate, $endDate)
            ->pendingForBalance()
            ->sum('total_days');
    }

    private function annualLeaveRequestQuery(int $employeeId, LeaveType $annualType, Carbon $startDate, Carbon $endDate)
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $annualType->id)
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString());
    }

    private function eligibleCarryForward(LeaveType $annualType, float $remaining): float
    {
        return $this->carryForwardPolicy->calculate(
            (bool) $annualType->carry_forward,
            $remaining,
            $annualType->max_carry_forward,
            (bool) $annualType->carry_forward_all
        );
    }
}
