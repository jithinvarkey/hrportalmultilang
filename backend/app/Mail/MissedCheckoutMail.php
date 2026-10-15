<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MissedCheckoutMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $employeeName,
        public string $attendanceDate,
        public string $checkInTime,
    ) {
    }

    public function build(): self
    {
        return $this->subject("Missing checkout for {$this->attendanceDate}")
            ->view('emails.missed-checkout');
    }
}
