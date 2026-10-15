<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use Carbon\Carbon;
use RuntimeException;

class AttendancePolicyService
{
    public const DEFAULTS = [
        'work_start' => '08:00',
        'late_after_minutes' => 15,
        'half_day_hours' => 4,
        'full_day_hours' => 8,
        'grace_minutes' => 5,
        'weekend_days' => [5, 6],
    ];

    public function settings(): array
    {
        $stored = rescue(function (): array {
            $contents = file_get_contents($this->settingsPath());
            $decoded = json_decode($contents, true);

            return is_array($decoded) ? $decoded : [];
        }, [], false);

        return array_merge(self::DEFAULTS, $stored);
    }

    public function save(array $settings): void
    {
        $written = file_put_contents(
            $this->settingsPath(),
            json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException('Unable to save attendance policy settings.');
        }
    }

    public function statusForCheckIn(string $checkIn, ?array $settings = null): string
    {
        $policy = array_merge(self::DEFAULTS, $settings ?? $this->settings());
        $startSeconds = $this->timeToSeconds((string) $policy['work_start']);
        $lateThreshold = $startSeconds + ((int) $policy['late_after_minutes'] * 60);

        return $this->timeToSeconds($checkIn) > $lateThreshold ? 'late' : 'present';
    }

    /** Apply the current late policy to automatic report rows without overriding manual/special statuses. */
    public function statusForReport(?string $checkIn, string $storedStatus, ?string $source, ?array $settings = null): string
    {
        if (!$checkIn || $source === 'manual' || !in_array($storedStatus, ['present', 'late'], true)) {
            return $storedStatus;
        }

        return $this->statusForCheckIn($checkIn, $settings);
    }

    /**
     * Reclassify today's automatically created rows after a policy change.
     * Manual corrections and special statuses are intentionally preserved.
     */
    public function reclassifyAutomaticLogsForDate(Carbon|string $date, ?array $settings = null): int
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;
        $updated = 0;

        AttendanceLog::query()
            ->whereDate('date', $dateString)
            ->whereNotNull('check_in')
            ->where('source', '!=', 'manual')
            ->whereIn('status', ['present', 'late'])
            ->orderBy('id')
            ->chunkById(200, function ($logs) use (&$updated, $settings): void {
                foreach ($logs as $log) {
                    $status = $this->statusForCheckIn((string) $log->check_in, $settings);

                    if ($log->status !== $status) {
                        $log->update(['status' => $status]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }

    private function settingsPath(): string
    {
        return storage_path('app/attendance_settings.json');
    }

    private function timeToSeconds(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));

        return (($parts[0] ?? 0) * 3600) + (($parts[1] ?? 0) * 60) + ($parts[2] ?? 0);
    }
}
