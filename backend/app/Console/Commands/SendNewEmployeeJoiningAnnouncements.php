<?php

namespace App\Console\Commands;

use App\Services\NewEmployeeJoiningAnnouncementService;
use Illuminate\Console\Command;

class SendNewEmployeeJoiningAnnouncements extends Command
{
    protected $signature = 'employees:announce-new-joiners';
    protected $description = "Email and notify active employees about today's new joiners";

    public function handle(NewEmployeeJoiningAnnouncementService $service): int
    {
        $result = $service->sendToday();
        $this->info("Eligible: {$result['eligible']}; sent: {$result['sent']}; failed: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
