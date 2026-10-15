<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewHireItNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Employee $employee)
    {
    }

    public function build(): self
    {
        return $this->subject('New Hire Onboarding Submitted - ' . $this->employee->full_name)
            ->view('emails.new-hire-it-notification');
    }
}
