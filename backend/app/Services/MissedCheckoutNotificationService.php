<?php

namespace App\Services;

use App\Mail\MissedCheckoutMail;
use App\Models\AppNotification;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

class MissedCheckoutNotificationService
{
    public function sendForDate(CarbonInterface $date): array
    {
        $result = ['notified' => 0, 'failed' => 0];
        $logs = AttendanceLog::with('employee')
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->whereNull('missed_checkout_notified_at')
            ->whereHas('employee', function ($query) {
                $query->where(function ($statusQuery) {
                    $statusQuery->where('status', 'active')
                        ->orWhere(function ($probationQuery) {
                            $probationQuery->where('status', 'probation')
                                ->whereRaw('LOWER(email) LIKE ?', ['%@dbroker.com.sa']);
                        });
                });
            })
            ->get();

        foreach ($logs as $log) {
            try {
                $this->notify($log);
                $result['notified']++;
            } catch (\Throwable $e) {
                report($e);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function notify(AttendanceLog $log): void
    {
        $employee = $log->employee;
        $date = $log->date->format('F j, Y');
        $checkIn = date('g:i A', strtotime($log->check_in));

        if ($employee->email) {
            $hrEmails = Employee::query()->active()->whereNotNull('email')
                ->where('id', '<>', $employee->id)
                ->whereHas('user.roles', fn ($query) => $query->where('name', 'hr_manager'))
                ->whereDoesntHave('user.roles', fn ($query) => $query->where('name', 'hr_staff'))
                ->pluck('email')->filter()->unique()->values()->all();

            Mail::to($employee->email)->cc($hrEmails)->send(new MissedCheckoutMail(
                $employee->full_name,
                $date,
                $checkIn,
            ));
        }

        AppNotification::create([
            'employee_id' => $employee->id,
            'type' => 'missed_checkout',
            'title' => 'Missing checkout',
            'body' => "No checkout was recorded for {$date}. Please contact HR to correct your attendance.",
            'link' => '/attendance',
            'ref_id' => $log->id,
        ]);

        $log->update(['missed_checkout_notified_at' => now()]);
        activity('attendance')->performedOn($log)->event('missed_checkout_notified')
            ->withProperties(['employee_id' => $employee->id, 'date' => $log->date->toDateString()])
            ->log('Missed checkout notification sent');
    }
}
