<?php

namespace App\Support\Locale;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a notification that has several employee recipients as one message per communication language, so
 * every recipient reads it in their own language (BaseMailable alone would use the first "to" address's
 * language for everyone on the message).
 */
final class LocalizedMail
{
    /**
     * One message per language group. Within a group, "to" recipients stay in To and "cc" recipients in CC;
     * a group that has only CC recipients gets the first of them in To.
     *
     * @param  iterable<string>  $to
     * @param  iterable<string>  $cc
     * @param  callable(): Mailable  $make  builds a fresh mailable for each message
     * @return int number of messages sent
     */
    public static function send(iterable $to, iterable $cc, callable $make): int
    {
        $to = self::clean($to);
        $cc = array_values(array_diff_key(self::clean($cc), $to));

        $groups = [];
        foreach ($to as $email) {
            $groups[self::localeFor($email)]['to'][] = $email;
        }
        foreach ($cc as $email) {
            $groups[self::localeFor($email)]['cc'][] = $email;
        }

        $sent = 0;
        foreach ($groups as $locale => $group) {
            $groupTo = $group['to'] ?? [];
            $groupCc = $group['cc'] ?? [];
            if ($groupTo === []) {
                $groupTo = [array_shift($groupCc)];
            }

            $pending = Mail::to($groupTo)->locale($locale);
            if ($groupCc !== []) {
                $pending->cc($groupCc);
            }
            $pending->send($make());
            $sent++;
        }

        return $sent;
    }

    public static function localeFor(string $email): string
    {
        return RecipientLocale::forEmail($email) ?? SupportedLocales::DEFAULT;
    }

    /**
     * Trimmed, non-empty addresses, de-duplicated case-insensitively, keyed by the lower-cased address.
     *
     * @param  iterable<string|null>  $emails
     * @return array<string,string>
     */
    private static function clean(iterable $emails): array
    {
        $clean = [];
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email !== '' && !isset($clean[strtolower($email)])) {
                $clean[strtolower($email)] = $email;
            }
        }

        return $clean;
    }
}
