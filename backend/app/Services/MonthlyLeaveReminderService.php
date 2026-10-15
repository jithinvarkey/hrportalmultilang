<?php

namespace App\Services;

use App\Mail\MonthlyLeaveReminderMail;
use App\Models\Employee;
use App\Support\Locale\SupportedLocales;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class MonthlyLeaveReminderService
{
    private const DEFAULT_SUBJECT = 'Reminder: Submit current month leave entries';
    private const DEFAULT_BODY = "Dear {{first_name}},\n\nPlease make sure all leave requests for {{month_name}} {{year}} are submitted in HRMS before payroll processing. Any missing or unsubmitted leave entries may be deducted from salary as per company policy.\n\nRegards,\nHuman Resources";

    public function settings(): array
    {
        $values = DB::table('system_settings')->whereIn('key', [
            'monthly_leave_reminder_enabled',
            'monthly_leave_reminder_day',
            'monthly_leave_reminder_subject',
            'monthly_leave_reminder_body',
            'monthly_leave_reminder_subject_ar',
            'monthly_leave_reminder_body_ar',
            'monthly_leave_reminder_last_sent_month',
        ])->pluck('value', 'key');

        return [
            'enabled' => ($values['monthly_leave_reminder_enabled'] ?? '1') === '1',
            'day' => max(1, min(31, (int) ($values['monthly_leave_reminder_day'] ?? 25))),
            'subject' => $values['monthly_leave_reminder_subject'] ?? self::DEFAULT_SUBJECT,
            'body' => $values['monthly_leave_reminder_body'] ?? self::DEFAULT_BODY,
            'subject_ar' => $values['monthly_leave_reminder_subject_ar'] ?? $this->arabicDefault('subject'),
            'body_ar' => $values['monthly_leave_reminder_body_ar'] ?? $this->arabicDefault('body'),
            'last_sent_month' => $values['monthly_leave_reminder_last_sent_month'] ?? null,
        ];
    }

    public function updateSettings(array $settings): array
    {
        $now = now();
        foreach ([
            'monthly_leave_reminder_enabled' => !empty($settings['enabled']) ? '1' : '0',
            'monthly_leave_reminder_day' => (string) max(1, min(31, (int) $settings['day'])),
            'monthly_leave_reminder_subject' => $settings['subject'] ?: self::DEFAULT_SUBJECT,
            'monthly_leave_reminder_body' => $settings['body'] ?: self::DEFAULT_BODY,
            'monthly_leave_reminder_subject_ar' => ($settings['subject_ar'] ?? null) ?: $this->arabicDefault('subject'),
            'monthly_leave_reminder_body_ar' => ($settings['body_ar'] ?? null) ?: $this->arabicDefault('body'),
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        return $this->settings();
    }

    public function sendIfDue(bool $force = false): array
    {
        return $this->sendForDate(now('Asia/Riyadh'), $force);
    }

    public function sendForDate(CarbonInterface $date, bool $force = false): array
    {
        $settings = $this->settings();
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'disabled' => false, 'not_due' => false];

        if (!$force && !$settings['enabled']) {
            $result['disabled'] = true;
            return $result;
        }

        $sendDay = min((int) $settings['day'], $date->daysInMonth);
        if (!$force && (int) $date->day !== $sendDay) {
            $result['not_due'] = true;
            return $result;
        }

        $monthKey = $date->format('Y-m');
        if (!$force && $settings['last_sent_month'] === $monthKey) {
            $result['skipped'] = Employee::active()->whereNotNull('email')->count();
            return $result;
        }

        Employee::active()->whereNotNull('email')->orderBy('id')->chunkById(100, function ($employees) use (&$result, $settings, $date) {
            foreach ($employees as $employee) {
                try {
                    // Each employee gets the template of their communication language (English or Arabic).
                    // Default wording → null, so the mail uses the translated default; text customised by HR
                    // is sent as typed. Placeholders are filled by the mailable.
                    $locale = SupportedLocales::normalize($employee->preferred_communication_language);
                    [$subject, $body] = $locale === 'ar'
                        ? [$this->customOrNull($settings['subject_ar'], $this->arabicDefault('subject')),
                           $this->customOrNull($settings['body_ar'], $this->arabicDefault('body'))]
                        : [$this->customOrNull($settings['subject'], self::DEFAULT_SUBJECT),
                           $this->customOrNull($settings['body'], self::DEFAULT_BODY)];

                    Mail::to($employee->email)->locale($locale)->send(new MonthlyLeaveReminderMail(
                        $subject,
                        $body,
                        (string) $employee->first_name,
                        (string) $employee->full_name,
                        $date->toDateString(),
                        $employee->arabic_name
                    ));
                    $result['sent']++;
                } catch (\Throwable $e) {
                    report($e);
                    $result['failed']++;
                }
            }
        });

        if ($result['sent'] > 0 && !$force) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'monthly_leave_reminder_last_sent_month'],
                ['value' => $monthKey, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $result;
    }

    /** Default Arabic wording (lang/ar/mail_leave.php), used until HR saves their own Arabic text. */
    private function arabicDefault(string $part): string
    {
        return (string) trans("mail_leave.monthly_reminder.{$part}", [], 'ar');
    }

    private function customOrNull(?string $text, string $default): ?string
    {
        $normalize = fn (?string $value) => trim(str_replace("\r\n", "\n", (string) $value));

        return $normalize($text) === '' || $normalize($text) === $normalize($default) ? null : $text;
    }
}
