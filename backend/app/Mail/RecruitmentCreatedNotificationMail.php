<?php

namespace App\Mail;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecruitmentCreatedNotificationMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $type,
        public JobPosting|JobApplication $record,
        public ?User $addedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->type === 'job'
            ? __('mail_recruitment.created.subject_job', ['title' => $this->record->title])
            : __('mail_recruitment.created.subject_application', [
                'name'     => $this->record->applicant_name ?: __('mail_recruitment.candidate'),
                'position' => $this->record->jobPosting?->title
                    ?? __('mail_recruitment.position_fallback', ['company' => __('mail.company')]),
            ]);

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.recruitment-created-notification');
    }

    public function attachments(): array
    {
        return [];
    }
}
