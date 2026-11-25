<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

/** Sent from Admin > Settings to check the outgoing mail server. */
class MailServerTestMail extends BaseMailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        return $this->subject(__('mail_auth.server_test.subject'))
            ->view('emails.mail-server-test');
    }
}
