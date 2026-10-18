<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail;
use App\Models\Contract;
use App\Models\ContractRenewalRequest;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeRequest;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanType;
use App\Models\OnboardingTask;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\RequestType;
use App\Models\Separation;
use App\Models\User;
use App\Support\Locale\RecipientLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * EVERY email notification the system sends (leave, loans, attendance, payroll, requests, contracts,
 * separations, recruitment, onboarding, communications, auth) is written in the recipient's communication
 * language (employees.preferred_communication_language).
 *
 * Each email type is sent — through the real send path into the in-memory "array" mailer, without forcing a
 * language — once to an employee whose communication language is Arabic and once to one whose language is
 * English. The Arabic copy must be Arabic + right-to-left with no untranslated keys; the English copy English.
 *
 * When a new email type is added, add it to emailTypes() so it is held to the same rule.
 *
 * @group mail
 */
class AllEmailNotificationsLanguageTest extends TestCase
{
    use RefreshDatabase;

    private const ARABIC = 'arabic.employee@test.local';
    private const ENGLISH = 'english.employee@test.local';

    /** Subjects written by HR (not by the system) are shown as authored, so their language is not asserted. */
    private const HR_AUTHORED_SUBJECT = ['birthday wish', 'announcement published', 'monthly leave reminder (custom text)'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        RecipientLocale::flush();

        foreach ([self::ARABIC => 'ar', self::ENGLISH => 'en'] as $email => $language) {
            $user = User::factory()->create(['email' => $email]);
            Employee::factory()->create([
                'user_id' => $user->id,
                'email' => $email,
                'preferred_communication_language' => $language,
            ]);
        }
    }

    public static function emailTypes(): array
    {
        return array_combine($names = [
            // Leave
            'leave submitted (to manager)', 'leave status (manager approved)', 'leave approved', 'leave rejected',
            'leave cancelled (hourly excuse)', 'annual leave balance before contract expiry',
            'monthly leave reminder (default text)', 'monthly leave reminder (custom text)',
            // Attendance
            'missed check-out',
            // Loans
            'loan request submitted', 'loan approved', 'loan rejected', 'loan installment skipped',
            // Payroll
            'payroll month-end reminder (not paid)', 'payroll month-end reminder (not generated)', 'payslip',
            // Employee service requests
            'request in progress', 'request pending', 'request rejected',
            // Contracts
            'contract renewal reminder',
            // Separations / offboarding
            'separation submitted', 'separation offboarding tasks', 'separation department tasks completed',
            // Employees / onboarding
            'employee document uploaded', 'new employee joined', 'new hire IT notification',
            'new hire onboarding link', 'onboarding tasks assigned',
            // Recruitment
            'recruitment job created', 'recruitment application created', 'interview invite',
            'recruitment offer', 'applicant rejected',
            // Communications
            'announcement published', 'policy published', 'birthday wish',
            // Authentication
            'login OTP', 'password reset OTP',
        ], array_map(fn ($n) => [$n], $names));
    }

    /** @dataProvider emailTypes */
    public function test_email_is_written_in_each_recipients_language(string $type): void
    {
        MailFacade::to(self::ARABIC)->send($this->make($type));
        MailFacade::to(self::ENGLISH)->send($this->make($type));

        $sent = $this->sentByRecipient();
        $this->assertArrayHasKey(self::ARABIC, $sent, "$type: no email to the Arabic recipient");
        $this->assertArrayHasKey(self::ENGLISH, $sent, "$type: no email to the English recipient");

        $checkSubject = !in_array($type, self::HR_AUTHORED_SUBJECT, true);
        $this->assertArabicEmail($sent[self::ARABIC], $type, $checkSubject);
        $this->assertEnglishEmail($sent[self::ENGLISH], $type, $checkSubject);
    }

    public function test_password_reset_link_email_follows_the_users_language(): void
    {
        foreach ([self::ARABIC, self::ENGLISH] as $email) {
            User::where('email', $email)->firstOrFail()->sendPasswordResetNotification('test-token');
        }

        $sent = $this->sentByRecipient();
        $this->assertArabicEmail($sent[self::ARABIC], 'password reset link', true);
        $this->assertEnglishEmail($sent[self::ENGLISH], 'password reset link', true);
    }

    // ── Assertions ────────────────────────────────────────────────────────────

    private function assertArabicEmail(Email $message, string $type, bool $checkSubject): void
    {
        $html = (string) $message->getHtmlBody();
        $this->assertMatchesRegularExpression('/<html[^>]*lang="ar"/i', $html, "$type: Arabic recipient did not get an Arabic email");
        $this->assertMatchesRegularExpression('/<html[^>]*dir="rtl"/i', $html, "$type: Arabic email is not right-to-left");
        $this->assertNoRawKeys($html . ' ' . $message->getSubject(), "$type (Arabic)");
        if ($checkSubject) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $message->getSubject(), "$type: Arabic email has an English subject");
        }
    }

    private function assertEnglishEmail(Email $message, string $type, bool $checkSubject): void
    {
        $html = (string) $message->getHtmlBody();
        $this->assertMatchesRegularExpression('/<html[^>]*lang="en"/i', $html, "$type: English recipient did not get an English email");
        // The email as a whole is left-to-right; HR-authored Arabic text may still sit in its own RTL block.
        $this->assertMatchesRegularExpression('/<html[^>]*dir="ltr"/i', $html, "$type: English email is right-to-left");
        $this->assertNoRawKeys($html . ' ' . $message->getSubject(), "$type (English)");
        if ($checkSubject) {
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) $message->getSubject(), "$type: English email has an Arabic subject");
        }
    }

    private function assertNoRawKeys(string $text, string $type): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\b(mail(_[a-z]+)?|status|policy_message)\.[a-z_]+(\.[a-z_]+)*\b/',
            strip_tags($text),
            "$type: contains an untranslated text key"
        );
    }

    /** @return array<string, Email> sent messages keyed by their real recipient */
    private function sentByRecipient(): array
    {
        $byRecipient = [];
        foreach (app('mail.manager')->mailer('array')->getSymfonyTransport()->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            // With the local test-inbox redirect on (.env MAIL_FORCE_TEST_EMAIL), the real recipient is in X-Original-To.
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

    // ── Email factory (in-memory models; only the two recipients live in the database) ──

    private function make(string $type): \Illuminate\Mail\Mailable
    {
        $dept = (new Department)->forceFill(['id' => 3, 'name' => 'Information Technology', 'name_ar' => 'تقنية المعلومات']);
        $desig = (new Designation)->forceFill(['id' => 4, 'title' => 'Software Engineer', 'title_ar' => 'مهندس برمجيات']);
        $manager = (new Employee)->forceFill(['id' => 9, 'first_name' => 'Omar', 'last_name' => 'Ali', 'arabic_name' => 'عمر علي', 'email' => 'omar@test.local']);
        $emp = (new Employee)->forceFill([
            'id' => 10, 'employee_code' => '1010', 'first_name' => 'Sara', 'last_name' => 'Khan', 'arabic_name' => 'سارة خان',
            'email' => 'sara@test.local', 'hire_date' => '2026-10-15',
        ]);
        $emp->setRelation('department', $dept)->setRelation('designation', $desig)->setRelation('manager', $manager)->setRelation('user', null);
        $colleague = (new Employee)->forceFill(['first_name' => 'Huda', 'last_name' => 'Saleh', 'arabic_name' => '', 'email' => 'huda@test.local']);

        $annual = (new LeaveType)->forceFill(['name' => 'Annual Leave', 'name_ar' => 'إجازة سنوية', 'code' => 'AL']);
        $leave = (new LeaveRequest)->forceFill(['start_date' => '2026-11-02', 'end_date' => '2026-11-06', 'total_days' => 5, 'status' => 'pending']);
        $leave->id = 42;
        $leave->setRelation('employee', $emp)->setRelation('leaveType', $annual);
        $excuseType = (new LeaveType)->forceFill(['name' => 'Personal Excuse', 'name_ar' => '', 'code' => 'PE', 'is_hourly' => true]);
        $excuse = (new LeaveRequest)->forceFill([
            'start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'total_hours' => 1.5,
            'start_time' => '09:00:00', 'end_time' => '10:30:00', 'status' => 'pending',
        ]);
        $excuse->id = 43;
        $excuse->setRelation('employee', $colleague)->setRelation('leaveType', $excuseType);
        $conflict = (new LeaveRequest)->forceFill(['start_date' => '2026-11-01', 'end_date' => '2026-11-04', 'status' => 'manager_approved']);
        $conflict->setRelation('employee', $colleague);

        $loan = (new Loan)->forceFill(['reference' => 'LN-2026-0007', 'requested_amount' => 15000, 'status' => 'pending_hr', 'rejection_reason' => 'Exceeds limit']);
        $loan->id = 7;
        $loan->setRelation('employee', $emp)->setRelation('loanType', (new LoanType)->forceFill(['name' => 'Personal Loan', 'name_ar' => 'سلفة شخصية']));
        $skipped = (new LoanInstallment)->forceFill(['installment_no' => 3, 'amount' => 1250, 'due_date' => '2026-12-27', 'notes' => '']);
        $moved = (new LoanInstallment)->forceFill(['installment_no' => 13, 'amount' => 1250, 'due_date' => '2027-10-27']);

        $payslip = (new Payslip)->forceFill(['net_salary' => 12345.5, 'payroll_id' => 1, 'employee_id' => 10]);
        $payslip->setRelation('employee', $emp)->setRelation('payroll', (new Payroll)->forceFill(['month' => '2026-10', 'status' => 'approved']));

        $request = (new EmployeeRequest)->forceFill(['reference' => 'RQ-2026-0101', 'status' => 'in_progress']);
        $request->created_at = '2026-10-10 09:00:00';
        $request->setRelation('employee', $emp)
            ->setRelation('requestType', (new RequestType)->forceFill(['name' => 'Salary Certificate', 'name_ar' => 'شهادة راتب']))
            ->setRelation('assignedTo', (new User)->forceFill(['name' => 'HR Officer', 'email' => 'officer@test.local']));

        $renewal = (new ContractRenewalRequest)->forceFill([
            'reference' => 'REN-2026-0001', 'proposed_start_date' => '2027-01-01', 'proposed_end_date' => null,
            'proposed_type' => 'fixed_term', 'proposed_salary' => 15000,
        ]);
        $renewal->setRelation('employee', $emp)->setRelation('contract', (new Contract)->forceFill(['reference' => 'CON-2025-001', 'end_date' => '2026-12-31']));

        $separation = (new Separation)->forceFill(['id' => 21, 'reference' => 'SEP-2026-00001', 'type' => 'resignation', 'last_working_day' => '2026-11-30']);
        $separation->setRelation('employee', $emp);

        $posting = (new JobPosting)->forceFill(['id' => 1, 'title' => 'Senior Underwriter', 'employment_type' => 'full_time', 'status' => 'open', 'vacancies' => 2]);
        $posting->setRelation('department', $dept);
        $application = (new JobApplication)->forceFill(['id' => 5, 'applicant_name' => 'John Smith', 'applicant_email' => 'john@test.local', 'applicant_phone' => '+966500000000']);
        $application->setRelation('jobPosting', $posting);
        $interview = (new Interview)->forceFill([
            'id' => 7, 'scheduled_at' => '2026-10-20 10:30:00', 'duration_minutes' => 45, 'format' => 'in_person',
            'location_or_link' => 'Riyadh office', 'interviewers' => ['Omar Ali'], 'feedback' => 'Bring your ID.',
        ]);
        $interview->setRelation('application', $application);
        $hrUser = (new User)->forceFill(['id' => 2, 'name' => 'Huda HR', 'email' => 'hr@test.local']);
        $hrUser->setRelation('roles', collect([(new Role)->forceFill(['name' => 'hr_manager'])]));

        $document = (new EmployeeDocument)->forceFill(['id' => 11, 'title' => 'Signed NDA', 'type' => 'contract', 'file_name' => 'nda.pdf', 'created_at' => '2026-10-15 09:15:00']);
        $task = (new OnboardingTask)->forceFill(['title' => 'Create AD account', 'due_date' => '2026-10-17']);

        return match ($type) {
            'leave submitted (to manager)' => new Mail\LeaveRequestSubmittedMail($leave, 'Omar', collect([$conflict]), 'عمر'),
            'leave status (manager approved)' => new Mail\LeaveStatusMail($leave, 'manager_approved', 'Sara', 'Enjoy', null, 'سارة'),
            'leave approved' => new Mail\LeaveApprovedMail($leave, 'Sara', null, 'سارة'),
            'leave rejected' => new Mail\LeaveRejectedMail($leave, 'Sara', 'Busy period', 'سارة'),
            'leave cancelled (hourly excuse)' => new Mail\LeaveCancelledMail($excuse, 'Huda'),
            'annual leave balance before contract expiry' => new Mail\AnnualLeaveContractExpiryMail('Sara Khan', '2027-01-13', 24.5, 15, 'سارة خان'),
            'monthly leave reminder (default text)' => new Mail\MonthlyLeaveReminderMail(null, null, 'Sara', 'Sara Khan', '2026-10-25', 'سارة خان'),
            'monthly leave reminder (custom text)' => new Mail\MonthlyLeaveReminderMail('Custom {{month_name}}', 'Hi {{first_name}}, custom text for {{month_name}} {{year}}.', 'Sara', 'Sara Khan', '2026-10-25'),
            'missed check-out' => new Mail\MissedCheckoutMail('Sara Khan', '2026-10-14', '08:05:00', 'سارة خان'),
            'loan request submitted' => new Mail\LoanRequestSubmittedMail($loan, 'Approver'),
            'loan approved' => new Mail\LoanApprovedMail($loan, 'Sara', 'سارة'),
            'loan rejected' => new Mail\LoanRejectedMail($loan, 'Sara', 'سارة'),
            'loan installment skipped' => new Mail\LoanInstallmentSkippedMail($loan, $skipped, $moved, 'Finance', 'HR Manager'),
            'payroll month-end reminder (not paid)' => new Mail\PayrollMonthEndReminderMail('HR Manager', '2026-10', 'approved'),
            'payroll month-end reminder (not generated)' => new Mail\PayrollMonthEndReminderMail('HR Manager', '2026-10', null),
            'payslip' => new Mail\PayslipMail($payslip),
            'request in progress' => new Mail\RequestStatusMail($request, 'in_progress', 'Sara Khan', null, 'سارة خان'),
            'request pending' => new Mail\RequestStatusMail($request, 'pending'),
            'request rejected' => new Mail\RequestStatusMail($request, 'rejected', 'Sara Khan', 'Missing documents'),
            'contract renewal reminder' => new Mail\ContractRenewalReminderMail($renewal),
            'separation submitted' => new Mail\SeparationWorkflowMail($separation, 'submitted', 'Omar Ali'),
            'separation offboarding tasks' => new Mail\SeparationWorkflowMail($separation, 'offboarding_tasks', null, ['Return laptop', 'Revoke VPN'], 'it'),
            'separation department tasks completed' => new Mail\SeparationWorkflowMail($separation, 'department_tasks_completed', null, ['Return laptop'], 'finance', 'Omar Ali'),
            'employee document uploaded' => new Mail\EmployeeDocumentUploadedMail($emp, $document),
            'new employee joined' => new Mail\NewEmployeeJoinedMail($emp),
            'new hire IT notification' => new Mail\NewHireItNotificationMail($emp),
            'new hire onboarding link' => new Mail\NewHireOnboardingLinkMail($emp, 'https://hr.test/onboarding/abc', 'sara@test.local', 'Temp#123', now()->addDays(14)->toIso8601String(), []),
            'onboarding tasks assigned' => new Mail\OnboardingTasksAssignedMail($emp, collect([$task]), 'IT'),
            'recruitment job created' => new Mail\RecruitmentCreatedNotificationMail('job', $posting, $hrUser),
            'recruitment application created' => new Mail\RecruitmentCreatedNotificationMail('application', $application, $hrUser),
            'interview invite' => new Mail\InterviewInviteMail($interview, collect([$manager])),
            'recruitment offer' => new Mail\RecruitmentOfferMail($application, []),
            'applicant rejected' => new Mail\ApplicantRejectedMail($application),
            'announcement published' => new Mail\CommunicationPublishedMail('announcement', 'Office closed', 'The office is closed on Sunday.', 'https://hr.test/announcements', 'Sara Khan', 'إغلاق المكتب', 'المكتب مغلق يوم الأحد.', 'سارة خان'),
            'policy published' => new Mail\CommunicationPublishedMail('policy', 'Remote Work Policy', 'Remote Work Policy requires your acknowledgement.', 'https://hr.test/policies', 'Sara Khan', null, null, 'سارة خان', 'policy_message.updated', ['version' => 3]),
            'birthday wish' => new Mail\BirthdayWishMail('Happy Birthday Sara | عيد ميلاد سعيد سارة', "Dear Sara,\nHappy birthday!", "عزيزتي سارة،\nكل عام وأنتِ بخير!"),
            'login OTP' => new Mail\OtpMail('Sara', '123456', 'login', 3, 'سارة خان'),
            'password reset OTP' => new Mail\OtpMail('Sara', '654321', 'password_reset', 3),
        };
    }
}
