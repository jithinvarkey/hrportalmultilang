<?php

namespace App\Services;

use App\Mail\NewEmployeeJoinedMail;
use App\Models\Employee;
use App\Services\Communications\NotificationService;
use App\Support\Locale\RecipientLocale;
use App\Support\Locale\SupportedLocales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NewEmployeeJoiningAnnouncementService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function sendToday(): array
    {
        $today = now('Asia/Riyadh')->toDateString();
        $newEmployees = Employee::with(['department', 'designation'])
            ->whereDate('hire_date', $today)
            ->whereIn('status', ['active', 'probation'])
            ->whereNull('joining_announcement_sent_at')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($newEmployees as $newEmployee) {
            try {
                DB::transaction(function () use ($newEmployee, &$sent): void {
                    $locked = Employee::whereKey($newEmployee->id)->lockForUpdate()->first();
                    if (!$locked || $locked->joining_announcement_sent_at) {
                        return;
                    }

                    $recipientIds = $this->notifications->resolveAudience('all', null, null, true);
                    $title = 'Welcome ' . $newEmployee->full_name;
                    $body = trim(implode(' - ', array_filter([
                        $newEmployee->designation?->title,
                        $newEmployee->department?->name,
                    ]))) . ' joined the company today.';

                    $this->notifications->notifyMany(
                        $recipientIds,
                        'new_employee_joined',
                        $title,
                        $body,
                        '/org-chart',
                        $newEmployee->id
                    );

                    $recipientEmails = Employee::whereIn('id', $recipientIds)
                        ->whereNotNull('email')
                        ->where('email', '<>', '')
                        ->pluck('email')
                        ->map(fn ($email) => strtolower(trim((string) $email)))
                        ->filter()
                        ->unique()
                        ->values();

                    // One BCC message per language, so each colleague reads the announcement in their own language.
                    $byLocale = $recipientEmails->groupBy(
                        fn ($email) => RecipientLocale::forEmail($email) ?? SupportedLocales::DEFAULT
                    );
                    // A failed language group must not undo the groups already delivered: the command runs every
                    // 15 minutes, so rolling back would re-send the announcement to them all day long.
                    $delivered = 0;
                    $lastError = null;
                    foreach ($byLocale as $locale => $emails) {
                        try {
                            $emails = $emails->values();
                            $primaryEmail = $emails->shift();
                            $mail = Mail::to($primaryEmail)->locale($locale);
                            if ($emails->isNotEmpty()) {
                                $mail->bcc($emails->all());
                            }
                            $mail->send(new NewEmployeeJoinedMail($newEmployee));
                            $delivered++;
                        } catch (\Throwable $e) {
                            $lastError = $e;
                            Log::error('New employee joining announcement failed for one language group.', [
                                'employee_id' => $newEmployee->id,
                                'locale' => $locale,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    if ($delivered === 0 && $lastError) {
                        throw $lastError;
                    }

                    $locked->forceFill(['joining_announcement_sent_at' => now()])->save();
                    $sent++;
                });
            } catch (\Throwable $e) {
                $failed++;
                Log::error('New employee joining announcement failed.', [
                    'employee_id' => $newEmployee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['eligible' => $newEmployees->count(), 'sent' => $sent, 'failed' => $failed];
    }
}
