<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractRenewalRequest;
use App\Services\ContractRenewalNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manages the 3-level contract renewal approval workflow.
 *
 * Approval chain: Manager (level 1) → HR Manager (level 2) → CEO (level 3)
 *
 * Endpoints:
 *   GET  /api/v1/contracts/renewals              — paginated list with filters
 *   GET  /api/v1/contracts/renewals/stats        — summary counts
 *   POST /api/v1/contracts/renewals              — manually create a renewal request
 *   GET  /api/v1/contracts/renewals/{id}         — single request
 *   POST /api/v1/contracts/renewals/{id}/approve — approve at current stage
 *   POST /api/v1/contracts/renewals/{id}/reject  — reject at current stage
 */
class ContractRenewalController extends Controller {
    // ── List ──────────────────────────────────────────────────────────────

    /**
     * Return a paginated list of renewal requests.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse {
        $user = auth()->user();
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
        $isMgr = in_array('department_manager', $userRoles);

        $query = ContractRenewalRequest::with([
                    'employee.department',
                    'contract',
                    'manager',
                    'managerApprovedBy',
                    'hrApprovedBy',
                    'ceoApprovedBy',
                    'rejectedBy',
                ])
                // Department managers only see their team's renewals
                ->when(!$isHRAdmin, function ($q) use ($user, $isMgr) {
                    if ($isMgr && $user->employee) {
                        $teamIds = $user->employee->subordinates()->pluck('id');
                        $q->whereIn('employee_id', $teamIds->push($user->employee->id));
                    } elseif ($user->employee) {
                        $q->where('employee_id', $user->employee->id);
                    }
                })
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
                ->when($request->search, fn($q) => $q->whereHas('employee', fn($e) =>
                        $e->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$request->search}%")
        ));

        return response()->json(
                        $query->orderBy('created_at', 'desc')
                                ->paginate((int) ($request->per_page ?? 15))
                                ->through(fn($r) => $this->formatRenewal($r))
        );
    }

    // ── Stats ─────────────────────────────────────────────────────────────

    /**
     * Return renewal request summary counts.
     *
     * @return JsonResponse
     */

    public function stats(): JsonResponse {
        $safe = fn($cb, $default = 0) => rescue($cb, $default, false);

        $user = auth()->user();

        $isHR = $user->hasAnyRole([
            'super_admin',
            'hr_manager',
            'hr_staff'
        ]);

        $isMgr = $user->hasRole('department_manager');

        $deptId = $user->employee?->department_id;
        $employeeId = $user->employee?->id;

        $baseQuery = ContractRenewalRequest::query();

        // HR sees everything
        if ($isHR) {

            $baseQuery = ContractRenewalRequest::query();
        }
        // Manager sees renewals for employees in own department
        elseif ($isMgr && $deptId) {

            $baseQuery = ContractRenewalRequest::whereHas(
                            'contract.employee',
                            function ($q) use ($deptId) {
                                $q->where('department_id', $deptId);
                            }
            );
        }
        // Employee sees only own renewals
        else {

            $baseQuery = ContractRenewalRequest::whereHas(
                            'contract',
                            function ($q) use ($employeeId) {
                                $q->where('employee_id', $employeeId);
                            }
            );
        }

        return response()->json([
                    'total' => $safe(
                            fn() => (clone $baseQuery)->count()
                    ),
                    'pending' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'pending')
                                    ->count()
                    ),
                    'manager_approved' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'manager_approved')
                                    ->count()
                    ),
                    'hr_approved' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'hr_approved')
                                    ->count()
                    ),
                    'approved' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'approved')
                                    ->count()
                    ),
                    'rejected' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'rejected')
                                    ->count()
                    ),
        ]);
    }

    // ── Create (manual) ───────────────────────────────────────────────────

    /**
     * Manually create a renewal request for a contract.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse {
        $request->validate([
            'contract_id' => 'required|exists:employee_contracts,id',
            'proposed_start_date' => 'required|date',
            'proposed_end_date' => 'nullable|date|after:proposed_start_date',
            'proposed_salary' => 'nullable|numeric|min:0',
            'proposed_type' => 'nullable|in:full_time,part_time,contract,intern,probation,fixed_term,unlimited',
            'notes' => 'nullable|string|max:1000',
            'document' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        $contract = Contract::with('employee.manager')->findOrFail($request->contract_id);

        // Prevent duplicate open requests
        $existing = ContractRenewalRequest::where('contract_id', $contract->id)
                ->whereNotIn('status', ['rejected', 'cancelled'])
                ->exists();

        if ($existing) {
            return response()->json(['message' => 'An open renewal request already exists for this contract.'], 422);
        }

        $docData = [];
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $docData = [
                'document_path' => $file->store('contracts/renewals', 'public'),
                'document_name' => $file->getClientOriginalName(),
                'document_mime' => $file->getMimeType(),
                'document_size' => $file->getSize(),
            ];
        }

        $renewal = ContractRenewalRequest::create(array_merge([
                    'contract_id' => $contract->id,
                    'employee_id' => $contract->employee_id,
                    'reference' => ContractRenewalRequest::generateReference(),
                    'status' => 'pending',
                    'proposed_start_date' => $request->proposed_start_date,
                    'proposed_end_date' => $request->proposed_end_date,
                    'proposed_salary' => $request->proposed_salary ?? $contract->salary,
                    'proposed_type' => $request->proposed_type ?? $contract->type,
                    'manager_id' => optional($contract->employee)->manager_id,
                    'auto_generated' => false,
                    'notified_at' => now(),
                    'notes' => $request->notes,
        ], $docData));

        app(ContractRenewalNotificationService::class)->notifyManagerAndHr($renewal);

        return response()->json([
                    'message' => 'Renewal request created.',
                    'renewal' => $this->formatRenewal($renewal->load(['employee', 'contract'])),
                        ], 201);
    }

    // ── Show ──────────────────────────────────────────────────────────────

    /**
     * Return a single renewal request.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse {
        $renewal = ContractRenewalRequest::with([
                    'employee.department', 'contract', 'manager',
                    'managerApprovedBy', 'hrApprovedBy', 'ceoApprovedBy', 'rejectedBy', 'newContract',
                ])->findOrFail($id);

        return response()->json(['renewal' => $this->formatRenewal($renewal)]);
    }

    // ── Approve ───────────────────────────────────────────────────────────

    /**
     * Approve the renewal request at the current stage.
     *
     * Stage routing:
     *   pending          → manager_approved  (requires: department_manager or hr_manager or super_admin)
     *   manager_approved → hr_approved       (requires: hr_manager or hr_staff or super_admin)
     *   hr_approved      → approved          (requires: ceo or super_admin)
     *
     * When fully approved, a new contract is automatically created.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     */
    public function approve(Request $request, int $id): JsonResponse {
        $renewal = ContractRenewalRequest::with('contract.employee')->findOrFail($id);
        $user = auth()->user();

        $request->validate(['notes' => 'nullable|string|max:1000']);
        $notes = $request->notes ?? null;

        switch ($renewal->status) {
            case 'pending':
                if (!$user->hasAnyRole(['department_manager', 'hr_manager', 'hr_staff', 'super_admin'])) {
                    return response()->json(['message' => 'Only managers can approve at this stage.'], 403);
                }
                $renewal->update([
                    'status' => 'manager_approved',
                    'manager_approved_by' => $user->id,
                    'manager_approved_at' => now(),
                    'manager_notes' => $notes,
                ]);
                $message = 'Approved at manager level. Awaiting HR approval.';
                break;

            case 'manager_approved':
                if (!$user->hasAnyRole(['hr_manager', 'hr_staff', 'super_admin'])) {
                    return response()->json(['message' => 'Only HR can approve at this stage.'], 403);
                }
                $renewal->update([
                    'status' => 'hr_approved',
                    'hr_approved_by' => $user->id,
                    'hr_approved_at' => now(),
                    'hr_notes' => $notes,
                ]);
                $message = 'Approved by HR. Awaiting CEO approval.';
                break;

            case 'hr_approved':
                if (!$user->hasAnyRole(['ceo', 'super_admin'])) {
                    return response()->json(['message' => 'Only the CEO can give final approval.'], 403);
                }
                // Final approval — create the new contract
                $newContract = $this->createRenewedContract($renewal);
                $renewal->update([
                    'status' => 'approved',
                    'ceo_approved_by' => $user->id,
                    'ceo_approved_at' => now(),
                    'ceo_notes' => $notes,
                    'new_contract_id' => $newContract->id,
                ]);
                $message = 'Final approval granted by CEO. New contract has been created automatically.';
                break;

            default:
                return response()->json(['message' => "Cannot approve a request with status '{$renewal->status}'."], 422);
        }

        return response()->json([
                    'message' => $message,
                    'renewal' => $this->formatRenewal($renewal->fresh([
                                'employee', 'contract', 'managerApprovedBy', 'hrApprovedBy', 'ceoApprovedBy', 'newContract',
                    ])),
        ]);
    }

    // ── Reject ────────────────────────────────────────────────────────────

    /**
     * Reject the renewal request at the current stage.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     */
    public function reject(Request $request, int $id): JsonResponse {
        $renewal = ContractRenewalRequest::findOrFail($id);
        $user = auth()->user();

        $request->validate(['reason' => 'required|string|max:1000']);

        if (!in_array($renewal->status, ['pending', 'manager_approved', 'hr_approved'])) {
            return response()->json(['message' => "Cannot reject a request with status '{$renewal->status}'."], 422);
        }

        $canReject = match ($renewal->status) {
            'pending' => $user->hasAnyRole(['department_manager', 'hr_manager', 'hr_staff', 'super_admin']),
            'manager_approved' => $user->hasAnyRole(['hr_manager', 'hr_staff', 'super_admin']),
            'hr_approved' => $user->hasAnyRole(['ceo', 'super_admin']),
            default => false,
        };

        if (!$canReject) {
            return response()->json(['message' => 'You are not authorized to reject at this approval stage.'], 403);
        }

        $stage = $renewal->current_stage;

        $renewal->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejected_stage' => $stage,
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
                    'message' => "Renewal request rejected at {$stage} stage.",
                    'renewal' => $this->formatRenewal($renewal->fresh(['employee', 'contract', 'rejectedBy'])),
        ]);
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    /**
     * Create a new contract from the approved renewal terms.
     *
     * @param  ContractRenewalRequest $renewal
     * @return Contract
     */
    private function createRenewedContract(ContractRenewalRequest $renewal): Contract {
        $original = $renewal->contract;

        return Contract::create([
                    'employee_id' => $renewal->employee_id,
                    'reference' => Contract::generateReference(),
                    'type' => $renewal->proposed_type ?? $original->type,
                    'status' => 'active',
                    'start_date' => $renewal->proposed_start_date,
                    'end_date' => $renewal->proposed_end_date,
                    'salary' => $renewal->proposed_salary ?? $original->salary,
                    'currency' => $original->currency,
                    'position' => $original->position,
                    'department_id' => $original->department_id,
                    'terms' => "Renewed from {$original->reference} via approval {$renewal->reference}.",
                    'created_by' => auth()->id(),
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
        ]);
    }

    /**
     * Upload (or replace) the supporting document for a renewal request.
     * Accepts PDF/DOC/DOCX up to 10 MB, stored on the public disk.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function uploadDocument(Request $request, int $id): JsonResponse {
        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx|max:10240',
        ]);

        $renewal = ContractRenewalRequest::findOrFail($id);

        if ($renewal->document_path) {
            Storage::disk('public')->delete($renewal->document_path);
        }

        $file = $request->file('document');
        $renewal->update([
            'document_path' => $file->store('contracts/renewals', 'public'),
            'document_name' => $file->getClientOriginalName(),
            'document_mime' => $file->getMimeType(),
            'document_size' => $file->getSize(),
        ]);

        return response()->json([
                    'message' => 'Renewal document uploaded.',
                    'renewal' => $this->formatRenewal($renewal->fresh(['employee', 'contract'])),
                        ], 201);
    }

    /**
     * Download the renewal request's supporting document.
     *
     * @param  int $id
     * @return StreamedResponse|JsonResponse
     */
    public function downloadDocument(int $id): StreamedResponse|JsonResponse {
        $renewal = ContractRenewalRequest::findOrFail($id);

        if (!$renewal->document_path || !Storage::disk('public')->exists($renewal->document_path)) {
            return response()->json(['message' => 'No document attached to this renewal request.'], 404);
        }

        return Storage::disk('public')->download(
                        $renewal->document_path,
                        $renewal->document_name ?: "{$renewal->reference}.pdf"
        );
    }

    /**
     * Detach and delete the renewal request's document.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function deleteDocument(int $id): JsonResponse {
        $renewal = ContractRenewalRequest::findOrFail($id);

        if ($renewal->document_path) {
            Storage::disk('public')->delete($renewal->document_path);
            $renewal->update([
                'document_path' => null,
                'document_name' => null,
                'document_mime' => null,
                'document_size' => null,
            ]);
        }

        return response()->json(['message' => 'Renewal document removed.']);
    }

    /**
     * Format a renewal request for the API response.
     *
     * @param  ContractRenewalRequest $r
     * @return array<string, mixed>
     */
    private function formatRenewal(ContractRenewalRequest $r): array {
        return [
            'id' => $r->id,
            'reference' => $r->reference,
            'status' => $r->status,
            'current_stage' => $r->current_stage,
            'progress' => $r->progress,
            'auto_generated' => $r->auto_generated,
            'notes' => $r->notes,
            'proposed_start_date' => $r->proposed_start_date?->toDateString(),
            'proposed_end_date' => $r->proposed_end_date?->toDateString(),
            'proposed_salary' => $r->proposed_salary,
            'proposed_type' => $r->proposed_type,
            'created_at' => $r->created_at?->toDateTimeString(),
            'notified_at' => $r->notified_at?->toDateTimeString(),
            'document' => $r->document_path ? [
        'name' => $r->document_name,
        'mime' => $r->document_mime,
        'size' => $r->document_size,
        'url' => Storage::disk('public')->url($r->document_path),
            ] : null,
            'employee' => $r->employee ? [
        'id' => $r->employee->id,
        'full_name' => $r->employee->full_name,
        'code' => $r->employee->employee_code,
        'department' => $r->employee->department?->name,
            ] : null,
            'contract' => $r->contract ? [
        'id' => $r->contract->id,
        'reference' => $r->contract->reference,
        'end_date' => $r->contract->end_date?->toDateString(),
        'type' => $r->contract->type,
        'salary' => $r->contract->salary,
            ] : null,
            'approvals' => [
                'manager' => [
                    'approved' => (bool) $r->manager_approved_at,
                    'approved_by' => $r->managerApprovedBy?->name,
                    'approved_at' => $r->manager_approved_at?->toDateTimeString(),
                    'notes' => $r->manager_notes,
                ],
                'hr' => [
                    'approved' => (bool) $r->hr_approved_at,
                    'approved_by' => $r->hrApprovedBy?->name,
                    'approved_at' => $r->hr_approved_at?->toDateTimeString(),
                    'notes' => $r->hr_notes,
                ],
                'ceo' => [
                    'approved' => (bool) $r->ceo_approved_at,
                    'approved_by' => $r->ceoApprovedBy?->name,
                    'approved_at' => $r->ceo_approved_at?->toDateTimeString(),
                    'notes' => $r->ceo_notes,
                ],
            ],
            'rejection' => $r->status === 'rejected' ? [
        'stage' => $r->rejected_stage,
        'reason' => $r->rejection_reason,
        'by' => $r->rejectedBy?->name,
        'at' => $r->rejected_at?->toDateTimeString(),
            ] : null,
            'new_contract_id' => $r->new_contract_id,
        ];
    }
}
