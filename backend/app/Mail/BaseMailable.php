<?php

namespace App\Mail;

use App\Support\Locale\RecipientLocale;
use Illuminate\Mail\Mailable;

/**
 * Every application mailable extends this class so emails use the recipient employee's preferred
 * communication language (employees.preferred_communication_language, English by default) — not the
 * language the user happens to view the portal in.
 *
 * Laravel renders the mailable (build()/envelope()/content() and the Blade view) inside the mailable's
 * locale, so subjects and templates only need __()/@lang. When the sender did not set a locale explicitly
 * (->locale('ar'), or Mail::to($user) which uses User::preferredLocale()), it is resolved from the
 * "to" addresses. Queued mail is delivered through send() as well, so both paths are covered.
 */
abstract class BaseMailable extends Mailable
{
    public function send($mailer)
    {
        if (empty($this->locale)) {
            $this->locale(RecipientLocale::forAddresses(array_column($this->to, 'address')));
        }

        return parent::send($mailer);
    }
}
