<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\EmailSettingsService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;
use Throwable;

class SetNotificationReplyTo
{
    /**
     * Route replies to every outgoing application email to the configured inbox.
     */
    public function handle(MessageSending $event): void
    {
        $replyTo = '';

        try {
            $replyTo = trim((string) DB::table('system_settings')
                ->where('key', EmailSettingsService::REPLY_TO_KEY)
                ->value('value'));
        } catch (Throwable) {
            // The settings table may not exist yet during initial migrations.
        }

        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            try {
                $replyTo = (string) User::query()
                    ->whereNotNull('email')
                    ->whereHas('roles', fn ($query) => $query->where('name', 'hr_manager'))
                    ->orderBy('id')
                    ->value('email');
            } catch (Throwable) {
                // Mail must remain available during a temporary database outage.
                return;
            }
        }

        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $event->message->replyTo(new Address($replyTo));
    }
}
