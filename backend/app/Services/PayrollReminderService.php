<?php

namespace App\Services;

use App\Mail\PayrollMonthEndReminderMail;
use App\Models\Payroll;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PayrollReminderService
{
    public function sendIfDue(bool $force = false): array
    {
        return $this->sendForDate(now('Asia/Riyadh'), $force);
    }

    public function sendForDate(CarbonInterface $date, bool $force = false): array
    {
        $result = [
            'sent' => 0, 'failed' => 0, 'skipped' => 0,
            'not_due' => false, 'already_paid' => false, 'already_sent' => false,
        ];

        if (!$force && !$date->isLastOfMonth()) {
            $result['not_due'] = true;
            return $result;
        }

        $month = $date->format('Y-m');
        $payroll = Payroll::where('month', $month)->latest('id')->first();

        if ($payroll?->status === 'paid') {
            $result['already_paid'] = true;
            return $result;
        }

        $lastSentMonth = DB::table('system_settings')
            ->where('key', 'payroll_month_end_reminder_last_sent_month')
            ->value('value');

        if (!$force && $lastSentMonth === $month) {
            $result['already_sent'] = true;
            return $result;
        }

        $recipients = User::whereNotNull('email')
            ->whereHas('roles', fn ($query) => $query->where('name', 'hr_manager'))
            ->get(['id', 'name', 'email']);

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(new PayrollMonthEndReminderMail(
                    $recipient->name,
                    $month,
                    $payroll?->status
                ));
                $result['sent']++;
            } catch (\Throwable $exception) {
                report($exception);
                $result['failed']++;
            }
        }

        if ($result['sent'] > 0 && !$force) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'payroll_month_end_reminder_last_sent_month'],
                ['value' => $month, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        return $result;
    }
}
