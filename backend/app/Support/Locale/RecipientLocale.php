<?php

namespace App\Support\Locale;

use App\Models\Employee;
use App\Models\User;

/**
 * Works out which language an email should be written in from its recipient addresses, using the employee's
 * preferred communication language. The first recipient that is a known employee decides; unknown addresses
 * (applicants, external contacts) get English.
 */
final class RecipientLocale
{
    /**
     * Seconds a lookup is reused. Keeps a bulk send (one lookup per address) cheap, while a long-running
     * queue worker still picks up an employee's changed language instead of using it until the worker restarts.
     */
    private const TTL_SECONDS = 60;

    /** @var array<string,array{0:string|null,1:int}> per-process cache: lower-cased email => [locale, expires at] */
    private static array $cache = [];

    /** @param  iterable<string>  $emails */
    public static function forAddresses(iterable $emails): string
    {
        foreach ($emails as $email) {
            $locale = self::forEmail($email);
            if ($locale !== null) {
                return $locale;
            }
        }

        return SupportedLocales::DEFAULT;
    }

    public static function forEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        $now = time();
        if (!isset(self::$cache[$email]) || self::$cache[$email][1] < $now) {
            self::$cache[$email] = [self::lookup($email), $now + self::TTL_SECONDS];
        }

        return self::$cache[$email][0];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * The employee's preferred communication language (employees.preferred_communication_language,
     * default English). It is deliberately independent of the screen language the user picks in the UI.
     * The address is matched against the employee's email first, then the email of the linked login account.
     */
    private static function lookup(string $email): ?string
    {
        try {
            $language = Employee::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('preferred_communication_language');

            if ($language === null) {
                $userId = User::query()->whereRaw('LOWER(email) = ?', [$email])->value('id');
                if ($userId) {
                    $language = Employee::query()->where('user_id', $userId)->value('preferred_communication_language');
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $language ? SupportedLocales::normalize($language) : null;
    }
}
