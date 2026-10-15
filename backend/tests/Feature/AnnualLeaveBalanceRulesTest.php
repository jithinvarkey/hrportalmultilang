<?php

namespace Tests\Feature;

use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnnualLeaveBalanceRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->softDeletes();
        });
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_annual')->default(false);
            $table->integer('max_carry_forward')->default(0);
            $table->boolean('carry_forward')->default(false);
        });
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->integer('employee_id');
            $table->integer('leave_type_id');
            $table->string('status');
            $table->date('start_date');
            $table->date('end_date');
            $table->float('total_days');
            $table->timestamp('manager_approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('leave_allocations', function (Blueprint $table) {
            $table->id();
            $table->integer('employee_id');
            $table->integer('leave_type_id');
            $table->integer('year');
            $table->date('accrual_year_start');
            foreach (['allocated_days', 'used_days', 'pending_days', 'remaining_days', 'carried_forward_days'] as $field) {
                $table->float($field)->default(0);
            }
            $table->timestamp('carry_forward_overridden_at')->nullable();
            $table->unsignedBigInteger('carry_forward_overridden_by')->nullable();
            $table->timestamps();
        });
        DB::table('employees')->insert(['id' => 1]);
        DB::table('leave_types')->insert([
            ['id' => 1, 'code' => 'AL', 'name' => 'Annual Leave', 'is_annual' => true],
            ['id' => 2, 'code' => 'SL', 'name' => 'Sick Leave', 'is_annual' => false],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-03-31', 'Asia/Riyadh'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (['leave_requests', 'leave_allocations', 'leave_types', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function service(): LeaveService
    {
        // Isolate holiday lookup; each request in these tests is one working day.
        $service = \Mockery::mock(LeaveService::class)->makePartial();
        $service->shouldReceive('calculateWorkingDays')->andReturn(1.0);
        return $service;
    }

    private function request(string $status, int $type = 1, bool $managerApproved = true, ?string $cancelledAt = null): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => 1, 'leave_type_id' => $type, 'status' => $status,
            'start_date' => '2026-02-01', 'end_date' => '2026-02-01', 'total_days' => 1,
            'manager_approved_at' => $managerApproved ? '2026-01-20' : null,
            'cancelled_at' => $cancelledAt,
        ]);
    }

    public function test_cancelled_annual_leave_never_counts_as_taken(): void
    {
        // Every fixture request runs on 2026-02-01.
        $this->request('cancelled', 1, true, '2026-02-03 09:00:00');       // cancelled after it started
        $this->request('cancelled', 1, true, '2026-02-01 08:00:00');       // cancelled on the day
        $this->request('cancelled', 1, true, '2026-01-20 09:00:00');       // withdrawn in advance
        $this->request('cancelled');                                       // legacy, no cancelled_at
        $this->request('rejected', 1, true, '2026-02-03 09:00:00');        // rejection always restores

        $this->assertSame([], LeaveRequest::approvedForBalance()->pluck('id')->all());
        $this->assertSame([], LeaveRequest::pendingForBalance()->pluck('id')->all());
    }

    public function test_cancellation_restores_the_days_to_the_balance(): void
    {
        $allocation = LeaveAllocation::create([
            'employee_id' => 1, 'leave_type_id' => 1, 'year' => 2026,
            'accrual_year_start' => '2026-01-01', 'allocated_days' => 22,
        ]);
        $request = $this->request('approved');
        $service = $this->service();
        $service->updateLeaveBalance($request, 'approve');
        $this->assertSame(21.0, $allocation->fresh()->remaining_days);

        $request->update(['status' => 'cancelled', 'cancelled_at' => '2026-02-03 09:00:00']);
        $service->updateLeaveBalance($request, 'cancel');
        $this->assertSame(0.0, $allocation->fresh()->used_days);
        $this->assertSame(22.0, $allocation->fresh()->remaining_days);
    }

    public function test_balance_statuses_include_actual_manager_approval_only_for_annual_leave(): void
    {
        $manager = $this->request('manager_approved');
        $hr = $this->request('approved');
        $pending = $this->request('pending');
        $skipped = $this->request('manager_approved', 1, false);
        $sick = $this->request('manager_approved', 2);
        $this->request('rejected');
        $this->request('cancelled');
        $this->assertSame([$manager->id, $hr->id], LeaveRequest::approvedForBalance()->pluck('id')->all());
        $this->assertSame([$pending->id, $skipped->id, $sick->id], LeaveRequest::pendingForBalance()->pluck('id')->all());
    }

    public function test_approval_is_counted_once_and_cancellation_restores_balance(): void
    {
        $allocation = LeaveAllocation::create([
            'employee_id' => 1, 'leave_type_id' => 1, 'year' => 2026,
            'accrual_year_start' => '2026-01-01', 'allocated_days' => 22,
        ]);
        $request = $this->request('pending');
        $service = $this->service();
        $service->updateLeaveBalance($request, 'submit');
        $this->assertSame(1.0, $allocation->fresh()->pending_days);
        foreach (['manager_approved', 'approved'] as $status) {
            $request->update(['status' => $status]);
            $service->updateLeaveBalance($request, 'approve');
            $this->assertSame(1.0, $allocation->fresh()->used_days);
            $this->assertSame(0.0, $allocation->fresh()->pending_days);
            $this->assertSame(21.0, $allocation->fresh()->remaining_days);
        }
        $request->update(['status' => 'cancelled']);
        $service->updateLeaveBalance($request, 'cancel');
        $this->assertSame(0.0, $allocation->fresh()->used_days);
        $this->assertSame(22.0, $allocation->fresh()->remaining_days);
    }

    public function test_carry_forward_does_not_expire_and_is_consumed_before_the_new_entitlement(): void
    {
        $this->request('manager_approved');
        $allocation = new LeaveAllocation(['employee_id' => 1]);
        $service = $this->service();
        $start = Carbon::parse('2026-01-01');

        // Three months in, and again nine months in: the carried day stays available either way.
        foreach (['2026-03-31', '2026-12-31'] as $asOf) {
            $usage = $service->annualUsageWithCarryForward($allocation, $start, Carbon::parse($asOf), 15);
            $this->assertSame(14.0, $usage['active_carry_forward_remaining'], "as of {$asOf}");
            $this->assertSame(1.0, $usage['carry_forward_used_days'] ?? 1.0, "as of {$asOf}");
            // The day came out of carry-in, so none of this year's entitlement was touched.
            $this->assertSame(0.0, $usage['annual_used_days'], "as of {$asOf}");
        }
    }

    public function test_leave_taken_late_in_the_year_still_draws_on_carry_in_first(): void
    {
        // Reproduces employee 182: 2 days carried in, leave taken well past the old 3-month
        // window, 30 days of entitlement. Both carried days are consumed, so 4.5 survives.
        $service = $this->service();
        $start = Carbon::parse('2025-04-21');
        LeaveRequest::create([
            'employee_id' => 1, 'leave_type_id' => 1, 'status' => 'approved',
            'start_date' => '2025-12-14', 'end_date' => '2025-12-14', 'total_days' => 1,
        ]);
        $allocation = new LeaveAllocation(['employee_id' => 1]);
        $usage = $service->annualUsageWithCarryForward($allocation, $start, Carbon::parse('2026-04-20'), 2);

        $this->assertSame(1.0, $usage['total_used_days']);
        $this->assertSame(0.0, $usage['annual_used_days']);
        $this->assertSame(1.0, $usage['active_carry_forward_remaining']);
    }

    public function test_carry_forward_can_be_set_and_cleared_by_hand(): void
    {
        $this->carryForwardMigration()->up();
        DB::table('leave_types')->where('id', 1)
            ->update(['carry_forward' => true, 'carry_forward_all' => true, 'max_carry_forward' => 0]);
        $service = app(\App\Services\AnnualLeaveAllocationService::class);

        LeaveAllocation::create([
            'employee_id' => 1, 'leave_type_id' => 1, 'year' => 2025,
            'accrual_year_start' => '2025-01-01', 'allocated_days' => 22,
        ]);
        $current = LeaveAllocation::create([
            'employee_id' => 1, 'leave_type_id' => 1, 'year' => 2026,
            'accrual_year_start' => '2026-01-01', 'allocated_days' => 22,
        ]);
        // Derived from the untouched 2025 year: the whole 22 days roll over.
        $this->assertSame(22.0, $current->fresh()->carried_forward_days);

        // HR corrects it to 6, and the balance is rebuilt around the new figure.
        $service->setCarryForward($current, 6.0, 7);
        $this->assertSame(6.0, $current->fresh()->carried_forward_days);
        $this->assertSame(28.0, $current->fresh()->remaining_days);
        $this->assertSame(7, (int) $current->fresh()->carry_forward_overridden_by);

        // Clearing the override hands the period back to the normal rule.
        $service->setCarryForward($current->fresh(), null, 7);
        $this->assertSame(22.0, $current->fresh()->carried_forward_days);
        $this->assertNull($current->fresh()->carry_forward_overridden_at);
    }

    /**
     * When an employee rolls into a new contract year, the allocation created for it must
     * take the carry-forward rule currently configured in the admin settings area.
     */
    public function test_a_new_contract_year_carries_forward_using_the_configured_rule(): void
    {
        $this->carryForwardMigration()->up();

        // The year that is ending: full 30 day entitlement, none of it taken.
        LeaveAllocation::create([
            'employee_id' => 1, 'leave_type_id' => 1, 'year' => 2025,
            'accrual_year_start' => '2025-01-01', 'allocated_days' => 30,
        ]);

        $cases = [
            // [carry_forward, carry_forward_all, max_carry_forward, expected carry-in]
            'a numeric cap'      => [true,  false, 5,  5.0],
            'the whole balance'  => [true,  true,  0,  30.0],
            'carry forward off'  => [false, false, 10, 0.0],
        ];

        foreach ($cases as $label => [$enabled, $all, $max, $expected]) {
            DB::table('leave_types')->where('id', 1)->update([
                'carry_forward' => $enabled, 'carry_forward_all' => $all, 'max_carry_forward' => $max,
            ]);
            LeaveAllocation::where('year', 2026)->delete();

            $next = LeaveAllocation::create([
                'employee_id' => 1, 'leave_type_id' => 1, 'year' => 2026,
                'accrual_year_start' => '2026-01-01', 'allocated_days' => 30,
            ]);

            $this->assertSame($expected, $next->fresh()->carried_forward_days, "setting: {$label}");
        }
    }

    private function carryForwardMigration()
    {
        return require database_path('migrations/2026_09_20_000001_add_carry_forward_all_to_leave_types.php');
    }

    public function test_migration_preserves_legacy_limit_and_stores_all_option(): void
    {
        $this->carryForwardMigration()->up();
        $this->assertSame(10, (int) DB::table('leave_types')->where('id', 1)->value('max_carry_forward'));
        $type = \App\Models\LeaveType::findOrFail(1);
        $type->timestamps = false;
        $type->update(['carry_forward_all' => true]);
        $this->assertTrue($type->fresh()->carry_forward_all);
    }
}
