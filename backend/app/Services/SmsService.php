<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private $unifonicSettings;

    public function __construct(UnifonicSettingsService $unifonicSettings)
    {
        $this->unifonicSettings = $unifonicSettings;
    }

    public function send(string $to, string $message): bool
    {
        $to = trim($to);

        if ($to === '') {
            return false;
        }

        $settings = $this->unifonicSettings->settings();

        if (!$settings['enabled']) {
            Log::info('Unifonic SMS is disabled; OTP SMS skipped.', ['to' => $this->maskPhone($to)]);
            return false;
        }

        if (!$settings['api_url'] || !$settings['app_sid'] || !$settings['sender']) {
            Log::warning('Unifonic SMS settings are incomplete; OTP SMS skipped.', ['to' => $this->maskPhone($to)]);
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                ->withBody(http_build_query([
                    'AppSid' => $settings['app_sid'],
                    'SenderID' => $settings['sender'],
                    'Body' => $message,
                    'Recipient' => $to,
                ]), 'application/x-www-form-urlencoded')
                ->post($settings['api_url']);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Unifonic rejected OTP SMS.', [
                'to' => $this->maskPhone($to),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unifonic failed while sending OTP SMS.', [
                'to' => $this->maskPhone($to),
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? str_repeat('*', strlen($phone) - 4) . substr($phone, -4) : '****';
    }
}
