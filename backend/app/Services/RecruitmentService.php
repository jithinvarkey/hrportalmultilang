<?php
namespace App\Services;

use App\Mail\ApplicantRejectedMail;
use App\Mail\InterviewInviteMail;
use App\Mail\RecruitmentOfferMail;
use App\Mail\RecruitmentCreatedNotificationMail;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class RecruitmentService
{
    public function __construct(private RecruitmentDocumentService $documents) {}

    public function notifyHrManagersOfNewJob(JobPosting $job): void
    {
        $job->loadMissing(['department', 'designation']);
        $this->sendCreatedNotification('job', $job);
    }

    public function notifyHrManagersOfNewApplication(JobApplication $application, ?User $addedBy = null): void
    {
        $application->loadMissing('jobPosting.department');
        $this->sendCreatedNotification('application', $application, $addedBy);
    }

    private function sendCreatedNotification(string $type, JobPosting|JobApplication $record, ?User $addedBy = null): void
    {
        $recipients = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'hr_manager'))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->unique()
            ->values()
            ->all();

        if (empty($recipients)) {
            Log::warning("New recruitment {$type} notification skipped: no HR manager email recipients.", [
                'record_id' => $record->id,
            ]);
            return;
        }

        try {
            Mail::to($recipients)->send(new RecruitmentCreatedNotificationMail($type, $record, $addedBy));
            Log::info("New recruitment {$type} notification sent.", [
                'record_id' => $record->id,
                'recipients' => $recipients,
            ]);
        } catch (\Throwable $e) {
            Log::error("New recruitment {$type} notification failed.", [
                'record_id' => $record->id,
                'recipients' => $recipients,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendInterviewInvite($interview, Collection $interviewers): void
    {
        $interview->loadMissing('application.jobPosting');
        $email = $interview->application?->applicant_email;
        $interviewerEmails = $interviewers
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$email) {
            Log::warning('Interview invite email skipped: applicant email missing.', [
                'interview_id' => $interview->id,
                'application_id' => $interview->application_id,
            ]);
            return;
        }

        if (empty($interviewerEmails)) {
            Log::warning('Interview invite has no interviewer email recipients.', [
                'interview_id' => $interview->id,
                'application_id' => $interview->application_id,
            ]);
        }

        $recipients = array_values(array_unique(array_filter(array_merge([$email], $interviewerEmails))));
        $testEmail = config('mail.test_email');
        $mail = Mail::to($recipients);

        if ($testEmail
            && !app()->environment('prod')
            && !in_array($testEmail, $recipients, true)) {
            $mail->cc($testEmail);
        }

        $mail->send(new InterviewInviteMail($interview, $interviewers));
    }

    public function sendApplicantRejection(JobApplication $application): void
    {
        $application->loadMissing('jobPosting');

        if (!$application->applicant_email) {
            throw ValidationException::withMessages([
                'email' => 'Applicant email is missing. The applicant was not rejected.',
            ]);
        }

        try {
            Mail::to($application->applicant_email)->send(new ApplicantRejectedMail($application));

            Log::info('Applicant rejection email sent.', [
                'application_id' => $application->id,
                'to' => $application->applicant_email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Applicant rejection email failed.', [
                'application_id' => $application->id,
                'to' => $application->applicant_email,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'The rejection email could not be sent. The applicant was not rejected: ' . $e->getMessage(),
            ]);
        }
    }

    public function generateOfferLetter(JobApplication $app, array $data): array
    {
        $app->loadMissing(['jobPosting.department', 'jobPosting.designation']);
        $offer = $this->documents->generateOfferPdf($app, $data);

        $mail = Mail::to($app->applicant_email);
        $testEmail = config('mail.test_email');
        $ccEmails = $this->offerCcEmails($app->applicant_email, $testEmail);

        if (!empty($ccEmails)) {
            $mail->cc($ccEmails);
        }

        try {
            Log::info('Recruitment offer email sending.', [
                'application_id' => $app->id,
                'to' => $app->applicant_email,
                'cc' => $ccEmails,
                'offer_path' => $offer['path'] ?? null,
            ]);

            $mail->send(new RecruitmentOfferMail($app, [$offer]));

            Log::info('Recruitment offer email sent.', [
                'application_id' => $app->id,
                'to' => $app->applicant_email,
                'cc' => $ccEmails,
                'offer_path' => $offer['path'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Recruitment offer email failed.', [
                'application_id' => $app->id,
                'to' => $app->applicant_email,
                'cc' => $ccEmails,
                'offer_path' => $offer['path'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'Offer letter generated, but email sending failed: ' . $e->getMessage(),
            ]);
        }

        $gross = (float) ($data['basic_salary'] ?? 0)
            + (float) ($data['housing_allowance'] ?? 0)
            + (float) ($data['transport_allowance'] ?? 0)
            + (float) ($data['other_allowance'] ?? 0);

        return [
            'pdf_path' => $offer['path'],
            'basic_salary' => $data['basic_salary'] ?? null,
            'gross_salary' => $gross,
            'cc' => $ccEmails,
        ];
    }

    private function offerCcEmails(?string $applicantEmail, ?string $testEmail): array
    {
        $hrManagerEmails = User::whereHas('roles', fn ($query) => $query->where('name', 'hr_manager'))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->pluck('email')
            ->filter()
            ->all();

        return collect($hrManagerEmails)
            ->when(!app()->environment('prod'), fn ($emails) => $emails->push($testEmail))
            ->filter()
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->reject(fn ($email) => $applicantEmail && $email === strtolower(trim($applicantEmail)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Convert a hired applicant into a full Employee record.
     * Returns employee + temp password + onboarding tasks created.
     */
    public function hireApplicant(JobApplication $app, array $data): array
    {
        $nameParts = explode(' ', $app->applicant_name, 2);
        $firstName = $nameParts[0];
        $lastName  = $nameParts[1] ?? '-';
        $tempPassword = Str::random(8) . '@1aA';

        // Find or create User account
        $isNewUser = false;
        $user = User::where('email', $app->applicant_email)->first();
        if ($user) {
            // A repeated hire confirmation must issue credentials the applicant can
            // actually use, while continuing to reuse the existing account.
            $user->update(['password' => Hash::make($tempPassword)]);
            if (!$user->hasRole('employee')) {
                $user->assignRole('employee');
            }
        } else {
            $isNewUser = true;
            $user = User::create([
                'name'     => $app->applicant_name,
                'email'    => $app->applicant_email,
                'password' => Hash::make($tempPassword),
            ]);
            $user->assignRole('employee');
        }

        // Prevent duplicate employee records
        $existingEmployee = Employee::where('user_id', $user->id)->first();
        if ($existingEmployee) {
            return [
                'employee'         => $existingEmployee->load('department','designation'),
                'employee_code'    => $existingEmployee->employee_code,
                'temp_password'    => $tempPassword,
                'is_new'           => false,
                'is_new_employee'  => false,
                'onboarding_tasks' => $existingEmployee->onboardingTasks()->count(),
                'login_email'      => $user->email,
            ];
        }

        $empCode = $this->generateEmployeeCode();

        // The linked job posting is authoritative for organisational placement.
        $departmentId = $app->jobPosting?->department_id ?? $data['department_id'] ?? null;
        $designationId = $app->jobPosting?->designation_id ?? $data['designation_id'] ?? null;
        $unitId = $data['unit_id'] ?? null;

        $basicSalary = (float) ($data['salary'] ?? 0);

        $employee = Employee::create([
            'user_id'          => $user->id,
            'first_name'       => $firstName,
            'last_name'        => $lastName,
            'email'            => $app->applicant_email,
            'phone'            => $app->applicant_phone,
            'hire_date'        => $data['joining_date']     ?? $data['hire_date'] ?? now()->toDateString(),
            'employment_type'  => $data['employment_type']  ?? $app->jobPosting?->employment_type ?? 'full_time',
            'salary'           => $basicSalary,
            'housing_allowance'=> round($basicSalary * 0.25, 2),
            'transport_allowance'=> round($basicSalary * 0.10, 2),
            'other_allowances' => 0,
            'department_id'    => $departmentId,
            'unit_id'          => $unitId,
            'designation_id'   => $designationId,
            'manager_id'       => $data['manager_id']       ?? null,
            'probation_period' => $data['probation_period'] ?? 90,
            'status'           => 'probation',
            'employee_code'    => $empCode,
        ]);

        $this->createDefaultLeaveAllocations($employee);
        $onboardingTasks = $this->createOnboardingTasks($employee, $data);
        $this->documents->storeJoiningDateFormDocument($employee, $data);

        return [
            'employee'         => $employee->load('department','designation'),
            'employee_code'    => $empCode,
            'temp_password'    => $tempPassword,
            'is_new'           => $isNewUser,
            'is_new_employee'  => true,
            'onboarding_tasks' => count($onboardingTasks),
            'login_email'      => $app->applicant_email,
        ];
    }

    /**
     * Create standard onboarding tasks for a new employee.
     * Customise the list as needed for your organisation.
     */
    private function createOnboardingTasks(Employee $employee, array $data): array
    {
        $hireDate = \Carbon\Carbon::parse($data['joining_date'] ?? $data['hire_date'] ?? now());
        $tasks = [];

        $defaultTasks = [
            ['title' => 'Provide company laptop and accessories',     'category' => 'it_setup',     'days' => 1],
            ['title' => 'Create email and system accounts',           'category' => 'it_setup',     'days' => 1],
            ['title' => 'Prepare ID badge and access card',           'category' => 'hr_documents', 'days' => 1],
            ['title' => 'Set up workstation and desk allocation',      'category' => 'it_setup',     'days' => 1],
            ['title' => 'Sign employment contract',                   'category' => 'hr_documents', 'days' => 1],
            ['title' => 'Collect required personal documents',        'category' => 'hr_documents', 'days' => 3],
            ['title' => 'Register bank and payroll details',          'category' => 'hr_documents', 'days' => 3],
            ['title' => 'Complete mandatory compliance training',     'category' => 'training',     'days' => 7],
            ['title' => 'Introduce to team and department',           'category' => 'introduction', 'days' => 1],
            ['title' => 'Set up buddy or mentor',                     'category' => 'introduction', 'days' => 3],
            ['title' => '30-day probation check-in',                  'category' => 'probation',    'days' => 30],
            ['title' => '90-day probation review',                    'category' => 'probation',    'days' => (int)($data['probation_period'] ?? 90)],
        ];

        foreach ($defaultTasks as $i => $t) {
            $task = \App\Models\OnboardingTask::create([
                'employee_id' => $employee->id,
                'title'       => $t['title'],
                'category'    => $t['category'],
                'status'      => 'pending',
                'due_date'    => $hireDate->copy()->addDays($t['days'])->toDateString(),
                'sort_order'  => $i + 1,
            ]);
            $tasks[] = $task;
        }

        // Add custom tasks from request if any
        if (!empty($data['custom_tasks'])) {
            foreach ($data['custom_tasks'] as $j => $ct) {
                $tasks[] = \App\Models\OnboardingTask::create([
                    'employee_id' => $employee->id,
                    'title'       => $ct,
                    'category'    => 'hr_documents',
                    'status'      => 'pending',
                    'sort_order'  => count($defaultTasks) + $j + 1,
                ]);
            }
        }

        return $tasks;
    }

    /** Generate next employee number without the legacy EMP prefix. */
    private function generateEmployeeCode(): string
    {
        $max = \App\Models\Employee::withTrashed()
            ->pluck('employee_code')
            ->map(fn ($code) => $this->employeeCodeNumber($code))
            ->max();

        return (string) (((int) $max) + 1);
    }

    private function employeeCodeNumber(mixed $code): int
    {
        $value = strtoupper(trim((string) $code));
        $value = preg_replace('/^EMP/i', '', $value) ?? '';
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? 0 : (int) ltrim($digits, '0');
    }

    /** Create annual leave allocations for all active leave types */
    private function createDefaultLeaveAllocations(\App\Models\Employee $employee): void
    {
        $year  = now()->year;
        $types = \App\Models\LeaveType::where('is_active', true)->get();
        foreach ($types as $type) {
            \App\Models\LeaveAllocation::firstOrCreate(
                ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                [
                    'allocated_days' => $type->days_allowed,
                    'used_days'      => 0,
                    'pending_days'   => 0,
                    'remaining_days' => $type->days_allowed,
                ]
            );
        }
    }
}
