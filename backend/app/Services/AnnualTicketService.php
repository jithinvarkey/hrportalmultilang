<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnualTicketService
{
    public function settings(): array
    {
        $values = DB::table('system_settings')->whereIn('key', [
            'saudi_annual_employee_tickets', 'non_saudi_annual_employee_tickets', 'non_saudi_max_dependent_tickets',
        ])->pluck('value', 'key');
        return [
            'saudi_employee_tickets' => (int) ($values['saudi_annual_employee_tickets'] ?? 1),
            'non_saudi_employee_tickets' => (int) ($values['non_saudi_annual_employee_tickets'] ?? 1),
            'non_saudi_max_dependents' => (int) ($values['non_saudi_max_dependent_tickets'] ?? 3),
        ];
    }

    public function updateSettings(array $data): array
    {
        $now = now();
        foreach ([
            'saudi_annual_employee_tickets' => $data['saudi_employee_tickets'],
            'non_saudi_annual_employee_tickets' => $data['non_saudi_employee_tickets'],
            'non_saudi_max_dependent_tickets' => $data['non_saudi_max_dependents'],
        ] as $key => $value) {
            DB::table('system_settings')->updateOrInsert(['key' => $key], ['value' => (string) $value, 'created_at' => $now, 'updated_at' => $now]);
        }
        return $this->settings();
    }

    public function isSaudi(Employee $employee): bool
    {
        return in_array(strtolower(trim((string) $employee->nationality)), ['saudi', 'saudi arabian', 'saudi arabia'], true);
    }

    public function options(Employee $employee, int $year): array
    {
        $isSaudi = $this->isSaudi($employee);
        $used = LeaveRequest::where('employee_id', $employee->id)->where('requires_ticket', true)
            ->where('ticket_year', $year)->whereNotIn('status', ['cancelled', 'rejected'])->exists();
        return [
            'year' => $year,
            'is_saudi' => $isSaudi,
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name],
            'dependents' => $isSaudi ? [] : $employee->dependents()->where('is_active', true)->orderBy('full_name')->get(),
            'max_dependents' => $isSaudi ? 0 : $this->settings()['non_saudi_max_dependents'],
            'already_used' => $used,
        ];
    }

    public function validateSelection(Employee $employee, bool $requiresTicket, array $dependentIds, int $year): array
    {
        if (!$requiresTicket) return [];
        $dependentIds = array_values(array_unique(array_map('intval', $dependentIds)));
        $options = $this->options($employee, $year);
        if ($options['already_used']) {
            throw ValidationException::withMessages(['requires_ticket' => "The annual ticket entitlement for {$year} has already been used."]);
        }
        if ($this->isSaudi($employee) && count($dependentIds)) {
            throw ValidationException::withMessages(['ticket_dependent_ids' => 'Dependent tickets are available only for non-Saudi employees.']);
        }
        if (count($dependentIds) > $options['max_dependents']) {
            throw ValidationException::withMessages(['ticket_dependent_ids' => "A maximum of {$options['max_dependents']} dependents can be selected."]);
        }
        $valid = $employee->dependents()->where('is_active', true)->whereIn('id', $dependentIds)->pluck('id')->all();
        if (count($valid) !== count($dependentIds)) {
            throw ValidationException::withMessages(['ticket_dependent_ids' => 'One or more selected dependents are invalid.']);
        }
        return $valid;
    }

    public function savePassengers(LeaveRequest $leave, Employee $employee, array $dependentIds): void
    {
        $leave->ticketPassengers()->create(['passenger_type' => 'employee', 'passenger_name' => $employee->full_name]);
        foreach ($employee->dependents()->whereIn('id', $dependentIds)->get() as $dependent) {
            $leave->ticketPassengers()->create(['passenger_type' => 'dependent', 'dependent_id' => $dependent->id, 'passenger_name' => $dependent->full_name]);
        }
        $leave->update(['ticket_count' => 1 + count($dependentIds)]);
    }
}
