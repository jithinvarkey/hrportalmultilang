<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Local/test mail safety net: every message goes to MAIL_TEST_EMAIL instead of real people.
 *
 * Unlike Mail::alwaysTo(), it keeps track of who the email was really for: the subject is prefixed with
 * "[TEST → original recipients]" and the addresses are kept in X-Original-To / X-Original-Cc headers.
 * The email is still rendered for the real recipient (language, name), so a tester can see that e.g. the
 * employee's copy is in Arabic while the manager's copy is in English.
 */
class RedirectMailToTestInbox
{
    public function __construct(private string $testEmail)
    {
    }

    public function handle(MessageSending $event): void
    {
        $message = $event->message;
        $list = fn (array $addresses) => implode(', ', array_map(fn (Address $a) => $a->getAddress(), $addresses));

        $to = $list($message->getTo());
        $cc = $list(array_merge($message->getCc(), $message->getBcc()));

        $headers = $message->getHeaders();
        $headers->remove('Cc');
        $headers->remove('Bcc');
        $headers->addTextHeader('X-Original-To', $to);
        if ($cc !== '') {
            $headers->addTextHeader('X-Original-Cc', $cc);
        }

        $message->to($this->testEmail);
        $message->subject('[TEST → ' . $to . ($cc !== '' ? ' | CC: ' . $cc : '') . '] ' . $message->getSubject());
    }
}
