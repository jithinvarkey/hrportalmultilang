<?php

namespace App\Mail;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RecruitmentOfferMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public JobApplication $application,
        public array $attachmentsMeta = [],
    ) {
    }

    public function build(): self
    {
        $position = $this->application->jobPosting?->title
            ?? __('mail_recruitment.position_fallback', ['company' => __('mail.company')]);
        $mail = $this->subject(__('mail_recruitment.offer.subject', [
                'name'     => $this->application->applicant_name ?: __('mail_recruitment.candidate'),
                'position' => $position,
            ]))
            ->view('emails.recruitment-offer');

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
