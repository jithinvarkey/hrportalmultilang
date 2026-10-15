<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\EmployeeController;
use App\Http\Controllers\API\PublicOnboardingController;
use App\Mail\AnnualLeaveContractExpiryMail;
use App\Mail\ContractRenewalReminderMail;
use App\Mail\EmployeeDocumentUploadedMail;
use App\Mail\InterviewInviteMail;
use App\Mail\MissedCheckoutMail;
use App\Mail\NewHireItNotificationMail;
use App\Mail\OnboardingTasksAssignedMail;
use App\Mail\RecruitmentCreatedNotificationMail;
use App\Mail\RecruitmentOfferMail;
use App\Models\AttendanceLog;
use App\Models\Contract;
use App\Models\ContractRenewalRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\OnboardingTask;
use App\Models\User;
use App\Services\AnnualLeaveContractExpiryNotificationService;
use App\Services\ContractRenewalNotificationService;
use App\Services\MissedCheckoutNotificationService;
use App\Services\NewHireNotificationService;
use App\Services\OnboardingTaskNotificationService;
use App\Services\RecruitmentDocumentService;
use App\Services\RecruitmentService;
use App\Support\Locale\RecipientLocale;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Notifications with several employee recipients go out as one message per communication language, so each
 * recipient reads the email in employees.preferred_communication_language. Uses a minimal in-memory schema
 * (the full migrations do not run on SQLite).
 *
 * People: emp.en (employee, English), mgr.ar (their manager, Arabic), hr.en / hr.ar (HR managers in the HR
 * department), it.ar (IT department manager, Arabic).
 */
class PerLanguageNotificationMailTest extends TestCase
{
    private Employee $employee;
    private Employee $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['activitylog.enabled' => false, 'mail.test_email' => 'qa@test.local']);
        RecipientLocale::flush();
        Mail::fake();
        $this->buildSchema();
        $this->seedPeople();
    }

    protected function tearDown(): void
    {
        RecipientLocale::flush();
        parent::tearDown();
    }

    public function test_contract_renewal_sends_one_message_per_language(): void
    {
        $renewal = new ContractRenewalRequest(['reference' => 'CRR-1']);
        $renewal->setRelation('employee', $this->employee);
        $renewal->setRelation('contract', null);

        app(ContractRenewalNotificationService::class)->notifyManagerAndHr($renewal);

        Mail::assertSent(ContractRenewalReminderMail::class, 2);
        $this->assertMessage(ContractRenewalReminderMail::class, 'ar', ['mgr.ar@test.local'], ['hr.ar@test.local']);
        $this->assertMessage(ContractRenewalReminderMail::class, 'en', ['hr.en@test.local'], []);
    }

    public function test_document_uploaded_notice_sends_one_message_per_language(): void
    {
        foreach ([EmployeeController::class, PublicOnboardingController::class] as $controller) {
            Mail::fake();
            $this->callPrivate(app($controller), 'notifyHrDocumentUploaded', $this->employee, new EmployeeDocument(['title' => 'Passport']));

            Mail::assertSent(EmployeeDocumentUploadedMail::class, 2);
            $this->assertMessage(EmployeeDocumentUploadedMail::class, 'en', ['hr.en@test.local'], []);
            $this->assertMessage(EmployeeDocumentUploadedMail::class, 'ar', ['hr.ar@test.local'], []);
        }
    }

    public function test_onboarding_tasks_send_one_message_per_language(): void
    {
        $this->employee->setRelation('department', null)->setRelation('designation', null);
        $tasks = collect([new OnboardingTask(['category' => 'hr_documents', 'title' => 'Collect documents'])]);

        $results = app(OnboardingTaskNotificationService::class)->notifyResponsibleDepartments($this->employee, $tasks);

        $this->assertTrue($results['HR']['sent']);
        Mail::assertSent(OnboardingTasksAssignedMail::class, 2);
        $this->assertMessage(OnboardingTasksAssignedMail::class, 'en', ['hr.en@test.local'], []);
        $this->assertMessage(OnboardingTasksAssignedMail::class, 'ar', ['hr.ar@test.local'], []);
    }

    public function test_new_hire_it_notification_uses_it_manager_language(): void
    {
        $this->employee->setRelation('department', null)->setRelation('designation', null);

        $result = app(NewHireNotificationService::class)->notifyItManagers($this->employee);

        $this->assertTrue($result['sent']);
        Mail::assertSent(NewHireItNotificationMail::class, 1);
        $this->assertMessage(NewHireItNotificationMail::class, 'ar', ['it.ar@test.local'], []);
    }

    public function test_recruitment_created_notification_sends_one_message_per_language(): void
    {
        $job = new JobPosting(['title' => 'Underwriter']);
        $job->setRelation('department', null)->setRelation('designation', null);

        app(RecruitmentService::class)->notifyHrManagersOfNewJob($job);

        Mail::assertSent(RecruitmentCreatedNotificationMail::class, 2);
        $this->assertMessage(RecruitmentCreatedNotificationMail::class, 'en', ['hr.en@test.local'], []);
        $this->assertMessage(RecruitmentCreatedNotificationMail::class, 'ar', ['hr.ar@test.local'], []);
    }

    public function test_interview_invite_sends_applicant_english_and_interviewers_their_language(): void
    {
        $application = new JobApplication(['applicant_name' => 'Sam Applicant', 'applicant_email' => 'applicant@example.com']);
        $application->setRelation('jobPosting', null);
        $interview = new Interview(['scheduled_at' => now()]);
        $interview->setRelation('application', $application);
        $interviewers = Employee::whereIn('email', ['hr.ar@test.local', 'hr.en@test.local'])->get();

        app(RecruitmentService::class)->sendInterviewInvite($interview, $interviewers);

        Mail::assertSent(InterviewInviteMail::class, 3);
        $this->assertMessage(InterviewInviteMail::class, 'en', ['applicant@example.com'], ['qa@test.local']);
        $this->assertMessage(InterviewInviteMail::class, 'ar', ['hr.ar@test.local'], []);
        $this->assertMessage(InterviewInviteMail::class, 'en', ['hr.en@test.local'], []);
    }

    public function test_offer_sends_applicant_english_and_hr_managers_their_language(): void
    {
        $documents = $this->createMock(RecruitmentDocumentService::class);
        $documents->method('generateOfferPdf')->willReturn(['path' => 'offers/offer.pdf', 'name' => 'offer.pdf']);
        $application = new JobApplication(['applicant_name' => 'Sam Applicant', 'applicant_email' => 'applicant@example.com']);
        $application->setRelation('jobPosting', null);

        $result = (new RecruitmentService($documents))->generateOfferLetter($application, ['basic_salary' => 1000]);

        $this->assertEqualsCanonicalizing(['hr.en@test.local', 'hr.ar@test.local', 'qa@test.local'], $result['cc']);
        Mail::assertSent(RecruitmentOfferMail::class, 3);
        $this->assertMessage(RecruitmentOfferMail::class, 'en', ['applicant@example.com'], ['qa@test.local']);
        $this->assertMessage(RecruitmentOfferMail::class, 'en', ['hr.en@test.local'], []);
        $this->assertMessage(RecruitmentOfferMail::class, 'ar', ['hr.ar@test.local'], []);
    }

    public function test_annual_leave_contract_expiry_sends_one_message_per_language(): void
    {
        $contract = new Contract(['end_date' => now()->addDays(90)->toDateString()]);
        $contract->setRelation('employee', $this->employee);

        $this->callPrivate(app(AnnualLeaveContractExpiryNotificationService::class), 'notify', $contract, 12.0, 5.0);

        Mail::assertSent(AnnualLeaveContractExpiryMail::class, 2);
        $this->assertMessage(AnnualLeaveContractExpiryMail::class, 'en', ['emp.en@test.local'], ['hr.en@test.local']);
        $this->assertMessage(AnnualLeaveContractExpiryMail::class, 'ar', ['mgr.ar@test.local'], ['hr.ar@test.local']);
    }

    public function test_missed_checkout_sends_one_message_per_language(): void
    {
        $log = new AttendanceLog(['date' => now()->toDateString(), 'check_in' => '08:05:00']);
        $log->setRelation('employee', $this->employee);

        $this->callPrivate(app(MissedCheckoutNotificationService::class), 'notify', $log);

        Mail::assertSent(MissedCheckoutMail::class, 2);
        $this->assertMessage(MissedCheckoutMail::class, 'en', ['emp.en@test.local'], ['hr.en@test.local']);
        $this->assertMessage(MissedCheckoutMail::class, 'ar', ['hr.ar@test.local'], []);
    }

    /** Exactly one sent message of the class has this locale and exactly these To and CC addresses. */
    private function assertMessage(string $class, string $locale, array $to, array $cc): void
    {
        $matches = Mail::sent($class, fn (Mailable $mail) => $mail->locale === $locale
            && array_column($mail->to, 'address') === $to
            && array_column($mail->cc, 'address') === $cc);

        $this->assertCount(1, $matches, sprintf('Expected one %s in "%s" to [%s] cc [%s].', class_basename($class), $locale, implode(', ', $to), implode(', ', $cc)));
    }

    private function callPrivate(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, ...$args);
    }

    private function buildSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('arabic_name')->nullable();
            $table->string('email');
            $table->string('status')->default('active');
            $table->string('preferred_communication_language')->default('en');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedPeople(): void
    {
        $hrRole = DB::table('roles')->insertGetId(['name' => 'hr_manager', 'guard_name' => 'web']);
        $hrDepartment = DB::table('departments')->insertGetId(['name' => 'Human Resources', 'code' => 'HR']);
        $itDepartment = DB::table('departments')->insertGetId(['name' => 'IT', 'code' => 'IT']);

        foreach (['hr.en' => 'en', 'hr.ar' => 'ar'] as $name => $language) {
            $email = "{$name}@test.local";
            $userId = DB::table('users')->insertGetId(['name' => $name, 'email' => $email]);
            DB::table('model_has_roles')->insert(['role_id' => $hrRole, 'model_type' => (new User())->getMorphClass(), 'model_id' => $userId]);
            DB::table('employees')->insert([
                'user_id' => $userId, 'department_id' => $hrDepartment, 'first_name' => $name, 'last_name' => 'HR',
                'email' => $email, 'preferred_communication_language' => $language,
            ]);
        }

        $itManager = DB::table('employees')->insertGetId([
            'department_id' => $itDepartment, 'first_name' => 'It', 'last_name' => 'Manager',
            'email' => 'it.ar@test.local', 'preferred_communication_language' => 'ar',
        ]);
        DB::table('departments')->where('id', $itDepartment)->update(['manager_id' => $itManager]);

        $managerId = DB::table('employees')->insertGetId([
            'first_name' => 'Line', 'last_name' => 'Manager', 'email' => 'mgr.ar@test.local', 'preferred_communication_language' => 'ar',
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'manager_id' => $managerId, 'first_name' => 'Emp', 'last_name' => 'English', 'email' => 'emp.en@test.local',
        ]);

        $this->manager = Employee::findOrFail($managerId);
        $this->employee = Employee::findOrFail($employeeId);
        $this->employee->setRelation('manager', $this->manager);
    }
}
