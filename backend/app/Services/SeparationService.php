<?php
namespace App\Services;

use App\Models\Separation;
use App\Models\OffboardingTemplate;
use App\Models\OffboardingItem;
use App\Models\Employee;
use App\Models\Contract;
use App\Models\Department;
use App\Models\User;
use App\Mail\SeparationWorkflowMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SeparationService
{
    public function notifySubmitted(Separation $sep): void
    {
        $sep->loadMissing('employee.manager.user');
        $recipients = [];
        if ($sep->status === 'pending_hr') {
            foreach (User::role('hr_manager')->whereNotNull('email')->get() as $user) {
                $recipients[strtolower($user->email)] = ['email' => $user->email, 'name' => $user->name];
            }
        } else {
            $manager = $sep->employee?->manager;
            if ($manager?->user?->email || $manager?->email) {
                $email = $manager->user?->email ?: $manager->email;
                $recipients[strtolower($email)] = ['email' => $email, 'name' => $manager->full_name];
            }
        }

        $this->queueRecipients($sep, 'submitted', $recipients);
    }

    public function notifyManagerApproved(Separation $sep): void
    {
        $recipients = User::role('hr_manager')->whereNotNull('email')->get()
            ->mapWithKeys(fn ($user) => [strtolower($user->email) => ['email' => $user->email, 'name' => $user->name]])
            ->all();
        $this->queueRecipients($sep, 'manager_approved', $recipients);
    }

    public function notifyHrApproved(Separation $sep): void
    {
        $sep->loadMissing('employee.manager.user');
        $manager = $sep->employee?->manager;
        $email = $manager?->user?->email ?: $manager?->email;
        $recipients = $email
            ? [strtolower($email) => ['email' => $email, 'name' => $manager->full_name]]
            : [];
        $this->queueRecipients($sep, 'hr_approved', $recipients);
    }

    /**
     * Saudi employees always follow Manager -> HR. A non-Saudi employee on a
     * fixed-term contract is compliant when either the selected last working
     * day or the end of the 60-day notice period reaches the contract end.
     * Unlimited contracts still require the full 60-day notice period.
     */
    public function resignationRequiresHrFirst(Employee $employee, Carbon $lastWorkingDay, Carbon $requestDate): bool
    {
        $nationality = strtolower(trim((string) $employee->nationality));
        $isSaudi = in_array($nationality, ['saudi', 'saudi arabian', 'saudi arabia'], true);
        if ($isSaudi) return false;

        $requestDate = $requestDate->copy()->startOfDay();
        $lastWorkingDay = $lastWorkingDay->copy()->startOfDay();
        $noticeEndDate = $requestDate->copy()->addDays(60);
        $noticeCompliant = $requestDate->diffInDays($lastWorkingDay) >= 60;
        $contract = Contract::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $requestDate->toDateString())
            ->orderByDesc('start_date')
            ->first();

        // Missing active contracts go to HR for review. For a fixed contract,
        // either reaching its end with the chosen date or with the statutory
        // notice end is enough. Unlimited contracts have no end date to meet.
        if ($contract === null) return true;
        if ($contract->end_date === null) return !$noticeCompliant;

        $contractEndDate = $contract->end_date->copy()->startOfDay();
        $meetsContract = !$lastWorkingDay->lt($contractEndDate)
            || !$noticeEndDate->lt($contractEndDate);

        return !$meetsContract;
    }

    public function notifyOffboardingDepartments(Separation $sep): void
    {
        $sep->loadMissing('employee', 'checklistItems');
        $tasksByCategory = $sep->checklistItems->groupBy('category');

        foreach ($tasksByCategory as $category => $items) {
            $recipients = $this->departmentRecipients($sep, (string) $category);
            $this->queueRecipients(
                $sep,
                'offboarding_tasks',
                $recipients,
                $items->pluck('title')->values()->all(),
                (string) $category
            );
        }
    }

    public function notifyDepartmentTasksCompleted(Separation $sep, string $category, string $completedByName): void
    {
        $sep->loadMissing('employee', 'checklistItems');
        $items = $sep->checklistItems->where('category', $category);
        if ($items->isEmpty() || $items->contains('status', 'pending')) return;

        $recipients = $this->departmentRecipients($sep, 'hr');
        $this->queueRecipients(
            $sep,
            'department_tasks_completed',
            $recipients,
            $items->map(fn ($item) => $item->title . ' — ' . strtoupper($item->status))->values()->all(),
            $category,
            $completedByName
        );
    }

    private function departmentRecipients(Separation $sep, string $category): array
    {
        $recipients = [];
        $targetCategory = $category === 'general' ? 'hr' : $category;

        $departments = Department::with('manager.user')
            ->whereNotNull('manager_id')
            ->get()
            ->filter(fn (Department $department) => $this->categoryForDepartment($department) === $targetCategory);

        foreach ($departments as $department) {
            $manager = $department->manager;
            $email = $manager?->user?->email ?: $manager?->email;
            if (!$email) continue;
            $recipients[strtolower($email)] = [
                'email' => $email,
                'name' => $manager->full_name,
            ];
        }
        return $recipients;
    }

    private function categoryForDepartment(Department $department): string
    {
        $identity = strtolower(trim(($department?->code ?? '') . ' ' . ($department?->name ?? '')));
        if (preg_match('/\b(fin|finance|account|accounts|accounting)\b/', $identity)) return 'finance';
        if (preg_match('/\b(it|information technology|technology|cyber|systems)\b/', $identity)) return 'it';
        if (preg_match('/\b(hr|human resources|people)\b/', $identity)) return 'hr';
        if (preg_match('/\b(admin|administration|facilities)\b/', $identity)) return 'admin';
        return 'general';
    }

    private function queueRecipients(
        Separation $sep,
        string $event,
        array $recipients,
        array $tasks = [],
        ?string $category = null,
        ?string $completedByName = null
    ): void {
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient['email'])->queue(new SeparationWorkflowMail(
                    $sep,
                    $event,
                    $recipient['name'] ?: 'Colleague',
                    $tasks,
                    $category,
                    $completedByName
                ));
            } catch (\Throwable $e) {
                Log::error('Separation notification email failed.', [
                    'separation_id' => $sep->id,
                    'event' => $event,
                    'recipient' => $recipient['email'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Generate reference ────────────────────────────────────────────────
    public function generateReference(): string
    {
        $year  = now()->year;
        $count = Separation::whereYear('created_at', $year)->count() + 1;
        return 'SEP-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    // ── Calculate Saudi gratuity (Article 84 Labor Law) ───────────────────
    // - < 2 years:   0
    // - 2–5 years:   half-month per year
    // - > 5 years:   1 month per year (full)
    // For termination without cause: full month per year from day 1
    public function calculateGratuity(Employee $emp, string $type, string $lastWorkingDay): float
    {
        if (!$emp->hire_date) return 0;

        $hireDate = Carbon::parse($emp->hire_date);
        $endDate  = Carbon::parse($lastWorkingDay);
        $years    = $hireDate->floatDiffInYears($endDate);

        if ($years < 2) return 0;

        $monthlySalary = (float)($emp->salary ?? 0);
        $dailyRate     = $monthlySalary / 30;

        if ($type === 'termination') {
            // Full entitlement: 1 month per year from day 1
            return round($monthlySalary * $years, 2);
        }

        // Resignation / retirement / mutual
        if ($years <= 5) {
            return round(($monthlySalary / 2) * $years, 2);
        }
        // First 5 years: half month; beyond 5: full month
        return round((($monthlySalary / 2) * 5) + ($monthlySalary * ($years - 5)), 2);
    }

    // ── Calculate leave encashment ────────────────────────────────────────
    public function calculateLeaveEncashment(Employee $emp): float
    {
        $allocation = $emp->leaveAllocations()
            ->whereHas('leaveType', fn($q) => $q->where('code','AL'))
            ->where('year', now()->year)
            ->first();

        if (!$allocation) return 0;

        $remainingDays = max(0, (float)$allocation->remaining_days);
        $dailyRate     = ((float)($emp->salary ?? 0)) / 30;
        return round($remainingDays * $dailyRate, 2);
    }

    // ── Generate offboarding checklist from templates ─────────────────────
    public function generateChecklist(Separation $sep): void
    {
        $templates = OffboardingTemplate::where('is_active', true)->orderBy('sort_order')->get();
        $items = $templates->map(fn($t) => [
            'separation_id' => $sep->id,
            'template_id'   => $t->id,
            'title'         => $t->title,
            'category'      => $t->category,
            'is_required'   => $t->is_required,
            'status'        => 'pending',
            'sort_order'    => $t->sort_order,
            'created_at'    => now(),
            'updated_at'    => now(),
        ])->toArray();

        if (!empty($items)) OffboardingItem::insert($items);
    }

    // ── Stats ──────────────────────────────────────────────────────────────
    public function stats(): array
    {
        return [
            'pending_manager'  => Separation::where('status','pending_manager')->count(),
            'pending_hr'       => Separation::where('status','pending_hr')->count(),
            'offboarding'      => Separation::where('status','offboarding')->count(),
            'completed_ytd'    => Separation::where('status','completed')->whereYear('updated_at',now()->year)->count(),
            'by_type'          => Separation::selectRaw('type, count(*) as total')->groupBy('type')->pluck('total','type'),
        ];
    }
}
