<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Subject and bodies are authored by HR in both languages (BirthdayWishService) and are shown as written;
 * only the surrounding chrome follows the recipient's language.
 */
class BirthdayWishMail extends BaseMailable
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
