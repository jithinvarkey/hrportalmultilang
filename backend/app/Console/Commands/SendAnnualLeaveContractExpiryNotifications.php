<?php

namespace App\Console\Commands;

use App\Services\AnnualLeaveContractExpiryNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendAnnualLeaveContractExpiryNotifications extends Command
{
    protected $signature = 'leave:notify-contract-expiry {--date= : Evaluation date in YYYY-MM-DD format}';
    protected $description = 'Notify employees with more than 10 annual leave days 90 days before contract expiry';

    public function handle(AnnualLeaveContractExpiryNotificationService $service): int
    {
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', $this->option('date'), 'Asia/Riyadh')->startOfDay()
            : now('Asia/Riyadh')->startOfDay();
        $result = $service->sendForDate($date);

        $this->info("Date: {$date->toDateString()}; notified: {$result['notified']}; failed: {$result['failed']}");

        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
