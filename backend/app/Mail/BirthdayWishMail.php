<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BirthdayWishMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $mailBody,
        public string $mailBodyAr = '',
        public ?string $backgroundImagePath = null,
    )
    {
    }

    public function build(): self
    {
        return $this->subject($this->mailSubject)->view('emails.birthday-wish');
    }
}
