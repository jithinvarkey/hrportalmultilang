<?php

namespace App\Support\Locale;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Small display helpers for emails/PDFs rendered in the current locale:
 * Arabic master-data columns (name_ar / title_ar / arabic_name) with English fallback,
 * translated option codes and localized dates.
 */
final class LocalName
{
    /** $model->{$field}_ar in Arabic when filled, otherwise $model->{$field}. */
    public static function of($model, string $field = 'name', ?string $fallback = null): ?string
    {
        if (!$model) {
            return $fallback;
        }

        if (SupportedLocales::isRtl()) {
            $arabic = $model->{$field . '_ar'} ?? null;
            if (filled($arabic)) {
                return $arabic;
            }
        }

        $value = $model->{$field} ?? null;

        return filled($value) ? $value : $fallback;
    }

    public static function department($department, ?string $fallback = null): ?string
    {
        return self::of($department, 'name', $fallback);
    }

    public static function designation($designation, ?string $fallback = null): ?string
    {
        return self::of($designation, 'title', $fallback);
    }

    /** Employee display name: arabic_name in Arabic when filled, otherwise full_name. */
    public static function employee($employee, ?string $fallback = null): ?string
    {
        if (!$employee) {
            return $fallback;
        }

        if (SupportedLocales::isRtl() && filled($employee->arabic_name ?? null)) {
            return $employee->arabic_name;
        }

        $name = trim((string) ($employee->full_name ?? ''));

        return $name !== '' ? $name : $fallback;
    }

    /** Translate "$group.$code" (e.g. mail_contract.types.fixed_term), falling back to a humanised code. */
    public static function option(string $group, ?string $code, string $fallback = '-'): string
    {
        if ($code === null || $code === '') {
            return $fallback;
        }

        $key = $group . '.' . strtolower(str_replace([' ', '-'], '_', trim($code)));
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline($code);
    }

    /** Localized date (Arabic month/day names in Arabic). */
    public static function date($value, string $format = 'd M Y', ?string $fallback = '-'): ?string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->locale(app()->getLocale())->translatedFormat($format);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
