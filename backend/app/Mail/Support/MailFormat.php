<?php

namespace App\Mail\Support;

use App\Support\Locale\SupportedLocales;
use Carbon\Carbon;

/**
 * Small formatting helpers for email templates. Everything uses the current (= mail) locale,
 * so call these while the mailable is rendering (Blade, build(), envelope()).
 */
final class MailFormat
{
    /** Body font stack: Arabic-capable stack for RTL, the template's own Latin stack otherwise. */
    public static function font(string $latin = 'Arial,sans-serif'): string
    {
        return SupportedLocales::isRtl() ? "'IBM Plex Sans Arabic',Tahoma,Arial,sans-serif" : $latin;
    }

    /** "left" for LTR, "right" for RTL (text-align / align attribute). */
    public static function start(): string
    {
        return SupportedLocales::isRtl() ? 'right' : 'left';
    }

    /** Date with month names in the current locale; blank/invalid input returns $empty. */
    public static function date($value, string $format = 'd M Y', string $empty = ''): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        try {
            return Carbon::parse($value)->locale(app()->getLocale())->translatedFormat($format);
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /** "2026-10" (payroll month) → "October 2026" / "أكتوبر 2026". */
    public static function month(?string $month): string
    {
        if (!$month) {
            return '';
        }

        try {
            return Carbon::createFromFormat('!Y-m', substr($month, 0, 7))
                ->locale(app()->getLocale())->translatedFormat('F Y');
        } catch (\Throwable $e) {
            return $month;
        }
    }

    public static function days($count): string
    {
        return self::unit('days', (float) $count);
    }

    public static function hours($count): string
    {
        return self::unit('hours', (float) $count);
    }

    /** "SAR 1,250.00" in English, "1,250.00 ر.س" in Arabic. */
    public static function money($amount): string
    {
        $number = number_format((float) $amount, 2);
        $currency = __('mail.units.sar');

        return SupportedLocales::isRtl() ? "{$number} {$currency}" : "{$currency} {$number}";
    }

    /** Arabic name when the mail is Arabic and one is available, otherwise the Latin name. */
    public static function name(?string $latin, ?string $arabic = null): string
    {
        if (SupportedLocales::isRtl() && trim((string) $arabic) !== '') {
            return trim((string) $arabic);
        }

        return trim((string) $latin);
    }

    /** Localised name of master data that has a *_ar column (leave/loan/request types, departments …). */
    public static function localized($model, string $attribute = 'name'): string
    {
        if (!$model) {
            return '';
        }

        return self::name($model->{$attribute} ?? '', $model->{$attribute . '_ar'} ?? null);
    }

    private static function unit(string $unit, float $count): string
    {
        $formatted = rtrim(rtrim(number_format($count, 2, '.', ''), '0'), '.');

        if (abs($count - round($count)) < 0.001 && $count >= 1) {
            return trans_choice("mail.units.{$unit}", (int) round($count), ['count' => $formatted]);
        }

        return __("mail_leave.units.{$unit}_decimal", ['count' => $formatted]);
    }
}
