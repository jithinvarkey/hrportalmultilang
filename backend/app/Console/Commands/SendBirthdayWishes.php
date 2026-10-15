<?php

namespace App\Console\Commands;

use App\Services\BirthdayWishService;
use Illuminate\Console\Command;

class SendBirthdayWishes extends Command
{
    protected $signature = 'birthday-wishes:send';
    protected $description = "Send today's configured birthday wishes";

    public function handle(BirthdayWishService $service): int
    {
        $result = $service->sendToday();
        $this->info($result['disabled'] ? 'Birthday wishes are disabled.' : "Sent: {$result['sent']}; skipped: {$result['skipped']}; failed: {$result['failed']}");
        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
