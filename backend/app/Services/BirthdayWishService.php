<?php

namespace App\Services;

use App\Support\Locale\LocalizedMail;

use App\Mail\BirthdayWishMail;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class BirthdayWishService
{
    private const DEFAULT_SUBJECT = 'Happy Birthday, {{employee_name}}!';
    private const DEFAULT_BODY = "Dear {{first_name}},\n\nWishing you a very happy birthday and a wonderful year ahead!\n\nBest wishes,\n{{company_name}}";

    public function __construct(private readonly BirthdayWishImageComposer $imageComposer)
    {
    }

    public function settings(): array
    {
        $values = DB::table('system_settings')->whereIn('key', [
            'birthday_wishes_enabled', 'birthday_wish_subject', 'birthday_wish_body',
            'birthday_wish_subject_ar', 'birthday_wish_body_ar', 'birthday_wish_background_image',
            'birthday_wish_name_area', 'birthday_wish_name_color',
        ])->pluck('value', 'key');
        $area = json_decode($values['birthday_wish_name_area'] ?? '', true);

        return [
            'enabled' => ($values['birthday_wishes_enabled'] ?? '1') === '1',
            'subject' => $values['birthday_wish_subject'] ?? self::DEFAULT_SUBJECT,
            'body' => $values['birthday_wish_body'] ?? self::DEFAULT_BODY,
            'subject_ar' => $values['birthday_wish_subject_ar'] ?? '',
            'body_ar' => $values['birthday_wish_body_ar'] ?? '',
            'background_image_path' => $values['birthday_wish_background_image'] ?? null,
            'background_image_url' => !empty($values['birthday_wish_background_image'])
                ? Storage::disk('public')->url($values['birthday_wish_background_image']) : null,
            // Where the employee's name is written on the artwork, as percentages of the image.
            'name_area' => is_array($area) ? $this->normaliseArea($area) : BirthdayWishImageComposer::DEFAULT_AREA,
            'name_color' => $values['birthday_wish_name_color'] ?? BirthdayWishImageComposer::DEFAULT_COLOR,
        ];
    }

    public function updateSettings(array $settings): array
    {
        $now = now();
        foreach ([
            'birthday_wishes_enabled' => $settings['enabled'] ? '1' : '0',
            'birthday_wish_subject' => $settings['subject'],
            'birthday_wish_body' => $settings['body'],
            'birthday_wish_subject_ar' => $settings['subject_ar'] ?? '',
            'birthday_wish_body_ar' => $settings['body_ar'] ?? '',
            'birthday_wish_background_image' => $settings['background_image_path'] ?? null,
            'birthday_wish_name_area' => json_encode($this->normaliseArea($settings['name_area'] ?? BirthdayWishImageComposer::DEFAULT_AREA)),
            'birthday_wish_name_color' => $settings['name_color'] ?? BirthdayWishImageComposer::DEFAULT_COLOR,
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => $now, 'created_at' => $now]);
        }
        return $this->settings();
    }

    public function birthdaysFor(CarbonInterface $date)
    {
        return Employee::query()->active()->whereNotNull('email')->whereNotNull('dob')
            ->whereMonth('dob', $date->month)->whereDay('dob', $date->day)
            ->orderBy('first_name')->get();
    }

    public function dashboard(): array
    {
        $year = now('Asia/Riyadh')->year;
        return $this->birthdaysFor(now('Asia/Riyadh'))->map(function (Employee $employee) use ($year) {
            $delivery = DB::table('birthday_wish_deliveries')->where('employee_id', $employee->id)->where('birthday_year', $year)->first();
            return ['id' => $employee->id, 'name' => $employee->full_name, 'email' => $employee->email,
                'status' => $delivery?->status ?? 'not_sent', 'sent_at' => $delivery?->sent_at];
        })->all();
    }

    public function sendToday(bool $ignoreDisabled = false, bool $force = false): array
    {
        if (!$ignoreDisabled && !$this->settings()['enabled']) return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'disabled' => true];
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'disabled' => false];
        foreach ($this->birthdaysFor(now('Asia/Riyadh')) as $employee) {
            $status = $this->send($employee, $force);
            $result[$status]++;
        }
        return $result;
    }

    private function send(Employee $employee, bool $force = false): string
    {
        $year = now('Asia/Riyadh')->year;
        $existing = DB::table('birthday_wish_deliveries')->where('employee_id', $employee->id)->where('birthday_year', $year)->first();
        if (!$force && $existing?->status === 'sent') return 'skipped';

        $settings = $this->settings();
        $subject = $this->render($settings['subject'], $employee);
        $body = $this->render($settings['body'], $employee);
        $subjectAr = $this->render($settings['subject_ar'], $employee);
        $bodyAr = $this->render($settings['body_ar'], $employee);
        $mailSubject = $subjectAr ? "{$subject} | {$subjectAr}" : $subject;
        DB::table('birthday_wish_deliveries')->updateOrInsert(
            ['employee_id' => $employee->id, 'birthday_year' => $year],
            ['status' => 'pending', 'recipient_email' => $employee->email, 'subject' => $mailSubject, 'error' => null, 'updated_at' => now(), 'created_at' => now()]
        );

        try {
            $cc = Employee::query()->active()->whereNotNull('email')->where('id', '<>', $employee->id)
                ->pluck('email')->filter()->unique()->values()->all();
            $backgroundPath = $settings['background_image_path']
                ? Storage::disk('public')->path($settings['background_image_path']) : null;
            $renderedImage = $backgroundPath && is_file($backgroundPath)
                ? $this->imageComposer->compose($backgroundPath, $employee->full_name, (string) $employee->arabic_name,
                    $settings['name_area'], $settings['name_color']) : null;
            try {
                // One message per communication language: colleagues read the email frame in their own language
                // (the HR-written greeting itself is shown in both languages as authored).
                LocalizedMail::send([$employee->email], $cc, fn () => new BirthdayWishMail($mailSubject, $body, $bodyAr, $renderedImage));
            } finally {
                if ($renderedImage && is_file($renderedImage)) @unlink($renderedImage);
            }
            DB::table('birthday_wish_deliveries')->where('employee_id', $employee->id)->where('birthday_year', $year)
                ->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            activity('birthday_wishes')->performedOn($employee)->event('sent')->log('Birthday wish sent');
            return 'sent';
        } catch (\Throwable $e) {
            DB::table('birthday_wish_deliveries')->where('employee_id', $employee->id)->where('birthday_year', $year)
                ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            report($e);
            return 'failed';
        }
    }

    /** Keep the text area inside the image: whole percentages, at least 5% wide and tall. */
    private function normaliseArea(array $area): array
    {
        $x = max(0, min(95, (int) round($area['x'] ?? 0)));
        $y = max(0, min(95, (int) round($area['y'] ?? 0)));
        return [
            'x' => $x,
            'y' => $y,
            'width' => max(5, min(100 - $x, (int) round($area['width'] ?? 50))),
            'height' => max(5, min(100 - $y, (int) round($area['height'] ?? 30))),
        ];
    }

    private function render(string $template, Employee $employee): string
    {
        return strtr($template, ['{{employee_name}}' => $employee->full_name, '{{first_name}}' => $employee->first_name,
            '{{company_name}}' => config('app.name', 'HRMS')]);
    }
}
