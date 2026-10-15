<?php

namespace App\Services;

use App\Mail\AnnualLeaveContractExpiryMail;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\LeaveType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;

class AnnualLeaveContractExpiryNotificationService
{

    public function sendForDate(CarbonInterface $date): array
    {
        $result = ['notified' => 0, 'failed' => 0];
        $type = LeaveType::annualPolicyType();
        if (!$type || ($type->carry_forward && $type->carry_forward_all)) {
            return $result;
        }
        $limit = $type->carry_forward ? (float) $type->max_carry_forward : 0.0;
        $expiryDate = $date->copy()->addDays(90)->toDateString();

        $contracts = Contract::query()
            ->with(['employee.manager'])
            ->active()
            ->whereDate('end_date', $expiryDate)
            ->whereNull('annual_leave_reminder_sent_at')
            ->whereHas('employee', fn ($query) => $query->active())
            ->get();

        foreach ($contracts as $contract) {
            $remainingDays = (float) $contract->employee->leaveAllocations()
                ->where('year', $date->year)
                ->whereHas('leaveType', fn ($query) => $query->where('is_annual', true))
                ->sum('remaining_days');

            if ($remainingDays <= $limit) {
                continue;
            }

            try {
                $this->notify($contract, $remainingDays, $limit);
                $result['notified']++;
            } catch (\Throwable $e) {
                report($e);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function notify(Contract $contract, float $remainingDays, float $limit): void
    {
        $employee = $contract->employee;

        if (!$employee->email) {
            throw new \RuntimeException("Employee {$employee->id} has no email address.");
        }

        $ccEmails = collect([$employee->manager?->email])
            ->merge(Employee::query()->active()->whereNotNull('email')
                ->whereHas('department', fn ($query) => $query->where('code', 'HR'))
                ->pluck('email'))
            ->filter()
            ->reject(fn ($email) => strcasecmp($email, $employee->email) === 0)
            ->unique(fn ($email) => strtolower($email))
            ->values()
            ->all();

        Mail::to($employee->email)->cc($ccEmails)->send(new AnnualLeaveContractExpiryMail(
            $employee->full_name,
            $contract->end_date->format('F j, Y'),
            $remainingDays,
            $limit,
        ));

        $contract->update(['annual_leave_reminder_sent_at' => now()]);

        activity('leave')->performedOn($contract)->event('annual_leave_contract_expiry_notified')
            ->withProperties([
                'employee_id' => $employee->id,
                'contract_end_date' => $contract->end_date->toDateString(),
                'remaining_days' => $remainingDays,
                'carry_forward_limit' => $limit,
                'cc' => $ccEmails,
            ])->log('Annual leave contract expiry reminder sent');
    }
}
