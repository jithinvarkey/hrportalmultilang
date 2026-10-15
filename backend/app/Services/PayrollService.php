<?php
namespace App\Services;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\PayrollComponent;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\LoanInstallment;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    protected ExportService $exportService;
    protected LeaveService $leaveService;

    public function __construct(ExportService $exportService, LeaveService $leaveService)
    {
        $this->exportService = $exportService;
        $this->leaveService = $leaveService;
    }

    // ── Saudi GOSI Rates ──────────────────────────────────────────────────────
    // Applied on BASIC salary only, Saudi nationals only
    const GOSI_EMPLOYEE_RATE = 0.09;    // 9%  — deducted from employee
    const GOSI_EMPLOYER_RATE = 0.1175;  // 11.75% — company cost (annuities 9% + hazard 2% + work injury 0.75%)

    // Saudi standard allowance rates
    const HOUSING_RATE    = 0.25;   // 25% of basic
    const TRANSPORT_RATE   = 0.10;   // 10% of basic

    // Saudi working days Sun–Thu
    private const WORKING_DAYS = [0, 1, 2, 3, 4];

    // ── Run Payroll ───────────────────────────────────────────────────────────
    public function runPayroll(array $data, int $createdBy): Payroll
    {
        // Check if new salary columns exist (migration may not have run yet)
        $hasNewColumns = \Illuminate\Support\Facades\Schema::hasColumn('payslips', 'housing_allowance');

        $payroll = Payroll::create([
            'cycle_name'   => 'Payroll ' . $data['month'],
            'month'        => $data['month'],
            'period_start' => $data['period_start'],
            'period_end'   => $data['period_end'],
            'status'       => 'pending_approval',
            'created_by'   => $createdBy,
        ]);

        $employees    = $this->eligibleEmployees();
        $totalGross   = 0;
        $totalDeduct  = 0;
        $totalNet     = 0;

        foreach ($employees as $employee) {
            $payslip = $this->createPayslip($payroll, $employee, $data, $hasNewColumns);
            $totalGross  += $payslip->gross_salary;
            $totalDeduct += $payslip->total_deductions;
            $totalNet    += $payslip->net_salary;
        }

        $payroll->update([
            'total_gross'      => round($totalGross, 2),
            'total_deductions' => round($totalDeduct, 2),
            'total_net'        => round($totalNet, 2),
        ]);

        return $payroll->load('payslips');
    }

    /**
     * Active and probationary employees eligible for payroll.
     *
     * The System Admin account is operational rather than payroll-eligible,
     * so employees linked to a super_admin user must never receive a payslip.
     */
    public function eligibleEmployees(): Collection
    {
        return Employee::whereIn('status', ['active', 'probation'])
            ->whereDoesntHave('user.roles', fn ($query) => $query->where('name', 'super_admin'))
            ->get();
    }

    /** Calculate, persist, and reserve any loan installments included in a payslip. */
    public function createPayslip(Payroll $payroll, Employee $employee, array $data, bool $hasNewColumns = true): Payslip
    {
        $slip = $this->calculatePayslip($employee, $data, $hasNewColumns);
        $loanInstallmentIds = $slip['loan_installment_ids'] ?? [];
        unset($slip['loan_installment_ids']);

        $payslip = $payroll->payslips()->create($slip);

        if ($loanInstallmentIds) {
            LoanInstallment::whereIn('id', $loanInstallmentIds)
                ->whereNull('payslip_id')
                ->update(['payslip_id' => $payslip->id]);
        }

        return $payslip;
    }

    // ── Calculate one payslip ─────────────────────────────────────────────────
    public function calculatePayslipPublic(Employee $employee, array $data, bool $hasNewColumns = true): array
    {
        return $this->calculatePayslip($employee, $data, $hasNewColumns);
    }

    protected function calculatePayslip(Employee $employee, array $data, bool $hasNewColumns = true): array
    {
        $isSaudi     = strtolower($employee->nationality ?? '') === 'saudi';
        $workingDays = $this->getPeriodWorkingDays($data['period_start'], $data['period_end']);
        $absentDays  = $this->getAbsentDays($employee->id, $data['period_start'], $data['period_end']);
        $leaveDays   = $this->getApprovedLeaveDays($employee->id, $data['period_start'], $data['period_end']);
        $unpaidLeaveDays = $this->getUnpaidLeaveDays($employee->id, $data['period_start'], $data['period_end']);

        // ── Basic salary (pro-rated for absences) ─────────────────────────
        $fullBasic  = (float) $employee->salary;
        $dailyRate  = $this->dailyRate($fullBasic, $workingDays);
        $basicSalary = round($fullBasic - ($dailyRate * $absentDays), 2);

        // ── Allowances ────────────────────────────────────────────────────
        $housingAllowance   = round($basicSalary * self::HOUSING_RATE, 2);
        $transportAllowance = ($workingDays > $absentDays) ? round($basicSalary * self::TRANSPORT_RATE, 2) : 0;

        // ── Extra components from DB (bonuses etc.) ───────────────────────
        $components    = PayrollComponent::where('is_active', true)
            ->whereNotIn('code', ['HRA','TA','GOSI_EMP','GOSI_EMP_ER','LOAN']) // handled separately
            ->get();
        $otherAllowances  = 0;
        $otherDeductions  = 0;
        $componentBreakdown = [];

        foreach ($components as $comp) {
            $amount = $comp->calculation === 'percentage'
                ? round(($fullBasic * $comp->value) / 100, 2)
                : (float) $comp->value;

            if ($comp->type === 'earning') {
                $otherAllowances += $amount;
            } else {
                $otherDeductions += $amount;
            }

            $componentBreakdown[] = [
                'id'     => $comp->id,
                'code'   => $comp->code,
                'name'   => $comp->name,
                'type'   => $comp->type,
                'amount' => $amount,
            ];
        }

        // ── GOSI (Saudi nationals only) ───────────────────────────────────
        $gosiEmployee = $isSaudi ? round($basicSalary * self::GOSI_EMPLOYEE_RATE, 2) : 0;
        $gosiEmployer = $isSaudi ? round($basicSalary * self::GOSI_EMPLOYER_RATE, 2) : 0;

        $leaveDeduction = $this->settingEnabled('deduct_unpaid_leave', true)
            ? round($dailyRate * $unpaidLeaveDays, 2)
            : 0;

        if ($leaveDeduction > 0 && $this->settingEnabled('deduct_allowances_on_leave', false)) {
            $allowanceDailyRate = $this->dailyRate(
                ($fullBasic * self::HOUSING_RATE) + ($fullBasic * self::TRANSPORT_RATE),
                $workingDays
            );
            $leaveDeduction = round($leaveDeduction + ($allowanceDailyRate * $unpaidLeaveDays), 2);
        }

        $loan = $this->loanDeduction($employee->id, $data['period_start'], $data['period_end']);
        $loanDeduction = $loan['amount'];

        // ── Totals ────────────────────────────────────────────────────────
        $totalEarnings   = round($basicSalary + $housingAllowance + $transportAllowance + $otherAllowances, 2);
        $totalDeductions = round($gosiEmployee + $otherDeductions + $leaveDeduction + $loanDeduction, 2);
        $grossSalary     = $totalEarnings;
        $netSalary       = round(max(0, $grossSalary - $totalDeductions), 2);

        $base = [
            'employee_id'    => $employee->id,
            'basic_salary'   => $basicSalary,
            'total_earnings' => $totalEarnings,
            'gross_salary'   => $grossSalary,
            'total_deductions'=> $totalDeductions,
            'net_salary'     => $netSalary,
            'working_days'   => $workingDays - $absentDays,
            'absent_days'    => $absentDays,
            'leave_days'     => $leaveDays,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'leave_deduction' => $leaveDeduction,
            'loan_deduction' => $loanDeduction,
            'loan_installment_ids' => $loan['installment_ids'],
            'components'     => [],
        ];

        if (!$hasNewColumns) return $base;

        return array_merge($base, [
            'is_saudi'            => $isSaudi,
            // Earnings
            'housing_allowance'   => $housingAllowance,
            'transport_allowance' => $transportAllowance,
            'other_allowances'    => $otherAllowances,
            // Deductions
            'gosi_employee'       => $gosiEmployee,
            'gosi_employer'       => $gosiEmployer,
            'other_deductions'    => $otherDeductions,
            // Breakdown
            'components'          => array_merge(
                [
                    ['code'=>'BASIC',  'name'=>'Basic Salary',        'type'=>'earning',   'amount'=>$basicSalary],
                    ['code'=>'HRA',    'name'=>'Housing Allowance',   'type'=>'earning',   'amount'=>$housingAllowance],
                    ['code'=>'TA',     'name'=>'Transport Allowance', 'type'=>'earning',   'amount'=>$transportAllowance],
                ],
                $componentBreakdown,
                $leaveDeduction > 0 ? [[
                    'code'=>'UNPAID_LEAVE',
                    'name'=>'Unpaid Leave (' . $unpaidLeaveDays . ' days)',
                    'type'=>'deduction',
                    'amount'=>$leaveDeduction,
                ]] : [],
                $loan['components'],
                $isSaudi ? [
                    ['code'=>'GOSI_EMP',   'name'=>'GOSI (Employee 9%)',   'type'=>'deduction', 'amount'=>$gosiEmployee],
                    ['code'=>'GOSI_EMPER', 'name'=>'GOSI (Employer 11.75%)', 'type'=>'info',    'amount'=>$gosiEmployer],
                ] : []
            ),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function dailyRate(float $monthlyAmount, int $periodWorkingDays): float
    {
        $basis = (string) DB::table('payroll_settings')->where('key', 'daily_rate_basis')->value('value');

        return match ($basis) {
            'fixed' => $monthlyAmount / max(1, (int) (DB::table('payroll_settings')
                ->where('key', 'working_days_per_month')->value('value') ?: 26)),
            'annual' => ($monthlyAmount * 12) / 260,
            default => $periodWorkingDays > 0 ? $monthlyAmount / $periodWorkingDays : 0,
        };
    }

    protected function settingEnabled(string $key, bool $default): bool
    {
        $value = DB::table('payroll_settings')->where('key', $key)->value('value');
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function getUnpaidLeaveDays(int $employeeId, string $from, string $to): float
    {
        $requests = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereHas('leaveType', fn ($query) => $query
                ->where('is_paid', false)
                ->where('is_hourly', false))
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->get();

        return round((float) $requests->sum(function (LeaveRequest $leave) use ($from, $to) {
            $start = Carbon::parse($leave->start_date)->max(Carbon::parse($from));
            $end = Carbon::parse($leave->end_date)->min(Carbon::parse($to));

            if ($leave->is_half_day && $start->isSameDay($end)) {
                return 0.5;
            }

            return $this->leaveService->calculateWorkingDays(
                $start->toDateString(),
                $end->toDateString()
            );
        }), 2);
    }

    /** @return array{amount: float, installment_ids: array<int>, components: array<int, array<string, mixed>>} */
    protected function loanDeduction(int $employeeId, string $periodStart, string $periodEnd): array
    {
        $installments = LoanInstallment::with('loan:id,reference,employee_id,status')
            ->whereNull('payslip_id')
            ->whereIn('status', ['pending', 'overdue'])
            ->whereBetween('due_date', [$periodStart, $periodEnd])
            ->whereHas('loan', fn ($query) => $query
                ->where('employee_id', $employeeId)
                ->where('status', 'disbursed'))
            ->get();

        $amount = round((float) $installments->sum(
            fn (LoanInstallment $installment) => max(0, $installment->amount - $installment->paid_amount)
        ), 2);

        return [
            'amount' => $amount,
            'installment_ids' => $installments->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'components' => $installments->map(fn (LoanInstallment $installment) => [
                'code' => 'LOAN',
                'name' => ($installment->loan?->reference ?? 'Loan') . ' installment #' . $installment->installment_no,
                'type' => 'deduction',
                'amount' => round(max(0, $installment->amount - $installment->paid_amount), 2),
                'loan_installment_id' => $installment->id,
                'due_date' => $installment->due_date?->toDateString(),
            ])->all(),
        ];
    }

    public function settlePayrollLoanInstallments(Payroll $payroll, int $processedBy): void
    {
        $installments = LoanInstallment::whereHas(
            'payslip',
            fn ($query) => $query->where('payroll_id', $payroll->id)
        )->whereIn('status', ['pending', 'overdue'])->get();

        $loanIds = $installments->pluck('loan_id')->unique()->all();

        foreach ($installments as $installment) {
            $installment->update([
                'status' => 'paid',
                'paid_amount' => $installment->amount,
                'paid_date' => now()->toDateString(),
                'processed_by' => $processedBy,
                'paid_via_payroll' => true,
                'notes' => trim(($installment->notes ? $installment->notes . ' | ' : '') . 'Paid through payroll ' . $payroll->month),
            ]);
        }

        $this->syncLoanBalances($loanIds);
    }

    public function reversePayrollLoanInstallments(Payroll $payroll): void
    {
        $installments = LoanInstallment::whereHas(
            'payslip',
            fn ($query) => $query->where('payroll_id', $payroll->id)
        )->where('paid_via_payroll', true)->get();

        $loanIds = $installments->pluck('loan_id')->unique()->all();

        foreach ($installments as $installment) {
            $installment->update([
                'status' => $installment->due_date?->isPast() ? 'overdue' : 'pending',
                'paid_amount' => 0,
                'paid_date' => null,
                'processed_by' => null,
                'paid_via_payroll' => false,
            ]);
        }

        $this->syncLoanBalances($loanIds);
    }

    public function releasePayrollLoanInstallments(Payroll $payroll): void
    {
        LoanInstallment::whereHas(
            'payslip',
            fn ($query) => $query->where('payroll_id', $payroll->id)
        )->where('paid_via_payroll', false)->update(['payslip_id' => null]);
    }

    protected function syncLoanBalances(array $loanIds): void
    {
        Loan::whereIn('id', $loanIds)->get()->each(function (Loan $loan) {
            $totalPaid = (float) $loan->installments()->where('status', 'paid')->sum('paid_amount');
            $installmentsPaid = $loan->installments()->where('status', 'paid')->count();
            $pending = $loan->installments()->whereIn('status', ['pending', 'overdue'])->count();
            $principal = (float) ($loan->approved_amount ?? $loan->requested_amount);

            $loan->update([
                'total_paid' => round($totalPaid, 2),
                'balance_remaining' => round(max(0, $principal - $totalPaid), 2),
                'installments_paid' => $installmentsPaid,
                'status' => $pending === 0 ? 'completed' : 'disbursed',
            ]);
        });
    }

    /** Total Saudi working days (Sun–Thu) in a period */
    protected function getPeriodWorkingDays(string $from, string $to): int
    {
        $count  = 0;
        $period = CarbonPeriod::create($from, $to);
        foreach ($period as $date) {
            if (in_array($date->dayOfWeek, self::WORKING_DAYS)) $count++;
        }
        return $count;
    }

    protected function getAbsentDays(int $empId, string $from, string $to): int
    {
        return AttendanceLog::where('employee_id', $empId)
            ->whereBetween('date', [$from, $to])
            ->where('status', 'absent')
            ->count();
    }

    protected function getApprovedLeaveDays(int $empId, string $from, string $to): int
    {
        return \App\Models\LeaveRequest::where('employee_id', $empId)
            ->where('status', 'approved')
            ->where(function($q) use ($from, $to) {
                $q->whereBetween('start_date', [$from, $to])
                  ->orWhereBetween('end_date', [$from, $to]);
            })
            ->sum('total_days') ?? 0;
    }

    // ── PDF & Export ──────────────────────────────────────────────────────────
    /**
     * PDF generation placeholder — frontend handles printing via browser print API.
     * Implement with DomPDF or Browsershot when PDF library is installed.
     */
    public function generatePayslipPdf(Payslip $payslip): array
    {
        $payslip->load(['employee.department', 'payroll']);
        return ['payslip' => $payslip->toArray()];
    }

    public function dispatchPayslipEmails(Payroll $payroll): void {}

    public function exportBankTransfer(int $payrollId)
    {
        $rows = Payslip::with('employee')
            ->where('payroll_id', $payrollId)
            ->get()
            ->map(fn($p) => [
                'employee_code' => $p->employee->employee_code,
                'name'          => $p->employee->first_name . ' ' . $p->employee->last_name,
                'nationality'   => $p->employee->nationality ?? '',
                'bank_name'     => $p->employee->bank_name ?? '',
                'bank_account'  => $p->employee->bank_account ?? '',
                'basic_salary'  => $p->basic_salary,
                'housing'       => $p->housing_allowance,
                'transport'     => $p->transport_allowance,
                'gross'         => $p->gross_salary,
                'gosi_emp'      => $p->gosi_employee,
                'unpaid_leave'  => $p->leave_deduction,
                'loan'          => $p->loan_deduction,
                'other_deductions' => $p->other_deductions,
                'net_salary'    => $p->net_salary,
            ]);

        return $this->exportService->csvDownload(
            'bank_transfer_' . now()->format('Ymd') . '.csv',
            ['Emp Code','Name','Nationality','Bank','Account','Basic','Housing','Transport','Gross','GOSI(Emp)','Unpaid Leave','Loan','Other Deductions','Net'],
            $rows
        );
    }
}
