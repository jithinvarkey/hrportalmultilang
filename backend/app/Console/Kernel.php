<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $backupHours = config('database_backup.schedule_hours', [1, 13]);
        $schedule->command('database:backup')
                 ->cron(sprintf('0 %d,%d * * *', (int) $backupHours[0], (int) $backupHours[1]))
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/database-backup.log'));

        // Run every day at 00:05 AM Riyadh time
        $schedule->command('leave:accrue')
                 ->dailyAt('00:05')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/leave-accrual.log'));

        // Retain OTP challenge records for seven days, then remove them daily.
        $schedule->command('login-otps:prune --days=7')
                 ->dailyAt('02:15')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/login-otp-prune.log'));

        // Mark overdue requests daily (closure — no artisan command required)
        $schedule->call(function () {
            \App\Models\EmployeeRequest::whereNotIn('status', ['completed','rejected','cancelled'])
                ->where('due_date', '<', now()->toDateString())
                ->update(['is_overdue' => true]);
        })->name('mark-overdue-requests')->dailyAt('07:00')->timezone('Asia/Riyadh')->withoutOverlapping();

        // Auto-generate contract renewal requests 60 days before expiry
        $schedule->command('contracts:generate-renewals --days=60')
                 ->dailyAt('06:00')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/contract-renewals.log'));

        $schedule->command('birthday-wishes:send')
                 ->dailyAt('08:00')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/birthday-wishes.log'));

        $schedule->command('employees:announce-new-joiners')
                 ->everyFifteenMinutes()
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/new-employee-joining-announcements.log'));

        // Publish due announcements and fan out their in-app/email notifications.
        // The host must run `php artisan schedule:run` every minute.
        $schedule->command('communications:process')
                 ->everyMinute()
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/communications.log'));

        $schedule->command('attendance:notify-missed-checkouts')
                 ->dailyAt('12:01')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/missed-checkouts.log'));

        $schedule->command('biotime:sync --days=0')
                 ->everyFifteenMinutes()
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/biotime-sync.log'));

        $schedule->command('leave:notify-contract-expiry')
                 ->dailyAt('08:30')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/annual-leave-contract-expiry.log'));

        $schedule->command('leave:send-monthly-reminder')
                 ->dailyAt('16:26')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/monthly-leave-reminder.log'));

        // On the last calendar day, remind HR managers if this month's payroll is missing or unpaid.
        $schedule->command('payroll:send-month-end-reminder')
                 ->dailyAt('09:00')
                 ->timezone('Asia/Riyadh')
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/payroll-month-end-reminder.log'));
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
