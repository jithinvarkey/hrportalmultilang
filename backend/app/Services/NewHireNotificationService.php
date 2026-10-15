<?php

namespace App\Services;

use App\Mail\NewHireItNotificationMail;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NewHireNotificationService
{
    public function notifyItManagers(Employee $employee): array
    {
        $employee->loadMissing(['department', 'designation', 'manager']);

        $itDepartment = Department::query()
            ->where('code', 'IT')
            ->where('is_active', true)
            ->with('manager.user')
            ->first();

        $managerEmail = $itDepartment?->manager?->user?->email
            ?: $itDepartment?->manager?->email;
        $recipients = $managerEmail
            ? [strtolower(trim((string) $managerEmail))]
            : [];

        if (empty($recipients)) {
            Log::warning('New hire IT notification skipped: IT department manager email is missing.', [
                'employee_id' => $employee->id,
                'department_id' => $itDepartment?->id,
            ]);

            return [
                'sent' => false,
                'recipients' => [],
                'error' => 'The IT department manager email is not configured.',
            ];
        }

        try {
            Mail::to($recipients)->send(new NewHireItNotificationMail($employee));

            Log::info('New hire IT notification sent.', [
                'employee_id' => $employee->id,
                'recipients' => $recipients,
            ]);

            return [
                'sent' => true,
                'recipients' => $recipients,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('New hire IT notification failed.', [
                'employee_id' => $employee->id,
                'recipients' => $recipients,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'recipients' => $recipients,
                'error' => $e->getMessage(),
            ];
        }
    }
}
