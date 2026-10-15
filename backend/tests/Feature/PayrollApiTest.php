<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Payroll API.
 *
 * @group payroll
 */
class PayrollApiTest extends TestCase
{
    use RefreshDatabase;

    private User $hrManager;
    private User $financeManager;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // FIX: correct seeder class name
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->hrManager = User::factory()->create();
        $this->hrManager->assignRole('hr_manager');

        $this->financeManager = User::factory()->create();
        $this->financeManager->assignRole('finance_manager');

        $this->employee = User::factory()->create();
        $this->employee->assignRole('employee');

        Employee::factory()->count(3)->create(['status' => 'active']);
    }

    /** @test */
    public function stats_returns_correct_structure(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->getJson('/api/v1/payroll/stats')
            ->assertOk()
            ->assertJsonStructure([
                'total_runs', 'pending_approval', 'approved', 'paid',
                'latest_net', 'latest_gross', 'latest_month',
            ]);
    }

    /** @test */
    public function hr_manager_can_run_payroll(): void
    {
        $month = now()->format('Y-m');

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/payroll/run', [
                'month'        => $month,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end'   => now()->endOfMonth()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Payroll run successfully');

        $this->assertDatabaseHas('payrolls', ['month' => $month]);
    }

    /** @test */
    public function payroll_excludes_the_system_admin_employee(): void
    {
        $systemAdmin = User::factory()->create(['name' => 'System Admin']);
        $systemAdmin->assignRole('super_admin');
        $systemAdminEmployee = Employee::factory()->create([
            'user_id' => $systemAdmin->id,
            'status'  => 'active',
        ]);

        $month = now()->format('Y-m');

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/payroll/run', [
                'month'        => $month,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end'   => now()->endOfMonth()->toDateString(),
            ])
            ->assertCreated();

        $payrollId = $response->json('payroll.id');

        $this->assertDatabaseMissing('payslips', [
            'payroll_id'  => $payrollId,
            'employee_id' => $systemAdminEmployee->id,
        ]);
    }

    /** @test */
    public function payroll_includes_probation_employees(): void
    {
        $probationEmployee = Employee::factory()->create([
            'status' => 'probation',
            'salary' => 8000,
        ]);

        $month = now()->format('Y-m');

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/payroll/run', [
                'month'        => $month,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end'   => now()->endOfMonth()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payslips', [
            'payroll_id'  => $response->json('payroll.id'),
            'employee_id' => $probationEmployee->id,
        ]);
    }

    /** @test */
    public function hr_manager_can_remove_only_a_probation_employee_from_pending_payroll(): void
    {
        $payroll = Payroll::factory()->create([
            'status' => 'pending_approval',
            'total_gross' => 20000,
            'total_deductions' => 1800,
            'total_net' => 18200,
        ]);
        $probationEmployee = Employee::factory()->create(['status' => 'probation']);
        $activeEmployee = Employee::factory()->create(['status' => 'active']);
        $probationPayslip = Payslip::factory()->create([
            'payroll_id' => $payroll->id,
            'employee_id' => $probationEmployee->id,
        ]);
        $activePayslip = Payslip::factory()->create([
            'payroll_id' => $payroll->id,
            'employee_id' => $activeEmployee->id,
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->deleteJson("/api/v1/payroll/{$payroll->id}/payslips/{$activePayslip->id}")
            ->assertUnprocessable();

        $this->actingAs($this->hrManager, 'sanctum')
            ->deleteJson("/api/v1/payroll/{$payroll->id}/payslips/{$probationPayslip->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Probation employee removed from this payroll');

        $this->assertDatabaseHas('payslips', ['id' => $activePayslip->id]);
        $this->assertDatabaseMissing('payslips', ['id' => $probationPayslip->id]);
        $this->assertSame((float) $activePayslip->gross_salary, (float) $payroll->fresh()->total_gross);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/recalculate")
            ->assertOk();

        $this->assertDatabaseHas('payslips', [
            'payroll_id' => $payroll->id,
            'employee_id' => $probationEmployee->id,
        ]);
    }

    /** @test */
    public function payroll_reflects_due_loans_and_approved_unpaid_leave(): void
    {
        $employee = Employee::factory()->create([
            'status' => 'active',
            'salary' => 10000,
        ]);

        $unpaidType = LeaveType::create([
            'name' => 'Unpaid Leave',
            'code' => 'UL-TEST',
            'days_allowed' => 30,
            'is_paid' => false,
            'carry_forward' => false,
            'is_active' => true,
            'is_hourly' => false,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $unpaidType->id,
            'start_date' => '2026-08-02',
            'end_date' => '2026-08-03',
            'total_days' => 2,
            'status' => 'approved',
            'reason' => 'Test unpaid leave',
        ]);

        $loan = Loan::factory()->create([
            'employee_id' => $employee->id,
            'status' => 'disbursed',
            'approved_amount' => 1000,
            'balance_remaining' => 1000,
        ]);

        $installment = LoanInstallment::factory()->create([
            'loan_id' => $loan->id,
            'due_date' => '2026-08-10',
            'amount' => 500,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/payroll/run', [
                'month' => '2026-08',
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
            ])
            ->assertCreated();

        $payslip = Payslip::where('payroll_id', $response->json('payroll.id'))
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame(2.0, $payslip->unpaid_leave_days);
        $this->assertGreaterThan(0, $payslip->leave_deduction);
        $this->assertSame(500.0, $payslip->loan_deduction);
        $this->assertSame($payslip->id, $installment->fresh()->payslip_id);
    }

    /** @test */
    public function duplicate_payroll_run_is_rejected(): void
    {
        $month = now()->format('Y-m');

        Payroll::factory()->create(['month' => $month, 'status' => 'pending_approval']);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/payroll/run', [
                'month'        => $month,
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end'   => now()->endOfMonth()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'already exists'));
    }

    /** @test */
    public function run_requires_valid_month_format(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/payroll/run', [
                'month'        => 'invalid',
                'period_start' => now()->startOfMonth()->toDateString(),
                'period_end'   => now()->endOfMonth()->toDateString(),
            ])
            ->assertUnprocessable();
    }

    /** @test */
    public function finance_manager_can_approve_payroll(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'pending_approval']);

        $this->actingAs($this->financeManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/approve")
            ->assertOk()
            ->assertJsonPath('payroll.status', 'approved');
    }

    /** @test */
    public function regular_employee_cannot_approve_payroll(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'pending_approval']);

        $this->actingAs($this->employee, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/approve")
            ->assertForbidden();
    }

    /** @test */
    public function cannot_approve_already_approved_payroll(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'approved']);

        $this->actingAs($this->financeManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/approve")
            ->assertUnprocessable();
    }

    /** @test */
    public function finance_manager_can_mark_payroll_as_paid(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'approved']);

        $this->actingAs($this->financeManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('payroll.status', 'paid');
    }

    /** @test */
    public function cannot_mark_pending_payroll_as_paid(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'pending_approval']);

        $this->actingAs($this->financeManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/mark-paid")
            ->assertUnprocessable();
    }

    /** @test */
    public function hr_manager_can_reject_payroll(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'pending_approval']);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/reject", ['reason' => 'Data errors found.'])
            ->assertOk();

        $this->assertDatabaseHas('payrolls', ['id' => $payroll->id, 'status' => 'rejected']);
    }

    /** @test */
    public function approved_payroll_can_be_reopened(): void
    {
        $payroll = Payroll::factory()->create(['status' => 'approved']);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/payroll/{$payroll->id}/reopen")
            ->assertOk()
            ->assertJsonPath('payroll.status', 'pending_approval');
    }

    /** @test */
    public function can_list_payslips_for_payroll(): void
    {
        $payroll = Payroll::factory()->create();
        Payslip::factory()->count(3)->create(['payroll_id' => $payroll->id]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->getJson("/api/v1/payroll/{$payroll->id}/payslips")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    /** @test */
    public function unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/payroll/stats')->assertUnauthorized();
    }
}
