<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Separation;
use App\Models\OffboardingTemplate;
use App\Models\OffboardingItem;
use App\Models\Employee;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Loan;
use App\Services\SeparationService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class SeparationController extends Controller
{
    public function __construct(protected SeparationService $service) {}

    // ── Stats ──────────────────────────────────────────────────────────────
    public function stats()
    {
        $base = $this->scopedQuery();

        return response()->json([
            'pending_manager'  => (clone $base)->where('status','pending_manager')->count(),
            'pending_hr'       => (clone $base)->where('status','pending_hr')->count(),
            'offboarding'      => (clone $base)->where('status','offboarding')->count(),
            'completed_ytd'    => (clone $base)->where('status','completed')->whereYear('updated_at',now()->year)->count(),
            'by_type'          => (clone $base)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total','type'),
        ]);
    }

    // ── List ───────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $query = $this->scopedQuery()
            ->with(['employee.department','employee.designation','initiatedBy'])
            ->when($request->status, function ($q) use ($request) {
                $statuses = array_filter(explode(',', (string) $request->status));
                return count($statuses) > 1 ? $q->whereIn('status', $statuses) : $q->where('status', $request->status);
            })
            ->when($request->type,     fn($q) => $q->where('type',   $request->type))
            ->when($request->search,   fn($q) =>
                $q->whereHas('employee', fn($eq) =>
                    $eq->where('first_name','like',"%{$request->search}%")
                      ->orWhere('last_name','like',"%{$request->search}%")
                      ->orWhere('employee_code','like',"%{$request->search}%")
                )
            )
            ->orderBy('created_at','desc');

        return response()->json($query->paginate(15));
    }

    // ── Show ───────────────────────────────────────────────────────────────
    public function show($id)
    {
        $sep = Separation::with([
            'employee.department','employee.designation','employee.leaveAllocations.leaveType',
            'initiatedBy','managerApprover','hrApprover','rejectedBy',
            'checklistItems.completedBy',
        ])->findOrFail($id);
        if (!$this->canViewSeparation($sep)) {
            return response()->json(['message' => 'You do not have permission to view this separation.'], 403);
        }

        // HR/CEO can see the full checklist. Other departmental users receive
        // only the tasks assigned to their own department category.
        $category = $this->offboardingCategoryForUser(auth()->user());
        if ($category !== null && !$this->canViewAllChecklistItems(auth()->user())) {
            $sep->setRelation(
                'checklistItems',
                $sep->checklistItems->where('category', $category)->values()
            );
        }
        $sep->checklistItems->each(function ($item) {
            $item->setAttribute('completed_by_name', optional($item->completedBy)->name);
        });

        // Clearance information is deliberately scoped here so restricted
        // department data is never sent to an unauthorized browser.
        $clearanceAccess = $this->clearanceAccessForUser(auth()->user());
        $sep->setAttribute('clearance_access', $clearanceAccess);

        if ($sep->type === 'resignation' && $clearanceAccess['it']) {
            $assignmentAssets = AssetAssignment::query()
                ->with('asset.category:id,name')
                ->where('employee_id', $sep->employee_id)
                ->orderByDesc('id')
                ->get()
                ->unique('asset_id')
                ->map(function (AssetAssignment $assignment) {
                    $asset = $assignment->asset;
                    if (!$asset) return null;
                    return [
                        'id' => $asset->id,
                        'asset_code' => $asset->asset_code,
                        'name' => $asset->name,
                        'brand' => $asset->brand,
                        'model' => $asset->model,
                        'serial_number' => $asset->serial_number,
                        'category' => $asset->category,
                        'returned' => $assignment->return_date !== null,
                        'return_date' => optional($assignment->return_date)->toDateString(),
                    ];
                })
                ->filter()
                ->keyBy('id');

            // Preserve legacy/current custodian records that pre-date assignment
            // history, treating them as not returned.
            Asset::query()
                ->with('category:id,name')
                ->where('custodian_employee_id', $sep->employee_id)
                ->where('status', 'assigned')
                ->get()
                ->each(function (Asset $asset) use ($assignmentAssets) {
                    if ($assignmentAssets->has($asset->id)) return;
                    $assignmentAssets->put($asset->id, [
                        'id' => $asset->id,
                        'asset_code' => $asset->asset_code,
                        'name' => $asset->name,
                        'brand' => $asset->brand,
                        'model' => $asset->model,
                        'serial_number' => $asset->serial_number,
                        'category' => $asset->category,
                        'returned' => false,
                        'return_date' => null,
                    ]);
                });

            $sep->setAttribute('it_assets', $assignmentAssets->sortBy('name')->values());
        }

        if ($sep->type === 'resignation' && $clearanceAccess['finance']) {
            $sep->setAttribute('finance_loans', Loan::query()
                ->with([
                    'loanType:id,name',
                    'installments' => fn ($query) => $query
                        ->whereIn('status', ['pending','overdue'])
                        ->orderBy('due_date'),
                ])
                ->where('employee_id', $sep->employee_id)
                ->whereIn('status', ['approved','disbursed'])
                ->where('balance_remaining', '>', 0)
                ->orderBy('created_at')
                ->get(['id','reference','loan_type_id','approved_amount','monthly_installment','total_paid','balance_remaining','status']));
        }
        return response()->json(['separation' => $sep]);
    }

    // ── Create ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'type'            => 'required|in:resignation,termination,end_of_contract,retirement,abandonment,mutual_agreement',
            'reason'          => 'required|string|min:10',
            'last_working_day'=> 'required|date|after:today',
            'reason_category' => 'nullable|in:personal,better_opportunity,relocation,health,misconduct,performance,restructuring,contract_end,other',
            'notice_waived'   => 'boolean',
        ]);

        $user = auth()->user();
        $isHR = $this->canCreateAnySeparation($user);
        $employeeId = $isHR ? (int) $request->employee_id : (int) $user->employee?->id;

        if (!$employeeId) {
            return response()->json(['message' => 'Your user is not linked to an employee record.'], 422);
        }

        if (!$isHR && $request->type !== 'resignation') {
            return response()->json(['message' => 'Employees can submit resignation requests only.'], 403);
        }

        $type = $isHR ? $request->type : 'resignation';
        $emp     = Employee::findOrFail($employeeId);
        $lwDay   = Carbon::parse($request->last_working_day);
        $today   = now()->startOfDay();
        $noticeDays = (int)$lwDay->diffInDays($today);
        $status = in_array($type, ['termination','abandonment'])
            ? 'pending_hr'
            : 'pending_manager';
        if ($type === 'resignation' && $this->service->resignationRequiresHrFirst($emp, $lwDay, $today)) {
            $status = 'pending_hr';
        }

        $sep = Separation::create([
            'reference'          => $this->service->generateReference(),
            'employee_id'        => $employeeId,
            'type'               => $type,
            'status'             => $status,
            'request_date'       => now()->toDateString(),
            'last_working_day'   => $request->last_working_day,
            'notice_period_start'=> now()->toDateString(),
            'notice_period_days' => $noticeDays,
            'notice_waived'      => $request->notice_waived ?? false,
            'notice_waived_reason'=> $request->notice_waived_reason,
            'reason'             => $request->reason,
            'reason_category'    => $request->reason_category,
            'hr_notes'           => $isHR ? $request->hr_notes : null,
            'initiated_by'       => auth()->id(),
            'exit_interview_required' => !in_array($type, ['abandonment']),
        ]);

        $this->service->notifySubmitted($sep);

        return response()->json(['message' => 'Separation request created.', 'separation' => $sep->load('employee')], 201);
    }

    // ── Update (HR can edit before approval) ──────────────────────────────
    public function update(Request $request, $id)
    {
        $sep = Separation::with('employee')->findOrFail($id);
        if (!$this->canViewSeparation($sep)) {
            return response()->json(['message' => 'You do not have permission to edit this separation.'], 403);
        }
        if (!in_array($sep->status, ['draft','pending_manager','pending_hr'])) {
            return response()->json(['message' => 'Cannot edit at this stage.'], 422);
        }
        $payload = $request->only([
            'last_working_day','reason','reason_category','hr_notes',
            'notice_waived','notice_waived_reason','exit_interview_required',
        ]);
        if (!$this->canCreateAnySeparation(auth()->user())) {
            unset($payload['hr_notes'], $payload['exit_interview_required']);
        }
        if (array_key_exists('last_working_day', $payload)) {
            if ($sep->manager_approved_at || $sep->hr_approved_at) {
                return response()->json(['message' => 'The last working day cannot be changed after approval has started.'], 422);
            }
            $lastWorkingDay = Carbon::parse($payload['last_working_day']);
            $payload['notice_period_days'] = (int) $lastWorkingDay->diffInDays(now()->startOfDay());
            if ($sep->type === 'resignation') {
                $payload['status'] = $this->service->resignationRequiresHrFirst($sep->employee, $lastWorkingDay, now()->startOfDay())
                    ? 'pending_hr'
                    : 'pending_manager';
            }
        }
        $sep->update($payload);
        return response()->json(['separation' => $sep->fresh()]);
    }

    // ── Approve ────────────────────────────────────────────────────────────
    public function approve(Request $request, $id)
    {
        $sep  = Separation::with('employee')->findOrFail($id);
        $user = auth()->user();

        switch ($sep->status) {
            case 'pending_manager':
                if (!$this->canApproveManagerStage($sep, $user)) {
                    return response()->json(['message' => 'Only the employee direct manager, HR Manager, or Super Admin can approve this stage.'], 403);
                }
                $hrAlreadyApproved = $sep->hr_approved_at !== null;
                $sep->update([
                    'status'              => $hrAlreadyApproved ? 'approved' : 'pending_hr',
                    'manager_approved_by' => $user->id,
                    'manager_approved_at' => now(),
                ]);
                if (!$hrAlreadyApproved) {
                    $this->service->notifyManagerApproved($sep);
                }
                break;

            case 'pending_hr':
                if (!$this->canApproveHrStage($user, $sep)) {
                    return response()->json(['message' => $sep->type === 'termination'
                        ? 'Only HR Manager or Super Admin can approve a termination.'
                        : ($sep->type === 'resignation'
                            ? 'Only HR Manager or Super Admin can approve this stage.'
                            : 'Only HR Manager, Finance Manager, or Super Admin can approve this stage.')], 403);
                }
                $managerAlreadyApproved = $sep->manager_approved_at !== null;
                // Calculate settlement at HR approval, regardless of whether HR
                // is the first or second approver.
                $gratuity  = $this->service->calculateGratuity($sep->employee, $sep->type, $sep->last_working_day);
                $encash    = $this->service->calculateLeaveEncashment($sep->employee);
                $additions = (float)($request->other_additions ?? 0);
                $deductions= (float)($request->other_deductions ?? 0);
                $settlement= max(0, $gratuity + $encash + $additions - $deductions);

                $sep->update([
                    'status'              => $managerAlreadyApproved ? 'approved' : 'pending_manager',
                    'hr_approved_by'      => $user->id,
                    'hr_approved_at'      => now(),
                    'gratuity_amount'     => $gratuity,
                    'leave_encashment'    => $encash,
                    'other_additions'     => $additions,
                    'other_deductions'    => $deductions,
                    'final_settlement_amount' => $settlement,
                    'hr_notes'            => $request->hr_notes ?? $sep->hr_notes,
                ]);
                if (!$managerAlreadyApproved) {
                    $this->service->notifyHrApproved($sep);
                }
                break;

            case 'approved':
                // Move to offboarding — generate checklist
                if (!$this->canManageOffboarding($user)) {
                    return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can start offboarding.'], 403);
                }
                $sep->update(['status' => 'offboarding']);
                $this->service->generateChecklist($sep);
                // Mark employee status
                $sep->employee->update(['status' => 'inactive']);
                $this->service->notifyOffboardingDepartments($sep->fresh());
                break;

            default:
                return response()->json(['message' => 'Cannot approve at this stage.'], 422);
        }

        return response()->json(['message' => 'Approved.', 'separation' => $sep->fresh('employee')]);
    }

    // ── Reject ─────────────────────────────────────────────────────────────
    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|min:5']);
        $sep = Separation::with('employee')->findOrFail($id);

        if (!in_array($sep->status, ['pending_manager','pending_hr'])) {
            return response()->json(['message' => 'Cannot reject at this stage.'], 422);
        }
        $user = auth()->user();
        if (
            ($sep->status === 'pending_manager' && !$this->canApproveManagerStage($sep, $user)) ||
            ($sep->status === 'pending_hr' && !$this->canApproveHrStage($user, $sep))
        ) {
            return response()->json(['message' => 'You do not have permission to reject this separation.'], 403);
        }
        $sep->update([
            'status'           => 'rejected',
            'rejected_by'      => auth()->id(),
            'rejected_at'      => now(),
            'rejection_reason' => $request->reason,
        ]);
        return response()->json(['message' => 'Separation request rejected.']);
    }

    // ── Cancel ─────────────────────────────────────────────────────────────
    public function cancel($id)
    {
        $sep = Separation::with('employee')->findOrFail($id);
        $user = auth()->user();
        if ($sep->type === 'termination' && !$this->canApproveHrStage($user, $sep)) {
            return response()->json(['message' => 'Only HR Manager or CEO can cancel a termination.'], 403);
        }
        $isOwn = (int) $sep->employee_id === (int) $user?->employee?->id;
        if ($sep->status === 'approved' && !$this->canCancelApprovedSeparation($sep, $user)) {
            return response()->json(['message' => $sep->type === 'termination'
                ? 'Only HR Manager or CEO can cancel an approved termination.'
                : 'Only HR Manager, Finance Manager, or CEO can cancel an approved separation.'], 403);
        }
        if (!$isOwn && !$this->canApproveManagerStage($sep, $user) && !$this->canApproveHrStage($user, $sep)) {
            return response()->json(['message' => 'You do not have permission to cancel this separation.'], 403);
        }
        if (!in_array($sep->status, ['draft','pending_manager','pending_hr','approved'])) {
            return response()->json(['message' => 'Cannot cancel at this stage.'], 422);
        }
        $sep->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Separation cancelled.']);
    }

    // ── Complete (after offboarding done) ──────────────────────────────────
    public function complete(Request $request, $id)
    {
        $sep = Separation::with(['employee', 'checklistItems'])->findOrFail($id);
        if (!$this->canCompleteSeparation(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager or Super Admin can complete offboarding.'], 403);
        }
        if ($sep->status !== 'offboarding') {
            return response()->json(['message' => 'Separation is not in offboarding stage.'], 422);
        }
        if ($sep->checklistItems->isEmpty()) {
            return response()->json(['message' => 'No offboarding checklist tasks were generated for this separation.'], 422);
        }
        $pendingTasks = $sep->checklistItems->where('status', 'pending');
        if ($pendingTasks->isNotEmpty()) {
            return response()->json([
                'message' => "Complete, skip, or mark all offboarding tasks N/A before completing the separation. {$pendingTasks->count()} task(s) remain pending.",
                'pending_tasks' => $pendingTasks->pluck('title')->values(),
            ], 422);
        }
        $sep->update([
            'status'                 => 'completed',
            'settlement_paid'        => $request->settlement_paid ?? false,
            'settlement_paid_date'   => $request->settlement_paid ? now()->toDateString() : null,
            'settlement_notes'       => $request->settlement_notes,
        ]);
        // Fully terminate the employee
        $sep->employee->update([
            'status'           => 'terminated',
            'termination_date' => $sep->last_working_day,
        ]);
        return response()->json(['message' => 'Separation completed. Employee marked as terminated.']);
    }

    // ── Update settlement ──────────────────────────────────────────────────
    public function updateSettlement(Request $request, $id)
    {
        $sep = Separation::findOrFail($id);
        if (!$this->canManageOffboarding(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can update settlement.'], 403);
        }
        $sep->update($request->only([
            'gratuity_amount','leave_encashment','other_additions','other_deductions',
            'final_settlement_amount','settlement_paid','settlement_paid_date','settlement_notes',
        ]));
        return response()->json(['separation' => $sep->fresh()]);
    }

    // ── Exit interview ─────────────────────────────────────────────────────
    public function updateExitInterview(Request $request, $id)
    {
        $sep = Separation::findOrFail($id);
        if (!$this->canManageOffboarding(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can record exit interviews.'], 403);
        }
        $sep->update([
            'exit_interview_done'  => true,
            'exit_interview_date'  => $request->date ?? now()->toDateString(),
            'exit_interview_notes' => $request->notes,
        ]);
        return response()->json(['message' => 'Exit interview recorded.']);
    }

    // ── Checklist item update ─────────────────────────────────────────────
    public function updateChecklistItem(Request $request, $sepId, $itemId)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,skipped,na',
            'notes' => 'nullable|string|max:1000',
        ]);
        $item = OffboardingItem::where('separation_id', $sepId)->findOrFail($itemId);
        if (!$this->canUpdateChecklistItem($item, auth()->user())) {
            return response()->json(['message' => 'You can update only offboarding tasks assigned to your department.'], 403);
        }
        $previousStatus = $item->status;
        $item->update([
            'status'       => $request->status,
            'notes'        => $request->notes,
            'completed_by' => in_array($request->status, ['completed']) ? auth()->id() : null,
            'completed_at' => in_array($request->status, ['completed']) ? now() : null,
        ]);
        if ($previousStatus === 'pending' && in_array($request->status, ['completed', 'skipped', 'na'], true)) {
            $categoryStillPending = OffboardingItem::where('separation_id', $sepId)
                ->where('category', $item->category)
                ->where('status', 'pending')
                ->exists();
            if (!$categoryStillPending) {
                $separation = Separation::with(['employee', 'checklistItems'])->findOrFail($sepId);
                $this->service->notifyDepartmentTasksCompleted(
                    $separation,
                    $item->category,
                    auth()->user()?->name ?: 'Department Manager'
                );
            }
        }
        return response()->json(['item' => $item->fresh()]);
    }

    // ── Offboarding Templates CRUD ─────────────────────────────────────────
    public function templates()
    {
        if (!$this->canManageOffboarding(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can manage checklist templates.'], 403);
        }
        return response()->json(['templates' => OffboardingTemplate::orderBy('category')->orderBy('sort_order')->get()]);
    }

    public function storeTemplate(Request $request)
    {
        if (!$this->canManageOffboarding(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can manage checklist templates.'], 403);
        }
        $request->validate(['title' => 'required|string|max:150']);
        return response()->json(['template' => OffboardingTemplate::create($request->all())], 201);
    }

    public function updateTemplate(Request $request, $id)
    {
        if (!$this->canManageOffboarding(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can manage checklist templates.'], 403);
        }
        $t = OffboardingTemplate::findOrFail($id);
        $t->update($request->all());
        return response()->json(['template' => $t]);
    }

    public function deleteTemplate($id)
    {
        if (!$this->canManageOffboarding(auth()->user())) {
            return response()->json(['message' => 'Only HR Manager, Finance Manager, or CEO can manage checklist templates.'], 403);
        }
        OffboardingTemplate::findOrFail($id)->delete();
        return response()->json(['message' => 'Template deleted.']);
    }

    // ── Calculate settlement preview ───────────────────────────────────────
    public function settlementPreview(Request $request)
    {
        if (!$this->canCreateAnySeparation(auth()->user())) {
            return response()->json(['message' => 'Only HR can preview settlements while creating separation requests.'], 403);
        }
        $emp      = Employee::findOrFail($request->employee_id);
        $gratuity = $this->service->calculateGratuity($emp, $request->type, $request->last_working_day);
        $encash   = $this->service->calculateLeaveEncashment($emp);
        return response()->json([
            'gratuity_amount'  => $gratuity,
            'leave_encashment' => $encash,
            'total'            => $gratuity + $encash,
            'years_of_service' => round($emp->hire_date ? now()->floatDiffInYears($emp->hire_date) : 0, 1),
        ]);
    }

    private function scopedQuery()
    {
        $user = auth()->user();
        $query = Separation::query();

        if ($this->canViewAllSeparations($user)) {
            return $query;
        }

        if ($user?->hasRole('department_manager') && $user->employee) {
            $clearanceAccess = $this->clearanceAccessForUser($user);
            $canViewResignationsForClearance = $clearanceAccess['it'] || $clearanceAccess['finance'];
            return $query->where(function ($q) use ($user) {
                $q->where('employee_id', $user->employee->id)
                    ->orWhereHas('employee', fn($employee) => $employee->where('manager_id', $user->employee->id))
                    ->orWhereIn('status', ['offboarding', 'completed']);
            })->when($canViewResignationsForClearance, fn ($q) => $q->orWhere('type', 'resignation'));
        }

        return $query->where('employee_id', $user?->employee?->id ?: 0);
    }

    private function canViewSeparation(Separation $sep): bool
    {
        $user = auth()->user();
        if ($this->canViewAllSeparations($user)) return true;
        $clearanceAccess = $this->clearanceAccessForUser($user);
        if ($sep->type === 'resignation' && ($clearanceAccess['it'] || $clearanceAccess['finance'])) return true;
        if ((int) $sep->employee_id === (int) $user?->employee?->id) return true;
        if ($user?->hasRole('department_manager') && in_array($sep->status, ['offboarding', 'completed'])) return true;
        return $user?->hasRole('department_manager')
            && (int) $sep->employee?->manager_id === (int) $user?->employee?->id;
    }

    private function canViewAllSeparations($user): bool
    {
        return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager', 'hr_staff', 'finance_manager']);
    }

    private function canCreateAnySeparation($user): bool
    {
        return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
    }

    private function canApproveManagerStage(Separation $sep, $user): bool
    {
        if ($user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager'])) return true;
        return $user?->hasRole('department_manager')
            && (int) $sep->employee?->manager_id === (int) $user?->employee?->id;
    }

    private function canApproveHrStage($user, ?Separation $sep = null): bool
    {
        if (in_array($sep?->type, ['termination', 'resignation'], true)) {
            return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager']);
        }
        return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager', 'finance_manager']);
    }

    private function canCancelApprovedSeparation(Separation $sep, $user): bool
    {
        if ($sep->type === 'termination') {
            return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager']);
        }
        return $this->canManageOffboarding($user);
    }

    private function canManageOffboarding($user): bool
    {
        return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager', 'finance_manager']);
    }

    private function canCompleteSeparation($user): bool
    {
        return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager']);
    }

    private function canViewAllChecklistItems($user): bool
    {
        return $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
    }

    private function canUpdateChecklistItem(OffboardingItem $item, $user): bool
    {
        if ($this->canViewAllChecklistItems($user)) return true;

        $category = $this->offboardingCategoryForUser($user);
        return $category !== null && $item->category === $category;
    }

    private function offboardingCategoryForUser($user): ?string
    {
        if (!$user) return null;
        if ($user->hasRole('finance_manager')) return 'finance';

        $department = $user->employee?->department;
        if (!$department) return null;

        $identity = strtolower(trim(($department->code ?? '') . ' ' . ($department->name ?? '')));
        if (preg_match('/\b(fin|finance|account|accounts|accounting)\b/', $identity)) return 'finance';
        if (preg_match('/\b(it|information technology|technology|cyber|systems)\b/', $identity)) return 'it';
        if (preg_match('/\b(hr|human resources|people)\b/', $identity)) return 'hr';
        if (preg_match('/\b(admin|administration|facilities)\b/', $identity)) return 'admin';

        return 'general';
    }

    private function clearanceAccessForUser($user): array
    {
        $canViewBoth = $user?->hasAnyRole(['super_admin', 'ceo', 'hr_manager']) ?? false;
        $category = $this->offboardingCategoryForUser($user);

        return [
            'it' => $canViewBoth || ($user?->hasRole('department_manager') && $category === 'it'),
            'finance' => $canViewBoth
                || ($user?->hasRole('finance_manager') ?? false)
                || ($user?->hasRole('department_manager') && $category === 'finance'),
        ];
    }
}
