<?php

namespace App\Console\Commands;

use App\Services\PayrollReminderService;
use Illuminate\Console\Command;

class SendPayrollMonthEndReminder extends Command
{
    protected $signature = 'payroll:send-month-end-reminder {--force : Run immediately, ignoring date and duplicate checks}';
    protected $description = 'Notify HR managers when current-month payroll is missing or not paid';

    public function handle(PayrollReminderService $service): int
    {
        $result = $service->sendIfDue((bool) $this->option('force'));

        if ($result['not_due']) $this->info('Payroll reminder is not due today.');
        elseif ($result['already_paid']) $this->info('Current-month payroll is already paid.');
        elseif ($result['already_sent']) $this->info('Payroll reminder was already sent this month.');
        else $this->info("Sent: {$result['sent']}; failed: {$result['failed']}");

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
