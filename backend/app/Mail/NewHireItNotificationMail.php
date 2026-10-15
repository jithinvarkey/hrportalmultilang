<?php

namespace App\Mail;

use App\Models\Employee;
use App\Support\Locale\LocalName;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class NewHireItNotificationMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function __construct(public Employee $employee)
    {
    }

    public function build(): self
    {
        return $this->subject(__('mail_onboarding.it.subject', ['name' => LocalName::employee($this->employee)]))
            ->view('emails.new-hire-it-notification');
    }
}
