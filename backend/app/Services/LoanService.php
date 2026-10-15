<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanInstallment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanService {

    // ── Generate unique reference ─────────────────────────────────────────
    public function generateReference(): string {
        $year = now()->year;
        $count = Loan::whereYear('created_at', $year)->count() + 1;
        return 'LOAN-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    // ── Calculate monthly installment (flat interest) ─────────────────────
    public function calculateMonthlyInstallment(float $amount, int $installments, float $annualRate = 0): float {
        if ($installments <= 0)
            return $amount;
        if ($annualRate <= 0)
            return round($amount / $installments, 2);

        $monthlyRate = $annualRate / 100 / 12;
        // Standard amortization formula
        $payment = $amount * ($monthlyRate * pow(1 + $monthlyRate, $installments)) / (pow(1 + $monthlyRate, $installments) - 1);
        return round($payment, 2);
    }

    // ── Generate installment schedule ─────────────────────────────────────
    public function generateInstallments(Loan $loan): void {
        // Remove any existing schedule first
        $loan->installments()->delete();

        $startDate = $loan->first_installment_date ? Carbon::parse($loan->first_installment_date) : Carbon::parse($loan->disbursed_date)->addMonth()->startOfMonth();

        $amount = $loan->approved_amount ?? $loan->requested_amount;
        $installAmt = $loan->monthly_installment ?? $this->calculateMonthlyInstallment($amount, $loan->installments);

        $rows = [];
        for ($i = 1; $i <= $loan->installments; $i++) {
            // Last installment gets the rounding remainder
            $installAmount = ($i === $loan->installments) ? round($amount - ($installAmt * ($loan->installments - 1)), 2) : $installAmt;

            $rows[] = [
                'loan_id' => $loan->id,
                'installment_no' => $i,
                'due_date' => $startDate->copy()->addMonths($i - 1)->toDateString(),
                'amount' => $installAmount,
                'paid_amount' => 0,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        LoanInstallment::insert($rows);

        // Sync balance
        $loan->update(['balance_remaining' => $amount]);
    }

    // ── Mark installment as paid ──────────────────────────────────────────
    public function payInstallment(LoanInstallment $inst, ?string $paidDate = null, ?string $notes = null): void {
        $inst->update([
            'status' => 'paid',
            'paid_amount' => $inst->amount,
            'paid_date' => $paidDate ?? now()->toDateString(),
            'processed_by' => auth()->id(),
            'notes' => $notes,
        ]);

        $loan = $inst->loan;
        $loan->increment('total_paid', $inst->amount);
        $loan->decrement('balance_remaining', $inst->amount);
        $loan->increment('installments_paid');

        // Auto-complete if all paid
        $pendingCount = $loan->installments()->whereIn('status', ['pending', 'overdue'])->count();
        if ($pendingCount === 0) {
            $loan->update(['status' => 'completed', 'balance_remaining' => 0]);
        }
    }

    // ── Skip installment for one month ────────────────────────────────────
    public function skipInstallment(LoanInstallment $inst, ?string $notes = null): LoanInstallment {
        $inst->update([
            'status' => 'skipped',
            'processed_by' => auth()->id(),
            'notes' => $notes ?? 'Skipped — deferred to next month',
        ]);

        $loan = $inst->loan;
        $loan->increment('installments_skipped');

        // Push a new installment at the end of the schedule
        // NOTE: Cannot use $loan->installments()->orderBy() because the relationship
        // already has a default ASC order; chaining DESC appends rather than replaces,
        // causing it to still return installment #1. Query directly instead.
        $lastInst = LoanInstallment::where('loan_id', $loan->id)
                ->orderBy('installment_no', 'desc')
                ->first();
        $newDue = Carbon::parse($lastInst->due_date)->addMonth()->toDateString();

        return LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_no' => $lastInst->installment_no + 1,
            'due_date' => $newDue,
            'amount' => $inst->amount,
            'status' => 'pending',
        ]);
    }

    // ── Mark overdue installments ─────────────────────────────────────────
    public function markOverdue(): int {
        return LoanInstallment::where('status', 'pending')
                        ->where('due_date', '<', now()->toDateString())
                        ->update(['status' => 'overdue']);
    }

    // ── Loan summary stats ────────────────────────────────────────────────
    public function stats(): array {
        $user = auth()->user();
        $approvalLevels = $this->loanApprovalLevels();

        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')
                        ->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, [
                    'super_admin',
                    'hr_manager',
                    'hr_staff'
        ]);

        $isMgr = in_array('department_manager', $userRoles);

        $baseQuery = Loan::query();

        if (!$isHRAdmin) {

            if ($isMgr && $user->employee) {

                $deptId = $user->employee->department_id;

                $baseQuery->whereHas('employee', function ($q) use ($deptId, $user) {

                    $q->where('department_id', $deptId)
                            ->where('id', '!=', $user->employee->id); // exclude manager himself
                });
            } elseif ($user->employee) {

                $baseQuery->where('employee_id', $user->employee->id);
            }
        }

        $pendingManagerQuery = (clone $baseQuery)->where('status', 'pending_manager');
        if ($this->shouldLimitApprovalViewsToActiveEmployees($userRoles)) {
            $pendingManagerQuery->whereHas('employee', fn($employee) => $employee->whereIn('status', ['active', 'probation']));
        }

        $pendingManager = $pendingManagerQuery->count();

        $pendingHr = (clone $baseQuery)
                ->when(
                        $approvalLevels === 2,
                        fn($q) => $q->whereIn('status', ['pending_hr', 'pending_manager']),
                        fn($q) => $q->where('status', 'pending_hr')
                )
                ->count();

        return [
            'pending_manager' => $approvalLevels === 3 ? $pendingManager : 0,
            'pending_hr' => $pendingHr,
            'pending_finance' => (clone $baseQuery)
                    ->where('status', 'pending_finance')
                    ->count(),
            'active_loans' => (clone $baseQuery)
                    ->whereIn('status', ['approved', 'disbursed'])
                    ->count(),
            'total_outstanding' => (clone $baseQuery)
                    ->whereIn('status', ['approved', 'disbursed'])
                    ->sum('balance_remaining'),
            'completed' => (clone $baseQuery)
                    ->where('status', 'completed')
                    ->count(),
        ];
    }

    private function loanApprovalLevels(): int {
        $configured = (int) config('loans.approval_levels', 3);

        $stored = rescue(
                fn() => DB::table('system_settings')
                        ->where('key', 'loan_approval_levels')
                        ->value('value'),
                null,
                false
        );

        $levels = $stored === null ? $configured : (int) $stored;

        return in_array($levels, [2, 3], true) ? $levels : 3;
    }

    private function shouldLimitApprovalViewsToActiveEmployees(array $userRoles): bool {
        return !in_array('super_admin', $userRoles, true)
                && (in_array('department_manager', $userRoles, true) || in_array('hr_manager', $userRoles, true));
    }
}
