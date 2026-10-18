<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\LoanApprovedMail;
use App\Mail\LoanRejectedMail;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\User;
use App\Services\LeaveService;
use App\Services\OtpService;
use App\Models\LoginOtp;
use App\Support\Locale\RecipientLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Every email is written in the RECIPIENT's communication language (employees.preferred_communication_language),
 * not the sender's. Mail goes through the real send path into the in-memory "array" mailer (no Mail::fake), so
 * the language is resolved exactly as in production.
 *
 * Scenario reported by a user: an employee whose communication language is Arabic applies for leave; the
 * employee's copy must be Arabic while the English-speaking manager and HR manager get English copies.
 *
 * @group mail
 */
class EmployeeEmailLanguageTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;   // Arabic
    private Employee $manager;    // English, direct manager
    private User $hrManager;      // English

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        config(['mail.default' => 'array', 'mail.force_test_email' => false, 'mail.test_email' => null]);
        app('mail.manager')->forgetMailers();
        RecipientLocale::flush();

        $managerUser = User::factory()->create(['email' => 'manager@test.local']);
        $managerUser->assignRole('department_manager');
        $this->manager = Employee::factory()->create([
            'user_id' => $managerUser->id, 'email' => 'manager@test.local',
            'preferred_communication_language' => 'en',
        ]);

        $employeeUser = User::factory()->create(['email' => 'employee@test.local']);
        $employeeUser->assignRole('employee');
        $this->employee = Employee::factory()->create([
            'user_id' => $employeeUser->id, 'email' => 'employee@test.local',
            'manager_id' => $this->manager->id, 'arabic_name' => 'جينيش ماني',
            'phone' => null, 'work_phone' => null,
            'preferred_communication_language' => 'ar',
        ]);

        $this->hrManager = User::factory()->create(['email' => 'hr@test.local']);
        $this->hrManager->assignRole('hr_manager');
        Employee::factory()->create([
            'user_id' => $this->hrManager->id, 'email' => 'hr@test.local',
            'preferred_communication_language' => 'en',
        ]);
    }

    /** @return array<string, Email> sent messages keyed by recipient address */
    private function sentByRecipient(): array
    {
        $byRecipient = [];
        foreach (app('mail.manager')->mailer('array')->getSymfonyTransport()->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            // When the local test-inbox redirect is on (.env MAIL_FORCE_TEST_EMAIL), the real recipient is in X-Original-To.
            $original = $message->getHeaders()->getHeaderBody('X-Original-To');
            $recipients = $original
                ? array_map('trim', explode(',', (string) $original))
                : array_map(fn ($a) => $a->getAddress(), $message->getTo());
            foreach ($recipients as $address) {
                $byRecipient[$address] = $message;
            }
        }

        return $byRecipient;
    }

    private function assertArabic(Email $message, string $who): void
    {
        $html = (string) $message->getHtmlBody();
        $this->assertStringContainsString('lang="ar"', $html, "$who: expected an Arabic email");
        $this->assertStringContainsString('dir="rtl"', $html, "$who: expected right-to-left layout");
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $message->getSubject(), "$who: expected an Arabic subject");
        $this->assertDoesNotMatchRegularExpression('/\bmail_[a-z]+\./', $html, "$who: untranslated key in email");
    }

    private function assertEnglish(Email $message, string $who): void
    {
        $html = (string) $message->getHtmlBody();
        $this->assertStringContainsString('lang="en"', $html, "$who: expected an English email");
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $message->getSubject(), "$who: expected an English subject");
    }

    /** @test */
    public function leave_request_emails_follow_each_recipients_language(): void
    {
        $leave = LeaveRequest::factory()->create([
            'employee_id'   => $this->employee->id,
            'leave_type_id' => LeaveType::factory()->create(['name' => 'Annual Leave', 'code' => 'AL'])->id,
            'status'        => 'pending',
        ]);

        $service = app(LeaveService::class);
        $service->notifyEmployee($leave, 'submitted');
        $service->notifyManager($leave, 'submitted');

        $sent = $this->sentByRecipient();
        $this->assertArabic($sent['employee@test.local'], 'employee (Arabic)');
        $this->assertEnglish($sent['manager@test.local'], 'direct manager (English)');
        $this->assertEnglish($sent['hr@test.local'], 'HR manager (English)');

        // Subject carries the leave reference (it used to end in an empty "- ").
        $this->assertStringContainsString($leave->reference, (string) $sent['employee@test.local']->getSubject());
    }

    /** @test */
    public function leave_decision_emails_to_the_employee_are_arabic(): void
    {
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::factory()->create()->id,
            'status' => 'approved',
        ]);

        foreach (['manager_approved', 'hr_approved', 'hr_rejected', 'cancelled'] as $status) {
            app(LeaveService::class)->notifyEmployee($leave, $status, 'Reason text');
            $this->assertArabic($this->sentByRecipient()['employee@test.local'], "leave $status");
        }
    }

    /** @test */
    public function loan_emails_to_the_employee_are_arabic(): void
    {
        $loan = Loan::factory()->create(['employee_id' => $this->employee->id, 'status' => 'approved']);

        Mail::to('employee@test.local')->send(new LoanApprovedMail($loan, 'Jinesh', 'جينيش'));
        $this->assertArabic($this->sentByRecipient()['employee@test.local'], 'loan approved');

        Mail::to('employee@test.local')->send(new LoanRejectedMail($loan, 'Jinesh', 'جينيش'));
        $this->assertArabic($this->sentByRecipient()['employee@test.local'], 'loan rejected');
    }

    /** @test */
    public function login_otp_email_is_arabic_for_an_arabic_employee(): void
    {
        app(OtpService::class)->createChallenge($this->employee->user()->first()->load('employee'), LoginOtp::PURPOSE_LOGIN);

        $this->assertArabic($this->sentByRecipient()['employee@test.local'], 'login OTP');
    }

    /** @test */
    public function password_reset_email_is_arabic_for_an_arabic_employee(): void
    {
        $this->employee->user()->first()->sendPasswordResetNotification('test-token');

        $this->assertArabic($this->sentByRecipient()['employee@test.local'], 'password reset');
    }

    /** @test */
    public function switching_back_to_english_takes_effect_for_the_next_email(): void
    {
        $this->employee->update(['preferred_communication_language' => 'en']);
        RecipientLocale::flush(); // done by the profile endpoint when the language changes

        $loan = Loan::factory()->create(['employee_id' => $this->employee->id, 'status' => 'approved']);
        Mail::to('employee@test.local')->send(new LoanApprovedMail($loan, 'Jinesh'));

        $this->assertEnglish($this->sentByRecipient()['employee@test.local'], 'employee after switching to English');
    }
}
