<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EmailSettingsService
{
    public const REPLY_TO_KEY = 'notification_reply_to_email';

    public function settings(): array
    {
        return [
            'reply_to_email' => (string) (DB::table('system_settings')
                ->where('key', self::REPLY_TO_KEY)
                ->value('value') ?? ''),
        ];
    }

    public function updateSettings(array $settings): array
    {
        $now = now();

        DB::table('system_settings')->updateOrInsert(
            ['key' => self::REPLY_TO_KEY],
            [
                'value' => trim((string) $settings['reply_to_email']),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return $this->settings();
    }
}
