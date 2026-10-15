<?php

namespace App\Console\Commands;

use App\Models\LoginOtp;
use Illuminate\Console\Command;

class PruneLoginOtps extends Command
{
    protected $signature = 'login-otps:prune
                            {--days=7 : Delete OTP records created more than this many days ago}
                            {--dry-run : Report matching records without deleting them}';

    protected $description = 'Delete stored login OTP challenges older than the retention period';

    public function handle(): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($days === false) {
            $this->error('The --days option must be a positive whole number.');
            return self::INVALID;
        }

        $cutoff = now()->subDays($days);
        $query = LoginOtp::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} OTP record(s) are older than {$days} day(s) and would be deleted.");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} OTP record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
