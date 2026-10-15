<?php

namespace App\Mail;

use App\Models\JobApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicantRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public JobApplication $application)
    {
    }

    public function build(): self
    {
        $position = $this->application->jobPosting?->title ?? 'your application';

        return $this->subject('Application Update — ' . ($this->application->applicant_name ?: 'Candidate') . ' — ' . $position)
            ->view('emails.applicant-rejected');
    }
}
