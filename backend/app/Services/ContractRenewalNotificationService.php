<?php

namespace App\Services;

use App\Mail\ContractRenewalReminderMail;
use App\Models\ContractRenewalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContractRenewalNotificationService
{
    public function notifyManagerAndHr(ContractRenewalRequest $renewal): void
    {
        $renewal->loadMissing(['employee.manager', 'contract']);

        $managerEmail = $renewal->employee?->manager?->email;
        $hrEmails = $this->hrEmails();

        $to = $managerEmail ?: ($hrEmails[0] ?? null);
        if (!$to) {
            Log::warning('[Contracts] Renewal notification skipped: no manager or HR email found', [
                'renewal_reference' => $renewal->reference,
                'employee_id' => $renewal->employee_id,
            ]);
            return;
        }

        $cc = array_values(array_diff($hrEmails, [$to]));

        try {
            Mail::to($to)->cc($cc)->send(new ContractRenewalReminderMail($renewal));
            Log::info('[Contracts] Renewal notification sent', [
                'renewal_reference' => $renewal->reference,
                'to' => $to,
                'cc_count' => count($cc),
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('[Contracts] Renewal notification failed', [
                'renewal_reference' => $renewal->reference,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function hrEmails(): array
    {
        return DB::table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', ['super_admin', 'hr_manager', 'hr_staff'])
            ->whereNotNull('users.email')
            ->pluck('users.email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
