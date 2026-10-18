<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\RequestStatusMail;
use App\Models\RequestType;
use App\Models\EmployeeRequest;
use App\Models\RequestComment;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequestManagementController extends Controller {

    // ── Reference generator ───────────────────────────────────────────────
    private function generateRef(): string {
        $year = now()->year;
        $count = EmployeeRequest::whereYear('created_at', $year)->count() + 1;
        return 'REQ-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    // ══════════════════════════════════════════════════════════════════════
    // REQUEST TYPES
    // ══════════════════════════════════════════════════════════════════════
    public function types() {
        return response()->json(['types' => RequestType::where('is_active', true)->orderBy('category')->orderBy('sort_order')->get()]);
    }

    public function allTypes() {
        return response()->json(['types' => RequestType::orderBy('category')->orderBy('sort_order')->get()]);
    }

    public function storeType(Request $request) {
        if (!$this->isHRAdminUser()) {
            return $this->forbidden();
        }
        $data = $request->validate(array_merge($this->typeRules(), [
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:30|unique:request_types',
        ]));
        return response()->json(['type' => RequestType::create($data)], 201);
    }

    public function updateType(Request $request, $id) {
        if (!$this->isHRAdminUser()) {
            return $this->forbidden();
        }
        $data = $request->validate(array_merge($this->typeRules(), [
            'name' => 'required|string|max:120',
            'code' => 'sometimes|required|string|max:30|unique:request_types,code,' . (int) $id,
        ]));
        $type = RequestType::findOrFail($id);
        $type->update($data);
        return response()->json(['type' => $type]);
    }

    /** Validated, fillable request-type fields (never pass the raw request into create/update). */
    private function typeRules(): array
    {
        return [
            'name_ar' => 'nullable|string|max:150',
            'category' => 'sometimes|required|string|max:60',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'sla_days' => 'sometimes|required|integer|min:0|max:365',
            'requires_attachment' => 'sometimes|boolean',
            'requires_manager_approval' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0',
            'icon' => 'sometimes|string|max:50',
            'color' => 'sometimes|string|max:20',
            'handling_department_id' => 'required|exists:departments,id',
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // STATS
    // ══════════════════════════════════════════════════════════════════════

    public function stats(): JsonResponse {
        $user = auth()->user();

        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')
                        ->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, [
                    'super_admin',
                    'hr_manager',
                    'hr_staff'
        ]);

        $isMgr = in_array('department_manager', $userRoles);

        $baseQuery = EmployeeRequest::query();

        if (!$isHRAdmin && $user->employee) {

            if ($isMgr) {

                $deptId = $user->employee->department_id;

                $baseQuery->where(function ($q) use ($deptId, $user) {

                    // Employees in my department
                    $q->whereHas('employee', function ($eq) use ($deptId) {
                                $eq->where('department_id', $deptId);
                            })

                            // Assigned to me
                            ->orWhere('assigned_to', $user->id);
                });
            } else {

                // Own requests + requests assigned to me
                $baseQuery->where(function ($q) use ($user) {

                    $q->where('employee_id', $user->employee->id)
                            ->orWhere('assigned_to', $user->id);
                });
            }
        }

        $safe = fn($fn) => rescue($fn, 0, false);

        $byCategory = rescue(function () use ($baseQuery) {

            return (clone $baseQuery)
                    ->join('request_types', 'request_types.id', '=', 'employee_requests.request_type_id')
                    ->whereNotIn('employee_requests.status', ['cancelled'])
                    ->selectRaw('request_types.category, count(*) as total')
                    ->groupBy('request_types.category')
                    ->pluck('total', 'category');
        }, collect(), false);

        return response()->json([
                    'pending' => $safe(
                            fn() => (clone $baseQuery)
                                    ->whereIn('status', ['pending', 'pending_manager'])
                                    ->count()
                    ),
                    'in_progress' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'in_progress')
                                    ->count()
                    ),
                    'completed' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'completed')
                                    ->count()
                    ),
                    'overdue' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('is_overdue', true)
                                    ->whereNotIn('status', [
                                        'completed',
                                        'rejected',
                                        'cancelled'
                                    ])
                                    ->count()
                    ),
                    'by_category' => $byCategory,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // LIST
    // ══════════════════════════════════════════════════════════════════════
    public function index(Request $request) {
        $perPage = min(max((int) $request->input('per_page', 10), 10), 100);
        $user = auth()->user();
        $isMine = $request->scope === 'mine';

        // Role-based scope via raw DB (bypasses Spatie guard issues)
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
        $isMgr = in_array('department_manager', $userRoles);

        // If not HR/admin and not explicitly requesting own, restrict to own
        $scopeToOwn = !$isHRAdmin && ($isMine || !$isMgr);

        $deptId = $user->employee?->department_id;

        $query = EmployeeRequest::with([
                    'employee.department',
                    'requestType',
                    'assignedTo'
                ])
                // "My requests" applies to everyone (HR included); other non-HR views are
                // always scoped, even for accounts without a linked employee record.
                ->when(!$isHRAdmin || $isMine, function ($q) use ($user, $deptId, $isMgr, $isMine) {

                    $q->where(function ($inner) use ($user, $deptId, $isMgr, $isMine) {


                        if ($isMine) {
                            // Own requests
                            $inner->where('employee_id', $user->employee?->id ?? 0);
                        } else {

                            // Assigned to me
                            $inner->where('assigned_to', $user->id);

                            // Department manager can also see department-routed requests

                            if ($isMgr) {


                                // Employee belongs to my department and requires manager approval
                                $inner->orWhere(function ($mgrQ) use ($deptId) {

                                    $mgrQ->whereHas('employee', function ($eq) use ($deptId) {
                                        $eq->where('department_id', $deptId);
                                    })
                                    ->whereHas('requestType', function ($rq) {
                                        $rq->where('requires_manager_approval', 1);
                                    });
                                });

                                // Request routed to my department after manager approval
                                $inner->orWhere(function ($deptQ) use ($deptId) {

                                    $deptQ->where('status', '!=', 'pending_manager')
                                    ->whereHas('requestType', function ($rq) use ($deptId) {
                                        $rq->where('handling_department_id', $deptId);
                                    });
                                });
                            }
                        }
                    });
                })
                ->when($request->status, function ($q) use ($request, $isMine) {

                    if ($request->status === 'pending') {

                        $q->whereIn('status', [
                            'pending',
                            'pending_manager'
                        ]);
                    } else {

                        $q->where('status', $request->status);
                    }
                })
                ->when($request->category,
                        fn($q) => $q->whereHas('requestType',
                                fn($rq) => $rq->where('category', $request->category)
                        )
                )
                ->when($request->request_type_id,
                        fn($q) => $q->where('request_type_id', $request->request_type_id)
                )
                ->when($request->overdue,
                        fn($q) => $q->where('is_overdue', true)
                )
                ->when($request->search, function ($q) use ($request) {

                    $search = $request->search;

                    $q->where(function ($sq) use ($search) {

                        $sq->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('employee', function ($eq) use ($search) {

                            $eq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('employee_code', 'like', "%{$search}%");
                        });
                    });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        try {
            return response()->json($query->paginate($perPage));
        } catch (\Exception $e) {
            return response()->json(['error' => 'INDEX ERROR: ' . $e->getMessage(), 'line' => $e->getLine()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // SHOW
    // ══════════════════════════════════════════════════════════════════════
    public function show($id) {
        $req = EmployeeRequest::with([
                    'employee.department', 'employee.designation',
                    'requestType', 'managerApprover', 'assignedTo.employee.department', 'completedBy', 'rejectedBy',
                    'comments.user',
                ])->findOrFail($id);

        if (!$this->canAccessRequestFile($req)) {
            return $this->forbidden();
        }

        // Internal notes are for the people handling the request, not the requester.
        if (!$this->isRequestStaff($req)) {
            $req->setRelation('comments', $req->comments->reject(fn($c) => $c->is_internal)->values());
        }

        return response()->json(['request' => $req]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CREATE (employee submits)
    // ══════════════════════════════════════════════════════════════════════
    public function store(Request $request) {
        $request->validate([
            'request_type_id' => 'required|exists:request_types,id',
            'details' => 'required|string|min:5',
            'required_by' => 'nullable|date|after:today',
            'copies_needed' => 'nullable|integer|min:1|max:20',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $employee = auth()->user()->employee;
        if (!$employee) {
            return response()->json(['message' => __('No employee record linked to your account.')], 422);
        }

        $type = RequestType::findOrFail($request->request_type_id);
        $dueDate = now()->addDays($type->sla_days)->toDateString();

        // Enforce attachment requirement
        if ($type->requires_attachment && !$request->hasFile('attachment')) {
            return response()->json(['message' => __('A supporting document is required for this request type.')], 422);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store(
                    "requests/{$employee->id}", 'public'
            );
        }

        $req = EmployeeRequest::create([
                    'reference' => $this->generateRef(),
                    'employee_id' => $employee->id,
                    'request_type_id' => $request->request_type_id,
                    'status' => $type->requires_manager_approval ? 'pending_manager' : 'pending',
                    'details' => $request->details,
                    'required_by' => $request->required_by,
                    'copies_needed' => $request->copies_needed ?? 1,
                    'due_date' => $dueDate,
                    'attachment_path' => $attachmentPath,
                    'handling_department_id' => $request->handling_department_id
        ]);

        return response()->json(['message' => __('Request submitted successfully.'), 'request' => $req->load('requestType')], 201);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MANAGER APPROVE
    // ══════════════════════════════════════════════════════════════════════
    public function managerApprove(Request $request, $id) {
        $req = EmployeeRequest::findOrFail($id);
        if ($req->status !== 'pending_manager') {
            return response()->json(['message' => __('Not awaiting manager approval.')], 422);
        }
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', auth()->id())
                        ->pluck('roles.name')->toArray(), [], false);

        $canApprove = (bool) array_intersect($userRoles, [
                    'super_admin', 'hr_manager', 'hr_staff', 'department_manager'
        ]);
        if (!$canApprove) {
            return response()->json(['message' => __('Only managers can approve at this stage.')], 403);
        }
        // A department manager may approve only requests of their own department's employees,
        // and nobody (other than HR) approves their own request.
        if (!$this->isHRAdminUser()) {
            $me = auth()->user()->employee;
            $req->loadMissing('employee');
            if (!$me || $this->isOwnRequest($req)
                || (int) $req->employee?->department_id !== (int) $me->department_id) {
                return response()->json(['message' => __('Only managers can approve at this stage.')], 403);
            }
        }
        $req->update([
            'status' => 'pending',
            'manager_approved_by' => auth()->id(),
            'manager_approved_at' => now(),
            'manager_notes' => $request->notes,
        ]);
        $this->sendStatusUpdateEmails(
            $req->fresh(['employee', 'requestType', 'assignedTo']),
            'pending',
            null // the mail adds the standard note in the recipient's language
        );
        return response()->json(['message' => __('Approved — forwarded to HR.'), 'request' => $req->fresh()]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HR: TAKE / ASSIGN
    // ══════════════════════════════════════════════════════════════════════
    public function assign(Request $request, $id) {
        $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'hr_notes' => 'nullable|string|max:1000',
        ]);

        $req = EmployeeRequest::findOrFail($id);

        if (!$this->isHRAdminUser() && ($this->isOwnRequest($req) || !$this->isRequestStaff($req))) {
            return $this->forbidden();
        }

        if (!in_array($req->status, ['pending', 'in_progress'])) {
            return response()->json(['message' => __('Request cannot be assigned at this stage.')], 422);
        }

        $assigneeId = $request->assigned_to ?? auth()->id();
        $assignee = \App\Models\User::find($assigneeId);

        $req->update([
            'status' => 'in_progress',
            'assigned_to' => $assigneeId,
            'hr_notes' => $request->hr_notes,
        ]);

        // Activity log comment
        $label = $assigneeId === auth()->id() ? 'self' : optional($assignee)->name;
        RequestComment::create([
            'request_id' => $req->id,
            'user_id' => auth()->id(),
            'comment' => 'Request assigned to ' . $label . ' and is now in progress.',
            'is_internal' => true,
        ]);

        $this->sendStatusUpdateEmails(
            $req->fresh(['employee', 'requestType', 'assignedTo']),
            'in_progress',
            null // the mail adds "assigned to <name>" in the recipient's language
        );

        return response()->json(['message' => __('Request assigned.'), 'request' => $req->fresh(['assignedTo'])]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HR: COMPLETE
    // ══════════════════════════════════════════════════════════════════════
    public function complete(Request $request, $id) {
        $req = EmployeeRequest::findOrFail($id);

        // Only HR, or the user this request is assigned to, may complete it
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', auth()->id())
                        ->pluck('roles.name')->toArray(), [], false);
        $isHRAdmin = (bool) array_intersect($userRoles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
        $isAssignee = (int) $req->assigned_to === (int) auth()->id();

        if (!$isHRAdmin && !$isAssignee) {
            return response()->json(['message' => __('Only the assigned person or HR can complete this request.')], 403);
        }

        if (in_array($req->status, ['completed', 'rejected', 'cancelled'], true)) {
            return response()->json(['message' => __('Request cannot be completed at this stage.')], 422);
        }

        $completionFile = $req->completion_file;
        if ($request->hasFile('completion_file')) {
            $request->validate(['completion_file' => 'file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240']);
            $completionFile = $request->file('completion_file')->store(
                    "requests/completed/{$req->id}", 'public'
            );
        }

        $req->update([
            'status' => 'completed',
            'completed_by' => auth()->id(),
            'completed_at' => now(),
            'completion_notes' => $request->completion_notes,
            'completion_file' => $completionFile,
            'hr_notes' => $request->hr_notes ?? $req->hr_notes,
        ]);

        // Auto-add a comment
        if ($request->completion_notes) {
            RequestComment::create([
                'request_id' => $req->id,
                'user_id' => auth()->id(),
                'comment' => 'Request completed. ' . $request->completion_notes,
                'is_internal' => false,
            ]);
        }

        $this->sendStatusUpdateEmails(
            $req->fresh(['employee', 'requestType', 'assignedTo']),
            'completed',
            $request->completion_notes
        );

        return response()->json(['message' => __('Request marked as completed.')]);
    }

    public function downloadCompletionFile($id): StreamedResponse|JsonResponse
    {
        $req = EmployeeRequest::with(['employee', 'requestType'])->findOrFail($id);

        if (!$this->canAccessRequestFile($req)) {
            return response()->json(['message' => __('You are not allowed to download this file.')], 403);
        }

        if (!$req->completion_file || !Storage::disk('public')->exists($req->completion_file)) {
            return response()->json(['message' => __('Completion file not found.')], 404);
        }

        $extension = pathinfo($req->completion_file, PATHINFO_EXTENSION);
        $filename = $req->reference . '-completion' . ($extension ? '.' . $extension : '');

        return Storage::disk('public')->download($req->completion_file, $filename);
    }

    // ══════════════════════════════════════════════════════════════════════
    // REJECT
    // ══════════════════════════════════════════════════════════════════════
    public function reject(Request $request, $id) {
        $request->validate(['reason' => 'required|string|min:5']);
        $req = EmployeeRequest::findOrFail($id);

        if (!$this->isHRAdminUser() && ($this->isOwnRequest($req) || !$this->isRequestStaff($req))) {
            return $this->forbidden();
        }

        if (in_array($req->status, ['completed', 'cancelled', 'rejected'])) {
            return response()->json(['message' => __('Cannot reject at this stage.')], 422);
        }
        $req->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
        ]);
        $this->sendStatusUpdateEmails(
            $req->fresh(['employee', 'requestType', 'assignedTo']),
            'rejected',
            $request->reason
        );
        return response()->json(['message' => __('Request rejected.')]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CANCEL (employee)
    // ══════════════════════════════════════════════════════════════════════
    public function cancel($id) {
        $req = EmployeeRequest::findOrFail($id);
        if (!$this->isOwnRequest($req) && !$this->isHRAdminUser()) {
            return $this->forbidden();
        }
        if (!in_array($req->status, ['pending', 'pending_manager'])) {
            return response()->json(['message' => __('Request cannot be cancelled at this stage.')], 422);
        }
        $req->update(['status' => 'cancelled']);
        $this->sendStatusUpdateEmails(
            $req->fresh(['employee', 'requestType', 'assignedTo']),
            'cancelled',
            null // the mail adds the standard note in the recipient's language
        );
        return response()->json(['message' => __('Request cancelled.')]);
    }

    private function sendStatusUpdateEmails(EmployeeRequest $req, string $status, ?string $note = null): void
    {
        $req->loadMissing(['employee', 'requestType', 'assignedTo']);

        $recipients = collect();
        if ($req->employee?->email) {
            $recipients->push([
                'email' => $req->employee->email,
                'name' => trim($req->employee->first_name . ' ' . $req->employee->last_name) ?: null,
                'name_ar' => $req->employee->arabic_name,
            ]);
        }

        if ($req->assignedTo?->email) {
            $recipients->push([
                'email' => $req->assignedTo->email,
                'name' => $req->assignedTo->name ?: null,
                'name_ar' => $req->assignedTo->employee?->arabic_name,
            ]);
        }

        if ($status === 'pending' && $req->requestType?->handling_department_id) {
            User::whereHas('employee', function ($q) use ($req) {
                $q->where('department_id', $req->requestType->handling_department_id)
                    ->where('status', 'active');
            })
                ->whereNotNull('email')
                ->where('email', '<>', '')
                ->get(['id', 'name', 'email'])
                ->each(fn($user) => $recipients->push([
                    'email' => $user->email,
                    'name' => $user->name ?: null,
                ]));
        }

        $recipients
            ->filter(fn($recipient) => !empty($recipient['email']))
            ->unique('email')
            ->values()
            ->each(function ($recipient) use ($req, $status, $note) {
                try {
                    Mail::to($recipient['email'])->queue(new RequestStatusMail(
                        $req,
                        $status,
                        $recipient['name'],
                        $note,
                        $recipient['name_ar'] ?? null
                    ));
                } catch (\Throwable $e) {
                    Log::warning('Request status email failed', [
                        'request_id' => $req->id,
                        'reference' => $req->reference,
                        'status' => $status,
                        'email' => $recipient['email'],
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    private function currentRoles(): array
    {
        return rescue(fn() => DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->pluck('roles.name')
            ->toArray(), [], false);
    }

    private function isHRAdminUser(): bool
    {
        return (bool) array_intersect($this->currentRoles(), ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
    }

    private function isOwnRequest(EmployeeRequest $req): bool
    {
        $employee = auth()->user()->employee;
        return $employee && (int) $req->employee_id === (int) $employee->id;
    }

    /**
     * True when the current user handles this request: HR, the assignee, or a department
     * manager of the requester's department or of the type's handling department.
     */
    private function isRequestStaff(EmployeeRequest $req): bool
    {
        $user = auth()->user();
        $roles = $this->currentRoles();

        if ((bool) array_intersect($roles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff'])) {
            return true;
        }

        if ($req->assigned_to && (int) $req->assigned_to === (int) $user->id) {
            return true;
        }

        if (in_array('department_manager', $roles, true) && $user->employee?->department_id) {
            $req->loadMissing(['employee', 'requestType']);
            return (int) $req->employee?->department_id === (int) $user->employee->department_id
                || (int) $req->requestType?->handling_department_id === (int) $user->employee->department_id;
        }

        return false;
    }

    private function canAccessRequestFile(EmployeeRequest $req): bool
    {
        return $this->isOwnRequest($req) || $this->isRequestStaff($req);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => __('Insufficient permissions.')], 403);
    }

    // ══════════════════════════════════════════════════════════════════════
    // COMMENTS
    // ══════════════════════════════════════════════════════════════════════
    public function addComment(Request $request, $id) {
        $request->validate(['comment' => 'required|string|min:1']);
        $req = EmployeeRequest::findOrFail($id);

        if (!$this->canAccessRequestFile($req)) {
            return $this->forbidden();
        }

        $comment = RequestComment::create([
                    'request_id' => $req->id,
                    'user_id' => auth()->id(),
                    'comment' => $request->comment,
                    'is_internal' => $this->isRequestStaff($req) ? $request->boolean('is_internal') : false,
        ]);

        return response()->json(['comment' => $comment->load('user')], 201);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DELETE REQUEST TYPE
    // ══════════════════════════════════════════════════════════════════════
    public function deleteType($id) {
        if (!$this->isHRAdminUser()) {
            return $this->forbidden();
        }
        $type = RequestType::findOrFail($id);

        // Prevent deletion if requests exist for this type
        $inUse = EmployeeRequest::where('request_type_id', $id)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

        if ($inUse) {
            return response()->json([
                        'message' => __('Cannot delete \':name\' — it has active or historical requests. Deactivate it instead.', ['name' => $type->name]),
                            ], 422);
        }

        $type->delete();
        return response()->json(['message' => __('Request type \':name\' deleted.', ['name' => $type->name])]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ASSIGNABLE USERS — for the assign-to dropdown
    // ══════════════════════════════════════════════════════════════════════
    public function assignableUsers(\Illuminate\Http\Request $request) {
        $query = DB::table('users')
                ->join('employees', 'employees.user_id', '=', 'users.id')
                ->join('departments', 'departments.id', '=', 'employees.department_id')
                ->where('employees.status', 'active')
                ->select(
                        'users.id',
                        'users.name',
                        'departments.id   as department_id',
                        'departments.name as department_name'
                )
                ->orderBy('departments.name')
                ->orderBy('users.name');

        $rows = $query->get();

        $grouped = $rows->groupBy('department_name')
                ->map(fn($users, $dept) => [
                    'department' => $dept,
                    'users' => $users->values(),
                        ])
                ->values();

        return response()->json(['groups' => $grouped]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DEPARTMENTS — for request type configuration
    // ══════════════════════════════════════════════════════════════════════
    public function departments() {
        return response()->json([
                    'departments' => \App\Models\Department::orderBy('name')->get(['id', 'name', 'name_ar'])
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // MARK OVERDUE (cron-friendly)
    // ══════════════════════════════════════════════════════════════════════
    public function markOverdue() {
        $count = EmployeeRequest::whereNotIn('status', ['completed', 'rejected', 'cancelled'])
                ->where('due_date', '<', now()->toDateString())
                ->update(['is_overdue' => true]);
        return response()->json(['message' => __(':count requests marked as overdue.', ['count' => $count])]);
    }
}
