<?php

namespace App\Console\Commands;

use App\Services\MonthlyLeaveReminderService;
use Illuminate\Console\Command;

class SendMonthlyLeaveReminders extends Command
{
    protected $signature = 'leave:send-monthly-reminder {--force : Send immediately, ignoring date and duplicate checks}';
    protected $description = 'Send monthly leave entry reminder emails to active employees';

    public function handle(MonthlyLeaveReminderService $service): int
    {
        $result = $service->sendIfDue((bool) $this->option('force'));

        if ($result['disabled']) {
            $this->info('Monthly leave reminder is disabled.');
            return self::SUCCESS;
        }

        if ($result['not_due']) {
            $this->info('Monthly leave reminder is not due today.');
            return self::SUCCESS;
        }

        $this->info("Sent: {$result['sent']}; skipped: {$result['skipped']}; failed: {$result['failed']}");

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
