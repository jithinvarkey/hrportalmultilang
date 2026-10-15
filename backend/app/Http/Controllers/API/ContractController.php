<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Contract;
use App\Models\Employee;
use App\Services\XlsxReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manages employee contract CRUD operations.
 *
 * Endpoints:
 *   GET    /api/v1/contracts           — paginated list with filters
 *   POST   /api/v1/contracts           — create new contract
 *   GET    /api/v1/contracts/{id}      — single contract
 *   PUT    /api/v1/contracts/{id}      — update contract
 *   DELETE /api/v1/contracts/{id}      — soft-delete
 *   POST   /api/v1/contracts/{id}/approve  — approve a draft
 *   GET    /api/v1/contracts/stats     — summary counts
 *   GET    /api/v1/employees/{id}/contracts — all contracts for one employee
 */
class ContractController extends Controller {
    private function canManageContracts(): bool {
        $user = auth()->user();

        return $user && $user->hasAnyRole([
                    'super_admin',
                    'hr_manager',
                    'hr_staff',
        ]);
    }

    // ── List ──────────────────────────────────────────────────────────────

    /**
     * Return a paginated, filterable list of contracts.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse {
        $user = auth()->user();

        // ── Role check via raw DB (no Spatie, no guard issues) ────────────────
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
        $isMgr = in_array('department_manager', $userRoles);

        $query = Contract::with(['employee.department', 'department', 'createdBy'])
                ->when(!$isHRAdmin, function ($q) use ($user, $isMgr) {
                    if ($user->employee) {
                        $q->where('employee_id', $user->employee->id);
                    }
                })
                ->when($request->status, fn($q) => $q->where('status', $request->status))
                ->when($request->type, fn($q) => $q->where('type', $request->type))
                ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
                ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
                ->when($request->expiring_soon, fn($q) => $q->expiringSoon(30))
                ->when($request->search, fn($q) => $q->where(function ($sub) use ($request) {
                            $sub->where('reference', 'like', "%{$request->search}%")
                            ->orWhere('position', 'like', "%{$request->search}%")
                            ->orWhereHas('employee', fn($e) => $e->where(
                                            DB::raw("CONCAT(first_name,' ',last_name)"),
                                            'like', "%{$request->search}%"
                            ));
                        }))
                ->orderBy($request->sort_by ?? 'created_at', $request->sort_dir ?? 'desc');

        return response()->json(
                        ContractResource::collection($query->paginate((int) ($request->per_page ?? 15)))
                                ->response()->getData(true)
        );
    }

    // ── Stats ─────────────────────────────────────────────────────────────

    /**
     * Return contract summary counts for the stat strip.
     *
     * @return JsonResponse
     */

    
    public function downloadActiveEmployeeContractsReport(Request $request, XlsxReportService $xlsxReports) {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to download this report.'], 403);
        }

        $today = now()->toDateString();

        $query = Contract::with([
                'employee.department',
                'employee.unit',
                'employee.designation',
                'department',
                'approvedBy',
            ])
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->whereHas('employee', fn($q) => $q->where('status', 'active'))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->search, function ($q) use ($request) {
                $search = "%{$request->search}%";
                $q->where(function ($sub) use ($search) {
                    $sub->where('reference', 'like', $search)
                        ->orWhere('position', 'like', $search)
                        ->orWhereHas('employee', function ($employee) use ($search) {
                            $employee->where('first_name', 'like', $search)
                                ->orWhere('last_name', 'like', $search)
                                ->orWhere('employee_code', 'like', $search)
                                ->orWhere('email', 'like', $search);
                        });
                });
            })
            ->orderBy(Employee::select('employee_code')->whereColumn('employees.id', 'employee_contracts.employee_id'))
            ->orderBy('start_date', 'desc');

        $headers = [
            'Employee ID', 'Employee Name', 'Email', 'Phone', 'Employee Department',
            'Unit', 'Designation', 'Contract Reference', 'Contract Type',
            'Contract Department', 'Contract Position', 'Contract Status',
            'Start Date', 'End Date', 'Days Remaining', 'Salary', 'Currency',
            'Approved By', 'Approved At',
        ];

        $rows = $query->get()->map(function (Contract $contract) {
            $endDate = $contract->end_date;

            return [
                $contract->employee?->employee_code,
                $contract->employee?->full_name,
                $contract->employee?->email,
                $contract->employee?->phone,
                $contract->employee?->department?->name,
                $contract->employee?->unit?->name,
                $contract->employee?->designation?->title,
                $contract->reference,
                str_replace('_', ' ', (string) $contract->type),
                $contract->department?->name,
                $contract->position,
                $contract->status,
                optional($contract->start_date)->format('Y-m-d'),
                optional($endDate)->format('Y-m-d'),
                $endDate ? now()->startOfDay()->diffInDays($endDate, false) : '',
                $contract->salary,
                $contract->currency,
                $contract->approvedBy?->name,
                optional($contract->approved_at)->format('Y-m-d H:i'),
            ];
        })->toArray();

        return $xlsxReports->download(
            'active-employee-contract-details-report-' . now()->format('Y-m-d') . '.xlsx',
            $headers,
            $rows
        );
    }

    public function stats(): JsonResponse
{
    $safe = fn(callable $fn) => rescue($fn, 0, false);

    $user = auth()->user();

    $isHR = $user->hasAnyRole([
        'super_admin',
        'hr_manager',
        'hr_staff'
    ]);

    $employeeId = $user->employee?->id;

    $baseQuery = Contract::query();

    // HR sees everything
    if ($isHR) {

        $baseQuery = Contract::query();

    }
    // Manager sees contracts for employees in own department
    
    // Employee sees only own contracts
    else {

        $baseQuery = Contract::where(
            'employee_id',
            $employeeId
        );
    }

    return response()->json([

        'total' => $safe(
            fn() => (clone $baseQuery)->count()
        ),

        'active' => $safe(
            fn() => (clone $baseQuery)
                ->where('status', 'active')
                ->count()
        ),

        'draft' => $safe(
            fn() => (clone $baseQuery)
                ->where('status', 'draft')
                ->count()
        ),

        'expiring_soon' => $safe(
            fn() => (clone $baseQuery)
                ->where('status', 'active')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [
                    now(),
                    now()->copy()->addDays(30)
                ])
                ->count()
        ),

        'expired' => $safe(
            fn() => (clone $baseQuery)
                ->where('status', 'expired')
                ->count()
        ),

        'terminated' => $safe(
            fn() => (clone $baseQuery)
                ->where('status', 'terminated')
                ->count()
        ),
    ]);
}

    // ── Create ────────────────────────────────────────────────────────────

    /**
     * Store a new contract.
     *
     * @param  Request      $request
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to manage contracts.'], 403);
        }

        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'type' => 'required|in:full_time,part_time,contract,intern,probation,fixed_term,unlimited',
            'status' => 'sometimes|in:draft,active',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'salary' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'position' => 'nullable|string|max:150',
            'department_id' => 'nullable|exists:departments,id',
            'terms'         => 'nullable|string',
            'document'      => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        // Store the optional signed/scanned contract document (â‰¤ 10 MB).
        $pdfPath = null;
        if ($request->hasFile('document')) {
            $pdfPath = $request->file('document')->store('contracts/documents', 'public');
        }

        $contract = Contract::create([
                    ...$request->only([
                        'employee_id', 'type', 'status', 'start_date', 'end_date',
                        'salary', 'currency', 'position', 'department_id', 'terms',
                    ]),
                    'reference' => Contract::generateReference(),
                    'created_by' => auth()->id(),
            'status'     => $request->status ?? 'draft',
            'pdf_path'   => $pdfPath,
        ]);

        return response()->json([
                    'message' => 'Contract created successfully.',
                    'contract' => new ContractResource($contract->load(['employee.department', 'department', 'createdBy'])),
                        ], 201);
    }

    // ── Read ──────────────────────────────────────────────────────────────

    /**
     * Return a single contract.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse {
        $contract = Contract::with(['employee.department', 'department', 'createdBy', 'approvedBy'])
                ->findOrFail($id);

        return response()->json(['contract' => new ContractResource($contract)]);
    }

    // ── Update ────────────────────────────────────────────────────────────

    /**
     * Update an existing contract.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to manage contracts.'], 403);
        }

        $contract = Contract::findOrFail($id);

        $request->validate([
            'type' => 'sometimes|in:full_time,part_time,contract,intern,probation,fixed_term,unlimited',
            'status' => 'sometimes|in:draft,active,expired,terminated,cancelled',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after:start_date',
            'salary' => 'nullable|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'position' => 'nullable|string|max:150',
            'department_id' => 'nullable|exists:departments,id',
            'terms'         => 'nullable|string',
            'document'      => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        $data = $request->only([
                    'type', 'status', 'start_date', 'end_date',
                    'salary', 'currency', 'position', 'department_id', 'terms',
        ]);

        // Replace the attached document if a new file is provided.
        if ($request->hasFile('document')) {
            if ($contract->pdf_path) {
                Storage::disk('public')->delete($contract->pdf_path);
            }
            $data['pdf_path'] = $request->file('document')->store('contracts/documents', 'public');
        }

        $contract->update($data);

        return response()->json([
                    'message' => 'Contract updated.',
                    'contract' => new ContractResource($contract->fresh(['employee.department', 'department'])),
        ]);
    }

    // ── Approve ───────────────────────────────────────────────────────────

    /**
     * Approve a draft contract (sets status → active).
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function approve(int $id): JsonResponse {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to manage contracts.'], 403);
        }

        $contract = Contract::findOrFail($id);

        if ($contract->status !== 'draft') {
            return response()->json(['message' => 'Only draft contracts can be approved.'], 422);
        }

        $contract->update([
            'status' => 'active',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
                    'message' => 'Contract approved and activated.',
                    'contract' => new ContractResource($contract->fresh(['employee', 'approvedBy'])),
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────

    /**
     * Soft-delete a contract.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to manage contracts.'], 403);
        }

        $contract = Contract::findOrFail($id);
        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }

    // ── Employee contracts ────────────────────────────────────────────────

    /**
     * Upload (or replace) the signed/scanned document for a contract.
     * Accepts PDF/DOC/DOCX up to 10 MB and stores it on the public disk.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to manage contracts.'], 403);
        }

        $request->validate([
            'document' => 'required|file|mimes:pdf,doc,docx|max:10240',
        ]);

        $contract = Contract::findOrFail($id);

        // Remove the previous file so we don't orphan storage.
        if ($contract->pdf_path) {
            Storage::disk('public')->delete($contract->pdf_path);
        }

        $path = $request->file('document')->store('contracts/documents', 'public');
        $contract->update(['pdf_path' => $path]);

        return response()->json([
            'message'  => 'Contract document uploaded.',
            'pdf_path' => $path,
            'pdf_url'  => Storage::disk('public')->url($path),
            'contract' => new ContractResource($contract->fresh(['employee.department', 'department'])),
        ], 201);
    }

    /**
     * Download the contract document.
     *
     * @param  int $id
     * @return StreamedResponse|JsonResponse
     */
    public function downloadDocument(int $id): StreamedResponse|JsonResponse
    {
        $contract = Contract::findOrFail($id);

        if (!$contract->pdf_path || !Storage::disk('public')->exists($contract->pdf_path)) {
            return response()->json(['message' => 'No document attached to this contract.'], 404);
        }

        $filename = "{$contract->reference}." . pathinfo($contract->pdf_path, PATHINFO_EXTENSION);

        return Storage::disk('public')->download($contract->pdf_path, $filename);
    }

    /**
     * Detach and delete the contract document.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function deleteDocument(int $id): JsonResponse
    {
        if (!$this->canManageContracts()) {
            return response()->json(['message' => 'You do not have permission to manage contracts.'], 403);
        }

        $contract = Contract::findOrFail($id);

        if ($contract->pdf_path) {
            Storage::disk('public')->delete($contract->pdf_path);
            $contract->update(['pdf_path' => null]);
        }

        return response()->json(['message' => 'Contract document removed.']);
    }

    // â”€â”€ Employee contracts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * List all contracts for a specific employee.
     *
     * @param  int          $empId
     * @return JsonResponse
     */
    public function forEmployee(int $empId): JsonResponse {
        $contracts = Contract::with(['department', 'createdBy', 'approvedBy'])
                ->where('employee_id', $empId)
                ->orderBy('start_date', 'desc')
                ->get();

        return response()->json([
                    'contracts' => ContractResource::collection($contracts),
        ]);
    }
}
