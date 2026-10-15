<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class NewHireOnboardingLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public string $onboardingUrl,
        public ?string $loginEmail = null,
        public ?string $tempPassword = null,
        public ?string $expiresAt = null,
        public array $attachmentsMeta = [],
    ) {
    }

    public function build(): self
    {
        $mail = $this->subject('Complete your employee onboarding details')
            ->view('emails.new-hire-onboarding-link');

        foreach ($this->attachmentsMeta as $attachment) {
            $path = $attachment['path'] ?? null;
            if ($path && Storage::exists($path)) {
                $mail->attach(Storage::path($path), [
                    'as' => $attachment['name'] ?? basename($path),
                    'mime' => $attachment['mime'] ?? 'application/pdf',
                ]);
            }
        }

        return $mail;
    }
}
