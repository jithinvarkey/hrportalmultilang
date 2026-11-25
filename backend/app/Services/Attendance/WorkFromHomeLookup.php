<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Models\EmployeeRequest;
use Carbon\Carbon;

/**
 * Work From Home requests matched to attendance days by the request's "Needed By" date (required_by).
 * Rejected and cancelled requests are ignored.
 */
class WorkFromHomeLookup
{
    public const TYPE_CODE = 'HR_WFH';

    /**
     * @param  iterable<int>  $employeeIds
     * @return array<string, EmployeeRequest>  keyed "employeeId|Y-m-d"; the latest request wins when there are several
     */
    public function byEmployeeDate(iterable $employeeIds, string $from, string $to): array
    {
        $ids = collect($employeeIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return EmployeeRequest::query()
            ->whereHas('requestType', fn($q) => $q->where('code', self::TYPE_CODE))
            ->whereIn('employee_id', $ids)
            ->whereNotNull('required_by')
            ->whereDate('required_by', '>=', $from)
            ->whereDate('required_by', '<=', $to)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->orderBy('id')
            ->get(['id', 'reference', 'employee_id', 'required_by', 'status', 'details'])
            ->keyBy(fn(EmployeeRequest $r) => $r->employee_id . '|' . Carbon::parse($r->required_by)->toDateString())
            ->all();
    }

    /** Plain-English approval stage, for exports. */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Approved',
            'pending_manager' => 'Awaiting manager approval',
            'pending', 'in_progress' => 'Awaiting HR approval',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** One-line summary for exports, e.g. "WFH REQ-2026-00020 (Approved): reason…". */
    public function describe(EmployeeRequest $r): string
    {
        $details = trim(preg_replace('/\s+/', ' ', (string) $r->details));

        return 'WFH ' . $r->reference . ' (' . $this->statusLabel((string) $r->status) . ')'
            . ($details !== '' ? ': ' . $details : '');
    }

    /** Compact payload for the attendance log API. */
    public function toArray(EmployeeRequest $r): array
    {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'status' => $r->status,
            'details' => $r->details,
        ];
    }
}
