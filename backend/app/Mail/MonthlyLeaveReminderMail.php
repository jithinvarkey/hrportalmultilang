<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MonthlyLeaveReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $leaveUrl;

    public function __construct(
        public string $mailSubject,
        public string $mailBody,
    ) {
        $this->leaveUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/') . '/leave/my';
    }

    public function build(): self
    {
        return $this->subject($this->mailSubject)
            ->view('emails.monthly-leave-reminder');
    }
}
