<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractRenewalRequest;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\RequestType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sensitive uploads live on the private "local" disk and are only reachable through
 * authorized API endpoints (finding H-6), plus related authorization fixes.
 */
class PrivateUploadsTest extends TestCase
{
    use RefreshDatabase;

    private User $hrManager;
    private User $employee;
    private Employee $empRecord;
    private User $otherEmployee;
    private LeaveType $sickLeave;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();

        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->hrManager = User::factory()->create();
        $this->hrManager->assignRole('hr_manager');

        $this->employee = User::factory()->create();
        $this->employee->assignRole('employee');
        $this->empRecord = Employee::factory()->create(['user_id' => $this->employee->id]);

        $this->otherEmployee = User::factory()->create();
        $this->otherEmployee->assignRole('employee');
        Employee::factory()->create(['user_id' => $this->otherEmployee->id]);

        $this->sickLeave = LeaveType::factory()->create([
            'name'                  => 'Sick Leave',
            'code'                  => 'SL',
            'days_allowed'          => 14,
            'is_paid'               => true,
            'skip_manager_approval' => true,
            'requires_document'     => false,
        ]);
    }

    private function submitSickLeaveWithDocument(): LeaveRequest
    {
        $response = $this->actingAs($this->employee, 'sanctum')
            ->post('/api/v1/leave/requests', [
                'leave_type_id' => $this->sickLeave->id,
                // Mon-Wed: working days whatever the weekend configuration.
                'start_date'    => now()->next(\Carbon\Carbon::MONDAY)->toDateString(),
                'end_date'      => now()->next(\Carbon\Carbon::MONDAY)->addDays(2)->toDateString(),
                'reason'        => 'Medical certificate attached for review',
                'document'      => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json']);
        $this->assertSame(201, $response->status(), $response->getContent());

        return LeaveRequest::latest('id')->firstOrFail();
    }

    // ── Leave documents ───────────────────────────────────────────────────

    /** @test */
    public function leave_document_is_stored_on_the_private_disk(): void
    {
        $leave = $this->submitSickLeaveWithDocument();

        $this->assertNotNull($leave->document_path);
        $this->assertStringStartsWith("leave-documents/{$this->empRecord->id}/", $leave->document_path);
        Storage::disk('local')->assertExists($leave->document_path);
        Storage::disk('public')->assertMissing($leave->document_path);
    }

    /** @test */
    public function owner_and_hr_can_download_the_leave_document(): void
    {
        $leave = $this->submitSickLeaveWithDocument();

        $this->actingAs($this->employee, 'sanctum')
            ->get("/api/v1/leave/requests/{$leave->id}/document")
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($this->hrManager, 'sanctum')
            ->get("/api/v1/leave/requests/{$leave->id}/document")
            ->assertOk();
    }

    /** @test */
    public function unrelated_employee_cannot_download_the_leave_document(): void
    {
        $leave = $this->submitSickLeaveWithDocument();

        $this->actingAs($this->otherEmployee, 'sanctum')
            ->getJson("/api/v1/leave/requests/{$leave->id}/document")
            ->assertForbidden();
    }

    /** @test */
    public function unauthenticated_user_cannot_download_the_leave_document(): void
    {
        $leave = $this->submitSickLeaveWithDocument();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/leave/requests/{$leave->id}/document")
            ->assertUnauthorized();
    }

    /** @test */
    public function legacy_leave_document_on_the_public_disk_is_still_served(): void
    {
        $path = "leave-documents/{$this->empRecord->id}/legacy.pdf";
        Storage::disk('public')->put($path, '%PDF-1.4 legacy');

        $leave = LeaveRequest::factory()->create([
            'employee_id'   => $this->empRecord->id,
            'leave_type_id' => $this->sickLeave->id,
            'document_path' => $path,
        ]);

        $this->actingAs($this->employee, 'sanctum')
            ->get("/api/v1/leave/requests/{$leave->id}/document")
            ->assertOk();

        $this->actingAs($this->otherEmployee, 'sanctum')
            ->getJson("/api/v1/leave/requests/{$leave->id}/document")
            ->assertForbidden();
    }

    // ── Recruitment ───────────────────────────────────────────────────────

    /** @test */
    public function user_without_recruitment_role_or_employee_record_sees_no_applications(): void
    {
        $app = JobApplication::factory()->create([
            'job_posting_id' => JobPosting::factory()->create()->id,
            'cv_path'        => 'recruitment/cvs/1/cv.pdf',
        ]);
        Storage::disk('local')->put('recruitment/cvs/1/cv.pdf', '%PDF-1.4 cv');

        $orphan = User::factory()->create();
        $orphan->assignRole('employee');

        $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/v1/recruitment/applications')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($orphan, 'sanctum')
            ->getJson("/api/v1/recruitment/applications/{$app->id}")
            ->assertNotFound();

        $this->actingAs($orphan, 'sanctum')
            ->getJson("/api/v1/recruitment/applications/{$app->id}/cv")
            ->assertNotFound();
    }

    /** @test */
    public function hr_can_download_an_applicant_cv(): void
    {
        $app = JobApplication::factory()->create([
            'job_posting_id' => JobPosting::factory()->create()->id,
            'cv_path'        => 'recruitment/cvs/1/cv.pdf',
        ]);
        Storage::disk('local')->put('recruitment/cvs/1/cv.pdf', '%PDF-1.4 cv');

        $this->actingAs($this->hrManager, 'sanctum')
            ->get("/api/v1/recruitment/applications/{$app->id}/cv")
            ->assertOk();
    }

    // ── Self-approval guards ──────────────────────────────────────────────

    /** @test */
    public function hr_cannot_complete_their_own_request(): void
    {
        $hrEmployee = Employee::factory()->create(['user_id' => $this->hrManager->id]);
        $type = RequestType::create([
            'name' => 'Salary certificate', 'code' => 'SAL_CERT', 'category' => 'hr',
            'sla_days' => 2, 'requires_attachment' => false, 'requires_manager_approval' => false,
        ]);
        $req = EmployeeRequest::create([
            'reference' => 'REQ-TEST-00001', 'employee_id' => $hrEmployee->id,
            'request_type_id' => $type->id, 'status' => 'pending', 'details' => 'Need a certificate',
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/requests/{$req->id}/complete")
            ->assertForbidden();

        $this->assertSame('pending', $req->fresh()->status);
    }

    /** @test */
    public function hr_cannot_approve_their_own_contract_renewal(): void
    {
        $hrEmployee = Employee::factory()->create(['user_id' => $this->hrManager->id]);
        $contract = Contract::create([
            'employee_id' => $hrEmployee->id, 'reference' => 'CTR-TEST-1', 'type' => 'fixed_term',
            'status' => 'active', 'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(), 'currency' => 'SAR',
        ]);
        $renewal = ContractRenewalRequest::create([
            'contract_id' => $contract->id, 'employee_id' => $hrEmployee->id,
            'reference' => 'REN-TEST-1', 'status' => 'pending',
            'proposed_start_date' => now()->addMonth()->toDateString(),
            'proposed_end_date' => now()->addYears(1)->addMonth()->toDateString(),
        ]);

        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/contracts/renewals/{$renewal->id}/approve")
            ->assertForbidden();

        $renewal->update(['status' => 'manager_approved']);
        $this->actingAs($this->hrManager, 'sanctum')
            ->postJson("/api/v1/contracts/renewals/{$renewal->id}/approve")
            ->assertForbidden();

        $this->assertSame('manager_approved', $renewal->fresh()->status);
    }
}
