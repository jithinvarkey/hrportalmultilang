<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Policy;
use App\Services\Communications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scheduled communications housekeeping:
 *   1. Publish announcements whose scheduled_at has arrived, and notify the
 *      audience.
 *   2. Notify HR about policies whose review_date is due (re-review reminder).
 */
class ProcessCommunications extends Command
{
    protected $signature   = 'communications:process';
    protected $description = 'Publish scheduled announcements and send policy review reminders';

    public function handle(NotificationService $notifications): int
    {
        $this->publishScheduledAnnouncements($notifications);
        $this->remindPolicyReviews($notifications);
        return self::SUCCESS;
    }

    private function publishScheduledAnnouncements(NotificationService $notifications): void
    {
        $due = Announcement::where('is_published', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $a) {
            $a->update(['is_published' => true, 'published_at' => now()]);

            $ids = $notifications->resolveAudience(
                $a->audience_type ?? 'all', $a->target_department_ids, $a->target_roles, true,
                $a->target_employee_ids
            );
            $summary = Str::limit(strip_tags($a->body), 120);
            $notifications->notifyMany(
                $ids, 'announcement', $a->title,
                $summary, '/announcements', $a->id
            );
            $notifications->emailMany(
                $ids, 'announcement', $a->title,
                $a->body,
                rtrim((string) config('app.frontend_url'), '/') . '/announcements',
                $a->title_ar,
                $a->body_ar,
            );
            $this->info("Published scheduled announcement #{$a->id}: {$a->title}");
        }
    }

    private function remindPolicyReviews(NotificationService $notifications): void
    {
        // Policies due for review today or overdue.
        $due = Policy::whereNotNull('review_date')
            ->whereDate('review_date', '<=', now()->toDateString())
            ->where('is_published', true)
            ->get();

        if ($due->isEmpty()) return;

        // Notify HR managers (their employee records) about each due policy.
        $hrEmployeeIds = Employee::where('status', 'active')
            ->whereIn('user_id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereIn('roles.name', ['super_admin', 'hr_manager', 'hr_staff'])
                ->pluck('model_has_roles.model_id'))
            ->pluck('id');

        foreach ($due as $policy) {
            $notifications->notifyMany(
                $hrEmployeeIds, 'policy_review',
                "Policy review due: {$policy->title}",
                "“{$policy->title}” (v{$policy->version}) is due for review.",
                '/policies', $policy->id
            );
            $this->info("Review reminder sent for policy #{$policy->id}");
        }
    }
}
