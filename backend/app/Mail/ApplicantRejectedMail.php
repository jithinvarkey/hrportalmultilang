<?php

namespace App\Mail;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class ApplicantRejectedMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(public JobApplication $application)
    {
    }

    public function build(): self
    {
        $position = $this->application->jobPosting?->title ?? __('mail_recruitment.rejected.subject_position');

        return $this->subject(__('mail_recruitment.rejected.subject', [
                'name'     => $this->application->applicant_name ?: __('mail_recruitment.candidate'),
                'position' => $position,
            ]))
            ->view('emails.applicant-rejected');
    }
}
