<?php

namespace App\Mail;

use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecruitmentCreatedNotificationMail extends Mailable
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
            ? 'New job posting: ' . $this->record->title
            : 'New Applicant — '
                . ($this->record->applicant_name ?: 'Candidate')
                . ' — '
                . ($this->record->jobPosting?->title ?? 'Position at Diamond Insurance Broker');

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
