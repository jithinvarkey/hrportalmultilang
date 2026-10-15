<?php

namespace App\Services;

use App\Mail\NewHireOnboardingLinkMail;
use App\Models\Employee;
use App\Models\EmployeeOnboardingLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NewHireOnboardingService
{
    public function __construct(
        private RecruitmentDocumentService $documents,
        private HdfTemplateService $hdfTemplates
    ) {
    }

    public function createAndEmailLink(Employee $employee, ?string $loginEmail = null, ?string $tempPassword = null): array
    {
        $rawToken = Str::random(64);
        $expiresAt = now()->addDays(14);

        EmployeeOnboardingLink::create([
            'employee_id' => $employee->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt,
            'created_by' => auth()->id(),
        ]);

        $frontendUrl = (string) config('app.frontend_url', config('app.url'));

        $url = rtrim($frontendUrl, '/') . '/public/onboarding/' . $rawToken;
        $attachments = [];
        $attachmentError = null;
        $ccEmails = $this->ccEmails($employee->email);

        try {
            try {
                $attachments = $this->documents->onboardingAttachments($employee);
                $hdf = $this->hdfTemplates->attachment();
                if ($hdf) {
                    $attachments[] = $hdf;
                }
            } catch (\Throwable $e) {
                $attachmentError = $e->getMessage();
                Log::warning('New hire onboarding attachment generation failed.', [
                    'employee_id' => $employee->id,
                    'email' => $employee->email,
                    'error' => $attachmentError,
                ]);
            }

            if (!$employee->email) {
                throw new \RuntimeException('Employee email address is missing.');
            }

            $mail = Mail::to($employee->email);
            if (!empty($ccEmails)) {
                $mail->cc($ccEmails);
            }

            $mail->send(new NewHireOnboardingLinkMail(
                $employee,
                $url,
                $loginEmail,
                $tempPassword,
                $expiresAt->format('d M Y h:i A'),
                $attachments
            ));

            Log::info('New hire onboarding email sent.', [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'cc' => $ccEmails,
                'attachments' => collect($attachments)->pluck('path')->all(),
                'attachment_error' => $attachmentError,
            ]);

            return [
                'url' => $url,
                'email_sent' => true,
                'email_to' => $employee->email,
                'email_cc' => $ccEmails,
                'attachments' => $attachments,
                'error' => $attachmentError,
            ];
        } catch (\Throwable $e) {
            Log::warning('New hire onboarding email failed.', [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'cc' => $ccEmails,
                'error' => $e->getMessage(),
            ]);

            return [
                'url' => $url,
                'email_sent' => false,
                'email_to' => $employee->email,
                'email_cc' => $ccEmails,
                'attachments' => $attachments,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function ccEmails(?string $employeeEmail): array
    {
        if (app()->environment('prod')) {
            return [];
        }

        $testEmail = config('mail.test_email');
        if (!$testEmail) {
            return [];
        }

        $email = strtolower(trim((string) $employeeEmail));

        return collect([$testEmail])
            ->filter()
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->reject(fn ($value) => $email !== '' && $value === $email)
            ->unique()
            ->values()
            ->all();
    }
}
