<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ApplicantRejectedMail;
use App\Mail\LoanInstallmentSkippedMail;
use App\Mail\RecruitmentCreatedNotificationMail;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanType;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Feature tests for Performance, Recruitment, and Loan APIs.
 *
 * @group performance
 * @group recruitment
 * @group loans
 */
class PerformanceRecruitmentLoanApiTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User     $hrManager;
    private User     $deptManager;
    private User     $financeManager;
    private User     $employeeUser;
    private Employee $empRecord;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['loans.approval_levels' => 3]);

        // FIX: correct seeder class name
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->hrManager = User::factory()->create();
        $this->hrManager->assignRole('hr_manager');

        $this->deptManager = User::factory()->create();
        $this->deptManager->assignRole('department_manager');

        $this->financeManager = User::factory()->create();
        $this->financeManager->assignRole('finance_manager');

        $this->employeeUser = User::factory()->create();
        $this->employeeUser->assignRole('employee');

        $this->empRecord = Employee::factory()->create(['user_id' => $this->employeeUser->id]);
    }

    // ── Performance ───────────────────────────────────────────────────────

    /** @test */
    public function performance_stats_returns_correct_structure(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->getJson('/api/v1/performance/stats')
            ->assertOk()
            ->assertJsonStructure([
                'total_cycles', 'active_cycles', 'total_reviews',
                'pending_self', 'pending_manager', 'finalized', 'avg_final_rating',
            ]);
    }

    /** @test */
    public function hr_can_create_performance_cycle(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/performance', [
                'name'       => 'Q1 2026 Review',
                'type'       => 'quarterly',
                'start_date' => now()->startOfQuarter()->toDateString(),
                'end_date'   => now()->endOfQuarter()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.name', 'Q1 2026 Review');

        $this->assertDatabaseHas('performance_cycles', ['name' => 'Q1 2026 Review']);
    }

    /** @test */
    public function hr_can_initiate_cycle_creating_reviews(): void
    {
        Employee::factory()->count(3)->create(['status' => 'active']);
        $cycle = PerformanceCycle::factory()->create(['status' => 'draft']);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/performance/{$cycle->id}/initiate")
            ->assertOk()
            ->assertJsonPath('cycle.status', 'active');

        $this->assertDatabaseHas('performance_reviews', ['cycle_id' => $cycle->id]);
    }

    /** @test */
    public function non_hr_cannot_initiate_cycle(): void
    {
        $cycle = PerformanceCycle::factory()->create(['status' => 'draft']);

        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson("/api/v1/performance/{$cycle->id}/initiate")
            ->assertForbidden();
    }

    /** @test */
    public function employee_can_submit_self_assessment(): void
    {
        $cycle  = PerformanceCycle::factory()->create(['status' => 'active']);
        $review = PerformanceReview::factory()->create([
            'cycle_id'    => $cycle->id,
            'employee_id' => $this->empRecord->id,
            'status'      => 'pending',
        ]);

        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson("/api/v1/performance/review/{$review->id}/self", [
                'rating'   => 4,
                'comments' => 'Achieved all quarterly targets on schedule.',
            ])
            ->assertOk()
            ->assertJsonPath('review.status', 'self_submitted');
    }

    /** @test */
    public function manager_can_submit_manager_review(): void
    {
        $cycle  = PerformanceCycle::factory()->create(['status' => 'active']);
        $review = PerformanceReview::factory()->create([
            'cycle_id'    => $cycle->id,
            'employee_id' => $this->empRecord->id,
            'status'      => 'self_submitted',
        ]);

        $this->actingAs($this->deptManager, 'sanctum')
            ->postJson("/api/v1/performance/review/{$review->id}/manager", [
                'rating'   => 3.5,
                'comments' => 'Good performance overall with some areas for improvement.',
            ])
            ->assertOk()
            ->assertJsonPath('review.status', 'manager_reviewed');
    }

    /** @test */
    public function hr_can_finalize_review(): void
    {
        $cycle  = PerformanceCycle::factory()->create(['status' => 'active']);
        $review = PerformanceReview::factory()->create([
            'cycle_id'    => $cycle->id,
            'employee_id' => $this->empRecord->id,
            'status'      => 'manager_reviewed',
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/performance/review/{$review->id}/finalize", [
                'final_rating'     => 4.0,
                'performance_band' => 'good',
                'development_plan' => 'Enroll in advanced project management training.',
            ])
            ->assertOk()
            ->assertJsonPath('review.status', 'finalized');
    }

    // ── Recruitment ───────────────────────────────────────────────────────

    /** @test */
    public function hr_can_create_job_posting(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/recruitment/jobs', [
                'title'           => 'Senior Laravel Developer',
                'employment_type' => 'full_time',
                'description'     => 'We are looking for an experienced Laravel developer.',
                'vacancies'       => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('job.title', 'Senior Laravel Developer');
    }

    /** @test */
    public function job_created_by_non_hr_manager_notifies_hr_manager(): void
    {
        Mail::fake();
        $hrStaff = User::factory()->create();
        $hrStaff->assignRole('hr_staff');
        $superAdminHrManager = User::factory()->create();
        $superAdminHrManager->assignRole(['super_admin', 'hr_manager']);

        $this->actingAs($hrStaff, 'sanctum')
            ->postJson('/api/v1/recruitment/jobs', [
                'title' => 'Payroll Specialist',
                'employment_type' => 'full_time',
            ])
            ->assertCreated();

        Mail::assertSent(RecruitmentCreatedNotificationMail::class, fn ($mail) =>
            $mail->type === 'job'
            && $mail->hasTo($this->hrManager->email)
            && !$mail->hasTo($superAdminHrManager->email)
            && $mail->record->title === 'Payroll Specialist'
        );
    }

    /** @test */
    public function job_created_by_hr_manager_does_not_send_notification(): void
    {
        Mail::fake();

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson('/api/v1/recruitment/jobs', [
                'title' => 'HR Coordinator',
                'employment_type' => 'full_time',
            ])
            ->assertCreated();

        Mail::assertNotSent(RecruitmentCreatedNotificationMail::class);
    }

    /** @test */
    public function public_can_view_open_jobs(): void
    {
        JobPosting::factory()->create(['status' => 'open']);
        JobPosting::factory()->create(['status' => 'closed']);

        $response = $this->getJson('/api/v1/jobs')->assertOk();

        $this->assertTrue(
            collect($response->json('jobs'))->every(fn ($j) => $j['status'] === 'open')
        );
    }

    /** @test */
    public function applicant_can_apply_for_job(): void
    {
        $job = JobPosting::factory()->create(['status' => 'open']);

        $this->postJson("/api/v1/jobs/{$job->id}/apply", [
            'applicant_name'  => 'John Smith',
            'applicant_email' => 'john@example.com',
        ])
        ->assertCreated()
        ->assertJson(['application' => ['job_posting_id' => $job->id]]);
    }

    /** @test */
    public function public_application_notifies_hr_manager(): void
    {
        Mail::fake();
        $job = JobPosting::factory()->create(['status' => 'open', 'title' => 'Accountant']);

        $this->postJson("/api/v1/jobs/{$job->id}/apply", [
            'applicant_name' => 'Sara Ahmed',
            'applicant_email' => 'sara@example.com',
            'applicant_phone' => '+966500000001',
        ])->assertCreated();

        Mail::assertSent(RecruitmentCreatedNotificationMail::class, fn ($mail) =>
            $mail->type === 'application'
            && $mail->hasTo($this->hrManager->email)
            && $mail->record->applicant_email === 'sara@example.com'
        );
    }

    /** @test */
    public function application_added_by_hr_manager_does_not_send_notification(): void
    {
        Mail::fake();
        $job = JobPosting::factory()->create(['status' => 'open']);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/recruitment/apply/{$job->id}", [
                'applicant_name' => 'Internal Candidate',
                'applicant_email' => 'internal@example.com',
                'applicant_phone' => '+966500000002',
            ])
            ->assertCreated();

        Mail::assertNotSent(RecruitmentCreatedNotificationMail::class);
    }

    /** @test */
    public function hr_can_edit_applicant_details_without_changing_stage(): void
    {
        Mail::fake();
        $newJob = JobPosting::factory()->create(['title' => 'Accountant']);
        $app = JobApplication::factory()->create([
            'job_posting_id' => JobPosting::factory()->create()->id,
            'stage' => 'offer', 'expected_salary' => 8000, 'hr_notes' => 'Keep notes',
        ]);
        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/v1/recruitment/applications/{$app->id}", [
                'applicant_name' => 'Updated Applicant', 'applicant_email' => 'updated@example.com',
                'applicant_phone' => '0501234567', 'expected_salary' => null,
                'available_from' => '2026-10-01', 'stage' => 'hired',
                'job_posting_id' => $newJob->id,
            ])->assertOk()->assertJsonPath('application.stage', 'offer')
                ->assertJsonPath('application.job_posting.title', 'Accountant');
        $this->assertDatabaseHas('job_applications', [
            'id' => $app->id, 'applicant_name' => 'Updated Applicant',
            'applicant_email' => 'updated@example.com', 'applicant_phone' => '0501234567',
            'expected_salary' => null, 'hr_notes' => 'Keep notes', 'stage' => 'offer',
            'job_posting_id' => $newJob->id, 'department_id' => $newJob->department_id,
            'position_applied' => 'Accountant',
        ]);
        $this->putJson("/api/v1/recruitment/applications/{$app->id}", ['hr_notes' => 'Updated notes'])
            ->assertOk()->assertJsonPath('application.hr_notes', 'Updated notes');
        Mail::assertNothingSent();
    }

    /** @test */
    public function applicant_details_require_hr_and_valid_data(): void
    {
        $app = JobApplication::factory()->create(['job_posting_id' => JobPosting::factory()->create()->id]);
        $url = "/api/v1/recruitment/applications/{$app->id}";
        foreach (['hr_staff', 'ceo', 'finance_manager'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user, 'sanctum')->putJson($url, ['job_posting_id' => $app->job_posting_id])->assertForbidden();
            $this->putJson($url, ['hr_notes' => 'Changed'])->assertForbidden();
        }
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'sanctum')->putJson($url, ['applicant_name' => 'Admin updated'])
            ->assertOk()->assertJsonPath('application.applicant_name', 'Admin updated');
        $this->actingAs($this->employeeUser, 'sanctum')->putJson($url, ['applicant_name' => 'Changed'])->assertForbidden();
        $this->actingAs($this->deptManager, 'sanctum')->putJson($url, ['expected_salary' => 100])->assertForbidden();
        $this->actingAs($this->hrManager, 'sanctum')->putJson($url, [
            'applicant_name' => '', 'applicant_email' => 'invalid', 'applicant_phone' => '',
            'expected_salary' => -1, 'available_from' => 'invalid',
            'job_posting_id' => 999999999,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'applicant_name', 'applicant_email', 'applicant_phone', 'expected_salary', 'available_from',
            'job_posting_id',
        ]);
    }

    /** @test */
    public function hr_can_update_application_stage(): void
    {
        $job = JobPosting::factory()->create(['status' => 'open']);
        $app = JobApplication::factory()->create([
            'job_posting_id' => $job->id,
            'stage'          => 'applied',
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/v1/recruitment/applications/{$app->id}/stage", ['stage' => 'interview'])
            ->assertOk()
            ->assertJsonPath('application.stage', 'interview');
    }

    /** @test */
    public function rejecting_an_applicant_sends_a_rejection_email(): void
    {
        Mail::fake();

        $job = JobPosting::factory()->create([
            'status' => 'open',
            'title' => 'Operations Officer',
        ]);
        $app = JobApplication::factory()->create([
            'job_posting_id' => $job->id,
            'applicant_name' => 'Amjad Almutairi',
            'applicant_email' => 'amjad@example.com',
            'stage' => 'applied',
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson("/api/v1/recruitment/applications/{$app->id}/stage", ['stage' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('application.stage', 'rejected')
            ->assertJsonPath('email_sent', true);

        Mail::assertSent(
            ApplicantRejectedMail::class,
            fn (ApplicantRejectedMail $mail) => $mail->hasTo('amjad@example.com')
                && $mail->application->is($app)
        );
    }

    /** @test */
    public function recruitment_stats_return_correct_structure(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->getJson('/api/v1/recruitment/stats')
            ->assertOk()
            ->assertJsonStructure([
                'open_jobs', 'total_jobs', 'total_applicants',
                'new_this_week', 'in_interview', 'offers_sent', 'hired', 'rejected',
            ]);
    }

    // ── Loans ─────────────────────────────────────────────────────────────

    /** @test */
    public function only_finance_or_hr_manager_can_mark_an_installment_as_paid(): void
    {
        $loan = Loan::factory()->create([
            'employee_id' => $this->empRecord->id,
            'status' => 'disbursed',
        ]);
        $installment = LoanInstallment::factory()->create([
            'loan_id' => $loan->id,
            'status' => 'pending',
            'payslip_id' => null,
        ]);

        $this->actingAs($this->deptManager, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->id}/installments/{$installment->id}/pay")
            ->assertForbidden();

        $this->actingAs($this->financeManager, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->id}/installments/{$installment->id}/pay")
            ->assertOk();
    }

    /** @test */
    public function skipping_an_installment_notifies_hr_and_finance_managers(): void
    {
        Mail::fake();
        $loan = Loan::factory()->create([
            'employee_id' => $this->empRecord->id,
            'status' => 'disbursed',
        ]);
        $installment = LoanInstallment::factory()->create([
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => '2026-09-01',
            'status' => 'pending',
            'payslip_id' => null,
        ]);

        $this->actingAs($this->deptManager, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->id}/installments/{$installment->id}/skip", [
                'notes' => 'Employee requested a one-month deferment.',
            ])
            ->assertOk();

        Mail::assertQueued(LoanInstallmentSkippedMail::class, 2);
        Mail::assertQueued(LoanInstallmentSkippedMail::class, fn ($mail) =>
            $mail->hasTo($this->hrManager->email)
            && $mail->skippedInstallment->is($installment)
        );
        Mail::assertQueued(LoanInstallmentSkippedMail::class, fn ($mail) =>
            $mail->hasTo($this->financeManager->email)
            && $mail->replacementInstallment->due_date->toDateString() === '2026-10-01'
        );
    }

    /** @test */
    public function employee_can_submit_loan_request(): void
    {
        $loanType = LoanType::factory()->create([
            'max_amount' => 50000, 'max_installments' => 24,
            'interest_rate' => 0, 'is_active' => true,
        ]);

        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/v1/loans', [
                'loan_type_id'     => $loanType->id,
                'requested_amount' => 10000,
                'installments'     => 12,
                'purpose'          => 'Home renovation project starting next month.',
            ])
            ->assertCreated()
            ->assertJsonPath('loan.status', 'pending_manager');
    }

    /** @test */
    public function two_level_workflow_starts_with_hr_and_skips_manager(): void
    {
        config(['loans.approval_levels' => 2]);
        $loanType = LoanType::factory()->create([
            'max_amount' => 50000, 'max_installments' => 24,
            'interest_rate' => 0, 'is_active' => true,
        ]);

        $loanId = $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/v1/loans', [
                'loan_type_id' => $loanType->id,
                'requested_amount' => 10000,
                'installments' => 12,
                'purpose' => 'Temporary two level approval workflow test.',
            ])
            ->assertCreated()
            ->assertJsonPath('loan.status', 'pending_hr')
            ->json('loan.id');

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/loans/{$loanId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('loans', [
            'id' => $loanId,
            'status' => 'pending_finance',
            'manager_approved_by' => null,
            'hr_approved_by' => $this->hrManager->id,
        ]);
    }

    /** @test */
    public function super_admin_can_change_loan_approval_levels_from_settings(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin, 'sanctum')
            ->putJson('/api/v1/admin/settings/loans', ['approval_levels' => 2])
            ->assertOk()
            ->assertJsonPath('settings.approval_levels', 2);

        $this->assertDatabaseHas('system_settings', [
            'key' => 'loan_approval_levels',
            'value' => '2',
        ]);

        $loanType = LoanType::factory()->create([
            'max_amount' => 50000, 'max_installments' => 24,
            'interest_rate' => 0, 'is_active' => true,
        ]);

        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/v1/loans', [
                'loan_type_id' => $loanType->id,
                'requested_amount' => 10000,
                'installments' => 12,
                'purpose' => 'Database setting should override configuration fallback.',
            ])
            ->assertCreated()
            ->assertJsonPath('loan.status', 'pending_hr');
    }

    /** @test */
    public function non_super_admin_cannot_change_loan_approval_levels(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->putJson('/api/v1/admin/settings/loans', ['approval_levels' => 2])
            ->assertForbidden();
    }

    /** @test */
    public function loan_exceeding_max_amount_is_rejected(): void
    {
        $loanType = LoanType::factory()->create([
            'max_amount' => 5000, 'is_active' => true, 'max_installments' => 12,
        ]);

        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/v1/loans', [
                'loan_type_id'     => $loanType->id,
                'requested_amount' => 10000,
                'installments'     => 6,
                'purpose'          => 'Purpose longer than ten characters here.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'maximum'));
    }

    /** @test */
    public function manager_can_approve_loan_at_stage_1(): void
    {
        $loan = Loan::factory()->create([
            'employee_id' => $this->empRecord->id,
            'status'      => 'pending_manager',
        ]);

        $this->actingAs($this->deptManager, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'pending_hr']);
    }

    /** @test */
    public function loan_index_uses_raw_db_role_check(): void
    {
        Loan::factory()->create(['employee_id' => $this->empRecord->id, 'status' => 'pending_manager']);

        $response = $this->actingAs($this->financeManager, 'sanctum')
            ->getJson('/api/v1/loans')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertGreaterThan(0, count($response->json('data')));
    }

    /** @test */
    public function employee_can_cancel_pending_loan(): void
    {
        $loan = Loan::factory()->create([
            'employee_id' => $this->empRecord->id,
            'status'      => 'pending_manager',
        ]);

        $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson("/api/v1/loans/{$loan->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'status' => 'cancelled']);
    }

    /** @test */
    public function loan_stats_return_correct_structure(): void
    {
        $this->actingAs($this->hrManager, 'sanctum')
            ->getJson('/api/v1/loans/stats')
            ->assertOk();
    }
}
