<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class UnifonicSettingsService
{
    private const DEFAULT_URL = 'https://el.cloud.unifonic.com/rest/SMS/messages';

    public function settings(): array
    {
        $values = DB::table('system_settings')->whereIn('key', [
            'unifonic_enabled',
            'unifonic_api_url',
            'unifonic_app_sid',
            'unifonic_sender',
        ])->pluck('value', 'key');

        return [
            'enabled' => (($values['unifonic_enabled'] ?? env('UNIFONIC_ENABLED', '0')) === '1'),
            'api_url' => $values['unifonic_api_url'] ?? env('UNIFONIC_API_URL', self::DEFAULT_URL),
            'app_sid' => $values['unifonic_app_sid'] ?? env('UNIFONIC_APP_SID', ''),
            'sender' => $values['unifonic_sender'] ?? env('UNIFONIC_SENDER', ''),
        ];
    }

    public function updateSettings(array $settings): array
    {
        $now = now();
        $rows = [
            'unifonic_enabled' => !empty($settings['enabled']) ? '1' : '0',
            'unifonic_api_url' => $settings['api_url'] ?: self::DEFAULT_URL,
            'unifonic_app_sid' => $settings['app_sid'] ?? '',
            'unifonic_sender' => $settings['sender'] ?? '',
        ];

        foreach ($rows as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        return $this->settings();
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();

        return (bool) ($settings['enabled'] && $settings['api_url'] && $settings['app_sid'] && $settings['sender']);
    }
}
