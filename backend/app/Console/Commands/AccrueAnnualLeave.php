<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveAllocation;
use App\Models\LeaveType;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Services\AnnualLeaveAllocationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AccrueAnnualLeave extends Command
{
    protected $signature = 'leave:accrue {--dry-run : Preview without saving}';

    protected $description = 'Daily accrual of annual leave — Saudi working days (Sun–Thu), 22 days <5yrs / 30 days >=5yrs';

    // Saudi working days (Carbon: 0=Sun, 1=Mon, 2=Tue, 3=Wed, 3=Thu, 5=Fri, 6=Sat)
    private const WORKING_DAYS = [0, 1, 2, 3, 4]; // Sun–Thu

    private const WORKING_PER_YEAR = 260;               // 52 weeks × 5 days

    public function handle(AnnualLeaveAllocationService $annualAllocations): int
    {
        $today = Carbon::today('Asia/Riyadh');
        $dryRun = $this->option('dry-run');

        // Get Annual Leave type
        $annualType = LeaveType::annualPolicyType();

        if (! $annualType) {
            $this->error('Annual Leave type not found. Run: php artisan db:seed');

            return 1;
        }

        $employees = Employee::whereIn('status', ['active', 'probation', 'on_leave'])
            ->whereNotNull('hire_date')
            ->get();

        $this->info("Running leave accrual — {$today->toDateString()} — {$employees->count()} employees");

        $updated = 0;
        $rows = [];

        foreach ($employees as $emp) {
            $hireDate = Carbon::parse($emp->hire_date, 'Asia/Riyadh');

            if ($hireDate->gt($today)) {
                continue;
            } // not started yet

            // ── Entitlement: 22 days until 5 full years, then 30 days ─────
            $yearsCompleted = (int) $hireDate->diffInYears($today);
            $entitlement = $yearsCompleted >= 5 ? 30 : 22;

            // ── Accrual year: from last hire-date anniversary to today ─────
            // e.g. hired 15 Mar 2022 → accrual year starts 15 Mar each year
            $accrualStart = $hireDate->copy()->setYear($today->year);
            if ($accrualStart->gt($today)) {
                $accrualStart->subYear();
            }

            // ── Count working days (Sun–Thu) from accrual year start to today ──
            $workedDays = $this->countWorkingDays($accrualStart, $today);

            // ── Accrued = proportion of entitlement, capped at entitlement ─
            // daily rate = entitlement / 260
            $accrued = min(
                (float) $entitlement,
                round($workedDays * ($entitlement / self::WORKING_PER_YEAR), 2)
            );

            // ── Get or create allocation record ───────────────────────────
            $alloc = LeaveAllocation::query()
                ->where('employee_id', $emp->id)
                ->where('leave_type_id', $annualType->id)
                ->whereDate('accrual_year_start', $accrualStart->toDateString())
                ->first();

            $alloc ??= LeaveAllocation::firstOrNew(
                [
                    'employee_id' => $emp->id,
                    'leave_type_id' => $annualType->id,
                    'year' => $accrualStart->year,
                ],
                [
                    'allocated_days' => 0,
                    'used_days' => 0,
                    'pending_days' => 0,
                    'remaining_days' => 0,
                    'carried_forward_days' => 0,
                ]
            );

            $service = app(LeaveService::class);
            $alloc->accrual_year_start = $accrualStart;
            // Snapshot the setting only when the new contract-year allocation is created.
            $carryForward = $alloc->exists
                ? (float) $alloc->carried_forward_days
                : $annualAllocations->carryForwardFor($alloc);

            $base = LeaveRequest::where('employee_id', $emp->id)->where('leave_type_id', $annualType->id)
                ->whereDate('start_date', '<=', $accrualStart->copy()->addYear()->subDay()->toDateString())
                ->whereDate('end_date', '>=', $accrualStart->toDateString());
            $used = (float) (clone $base)->approvedForBalance()->sum('total_days');
            $pending = (float) (clone $base)->pendingForBalance()->sum('total_days');
            $usage = $service->annualUsageWithCarryForward($alloc, $accrualStart, $accrualStart->copy()->addYear()->subDay(), $carryForward);
            $remaining = max(0, round($accrued + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pending, 2));

            $rows[] = [
                'name' => $emp->full_name ?? ($emp->first_name.' '.$emp->last_name),
                'years' => $yearsCompleted,
                'entitlement' => $entitlement,
                'worked' => $workedDays,
                'old' => (float) $alloc->allocated_days,
                'new' => $accrued,
                'carry' => $carryForward,
                'remaining' => $remaining,
            ];

            if (! $dryRun) {
                $values = [
                    'allocated_days' => $accrued,
                    'used_days' => $used,
                    'pending_days' => $pending,
                    'remaining_days' => $remaining,
                    'last_accrual_date' => $today->toDateString(),
                    'annual_entitlement' => $entitlement,
                    'accrual_year_start' => $accrualStart->toDateString(),
                ];

                if (!$alloc->exists) {
                    $values['carried_forward_days'] = $carryForward;
                }

                $alloc->fill($values);
                $alloc->save();
            }

            $updated++;
        }

        // Display results table
        $this->table(
            ['Employee', 'Years', 'Entitlement', 'Worked Days', 'Was', 'Accrued', 'Carry Forward', 'Remaining'],
            collect($rows)->map(fn ($r) => [
                $r['name'], $r['years'], $r['entitlement'].' days',
                $r['worked'], $r['old'], $r['new'], $r['carry'], $r['remaining'],
            ])->toArray()
        );

        $label = $dryRun ? '[DRY RUN] Would update' : 'Updated';
        $this->info("{$label} {$updated} allocations.");

        return 0;
    }

    /**
     * Count Saudi working days (Sun–Thu) between two dates, inclusive.
     */
    private function countWorkingDays(Carbon $from, Carbon $to): int
    {
        if ($from->gt($to)) {
            return 0;
        }

        $fromTs = $from->copy()->startOfDay();
        $toTs = $to->copy()->startOfDay();
        $count = 0;

        // Fast calculation using week math instead of looping every day
        $totalDays = $fromTs->diffInDays($toTs) + 1;
        $fullWeeks = (int) ($totalDays / 7);
        $count = $fullWeeks * 5;

        // Handle remaining days
        $remainder = $totalDays % 7;
        $current = $fromTs->copy()->addDays($fullWeeks * 7);

        for ($i = 0; $i < $remainder; $i++) {
            if (in_array($current->dayOfWeek, self::WORKING_DAYS)) {
                $count++;
            }
            $current->addDay();
        }

        return $count;
    }
}
