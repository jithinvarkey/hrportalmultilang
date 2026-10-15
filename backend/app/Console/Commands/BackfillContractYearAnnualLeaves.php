<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillContractYearAnnualLeaves extends Command
{
    protected $signature = 'leave:backfill-contract-years
        {--dry-run : Preview changes without saving}
        {--as-of= : Evaluation date in YYYY-MM-DD format, defaults to today}
        {--employee= : Limit to one employee id or employee_code}
        {--force : Update existing allocations/contracts created by this command}';

    protected $description = 'Create approved yearly contracts and annual leave allocations from active employees hire dates';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $asOf = $this->parseAsOfDate();

        if (!$asOf) {
            return self::FAILURE;
        }

        $annualType = LeaveType::annualPolicyType();

        if (!$annualType) {
            $this->error('Annual Leave type not found.');
            return self::FAILURE;
        }

        $employees = Employee::query()
            ->with(['department', 'designation'])
            ->where('status', 'active')
            ->whereNotNull('hire_date')
            ->when($this->option('employee'), function ($query, $value) {
                $query->where(function ($q) use ($value) {
                    $q->where('id', $value)->orWhere('employee_code', $value);
                });
            })
            ->orderBy('id')
            ->get();

        $summary = [
            'employees' => 0,
            'contracts_created' => 0,
            'contracts_updated' => 0,
            'allocations_created' => 0,
            'allocations_updated' => 0,
            'skipped' => 0,
        ];
        $rows = [];

        $this->info(sprintf(
            '%s annual leave contract-year backfill for %d active employees as of %s',
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
            $contractYears = $this->contractYears($hireDate, $asOf);

            foreach ($contractYears as $index => [$startDate, $endDate]) {
                $entitlement = $index >= 5 ? 30 : 22;
                $contractStatus = $endDate->lt($asOf) ? 'expired' : 'active';
                $reference = $this->legacyReference($employee, $startDate);

                $contractExists = Contract::withTrashed()->where('reference', $reference)->exists();
                $allocation = LeaveAllocation::where([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $annualType->id,
                    'year' => $startDate->year,
                ])->first();
                $allocationExists = (bool) $allocation;

                if (!$dryRun) {
                    DB::transaction(function () use (
                        $employee,
                        $annualType,
                        $startDate,
                        $endDate,
                        $entitlement,
                        $contractStatus,
                        $reference,
                        $force,
                        &$contractExists,
                        &$allocation
                    ) {
                        if (!$contractExists || $force) {
                            Contract::withTrashed()->updateOrCreate(
                                ['reference' => $reference],
                                [
                                    'employee_id' => $employee->id,
                                    'type' => $employee->employment_type ?: 'full_time',
                                    'status' => $contractStatus,
                                    'start_date' => $startDate->toDateString(),
                                    'end_date' => $endDate->toDateString(),
                                    'salary' => $employee->salary,
                                    'currency' => 'SAR',
                                    'position' => $employee->designation?->title,
                                    'department_id' => $employee->department_id,
                                    'terms' => 'Auto-created yearly contract from employee joining date for annual leave migration.',
                                    'approved_at' => now('Asia/Riyadh'),
                                ]
                            );
                        }

                        if (!$allocation || $force) {
                            $usedDays = (float) ($allocation?->used_days ?? 0);
                            $pendingDays = (float) ($allocation?->pending_days ?? 0);

                            $allocation = LeaveAllocation::updateOrCreate(
                                [
                                    'employee_id' => $employee->id,
                                    'leave_type_id' => $annualType->id,
                                    'year' => $startDate->year,
                                ],
                                [
                                    'allocated_days' => $entitlement,
                                    'used_days' => $usedDays,
                                    'pending_days' => $pendingDays,
                                    'remaining_days' => max(0, $entitlement - $usedDays - $pendingDays),
                                    'annual_entitlement' => $entitlement,
                                    'accrual_year_start' => $startDate->toDateString(),
                                    'last_accrual_date' => now('Asia/Riyadh')->toDateString(),
                                ]
                            );
                        }
                    });
                }

                $summary[$contractExists ? 'contracts_updated' : 'contracts_created'] += ($force || !$contractExists) ? 1 : 0;
                $summary[$allocationExists ? 'allocations_updated' : 'allocations_created'] += ($force || !$allocationExists) ? 1 : 0;

                $rows[] = [
                    $employee->employee_code,
                    $employee->full_name,
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                    $entitlement,
                    $contractStatus,
                ];
            }
        }

        $this->table(
            ['Employee ID', 'Employee', 'Contract Start', 'Contract End', 'Annual Days', 'Contract Status'],
            array_slice($rows, 0, 25)
        );

        if (count($rows) > 25) {
            $this->line('Showing first 25 rows only.');
        }

        $this->info(sprintf(
            '%s Employees: %d | Contracts created: %d | Contracts updated: %d | Allocations created: %d | Allocations updated: %d | Skipped: %d',
            $dryRun ? '[DRY RUN]' : 'Done.',
            $summary['employees'],
            $summary['contracts_created'],
            $summary['contracts_updated'],
            $summary['allocations_created'],
            $summary['allocations_updated'],
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

    /**
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
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

    private function legacyReference(Employee $employee, Carbon $startDate): string
    {
        $code = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($employee->employee_code ?: $employee->id));
        return sprintf('LEG-CTR-%s-%s', $code, $startDate->format('Ymd'));
    }
}
