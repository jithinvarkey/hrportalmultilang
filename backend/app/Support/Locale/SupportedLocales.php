<?php

namespace App\Support\Locale;

final class SupportedLocales
{
    public const DEFAULT = 'en';
    public const ALL = ['en', 'ar'];

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(substr(trim((string) $locale), 0, 2));

        return in_array($locale, self::ALL, true) ? $locale : self::DEFAULT;
    }

    public static function isSupported(?string $locale): bool
    {
        return in_array(strtolower(substr(trim((string) $locale), 0, 2)), self::ALL, true);
    }

    public static function isRtl(?string $locale = null): bool
    {
        return self::normalize($locale ?? app()->getLocale()) === 'ar';
    }

    /** "rtl" / "ltr" for the current (or given) locale. Blade: dir="{{ \App\Support\Locale\SupportedLocales::dir() }}" */
    public static function dir(?string $locale = null): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }
}
