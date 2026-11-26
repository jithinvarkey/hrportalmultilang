<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\RequestType;
use App\Models\User;
use App\Services\Attendance\WorkFromHomeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Work From Home as a leave type, limited to 4 working days per calendar month.
 * December 2026: the 1st is a Tuesday; working days are Sunday to Thursday.
 */
class WorkFromHomeLeaveTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $user;
    private Employee $employee;
    private LeaveType $wfh;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->hr = User::factory()->create();
        $this->hr->assignRole('hr_manager');
        $this->user = User::factory()->create();
        $this->user->assignRole('employee');
        $this->employee = Employee::factory()->create([
            'user_id' => $this->user->id,
            'department_id' => Department::factory()->create()->id,
        ]);

        $this->wfh = LeaveType::where('code', 'WFH')->firstOrFail(); // created by the migration
    }

    private function apply(string $from, string $to, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/leave/requests', array_merge([
            'leave_type_id' => $this->wfh->id,
            'start_date' => $from,
            'end_date' => $to,
            'reason' => 'Working from home today',
        ], $extra));
    }

    private function legacyRequest(string $date, string $status): EmployeeRequest
    {
        $type = RequestType::firstOrCreate(
            ['code' => 'HR_WFH'],
            ['name' => 'Work From Home Request', 'category' => 'hr', 'sla_days' => 2]
        );

        return EmployeeRequest::create([
            'reference' => 'REQ-' . $date, 'employee_id' => $this->employee->id, 'request_type_id' => $type->id,
            'status' => $status, 'details' => 'Old WFH request', 'required_by' => $date, 'copies_needed' => 1,
        ]);
    }

    public function test_the_type_is_available_to_employees_with_a_limit_of_four(): void
    {
        $type = collect($this->actingAs($this->user, 'sanctum')->getJson('/api/v1/leave/types')->assertOk()->json('types'))
            ->firstWhere('code', 'WFH');

        $this->assertNotNull($type);
        $this->assertEquals(4, $type['monthly_days_limit']);
        $this->assertTrue($type['is_paid']);
    }

    public function test_four_days_are_allowed_and_the_fifth_is_refused(): void
    {
        $this->apply('2026-12-01', '2026-12-03')->assertCreated();
        $this->apply('2026-12-06', '2026-12-06')->assertCreated();

        $this->apply('2026-12-07', '2026-12-07')->assertStatus(422)
            ->assertJsonPath('message', 'Work From Home is limited to 4 days per month. Month 2026-12 - used: 4, requested: 1, remaining: 0.');

        $this->apply('2027-01-04', '2027-01-04')->assertCreated(); // a new month starts again
    }

    public function test_a_single_request_over_the_limit_is_refused(): void
    {
        $this->apply('2026-12-06', '2026-12-10')->assertStatus(422);
        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_weekends_do_not_count(): void
    {
        $this->apply('2026-12-02', '2026-12-07')->assertCreated(); // Wed, Thu, (Fri, Sat), Sun, Mon
        $this->apply('2026-12-08', '2026-12-08')->assertStatus(422);
    }

    public function test_a_request_across_two_months_counts_in_each_month(): void
    {
        $this->apply('2026-12-29', '2027-01-03')->assertCreated(); // 29-31 December and 3 January
        $this->apply('2026-12-01', '2026-12-02')->assertStatus(422);
        $this->apply('2026-12-01', '2026-12-01')->assertCreated();
        $this->apply('2027-01-04', '2027-01-06')->assertCreated();
        $this->apply('2027-01-10', '2027-01-10')->assertStatus(422);
    }

    public function test_rejected_and_cancelled_days_are_given_back(): void
    {
        $this->apply('2026-12-06', '2026-12-09')->assertCreated();
        $this->apply('2026-12-10', '2026-12-10')->assertStatus(422);

        $leave = LeaveRequest::firstOrFail();
        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/leave/requests/{$leave->id}")->assertOk();
        $this->apply('2026-12-06', '2026-12-09')->assertCreated();

        $leave = LeaveRequest::latest('id')->firstOrFail();
        $this->actingAs($this->hr, 'sanctum')
            ->postJson("/api/v1/leave/requests/{$leave->id}/reject", ['reason' => 'Needed on site'])->assertOk();
        $this->apply('2026-12-10', '2026-12-10')->assertCreated();
    }

    public function test_editing_a_request_respects_the_limit(): void
    {
        $this->apply('2026-12-06', '2026-12-07')->assertCreated();
        $leave = LeaveRequest::firstOrFail();

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/leave/requests/{$leave->id}", ['end_date' => '2026-12-10'])->assertStatus(422);
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/leave/requests/{$leave->id}", ['end_date' => '2026-12-09'])->assertOk();
    }

    public function test_old_requests_use_up_the_month(): void
    {
        $this->legacyRequest('2026-12-01', 'completed');
        $this->legacyRequest('2026-12-02', 'pending_manager');
        $this->legacyRequest('2026-12-03', 'rejected');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/leave/monthly-usage?leave_type_id={$this->wfh->id}&year=2026&month=12")->assertOk()
            ->assertJson(['used_days' => 2, 'limit_days' => 4, 'remaining_days' => 2]);

        $this->apply('2026-12-06', '2026-12-08')->assertStatus(422);
        $this->apply('2026-12-06', '2026-12-07')->assertCreated();
    }

    public function test_usage_of_another_employee_is_private(): void
    {
        $other = Employee::factory()->create();

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/leave/monthly-usage?leave_type_id={$this->wfh->id}&employee_id={$other->id}")
            ->assertForbidden();
    }

    public function test_a_zero_yearly_allocation_does_not_block_it(): void
    {
        LeaveAllocation::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->wfh->id, 'year' => 2026,
            'allocated_days' => 0, 'remaining_days' => 0, 'used_days' => 0, 'pending_days' => 0,
        ]);

        $this->apply('2026-12-01', '2026-12-01')->assertCreated();
    }

    public function test_half_days_are_refused(): void
    {
        $this->apply('2026-12-01', '2026-12-01', ['is_half_day' => true, 'half_day_period' => 'morning'])->assertStatus(422);
    }

    public function test_types_without_a_monthly_limit_are_unaffected(): void
    {
        $sick = LeaveType::factory()->create(['code' => 'SL', 'name' => 'Sick Leave']);

        $this->apply('2026-12-06', '2026-12-17', ['leave_type_id' => $sick->id])->assertCreated();
    }

    public function test_the_old_request_type_is_closed(): void
    {
        $type = RequestType::firstOrCreate(
            ['code' => 'HR_WFH'],
            ['name' => 'Work From Home Request', 'category' => 'hr', 'sla_days' => 2]
        );

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/requests', [
            'request_type_id' => $type->id, 'details' => 'Work from home please',
        ])->assertStatus(422)->assertJsonPath('message', 'Work From Home is now requested from the Leave page.');
    }

    public function test_the_lookup_merges_leave_and_old_requests_without_weekends(): void
    {
        $this->legacyRequest('2026-12-01', 'completed');
        $this->apply('2026-12-03', '2026-12-06')->assertCreated();

        $days = app(WorkFromHomeLookup::class)->byEmployeeDate([$this->employee->id], '2026-12-01', '2026-12-31');
        $id = $this->employee->id;

        $this->assertSame(["{$id}|2026-12-01", "{$id}|2026-12-03", "{$id}|2026-12-06"], array_keys($days));
        $this->assertSame('completed', $days["{$id}|2026-12-01"]['status']);
        $this->assertSame('pending_manager', $days["{$id}|2026-12-03"]['status']);

        LeaveRequest::query()->update(['status' => 'manager_approved']);
        $days = app(WorkFromHomeLookup::class)->byEmployeeDate([$id], '2026-12-01', '2026-12-31');
        $this->assertStringContainsString('(Awaiting HR approval)', app(WorkFromHomeLookup::class)->describe($days["{$id}|2026-12-03"]));

        LeaveRequest::query()->update(['status' => 'approved']);
        $days = app(WorkFromHomeLookup::class)->byEmployeeDate([$id], '2026-12-01', '2026-12-31');
        $this->assertSame('completed', $days["{$id}|2026-12-03"]['status']);
    }

    public function test_someone_working_from_home_is_not_counted_as_on_leave(): void
    {
        $this->travelTo('2026-12-01 10:00:00');
        LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->wfh->id, 'start_date' => '2026-12-01',
            'end_date' => '2026-12-01', 'total_days' => 1, 'status' => 'approved', 'reason' => 'Working from home',
        ]);

        $this->actingAs($this->hr, 'sanctum')->getJson('/api/v1/leave/stats')->assertOk()
            ->assertJsonPath('on_leave_today', 0)->assertJsonPath('approved_count', 1);

        $payroll = new \ReflectionMethod(\App\Services\PayrollService::class, 'getApprovedLeaveDays');
        $this->assertSame(0, $payroll->invoke(app(\App\Services\PayrollService::class), $this->employee->id, '2026-12-01', '2026-12-31'));
    }
}
