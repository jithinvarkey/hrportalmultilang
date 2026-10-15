<?php

namespace App\Support\Locale;

use Illuminate\Support\Str;

/**
 * Display text for a status code in the current locale (lang/<locale>/status.php),
 * falling back to a humanised code so unknown statuses never render blank.
 */
final class StatusLabel
{
    public static function for(?string $code, ?string $locale = null): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        $key = 'status.' . strtolower(str_replace([' ', '-'], '_', trim($code)));
        $label = __($key, [], $locale);

        return $label !== $key ? $label : Str::headline($code);
    }
}
