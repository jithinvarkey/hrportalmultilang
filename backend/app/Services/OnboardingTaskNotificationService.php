<?php

namespace App\Services;

use App\Mail\OnboardingTasksAssignedMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingTask;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OnboardingTaskNotificationService
{
    /**
     * Email each responsible department one summary of its new onboarding tasks.
     * A missing or failing recipient never prevents the employee from being created.
     *
     * @param Collection<int,OnboardingTask>|null $tasks
     * @return array<string,array{sent:bool,recipients:array,error:?string}>
     */
    public function notifyResponsibleDepartments(Employee $employee, ?Collection $tasks = null): array
    {
        $employee->loadMissing(['department.manager.user', 'designation', 'manager']);
        $tasks ??= $employee->onboardingTasks()->where('status', 'pending')->get();

        $groups = [
            'IT' => $tasks->where('category', 'it_setup')->values(),
            'HR' => $tasks->whereIn('category', ['hr_documents', 'training'])->values(),
            'Department' => $tasks->whereIn('category', ['introduction', 'probation'])->values(),
        ];

        $results = [];
        foreach ($groups as $group => $groupTasks) {
            if ($groupTasks->isEmpty()) {
                continue;
            }

            $recipients = $this->recipientsFor($group, $employee);
            if ($recipients === []) {
                Log::warning('Onboarding task notification skipped: recipient is not configured.', [
                    'employee_id' => $employee->id,
                    'recipient_group' => $group,
                    'task_ids' => $groupTasks->pluck('id')->all(),
                ]);
                $results[$group] = ['sent' => false, 'recipients' => [], 'error' => 'Recipient is not configured.'];
                continue;
            }

            try {
                Mail::to($recipients)->send(new OnboardingTasksAssignedMail($employee, $groupTasks, $group));
                Log::info('Onboarding task notification sent.', [
                    'employee_id' => $employee->id,
                    'recipient_group' => $group,
                    'recipients' => $recipients,
                    'task_ids' => $groupTasks->pluck('id')->all(),
                ]);
                $results[$group] = ['sent' => true, 'recipients' => $recipients, 'error' => null];
            } catch (\Throwable $e) {
                Log::warning('Onboarding task notification failed.', [
                    'employee_id' => $employee->id,
                    'recipient_group' => $group,
                    'recipients' => $recipients,
                    'error' => $e->getMessage(),
                ]);
                $results[$group] = ['sent' => false, 'recipients' => $recipients, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    private function recipientsFor(string $group, Employee $employee): array
    {
        if ($group === 'IT') {
            $department = Department::query()
                ->where('code', 'IT')
                ->where('is_active', true)
                ->with('manager.user')
                ->first();

            return $this->clean([$department?->manager?->user?->email ?: $department?->manager?->email]);
        }

        if ($group === 'HR') {
            return $this->clean(User::query()
                ->whereHas('roles', fn ($query) => $query->whereIn('name', ['hr_manager', 'hr_staff']))
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
                ->pluck('email')
                ->all());
        }

        return $this->clean([
            $employee->department?->manager?->user?->email
                ?: $employee->department?->manager?->email
                ?: $employee->manager?->user?->email
                ?: $employee->manager?->email,
        ]);
    }

    private function clean(array $emails): array
    {
        return collect($emails)
            ->filter()
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }
}
