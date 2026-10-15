<?php

namespace App\Console\Commands;

use App\Models\AttendanceDevice;
use App\Services\Attendance\BioTimeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncBioTimeAttendance extends Command
{
    protected $signature = 'biotime:sync
        {--days=1 : Days ago to sync; use 0 for today}
        {--date= : Sync one exact date, format YYYY-MM-DD}
        {--from= : Sync range start date/time}
        {--to= : Sync range end date/time}
        {--device= : Sync specific device ID only}';

    protected $description = 'Sync attendance punches from ZKTeco BioTime devices';

    public function handle(BioTimeService $biotime): int
    {
        $days = (int) $this->option('days');
        $devId = $this->option('device');
        [$since, $until] = $this->syncWindow($days);

        if ($until->lt($since)) {
            $this->error('The sync end time must be after the start time.');
            return 1;
        }

        $query = AttendanceDevice::where('is_active', true)->where('brand', 'zkteco');
        if ($devId) {
            $query->where('id', $devId);
        }

        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->warn('No active ZKTeco devices found.');
            return 0;
        }

        $this->info("Syncing {$devices->count()} device(s) from {$since->format('Y-m-d H:i:s')} to {$until->format('Y-m-d H:i:s')}");

        foreach ($devices as $device) {
            $this->line("  -> {$device->name} ({$device->ip_address})");
            $result = $biotime->fullSync($device, $since, $until);

            if (!empty($result['errors'])) {
                $this->error('    Failed: ' . implode('; ', $result['errors']));
                continue;
            }

            $this->info("    Fetched: {$result['fetched']}  New: {$result['new_raw']}  Created: {$result['created']}  Updated: {$result['updated']}  Unmatched: {$result['unmatched']}");
        }

        $this->info('Done.');
        return 0;
    }

    private function syncWindow(int $days): array
    {
        if ($this->option('date')) {
            $date = Carbon::parse((string) $this->option('date'));
            return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        }

        if ($this->option('from') || $this->option('to')) {
            $from = $this->option('from')
                ? Carbon::parse((string) $this->option('from'))
                : now()->subDays($days)->startOfDay();
            $to = $this->option('to')
                ? Carbon::parse((string) $this->option('to'))
                : now();

            return [$from, $to];
        }

        $since = now()->subDays($days)->startOfDay();
        $until = $days === 0 ? now() : now()->subDays($days)->endOfDay();

        return [$since, $until];
    }
}
