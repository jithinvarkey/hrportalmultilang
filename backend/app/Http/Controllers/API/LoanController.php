<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\LoanApprovedMail;
use App\Mail\LoanRejectedMail;
use App\Mail\LoanRequestSubmittedMail;
use App\Mail\LoanInstallmentSkippedMail;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\LoanType;
use App\Models\User;
use App\Services\LoanService;
use App\Services\LoanApprovalService;
use App\Services\RequestActivityService;
use App\Services\XlsxReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Manages the full loan lifecycle: application, 3-stage approval,
 * disbursement, installment tracking, and closure.
 *
 * IMPORTANT: All role checks use raw DB queries to bypass the Spatie
 * Permission guard mismatch that occurs with Sanctum authentication.
 * Never use `$user->hasRole()` or `$user->hasAnyRole()` in this codebase.
 */
class LoanController extends Controller {

    /**
     * @param  LoanService $service  Injected by the service container
     */
    protected $service;
    protected $activityService;
    protected $approvalService;

    public function __construct(LoanService $service, RequestActivityService $activityService, LoanApprovalService $approvalService, protected XlsxReportService $xlsxReports) {
        $this->service = $service;
        $this->activityService = $activityService;
        $this->approvalService = $approvalService;
        
    }

    private function logLoanActivity(Loan $loan, string $event, string $description, array $properties = []): void {
        $this->activityService->record($loan, 'loan_request', $event, $description, $properties);
    }

    private function notifyLoanApprovers(Loan $loan): void
    {
        try {
            $loan->loadMissing(['employee.manager.user', 'loanType']);
            $recipients = collect();

            if ($loan->status === 'pending_manager' && $loan->employee?->manager?->user) {
                $recipients->push($loan->employee->manager->user);
            }

            $roles = match ($loan->status) {
                'pending_manager' => ['super_admin'],
                'pending_hr' => ['super_admin', 'hr_manager'],
                'pending_finance' => ['super_admin', 'finance_manager'],
                default => [],
            };

            if ($roles) {
                $recipients = $recipients->merge(
                    User::whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->get()
                );
            }

            foreach ($recipients->filter(fn ($user) => $user->email)->unique('id') as $recipient) {
                Mail::to($recipient->email)->queue(new LoanRequestSubmittedMail(
                    $loan,
                    $recipient->name
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Loan approver email failed: '.$e->getMessage());
        }
    }

    private function notifyLoanEmployee(Loan $loan, string $action): void
    {
        try {
            $loan->loadMissing(['employee', 'loanType']);
            $employee = $loan->employee;

            if (! $employee?->email) {
                return;
            }

            $mail = $action === 'approved'
                ? new LoanApprovedMail($loan, $employee->first_name ?: $employee->full_name)
                : new LoanRejectedMail($loan, $employee->first_name ?: $employee->full_name);

            Mail::to($employee->email)->queue($mail);
        } catch (\Throwable $e) {
            Log::warning("Loan {$action} email failed: ".$e->getMessage());
        }
    }

    private function notifyInstallmentSkipped(
        Loan $loan,
        LoanInstallment $skippedInstallment,
        LoanInstallment $replacementInstallment
    ): void {
        try {
            $loan->loadMissing(['employee', 'loanType']);
            $recipients = User::whereHas('roles', fn ($query) =>
                $query->whereIn('name', ['hr_manager', 'finance_manager'])
            )->whereNotNull('email')->get()->unique('id');

            foreach ($recipients as $recipient) {
                Mail::to($recipient->email)->queue(new LoanInstallmentSkippedMail(
                    $loan,
                    $skippedInstallment,
                    $replacementInstallment,
                    $recipient->name,
                    auth()->user()->name
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Skipped installment notification failed: '.$e->getMessage());
        }
    }

    // ── Role helper ───────────────────────────────────────────────────────

    /**
     * Fetch the authenticated user's role names directly from the database,
     * bypassing Spatie's guard resolution which silently returns false when
     * the Sanctum guard does not match Spatie's 'web' guard.
     *
     * @return string[]
     */
    private function userRoles(): array {
        $roles = DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', auth()->id())
                        ->where('model_has_roles.model_type', get_class(auth()->user()))
                        ->pluck('roles.name')
                        ->toArray();

        if (in_array('ceo', $roles, true) && !in_array('hr_manager', $roles, true)) {
            $roles[] = 'hr_manager';
        }

        return $roles;
    }

    /**
     * Return true if the user has at least one of the given roles.
     *
     * @param  string[] $roles
     * @return bool
     */
    private function hasAnyRoleDB(array $roles): bool {
        return (bool) array_intersect($this->userRoles(), $roles);
    }

    private function approvalLevels(): int {
        return $this->approvalService->levels();
    }

    private function canManageLoanTypes(): bool {
        return $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']);
    }

    private function canApproveStage(string $status): bool {
        if ($this->hasAnyRoleDB(['super_admin'])) {
            return true;
        }

        if ($status === 'pending_manager') {
            if ($this->approvalLevels() === 2) {
                return $this->hasAnyRoleDB(['hr_manager']);
            }

            return $this->hasAnyRoleDB(['department_manager']);
        }

        if ($status === 'pending_hr') {
            return $this->hasAnyRoleDB(['hr_manager']);
        }

        if ($status === 'pending_finance') {
            return $this->hasAnyRoleDB(['finance_manager']);
        }

        return false;
    }

    private function isOwnLoan(Loan $loan, $user): bool {
        if ($user->employee && (int) $loan->employee_id === (int) $user->employee->id) {
            return true;
        }

        $loan->loadMissing('employee');
        return $loan->employee && (int) $loan->employee->user_id === (int) $user->id;
    }

    private function shouldLimitApprovalViewsToActiveEmployees(array $userRoles): bool {
        return !in_array('super_admin', $userRoles, true)
            && (in_array('department_manager', $userRoles, true) || in_array('hr_manager', $userRoles, true));
    }

    // ── Loan Types ────────────────────────────────────────────────────────

    /**
     * Return active loan types available for new applications.
     *
     * @return JsonResponse
     */
    public function types(): JsonResponse {
        return response()->json(['types' => LoanType::where('is_active', true)->get()]);
    }

    /**
     * Return all loan types including inactive (for admin management).
     *
     * @return JsonResponse
     */
    public function allTypes(): JsonResponse {
        return response()->json(['types' => LoanType::orderBy('name')->get()]);
    }

    /**
     * Create a new loan type.
     *
     * @param  Request      $request
     * @return JsonResponse           201 with created type
     */
    public function storeType(Request $request): JsonResponse {
        if (!$this->canManageLoanTypes()) {
            return response()->json(['message' => 'You do not have permission to manage loan types.'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:loan_types',
            'max_amount' => 'required|numeric|min:0',
            'max_installments' => 'required|integer|min:1|max:120',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'requires_guarantor' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string',
        ]);

        return response()->json(['type' => LoanType::create($validated)], 201);
    }

    /**
     * Update an existing loan type.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     */
    public function updateType(Request $request, int $id): JsonResponse {
        if (!$this->canManageLoanTypes()) {
            return response()->json(['message' => 'You do not have permission to manage loan types.'], 403);
        }

        $type = LoanType::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => ['sometimes', 'string', 'max:20', Rule::unique('loan_types')->ignore($type->id)],
            'max_amount' => 'sometimes|numeric|min:0',
            'max_installments' => 'sometimes|integer|min:1|max:120',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'requires_guarantor' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string',
        ]);

        $type->update($validated);

        return response()->json(['type' => $type]);
    }

    // ── Stats ─────────────────────────────────────────────────────────────

    /**
     * Return loan summary statistics.
     *
     * @return JsonResponse
     */
    public function stats(): JsonResponse {
        return response()->json(array_merge($this->service->stats(), [
            'approval_levels' => $this->approvalLevels(),
        ]));
    }

    // ── List Loans ────────────────────────────────────────────────────────

    /**
     * Return a paginated list of loans, scoped by the caller's role.
     *
     * FIX: Previously used `$user->hasRole()` which silently returns false
     * under Sanctum.  Now uses raw DB role lookup via `userRoles()`.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse {
        $perPage = min(max((int) $request->input('per_page', 10), 10), 100);
        $user = auth()->user();
        // FIX: Use raw DB query instead of Spatie hasRole() to avoid guard mismatch
        $userRoles = $this->userRoles();
        $isAdmin = $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'finance_manager']);
        $isMgr = $this->hasAnyRoleDB(['department_manager']);

        $query = Loan::with(['employee.department', 'loanType'])
                ->when(!$isAdmin, function ($q) use ($user, $isMgr) {
                    if ($isMgr && $user->employee) {
                        $teamIds = $user->employee->subordinates()->pluck('id');
                        $q->whereIn('employee_id', $teamIds);
                    } elseif ($user->employee) {
                        $q->where('employee_id', $user->employee->id);
                    }
                })
                ->when($request->status, function ($q, string $status) {
                    if ($status === 'pending_hr' && $this->approvalLevels() === 2) {
                        return $q->whereIn('status', ['pending_hr', 'pending_manager']);
                    }

                    return $q->where('status', $status);
                })
                ->when(
                    $request->status === 'pending_manager' && $this->shouldLimitApprovalViewsToActiveEmployees($userRoles),
                    fn($q) => $q->whereHas('employee', fn($employee) => $employee->whereIn('status', ['active', 'probation']))
                )
                ->when($request->loan_type_id, fn($q) => $q->where('loan_type_id', $request->loan_type_id))
                ->when($request->search, fn($q) =>
                        $q->whereHas('employee', fn($eq) =>
                                $eq->where('first_name', 'like', "%{$request->search}%")
                                ->orWhere('last_name', 'like', "%{$request->search}%")
                                ->orWhere('employee_code', 'like', "%{$request->search}%")
                        )
                )
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc');

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform(function (Loan $loan) use ($user) {
            $isOwn = $this->isOwnLoan($loan, $user);
            $loan->setAttribute('can_approve', !$isOwn);
            $loan->setAttribute('can_reject', !$isOwn);
            return $loan;
        });

        return response()->json($paginated);
    }

    public function downloadDetailsReport(Request $request) {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff', 'finance_manager'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = Loan::with(['employee.department', 'loanType', 'installments'])
            ->when($request->status, function ($q, string $status) {
                if ($status === 'pending_hr' && $this->approvalLevels() === 2) {
                    return $q->whereIn('status', ['pending_hr', 'pending_manager']);
                }

                return $q->where('status', $status);
            })
            ->when($request->loan_type_id, fn($q) => $q->where('loan_type_id', $request->loan_type_id))
            ->when($request->search, fn($q) =>
                $q->whereHas('employee', fn($eq) =>
                    $eq->where('first_name', 'like', "%{$request->search}%")
                        ->orWhere('last_name', 'like', "%{$request->search}%")
                        ->orWhere('employee_code', 'like', "%{$request->search}%")
                        ->orWhere('email', 'like', "%{$request->search}%")
                )
            )
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        $headers = [
            'Reference', 'Employee ID', 'Employee Name', 'Department', 'Loan Type',
            'Requested Amount', 'Approved Amount', 'Monthly Installment',
            'Installments', 'Paid Installments', 'Skipped Installments', 'Total Paid',
            'Outstanding Balance', 'Status', 'Purpose', 'Requested Date',
            'Manager Approved At', 'HR Approved At', 'Finance Approved At',
            'Disbursed Date', 'First Installment Date', 'Rejected Reason',
        ];

        $rows = $query->get()->map(function (Loan $loan) {
            return [
                $loan->reference,
                $loan->employee?->employee_code,
                $loan->employee?->full_name,
                $loan->employee?->department?->name,
                $loan->loanType?->name,
                $loan->requested_amount,
                $loan->approved_amount,
                $loan->monthly_installment,
                $loan->getAttribute('installments'),
                $loan->installments_paid,
                $loan->installments_skipped,
                $loan->total_paid,
                $loan->balance_remaining,
                str_replace('_', ' ', (string) $loan->status),
                $loan->purpose,
                optional($loan->created_at)->format('Y-m-d'),
                optional($loan->manager_approved_at)->format('Y-m-d H:i'),
                optional($loan->hr_approved_at)->format('Y-m-d H:i'),
                optional($loan->finance_approved_at)->format('Y-m-d H:i'),
                optional($loan->disbursed_date)->format('Y-m-d'),
                optional($loan->first_installment_date)->format('Y-m-d'),
                $loan->rejection_reason,
            ];
        })->toArray();

        return $this->xlsxReports->download(
            'loan-details-report-' . now()->format('Y-m-d') . '.xlsx',
            $headers,
            $rows
        );
    }

    // ── Create Loan Request ───────────────────────────────────────────────

    /**
     * Submit a new loan application.
     *
     * @param  Request      $request
     * @return JsonResponse           201 with created loan
     */
    public function store(Request $request): JsonResponse {
        $request->validate([
            'loan_type_id' => 'required|exists:loan_types,id',
            'requested_amount' => 'required|numeric|min:100',
            'installments' => 'required|integer|min:1|max:120',
            'purpose' => 'required|string|min:10',
            'notes' => 'nullable|string|max:1000',
        ]);

        $employee = auth()->user()->employee;
        $loanType = LoanType::findOrFail($request->loan_type_id);

        if ($loanType->max_amount > 0 && $request->requested_amount > $loanType->max_amount) {
            return response()->json([
                        'message' => "Amount exceeds maximum allowed ({$loanType->max_amount} SAR) for this loan type.",
                            ], 422);
        }

        if ($request->installments > $loanType->max_installments) {
            return response()->json([
                        'message' => "Maximum installments for this loan type is {$loanType->max_installments}.",
                            ], 422);
        }

        $active = Loan::where('employee_id', $employee->id)
                ->where('loan_type_id', $request->loan_type_id)
                ->whereIn('status', ['pending_manager', 'pending_hr', 'pending_finance', 'approved', 'disbursed'])
                ->exists();

        if ($active) {
            return response()->json(['message' => 'You already have an active loan of this type.'], 422);
        }

        $monthly = $this->service->calculateMonthlyInstallment((float) $request->requested_amount, (int) $request->installments, (float) ($loanType->interest_rate ?? 0));

        $loan = Loan::create([
                    'reference' => $this->service->generateReference(),
                    'employee_id' => $employee->id,
                    'loan_type_id' => $request->loan_type_id,
                    'requested_amount' => $request->requested_amount,
                    'installments' => $request->installments,
                    'monthly_installment' => $monthly,
                    'purpose' => $request->purpose,
                    'notes' => $request->notes,
                    'status' => $this->approvalLevels() === 3 ? 'pending_manager' : 'pending_hr',
        ]);

        $this->logLoanActivity($loan, 'submitted', 'Loan request submitted.', [
            'to_status' => $loan->status,
            'requested_amount' => $loan->requested_amount,
            'installments' => $loan->installments,
            'notes' => $request->purpose,
        ]);
        $this->notifyLoanApprovers($loan);

        return response()->json(['message' => 'Loan request submitted.', 'loan' => $loan->load('loanType')], 201);
    }

    // ── Show single loan ──────────────────────────────────────────────────

    /**
     * Return a single loan with full detail including installment schedule.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse {
        $loan = Loan::with([
                    'employee.department', 'loanType',
                    'installments.processedBy',
                    'managerApprover', 'hrApprover', 'financeApprover', 'rejectedBy',
                ])->findOrFail($id);

        $data = $loan->toArray();
        $data['installment_schedule'] = $data['installments'];
        $data['installments'] = $loan->getRawOriginal('installments');
        $data['activities'] = $this->activityService->timeline($loan);
        $data['approval_levels'] = $this->approvalLevels();
        $data['can_approve'] = !$this->isOwnLoan($loan, auth()->user());
        $data['can_reject'] = $data['can_approve'];
        $data['can_delete'] = $this->isOwnLoan($loan, auth()->user()) && $loan->canDeleteRequest();

        return response()->json(['loan' => $data]);
    }

    // ── Approve ───────────────────────────────────────────────────────────

    /**
     * Advance a loan through its approval stages.
     *
     * Stage flow: pending_manager → pending_hr → pending_finance → approved
     * Finance approval triggers installment schedule generation.
     *
     * @param  Request      $request
     * @param  int          $id
     * @return JsonResponse
     */
    public function approve(Request $request, int $id): JsonResponse {
        return DB::transaction(fn () => $this->approveLocked($request, $id));
    }

    private function approveLocked(Request $request, int $id): JsonResponse {
        $loan = Loan::lockForUpdate()->findOrFail($id);
        $user = auth()->user();

        if (!$this->canApproveStage($loan->status)) {
            return response()->json(['message' => 'You are not authorized to approve this loan at its current stage.'], 403);
        }

        if ($this->isOwnLoan($loan, $user)) {
            return response()->json(['message' => 'You cannot approve your own loan request.'], 403);
        }

        switch ($loan->status) {
            case 'pending_manager':
                $oldStatus = $loan->status;

                if ($this->approvalLevels() === 2) {
                    $loan->update([
                        'status' => 'pending_finance',
                        'hr_approved_by' => $user->id,
                        'hr_approved_at' => now(),
                    ]);
                    $this->logLoanActivity($loan, 'hr_approved', 'Loan request approved by HR; manager approval was skipped by configuration.', [
                        'from_status' => $oldStatus,
                        'to_status' => 'pending_finance',
                        'approval_levels' => 2,
                    ]);
                    break;
                }

                $loan->update([
                    'status' => 'pending_hr',
                    'manager_approved_by' => $user->id,
                    'manager_approved_at' => now(),
                ]);
                $this->logLoanActivity($loan, 'manager_approved', 'Loan request approved by manager.', [
                    'from_status' => $oldStatus,
                    'to_status' => 'pending_hr',
                ]);
                break;

            case 'pending_hr':
                $oldStatus = $loan->status;
                $loan->update([
                    'status' => 'pending_finance',
                    'hr_approved_by' => $user->id,
                    'hr_approved_at' => now(),
                ]);
                $this->logLoanActivity($loan, 'hr_approved', 'Loan request approved by HR.', [
                    'from_status' => $oldStatus,
                    'to_status' => 'pending_finance',
                ]);
                break;

            case 'pending_finance':
                $request->validate([
                    'approved_amount' => 'nullable|numeric|min:1',
                    'disbursed_date' => 'nullable|date',
                    'first_installment_date' => 'nullable|date',
                ]);

                $approvedAmt = $request->approved_amount ?? $loan->requested_amount;
                $monthly = $this->service->calculateMonthlyInstallment((float) $approvedAmt, (int) $loan->installments,  (float) ($loan->loanType->interest_rate ?? 0)  );

                $oldStatus = $loan->status;
                $loan->update([
                    'status' => 'approved',
                    'finance_approved_by' => $user->id,
                    'finance_approved_at' => now(),
                    'approved_amount' => $approvedAmt,
                    'monthly_installment' => $monthly,
                    'balance_remaining' => $approvedAmt,
                    'disbursed_date' => $request->disbursed_date ?? now()->toDateString(),
                    'first_installment_date' => $request->first_installment_date ?? now()->addMonth()->startOfMonth()->toDateString(),
                ]);

                $loan->refresh();
                $this->service->generateInstallments($loan);
                $this->logLoanActivity($loan, 'finance_approved', 'Loan request approved by finance and schedule generated.', [
                    'from_status' => $oldStatus,
                    'to_status' => 'approved',
                    'approved_amount' => $approvedAmt,
                    'monthly_installment' => $monthly,
                ]);
                break;

            default:
                return response()->json(['message' => 'Loan is not in an approvable state.'], 422);
        }

        $loan->refresh();
        if ($loan->status === 'approved') {
            $this->notifyLoanEmployee($loan, 'approved');
        } else {
            $this->notifyLoanApprovers($loan);
        }

        return response()->json(['message' => 'Loan approved.', 'loan' => $loan->fresh('loanType')]);
    }

    // ── Reject ────────────────────────────────────────────────────────────

    /**
     * Reject a loan at the current stage.
     *
     * @param  Request      $request  Requires reason
     * @param  int          $id
     * @return JsonResponse
     */
    public function reject(Request $request, int $id): JsonResponse {
        $request->validate(['reason' => 'required|string|min:5']);

        $loan = Loan::findOrFail($id);
        $stage = match ($loan->status) {
            'pending_manager' => 'manager',
            'pending_hr' => 'hr',
            'pending_finance' => 'finance',
            default => null,
        };

        if (!$stage) {
            return response()->json(['message' => 'Loan cannot be rejected at this stage.'], 422);
        }

        if (!$this->canApproveStage($loan->status)) {
            return response()->json(['message' => 'You are not authorized to reject this loan at its current stage.'], 403);
        }

        if ($this->isOwnLoan($loan, auth()->user())) {
            return response()->json(['message' => 'You cannot reject your own loan request.'], 403);
        }

        $oldStatus = $loan->status;
        $loan->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejected_stage' => $stage,
        ]);

        $this->logLoanActivity($loan, 'rejected', "Loan request rejected at {$stage} stage.", [
            'from_status' => $oldStatus,
            'to_status' => 'rejected',
            'reason' => $request->reason,
            'stage' => $stage,
        ]);
        $this->notifyLoanEmployee($loan, 'rejected');

        return response()->json(['message' => 'Loan rejected.']);
    }

    // ── Cancel ────────────────────────────────────────────────────────────

    /**
     * Allow an employee to cancel their own pending loan application.
     *
     * @param  int          $id
     * @return JsonResponse
     */
    public function cancel(int $id): JsonResponse {
        $loan = Loan::findOrFail($id);
        $user = auth()->user();

        if (!in_array($loan->status, ['pending_manager', 'pending_hr', 'pending_finance'])) {
            return response()->json(['message' => 'Loan cannot be cancelled at this stage.'], 422);
        }

        $canCancelAsApprover = ($loan->status === 'pending_manager' && $this->hasAnyRoleDB(['super_admin', 'department_manager']))
            || ($loan->status === 'pending_hr' && $this->hasAnyRoleDB(['super_admin', 'hr_manager']))
            || ($loan->status === 'pending_finance' && $this->hasAnyRoleDB(['super_admin', 'finance_manager']));

        if (!$this->isOwnLoan($loan, $user) && !$canCancelAsApprover) {
            return response()->json(['message' => 'You are not authorized to cancel this loan request.'], 403);
        }

        $oldStatus = $loan->status;
        $loan->update(['status' => 'cancelled']);
        $this->logLoanActivity($loan, 'cancelled', 'Loan request cancelled.', [
            'from_status' => $oldStatus,
            'to_status' => 'cancelled',
        ]);

        return response()->json(['message' => 'Loan request cancelled.']);
    }

    public function destroy(int $id): JsonResponse {
        return DB::transaction(function () use ($id) {
            $loan = Loan::lockForUpdate()->findOrFail($id);

            if (!$this->isOwnLoan($loan, auth()->user())) {
                return response()->json(['message' => 'You can only delete your own loan request.'], 403);
            }

            if (!$loan->canDeleteRequest()) {
                return response()->json(['message' => 'Only pending loan requests with no approvals can be deleted.'], 422);
            }

            $this->logLoanActivity($loan, 'deleted', 'Loan request deleted before any approval.', [
                'reference' => $loan->reference,
                'from_status' => $loan->status,
            ]);
            $loan->delete();

            return response()->json(['message' => 'Loan request deleted.']);
        });
    }

    // ── Disburse ──────────────────────────────────────────────────────────

    /**
     * Mark a loan as disbursed.
     *
     * @param  Request      $request  Optional disbursed_date
     * @param  int          $id
     * @return JsonResponse
     */
    public function disburse(Request $request, int $id): JsonResponse {
        $loan = Loan::findOrFail($id);

        if ($loan->status !== 'approved') {
            return response()->json(['message' => 'Loan must be approved before disbursement.'], 422);
        }

        $oldStatus = $loan->status;
        $loan->update([
            'status' => 'disbursed',
            'disbursed_date' => $request->disbursed_date ?? now()->toDateString(),
        ]);

        $this->logLoanActivity($loan, 'disbursed', 'Loan marked as disbursed.', [
            'from_status' => $oldStatus,
            'to_status' => 'disbursed',
            'disbursed_date' => $loan->disbursed_date ? $loan->disbursed_date->toDateString() : null,
        ]);

        return response()->json(['message' => 'Loan marked as disbursed.']);
    }

    // ── Installments ──────────────────────────────────────────────────────

    /**
     * Mark an installment as paid.
     *
     * @param  Request $request   Optional paid_date, notes
     * @param  int     $loanId
     * @param  int     $instId
     * @return JsonResponse
     */
    public function payInstallment(Request $request, int $loanId, int $instId): JsonResponse {
        if (!$this->hasAnyRoleDB(['finance_manager', 'hr_manager'])) {
            return response()->json([
                'message' => 'Only the Finance Manager or HR Manager can mark an installment as paid.',
            ], 403);
        }

        $inst = LoanInstallment::where('loan_id', $loanId)->findOrFail($instId);

        if (!in_array($inst->status, ['pending', 'overdue'])) {
            return response()->json(['message' => 'Installment is not payable.'], 422);
        }

        if ($inst->payslip_id) {
            return response()->json([
                'message' => 'This installment is already scheduled for deduction through payroll.',
            ], 422);
        }

        $this->service->payInstallment($inst, $request->paid_date, $request->notes);
        $this->logLoanActivity($inst->loan()->first(), 'installment_paid', "Installment #{$inst->installment_no} marked as paid.", [
            'installment_id' => $inst->id,
            'installment_no' => $inst->installment_no,
            'amount' => $inst->amount,
            'notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Installment marked as paid.']);
    }

    /**
     * Skip an installment and reschedule it to the end of the loan.
     *
     * @param  Request $request  Optional notes
     * @param  int     $loanId
     * @param  int     $instId
     * @return JsonResponse
     */
    public function skipInstallment(Request $request, int $loanId, int $instId): JsonResponse {
        $inst = LoanInstallment::where('loan_id', $loanId)->findOrFail($instId);

        if (!in_array($inst->status, ['pending', 'overdue'])) {
            return response()->json(['message' => 'Installment cannot be skipped.'], 422);
        }

        if ($inst->payslip_id) {
            return response()->json([
                'message' => 'This installment is already scheduled for deduction through payroll.',
            ], 422);
        }

        $replacementInstallment = $this->service->skipInstallment($inst, $request->notes);
        $loan = $inst->loan()->with(['employee', 'loanType'])->firstOrFail();
        $this->logLoanActivity($loan, 'installment_skipped', "Installment #{$inst->installment_no} skipped and rescheduled.", [
            'installment_id' => $inst->id,
            'installment_no' => $inst->installment_no,
            'amount' => $inst->amount,
            'notes' => $request->notes,
            'replacement_installment_id' => $replacementInstallment->id,
            'replacement_due_date' => $replacementInstallment->due_date?->toDateString(),
        ]);
        $this->notifyInstallmentSkipped($loan, $inst->fresh(), $replacementInstallment);

        return response()->json(['message' => 'Installment skipped — rescheduled to end of loan.']);
    }

    /**
     * Mark all overdue installments (for cron job use).
     *
     * @return JsonResponse
     */
    public function markOverdue(): JsonResponse {
        $count = $this->service->markOverdue();

        return response()->json(['message' => "{$count} installments marked as overdue."]);
    }

    // ── My Loans ──────────────────────────────────────────────────────────

    /**
     * Return the authenticated employee's loan list.
     *
     * @return JsonResponse
     */
    public function myLoans(): JsonResponse {
        $employee = auth()->user()->employee;

        if (!$employee) {
            return response()->json(['loans' => []]);
        }

        $loans = Loan::with(['loanType'])
                ->where('employee_id', $employee->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function (Loan $loan) {
            $data = $loan->toArray();
            $data['total_installments'] = $loan->getRawOriginal('installments');
            return $data;
        });

        return response()->json(['loans' => $loans]);
    }
}
