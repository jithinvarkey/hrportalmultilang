<?php

namespace App\Console\Commands;

use App\Services\MissedCheckoutNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendMissedCheckoutNotifications extends Command
{
    protected $signature = 'attendance:notify-missed-checkouts {--date= : Attendance date in YYYY-MM-DD format}';
    protected $description = 'Notify employees who checked in but did not check out';

    public function handle(MissedCheckoutNotificationService $service): int
    {
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', $this->option('date'), 'Asia/Riyadh')->startOfDay()
            : now('Asia/Riyadh')->subDay()->startOfDay();
        $result = $service->sendForDate($date);
        $this->info("Date: {$date->toDateString()}; notified: {$result['notified']}; failed: {$result['failed']}");
        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
