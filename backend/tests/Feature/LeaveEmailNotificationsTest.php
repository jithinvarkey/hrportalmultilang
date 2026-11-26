<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LeaveStatusMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/** Who is emailed at each step of a leave / Work From Home request, and what the emails say. */
class LeaveEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $employeeUser;
    private User $managerUser;
    private User $hrManager;
    private User $hrStaff;
    private Employee $employee;
    private LeaveType $wfh;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $department = Department::factory()->create()->id;
        $this->managerUser = $this->person('manager', 'department_manager', $department);
        $this->employeeUser = $this->person('employee', 'employee', $department, $this->managerUser->employee->id);
        $this->hrManager = $this->person('hrmanager', 'hr_manager', $department);
        $this->hrStaff = $this->person('hrstaff', 'hr_staff', $department);
        $this->employee = $this->employeeUser->employee;
        $this->wfh = LeaveType::where('code', 'WFH')->firstOrFail();
    }

    private function person(string $name, string $role, int $department, ?int $managerId = null): User
    {
        $user = User::factory()->create(['name' => ucfirst($name), 'email' => "{$name}@test.local"]);
        $user->assignRole($role);
        Employee::factory()->create([
            'user_id' => $user->id, 'email' => "{$name}@test.local", 'department_id' => $department,
            'manager_id' => $managerId, 'first_name' => ucfirst($name), 'last_name' => 'Test', 'arabic_name' => null,
        ]);

        return $user->fresh('employee');
    }

    private function wfhRequest(string $status = 'pending', array $extra = []): LeaveRequest
    {
        return LeaveRequest::create($extra + [
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->wfh->id, 'start_date' => '2026-12-06',
            'end_date' => '2026-12-07', 'total_days' => 2, 'status' => $status, 'reason' => 'Working from home',
        ]);
    }

    /** Asserts exactly these people were emailed, in any order. */
    private function assertEmailed(array $names): void
    {
        $sent = Mail::queued(LeaveStatusMail::class)->map(fn ($mail) => str_replace('@test.local', '', $mail->to[0]['address']))->sort()->values()->all();
        sort($names);
        $this->assertSame($names, $sent);
    }

    private function mailTo(string $name): LeaveStatusMail
    {
        return Mail::queued(LeaveStatusMail::class)->first(fn ($mail) => $mail->hasTo("{$name}@test.local"));
    }

    public function test_submission_goes_to_the_employee_the_manager_and_the_hr_manager(): void
    {
        $this->actingAs($this->employeeUser, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $this->wfh->id, 'start_date' => '2026-12-06', 'end_date' => '2026-12-07', 'reason' => 'Working from home',
        ])->assertCreated();

        $this->assertEmailed(['employee', 'manager', 'hrmanager']);
        $this->assertStringContainsString('Your work from home request has been submitted and is awaiting approval.', $this->mailTo('employee')->render());
        $this->assertStringContainsString('requires your review', $this->mailTo('manager')->render());
    }

    public function test_the_approving_manager_is_not_emailed_about_their_own_approval(): void
    {
        $leave = $this->wfhRequest();
        $this->actingAs($this->managerUser, 'sanctum')->postJson("/api/v1/leave/requests/{$leave->id}/approve")->assertOk();

        $this->assertEmailed(['employee', 'hrmanager', 'hrstaff']);
        $this->assertStringContainsString('approved by the reporting manager', $this->mailTo('hrmanager')->render());
    }

    public function test_the_manager_hears_the_final_approval(): void
    {
        $leave = $this->wfhRequest('manager_approved');
        $this->actingAs($this->hrManager, 'sanctum')->postJson("/api/v1/leave/requests/{$leave->id}/approve")->assertOk();

        $this->assertEmailed(['employee', 'manager']);
        $this->assertStringContainsString('Your work from home request has been fully approved', $this->mailTo('employee')->render());
        $this->assertStringContainsString('The work from home request has been fully approved', $this->mailTo('manager')->render());
    }

    public function test_the_manager_hears_the_hr_rejection_with_its_reason_once(): void
    {
        $leave = $this->wfhRequest('manager_approved');
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/leave/requests/{$leave->id}/reject", ['reason' => 'Audit week'])->assertOk();

        $this->assertEmailed(['employee', 'manager']);
        $this->assertSame(1, substr_count($this->mailTo('manager')->render(), 'Audit week'));
    }

    public function test_a_rejecting_manager_emails_only_the_employee(): void
    {
        $leave = $this->wfhRequest();
        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson("/api/v1/leave/requests/{$leave->id}/reject", ['reason' => 'Needed on site'])->assertOk();

        $this->assertEmailed(['employee']);
        $this->assertStringContainsString('rejected by your reporting manager', $this->mailTo('employee')->render());
    }

    public function test_hr_deciding_the_first_stage_is_not_reported_as_the_manager(): void
    {
        $leave = $this->wfhRequest();
        $this->actingAs($this->hrManager, 'sanctum')->postJson("/api/v1/leave/requests/{$leave->id}/approve")->assertOk();

        $this->assertEmailed(['employee', 'manager', 'hrstaff']);
        $html = $this->mailTo('employee')->render();
        $this->assertStringContainsString('HR has given the first approval', $html);
        $this->assertStringNotContainsString('reporting manager', $html);
    }

    public function test_hr_rejecting_the_first_stage_is_not_reported_as_the_manager(): void
    {
        $leave = $this->wfhRequest();
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/leave/requests/{$leave->id}/reject", ['reason' => 'No'])->assertOk();

        $this->assertEmailed(['employee', 'manager']);
        $this->assertStringContainsString('rejected by HR', $this->mailTo('employee')->render());
    }

    public function test_a_cancellation_reason_reaches_the_approvers(): void
    {
        $leave = $this->wfhRequest();
        $this->actingAs($this->employeeUser, 'sanctum')
            ->deleteJson("/api/v1/leave/requests/{$leave->id}", ['reason' => 'Plans changed'])->assertOk();

        $this->assertEmailed(['employee', 'manager', 'hrmanager', 'hrstaff']);
        $this->assertStringContainsString('Plans changed', $this->mailTo('manager')->render());
    }

    public function test_the_hr_manager_is_not_asked_to_review_their_own_request(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')->postJson('/api/v1/leave/requests', [
            'leave_type_id' => $this->wfh->id, 'start_date' => '2026-12-06', 'end_date' => '2026-12-06', 'reason' => 'Working from home',
        ])->assertCreated();

        // Their own confirmation, and HR staff because nobody else is left to review it.
        $this->assertEmailed(['hrmanager', 'hrstaff']);
        $this->assertTrue($this->mailTo('hrmanager')->forEmployee);
    }

    public function test_one_bad_address_does_not_stop_the_other_recipients(): void
    {
        $tried = $this->failingMailServer(new TransportException('Expected response code "250" but got code "550"'));

        $service = app(LeaveService::class);
        $service->notifyManager($this->wfhRequest(), 'cancelled');

        $this->assertCount(3, $tried->all());
    }

    public function test_a_refusing_mail_server_is_tried_only_once(): void
    {
        $tried = $this->failingMailServer(new TransportException('Failed to authenticate on SMTP server with username "x"'));

        $leave = $this->wfhRequest();
        $service = app(LeaveService::class);
        $service->notifyManager($leave, 'cancelled');
        $service->notifyEmployee($leave, 'cancelled');

        $this->assertCount(1, $tried->all());
    }

    private function failingMailServer(TransportException $error): \Illuminate\Support\Collection
    {
        $tried = collect();
        $pending = \Mockery::mock();
        $pending->shouldReceive('queue')->andThrow($error);
        Mail::shouldReceive('to')->andReturnUsing(function ($email) use ($tried, $pending) {
            $tried->push($email);

            return $pending;
        });

        return $tried;
    }

    private function subject(LeaveStatusMail $mail, string $locale): string
    {
        app()->setLocale($locale);
        $mail->locale($locale)->build();

        return $mail->subject;
    }

    public function test_subjects_name_the_request_and_the_employee(): void
    {
        $leave = $this->wfhRequest();
        $ref = $leave->reference;

        $this->assertSame("New Work From Home Request - Employee Test - {$ref}", $this->subject(new LeaveStatusMail($leave, 'submitted', 'Manager'), 'en'));
        $this->assertSame("Work From Home Request Submitted - {$ref}", $this->subject(new LeaveStatusMail($leave, 'submitted', 'Employee', null, null, null, true), 'en'));
        $this->assertSame("طلب عمل عن بُعد جديد - Employee Test - {$ref}", $this->subject(new LeaveStatusMail($leave, 'submitted', 'Manager'), 'ar'));

        $leave->update(['leave_type_id' => LeaveType::factory()->create(['name' => 'Sick Leave', 'name_ar' => null, 'code' => 'SL'])->id]);
        $leave = $leave->fresh();
        $this->assertSame("New Leave Request - Sick Leave - Employee Test - {$ref}", $this->subject(new LeaveStatusMail($leave, 'submitted', 'Manager'), 'en'));
    }

    public function test_work_from_home_emails_show_the_months_usage_and_are_not_called_leave(): void
    {
        $this->wfhRequest('approved', ['start_date' => '2026-12-01', 'end_date' => '2026-12-01', 'total_days' => 1]);
        $leave = $this->wfhRequest();

        foreach (['submitted', 'manager_approved', 'manager_rejected', 'hr_approved', 'hr_rejected', 'cancelled'] as $action) {
            foreach ([true, false] as $forEmployee) {
                $html = (new LeaveStatusMail($leave, $action, 'Someone', null, null, null, $forEmployee))->render();
                $this->assertStringContainsString('Used in December 2026', $html);
                $this->assertStringContainsString('3 of 4 days', $html);
                $this->assertDoesNotMatchRegularExpression('/\bleave\b/i', preg_replace('/\s+/', ' ', strip_tags($html)), $action);

                app()->setLocale('ar');
                $arabic = (new LeaveStatusMail($leave, $action, 'Someone', null, null, null, $forEmployee))->render();
                $this->assertStringNotContainsString('mail_leave.', $arabic);
                $this->assertStringNotContainsString('إجازة', $arabic, $action);
                app()->setLocale('en');
            }
        }
    }

    public function test_other_leave_types_show_no_monthly_usage(): void
    {
        $leave = $this->wfhRequest('pending', ['leave_type_id' => LeaveType::factory()->create(['code' => 'SL', 'name' => 'Sick Leave'])->id]);

        $html = (new LeaveStatusMail($leave, 'submitted', 'Manager'))->render();
        $this->assertStringNotContainsString('Used in', $html);
        $this->assertStringContainsString('A new leave request has been submitted', $html);
    }
}
