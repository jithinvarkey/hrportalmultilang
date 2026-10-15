<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveRequest;
use App\Models\EmployeeRequest;
use App\Models\RequestType;
use App\Models\LeaveAllocation;
use App\Services\LeaveService;
use App\Services\RequestActivityService;
use App\Services\AnnualTicketService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeaveController extends Controller {

    protected $service;
    protected $activityService;

    public function __construct(LeaveService $service, RequestActivityService $activityService, protected AnnualTicketService $annualTickets) {
        $this->service = $service;
        $this->activityService = $activityService;
    }

    public function ticketOptions(Request $request) {
        $employee = auth()->user()->employee;
        abort_unless($employee, 404, 'Employee record not found.');
        $year = (int) ($request->query('year') ?: now()->year);
        return response()->json(['ticket_options' => $this->annualTickets->options($employee, $year)]);
    }

    private function logLeaveActivity(LeaveRequest $leave, string $event, string $description, array $properties = []): void {
        $this->activityService->record($leave, 'leave_request', $event, $description, $properties);
    }

    /**
     * Get the authenticated user's role names directly from the DB.
     * Bypasses Spatie's guard resolution which fails with Sanctum.
     */
    private function userRoles(): array {
        $user = auth()->user();
        $roles = DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->where('model_has_roles.model_type', get_class($user))
                        ->pluck('roles.name')
                        ->toArray();

        if (in_array('ceo', $roles, true) && !in_array('hr_manager', $roles, true)) {
            $roles[] = 'hr_manager';
        }

        return $roles;
    }

    private function hasAnyRoleDB(array $roles): bool {
        return count(array_intersect($this->userRoles(), $roles)) > 0;
    }

    private function canManageHolidays(): bool {
        return $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']);
    }

    private function visibleLeaveTypeIdsForDepartment(?int $departmentId): ?array {
        if (!$departmentId) {
            return [];
        }

        $configuredTypeIds = DB::table('leave_type_department_visibility')
                ->distinct()
                ->pluck('leave_type_id')
                ->toArray();

        if (empty($configuredTypeIds)) {
            return null;
        }

        $visibleConfiguredIds = DB::table('leave_type_department_visibility')
                ->where('department_id', $departmentId)
                ->where('is_visible', true)
                ->pluck('leave_type_id')
                ->toArray();

        return array_values(array_unique(array_merge(
            LeaveType::whereNotIn('id', $configuredTypeIds)->pluck('id')->toArray(),
            $visibleConfiguredIds
        )));
    }

    private function leaveTypeVisibleForEmployee(LeaveType $leaveType, $employee): bool {
        if (!$employee?->department_id) {
            return false;
        }

        $hasVisibilityConfig = DB::table('leave_type_department_visibility')
                ->where('leave_type_id', $leaveType->id)
                ->exists();

        if (!$hasVisibilityConfig) {
            return true;
        }

        return DB::table('leave_type_department_visibility')
                ->where('leave_type_id', $leaveType->id)
                ->where('department_id', $employee->department_id)
                ->where('is_visible', true)
                ->exists();
    }

    private function isOwnLeaveRequest(LeaveRequest $leave, $user): bool {
        if ($user->employee && (int) $leave->employee_id === (int) $user->employee->id) {
            return true;
        }

        $leave->loadMissing('employee');
        return $leave->employee && (int) $leave->employee->user_id === (int) $user->id;
    }

    private function leaveRequestScope($user, bool $isHRAdmin, bool $isMgr) {
        $query = LeaveRequest::query();

        if ($isHRAdmin) {
            return $query;
        }

        if ($isMgr && $user->employee) {
            $teamIds = $user->employee->subordinates()->pluck('id');
            $teamIds->push($user->employee->id);
            return $query->whereIn('employee_id', $teamIds);
        }

        if ($user->employee) {
            return $query->where('employee_id', $user->employee->id);
        }

        return $query->whereRaw('1 = 0');
    }

    private function actionableLeaveRequestScope($user, bool $isHRAdmin, bool $isMgr) {
        $query = LeaveRequest::query();

        if ($isHRAdmin) {
            $query->whereIn('status', ['pending', 'manager_approved']);
        } elseif ($isMgr && $user->employee) {
            $query->where('status', 'pending')
                    ->whereIn('employee_id', $user->employee->subordinates()->pluck('id'));
        } else {
            return $query->whereRaw('1 = 0');
        }

        if ($user->employee) {
            $query->where('employee_id', '!=', $user->employee->id);
        }

        $query->whereDoesntHave('employee', fn($eq) => $eq->where('user_id', $user->id));

        return $this->activeEmployeeApprovalScope($query, rescue(fn() => $this->userRoles(), [], false));
    }

    private function shouldLimitApprovalViewsToActiveEmployees(array $userRoles): bool {
        return !in_array('super_admin', $userRoles, true)
            && ((bool) array_intersect($userRoles, ['department_manager', 'finance_manager', 'hr_manager']));
    }

    private function activeEmployeeApprovalScope($query, array $userRoles) {
        if (!$this->shouldLimitApprovalViewsToActiveEmployees($userRoles)) {
            return $query;
        }

        return $query->whereHas('employee', fn($employee) => $employee->whereIn('status', ['active', 'probation']));
    }

    public function types() {
        $user = auth()->user();
        $isHRAdmin = rescue(fn() => $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']), false, false);

        $query = LeaveType::where('is_active', true)
                ->when(!$isHRAdmin, function ($q) use ($user) {
                    $visibleIds = $this->visibleLeaveTypeIdsForDepartment($user->employee?->department_id);
                    if (is_array($visibleIds)) {
                        $q->whereIn('id', $visibleIds);
                    }
                });

        return response()->json(['types' => $query->orderBy('name')->get()]);
    }

    public function storeType(Request $request) {
        if (!$this->canManageLeaveTypes()) {
            return response()->json(['message' => 'You do not have permission to manage leave types.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:leave_types',
            'days_allowed' => 'required|integer|min:0',
            'is_paid' => 'boolean',
            'carry_forward' => 'boolean',
            'carry_forward_all' => 'boolean',
            'max_carry_forward' => 'required_if:carry_forward_all,false|integer|min:0|max:65535',
            'requires_document' => 'boolean',
            'is_active' => 'boolean',
            'skip_manager_approval' => 'boolean',
            'is_hourly' => 'boolean',
            'monthly_hours_limit' => 'nullable|numeric|min:0.5|max:200',
            'description' => 'nullable|string',
        ]);
        return response()->json(['type' => LeaveType::create($request->all())], 201);
    }

    public function updateType(Request $request, $id) {
        if (!$this->canManageLeaveTypes()) {
            return response()->json(['message' => 'You do not have permission to manage leave types.'], 403);
        }

        $type = LeaveType::findOrFail($id);
        $request->validate([
            'name' => 'sometimes|string|max:100',
            'days_allowed' => 'sometimes|integer|min:0',
            'is_paid' => 'boolean',
            'carry_forward' => 'boolean',
            'carry_forward_all' => 'boolean',
            'max_carry_forward' => 'required_if:carry_forward_all,false|integer|min:0|max:65535',
            'requires_document' => 'boolean',
            'is_active' => 'boolean',
            'skip_manager_approval' => 'boolean', // sick leave policy
            'is_hourly' => 'boolean',
            'monthly_hours_limit' => 'nullable|numeric|min:0.5|max:200',
            'description' => 'nullable|string',
        ]);
        $type->update($request->all());
        return response()->json(['type' => $type->fresh()]);
    }

    public function typeVisibility($id) {
        $type = LeaveType::findOrFail($id);
        $departments = \App\Models\Department::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']);

        $configured = DB::table('leave_type_department_visibility')
                ->where('leave_type_id', $type->id)
                ->get()
                ->keyBy('department_id');

        $visibility = $departments->map(function ($department) use ($configured, $type) {
            $row = $configured->get($department->id);

            return [
                'department_id' => $department->id,
                'department_name' => $department->name,
                'department_code' => $department->code,
                'leave_type_id' => $type->id,
                'visibility_id' => $row?->id,
                'is_visible' => $row ? (bool) $row->is_visible : true,
            ];
        });

        return response()->json(['visibility' => $visibility]);
    }

    public function saveTypeVisibility(Request $request, $id) {
        if (!$this->canManageLeaveTypes()) {
            return response()->json(['message' => 'You do not have permission to manage leave types.'], 403);
        }

        $type = LeaveType::findOrFail($id);
        $request->validate([
            'visibility' => 'required|array',
            'visibility.*.department_id' => 'required|exists:departments,id',
            'visibility.*.is_visible' => 'required|boolean',
        ]);

        foreach ($request->visibility as $row) {
            DB::table('leave_type_department_visibility')->updateOrInsert(
                    [
                        'leave_type_id' => $type->id,
                        'department_id' => $row['department_id'],
                    ],
                    [
                        'is_visible' => (bool) $row['is_visible'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
            );
        }

        return response()->json(['message' => 'Department visibility saved successfully.']);
    }

    private function canManageLeaveTypes(): bool {
        return $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']);
    }

    public function index(Request $request) {
        $user = auth()->user();

        // ── Role check via raw DB (no Spatie, no guard issues) ────────────────
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, ['super_admin', 'hr_manager', 'hr_staff']);
        $isMgr = (bool) array_intersect($userRoles, ['department_manager', 'finance_manager']);

        $ownOnly = $request->boolean('own');

        $query = LeaveRequest::with(['employee.department', 'leaveType'])
                ->when($ownOnly, function ($q) use ($user) {
                    $user->employee
                        ? $q->where('employee_id', $user->employee->id)
                        : $q->whereRaw('1 = 0');
                })
                ->when(!$ownOnly && !$isHRAdmin, function ($q) use ($user, $isMgr) {
                    if ($isMgr && $user->employee) {
                        $teamIds = $user->employee->subordinates()->pluck('id');
                        if (!request()->needs_action) {
                            $teamIds->push($user->employee->id);
                        }
                        $q->whereIn('employee_id', $teamIds);
                    } elseif ($user->employee) {
                        $q->where('employee_id', $user->employee->id);
                    }
                })
                ->when($request->needs_action && !$ownOnly, fn($q) => $q->whereIn('status',
                                $isHRAdmin ? ['pending', 'manager_approved'] : ['pending']
                        ))
                ->when($request->needs_action && !$ownOnly && $user->employee, fn($q) => $q->where('employee_id', '!=', $user->employee->id))
                ->when($request->needs_action && !$ownOnly, fn($q) => $q->whereDoesntHave('employee', fn($eq) => $eq->where('user_id', $user->id)))
                ->when(!$request->needs_action && $request->status, fn($q) => $q->where('status', $request->status))
                ->when(!$ownOnly && ($request->needs_action || $request->status === 'pending'), fn($q) => $this->activeEmployeeApprovalScope($q, $userRoles))
                ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
                ->when($request->leave_type_id, fn($q) => $q->where('leave_type_id', $request->leave_type_id))
                ->when($request->search, function ($q) use ($request) {
                    $search = trim((string) $request->search);
                    $q->where(function ($sub) use ($search) {
                        $sub->where('reason', 'like', "%{$search}%")
                            ->orWhereHas('employee', function ($employeeQuery) use ($search) {
                                $employeeQuery->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('employee_code', 'like', "%{$search}%");
                            });
                    });
                })
                ->orderBy('created_at', 'desc');

        $perPage = min(max((int) $request->input('per_page', 10), 10), 100);
        $paginated = $query->orderByDesc('id')->paginate($perPage);
        $paginated->getCollection()->transform(function ($leave) use ($user) {
            $isOwn = $this->isOwnLeaveRequest($leave, $user);
            $leave->setAttribute('can_approve', !$isOwn);
            $leave->setAttribute('can_reject', !$isOwn);
            return $leave;
        });

        return response()->json($paginated);
    }

    public function downloadDetailsReport(Request $request) {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'You do not have permission to download this report.'], 403);
        }

        $user = auth()->user();

        $query = LeaveRequest::with(['employee.department', 'leaveType', 'approver'])
                ->when($request->needs_action, fn($q) => $q->whereIn('status', ['pending', 'manager_approved']))
                ->when($request->needs_action && $user->employee, fn($q) => $q->where('employee_id', '!=', $user->employee->id))
                ->when($request->needs_action, fn($q) => $q->whereDoesntHave('employee', fn($eq) => $eq->where('user_id', $user->id)))
                ->when(!$request->needs_action && $request->status, fn($q) => $q->where('status', $request->status))
                ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
                ->when($request->leave_type_id, fn($q) => $q->where('leave_type_id', $request->leave_type_id))
                ->when($request->search, function ($q) use ($request) {
                    $search = trim((string) $request->search);
                    $q->where(function ($sub) use ($search) {
                        $sub->where('reason', 'like', "%{$search}%")
                            ->orWhereHas('employee', function ($employeeQuery) use ($search) {
                                $employeeQuery->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%")
                                    ->orWhere('employee_code', 'like', "%{$search}%");
                            });
                    });
                })
                ->orderBy('created_at', 'desc')
                ->orderByDesc('id');

        $headers = [
            'Employee ID', 'Employee Name', 'Department', 'Leave Type', 'Start Date',
            'Start Time', 'End Date', 'End Time', 'Leave Days', 'Leave Hours',
            'Half Day', 'Reason', 'Manager Approval Status', 'HR Status',
            'Overall Status', 'Approved By', 'Approved At', 'Rejected Reason',
            'Submitted At',
        ];

        $rows = $query->get()->map(function (LeaveRequest $leave) {
            $status = (string) $leave->status;
            $managerStatus = match ($status) {
                'pending' => 'Pending',
                'manager_approved', 'approved' => 'Approved',
                'rejected' => 'Rejected',
                'cancelled' => 'Cancelled',
                default => ucfirst(str_replace('_', ' ', $status)),
            };
            $hrStatus = match ($status) {
                'manager_approved' => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'cancelled' => 'Cancelled',
                default => '',
            };

            return [
                $leave->employee?->employee_code,
                $leave->employee?->full_name,
                $leave->employee?->department?->name,
                $leave->leaveType?->name,
                optional($leave->start_date)->format('Y-m-d'),
                $leave->start_time,
                optional($leave->end_date)->format('Y-m-d'),
                $leave->end_time,
                $leave->total_days,
                $leave->total_hours,
                $leave->is_half_day ? ($leave->half_day_period ?: 'Yes') : 'No',
                $leave->reason,
                $managerStatus,
                $hrStatus,
                ucfirst(str_replace('_', ' ', $status)),
                $leave->approver?->name,
                optional($leave->approved_at)->format('Y-m-d H:i'),
                $leave->rejection_reason,
                optional($leave->created_at)->format('Y-m-d H:i'),
            ];
        })->toArray();

        return $this->xlsxDownload(
            'leave-details-report-' . now()->format('Y-m-d') . '.xlsx',
            $headers,
            $rows
        );
    }

    public function store(Request $request) {
        $leaveType = LeaveType::findOrFail($request->leave_type_id);
        $employee = auth()->user()->employee;

        if (!$this->leaveTypeVisibleForEmployee($leaveType, $employee)) {
            return response()->json(['message' => "{$leaveType->name} is not available for your department."], 403);
        }

        // ── Business Excuse (hourly) ──────────────────────────────────────
        if ($leaveType->is_hourly) {
            $request->validate([
                'leave_type_id' => 'required|exists:leave_types,id',
                'start_date' => 'required|date',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'reason' => 'required|string|min:5',
            ]);

            $employee = $employee->load('department');
            $hours = $this->service->calculateExcuseHours(
                    $request->start_date,
                    $request->start_time,
                    $request->end_time
            );

            $error = $this->service->validateHourlyExcuse(
                    $employee,
                    $leaveType,
                    $request->start_date,
                    $request->start_time, $request->end_time, $hours
            );

            if ($error)
                return response()->json(['message' => $error], 422);

            // Document upload for hourly types
            $documentPath = null;
            if ($request->hasFile('document')) {
                $request->validate(['document' => 'file|mimes:pdf,jpg,jpeg,png|max:5120']);
                $documentPath = $request->file('document')->store(
                        "leave-documents/{$employee->id}", 'public'
                );
            }

            $leaveRequest = LeaveRequest::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $request->leave_type_id,
                        'start_date' => $request->start_date,
                        'end_date' => $request->start_date,
                        'start_time' => $request->start_time,
                        'end_time' => $request->end_time,
                        'total_days' => 0,
                        'total_hours' => $hours,
                        'document_path' => $documentPath,
                        'status' => $leaveType->skip_manager_approval ? 'manager_approved' : 'pending',
                        'reason' => $request->reason,
            ]);

            $this->logLeaveActivity($leaveRequest, 'submitted', "{$leaveType->name} leave request submitted.", [
                'to_status' => $leaveRequest->status,
                'total_hours' => $hours,
                'notes' => $request->reason,
            ]);

            $this->service->updateLeaveBalance($leaveRequest, 'submit');
            $this->service->notifyManager($leaveRequest, 'submitted');
            return response()->json(['message' => "{$leaveType->name} of {$hours}h submitted", 'request' => $leaveRequest->load('leaveType')], 201);
        }

        // ── Standard (daily) leave ────────────────────────────────────────
        $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|min:10',
            'is_half_day' => 'nullable|boolean',
            'half_day_period' => 'nullable|in:morning,afternoon',
            'requires_exit_reentry' => 'nullable|boolean',
            'requires_ticket' => 'nullable|boolean',
            'destination_country' => 'nullable|string|max:100',
            'ticket_dependent_ids' => 'nullable|array|max:3',
            'ticket_dependent_ids.*' => 'integer',
        ]);

        $employee = auth()->user()->employee;
        $isHalfDay = (bool) $request->is_half_day;
        $requiresTicket = $leaveType->is_annual ? (bool) $request->requires_ticket : false;
        $ticketYear = (int) date('Y', strtotime($request->start_date));
        $dependentIds = $this->annualTickets->validateSelection(
            $employee,
            $requiresTicket,
            array_map('intval', $request->input('ticket_dependent_ids', [])),
            $ticketYear
        );

        // Half day: force start_date = end_date, total = 0.5 days
        if ($isHalfDay) {
            $request->merge(['end_date' => $request->start_date]);
        }

        $overlappingLeave = $this->overlappingLeaveRequest(
            $employee->id,
            $request->start_date,
            $request->end_date
        );

        if ($overlappingLeave) {
            return response()->json([
                'message' => sprintf(
                    'You already have a %s leave request from %s to %s with status %s. Please choose different dates.',
                    $overlappingLeave->leaveType?->name ?? 'leave',
                    Carbon::parse($overlappingLeave->start_date)->toDateString(),
                    Carbon::parse($overlappingLeave->end_date)->toDateString(),
                    str_replace('_', ' ', $overlappingLeave->status)
                ),
            ], 422);
        }

        $workingDays = $this->service->calculateWorkingDays($request->start_date, $request->end_date);
        $totalDays = $isHalfDay ? ($workingDays > 0 ? 0.5 : 0) : $workingDays;

        if ($totalDays <= 0) {
            return response()->json(['message' => 'Selected dates do not contain working days after excluding weekends and holidays.'], 422);
        }

        $allocation = $this->allocationForLeaveDate($employee, $leaveType, Carbon::parse($request->end_date));
        $availableDays = $allocation
            ? ($this->isAnnualLeaveType($leaveType)
                ? (float) $this->projectedAnnualAllocation($allocation, Carbon::parse($request->end_date))->remaining_days
                : (float) $allocation->remaining_days)
            : null;

        if ($availableDays !== null && $availableDays < $totalDays) {
            return response()->json(['message' => "Insufficient leave balance. Available until {$request->end_date}: {$availableDays} days"], 422);
        }

        // ── Document upload (required if leave type has requires_document=true) ──
        if ($leaveType->requires_document && !$request->hasFile('document')) {
            return response()->json(['message' => "A supporting document is required for '{$leaveType->name}' leave."], 422);
        }

        $documentPath = null;
        if ($request->hasFile('document')) {
            $request->validate(['document' => 'file|mimes:pdf,jpg,jpeg,png|max:5120']);
            $documentPath = $request->file('document')->store(
                    "leave-documents/{$employee->id}", 'public'
            );
        }

        $leaveRequest = LeaveRequest::create(array_merge($request->only(['leave_type_id', 'start_date', 'end_date', 'reason']), [
                    'employee_id' => $employee->id,
                    'total_days' => $totalDays,
                    'is_half_day' => $isHalfDay,
                    'half_day_period' => $isHalfDay ? $request->half_day_period : null,
                    'requires_exit_reentry' => $leaveType->is_annual ? (bool) $request->requires_exit_reentry : false,
                    'requires_ticket' => $requiresTicket,
                    'ticket_year' => $requiresTicket ? $ticketYear : null,
                    'ticket_count' => $requiresTicket ? 1 + count($dependentIds) : 0,
                    'destination_country' => $leaveType->is_annual ? $request->destination_country : null,
                    'document_path' => $documentPath,
                    'status' => $leaveType->skip_manager_approval ? 'manager_approved' : 'pending',
        ]));

        if ($requiresTicket) {
            $this->annualTickets->savePassengers($leaveRequest, $employee, $dependentIds);
        }

        $this->logLeaveActivity($leaveRequest, 'submitted', "{$leaveType->name} leave request submitted.", [
            'to_status' => $leaveRequest->status,
            'total_days' => $totalDays,
            'notes' => $request->reason,
        ]);
        $this->service->updateLeaveBalance($leaveRequest, 'submit');
        /*
          |--------------------------------------------------------------------------
          | FIND ANY EMPLOYEE IN THE SAME DEPARTMENT WHO HAS APPLIED FOR ANNUAL LEAVE WITH IN THE SAME DATE PERIOD
          |--------------------------------------------------------------------------
         */
        
          $conflicts = $this->service->getDepartmentLeaveConflicts($leaveRequest);

        /*
          |--------------------------------------------------------------------------
          | Notify HR
          |--------------------------------------------------------------------------
         */

        $this->service->notifyManager($leaveRequest, 'submitted',$request->reason,$conflicts);
        /*
          |--------------------------------------------------------------------------
          | Notify Employee
          |--------------------------------------------------------------------------
         */
      
        
        $this->service->notifyEmployee($leaveRequest, 'submitted');

        return response()->json(['message' => 'Leave request submitted', 'request' => $leaveRequest->load('leaveType')], 201);
    }

    /**
     * Create linked HR requests after an annual leave reaches final approval.
     */
    private function createLinkedRequests(LeaveRequest $leave, $employee): void {
        $travelInfo = $leave->destination_country ? "Destination: {$leave->destination_country}. " : '';
        $dateInfo = "Annual leave: {$leave->start_date} – {$leave->end_date} ({$leave->total_days} days). ";
        $baseNote = "Auto-generated from annual leave request #{$leave->id}. {$dateInfo}{$travelInfo}";
        $passengerManifest = $leave->requires_ticket ? $this->ticketPassengerManifest($leave, $employee) : '';
        $selectionDetails = $passengerManifest
            ? "\n\nSelected ticket passengers:\n{$passengerManifest}"
            : '';

        // Exit re-entry visa request
        if ($leave->requires_exit_reentry) {
            $visaType = RequestType::where('code', 'VISA_EXIT_S')->orWhere('code', 'VISA_EXIT_M')->first();
            if (!$visaType) {
                $visaType = RequestType::where('category', 'visa')
                                ->where('name', 'LIKE', '%exit%')->first();
            }
            if ($visaType) {
                $dueDate = now()->addDays($visaType->sla_days)->toDateString();
                $this->saveLinkedRequest($leave, 'exit_reentry', [
                    'employee_id' => $employee->id,
                    'request_type_id' => $visaType->id,
                    'status' => 'pending',
                    'details' => $baseNote . 'Exit re-entry visa required before departure.' . $selectionDetails,
                    'required_by' => $leave->start_date,
                    'due_date' => $dueDate,
                    'copies_needed' => 1,
                ]);
            }
        }

        // Air ticket request
        if ($leave->requires_ticket) {
            $ticketType = RequestType::where('code', 'TRAVEL_TICKET')->first();
            if (!$ticketType) {
                $ticketType = RequestType::where('category', 'travel')
                                ->where('name', 'LIKE', '%ticket%')->first();
            }
            if ($ticketType) {
                $dueDate = now()->addDays($ticketType->sla_days)->toDateString();
                $this->saveLinkedRequest($leave, 'ticket', [
                    'employee_id' => $employee->id,
                    'request_type_id' => $ticketType->id,
                    'status' => 'pending',
                    'details' => $baseNote . "\n\nTicket passenger details:\n" . $passengerManifest,
                    'required_by' => $leave->start_date,
                    'due_date' => $dueDate,
                    'copies_needed' => max(1, (int) $leave->ticket_count),
                ]);
            }
        }
    }

    private function saveLinkedRequest(LeaveRequest $leave, string $service, array $values): void {
        $linked = EmployeeRequest::where('leave_request_id', $leave->id)
            ->where('linked_service', $service)
            ->first();

        // Adopt requests generated before source links were introduced.
        if (!$linked) {
            $linked = EmployeeRequest::where('employee_id', $leave->employee_id)
                ->where('details', 'LIKE', "%annual leave request #{$leave->id}.%")
                ->where('request_type_id', $values['request_type_id'])
                ->first();
        }

        if ($linked) {
            $linked->update(array_merge($values, [
                'leave_request_id' => $leave->id,
                'linked_service' => $service,
            ]));
            return;
        }

        EmployeeRequest::create(array_merge($values, [
            'reference' => $this->generateLeaveRef(),
            'leave_request_id' => $leave->id,
            'linked_service' => $service,
        ]));
    }

    private function ticketPassengerManifest(LeaveRequest $leave, $employee): string {
        $lines = [];
        $number = 1;
        foreach ($leave->ticketPassengers()->with('dependent')->get() as $passenger) {
            if ($passenger->passenger_type === 'employee') {
                $lines[] = implode("\n", [
                    "{$number}. Employee: {$employee->full_name}",
                    "   Employee code: {$employee->employee_code}",
                    '   Nationality: ' . ($employee->nationality ?: 'Not provided'),
                    '   Date of birth: ' . ($employee->dob?->format('Y-m-d') ?: 'Not provided'),
                    '   Email: ' . ($employee->email ?: 'Not provided'),
                    '   Phone: ' . ($employee->phone ?: 'Not provided'),
                ]);
            } else {
                $dependent = $passenger->dependent;
                $lines[] = implode("\n", [
                    "{$number}. Dependent: {$passenger->passenger_name}",
                    '   Relationship: ' . ($dependent?->relationship ? ucfirst($dependent->relationship) : 'Not provided'),
                    '   Nationality: ' . ($dependent?->nationality ?: 'Not provided'),
                    '   Date of birth: ' . ($dependent?->date_of_birth?->format('Y-m-d') ?: 'Not provided'),
                    '   Passport number: ' . ($dependent?->passport_number ?: 'Not provided'),
                    '   Passport expiry: ' . ($dependent?->passport_expiry?->format('Y-m-d') ?: 'Not provided'),
                ]);
            }
            $number++;
        }
        return implode("\n\n", $lines);
    }

    /** Generate a unique reference number for auto-created requests. */
    private function generateLeaveRef(): string {
        $year = now()->year;
        $count = EmployeeRequest::whereYear('created_at', $year)->count() + 1;
        return 'REQ-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
    }

    public function show($id) {
        $request = LeaveRequest::with(['employee', 'leaveType', 'approver', 'managerApprover', 'ticketPassengers'])->findOrFail($id);
        $request->setAttribute('activities', $this->activityService->timeline($request));
        return response()->json(['request' => $request]);
    }

    public function approve(Request $request, $id) {
        $leave = LeaveRequest::with(['leaveType', 'employee'])->findOrFail($id);
        $user = auth()->user();

        if ($this->isOwnLeaveRequest($leave, $user)) {
            return response()->json(['message' => 'You cannot approve your own leave request.'], 403);
        }

        // ── Stage 1: Manager approval ──────────────────────────────────
        if ($leave->status === 'pending') {
            // Only managers / HR / super_admin can approve at this stage
            if (!$this->hasAnyRoleDB(['department_manager', 'finance_manager', 'hr_manager', 'hr_staff', 'super_admin'])) {
                return response()->json(['message' => 'Only a manager can approve at this stage.'], 403);
            }

            if (
                $this->hasAnyRoleDB(['department_manager', 'finance_manager']) &&
                !$this->hasAnyRoleDB(['hr_manager', 'hr_staff', 'super_admin']) &&
                (!$user->employee || (int) $leave->employee?->manager_id !== (int) $user->employee->id)
            ) {
                return response()->json(['message' => 'Only the employee direct manager can approve this leave request.'], 403);
            }

            $oldStatus = $leave->status;
            $leave->update([
                'status' => 'manager_approved',
                'manager_approved_by' => $user->id,
                'manager_approved_at' => now(),
                'manager_notes' => $request->input('notes'),
            ]);
            if ($leave->leaveType && $this->isAnnualLeaveType($leave->leaveType)) {
                $this->service->updateLeaveBalance($leave, 'approve');
            }
            $this->logLeaveActivity($leave, 'manager_approved', 'Leave request approved at manager level.', [
                'from_status' => $oldStatus,
                'to_status' => 'manager_approved',
                'notes' => $request->input('notes'),
            ]);
            /*
              |--------------------------------------------------------------------------
              | Notify Employee
              |--------------------------------------------------------------------------
             */

            $this->service->notifyEmployee($leave, 'manager_approved');
            /*
              |--------------------------------------------------------------------------
              | Notify HR
              |--------------------------------------------------------------------------
             */
            $this->service->notifyManager($leave, 'manager_approved');

            return response()->json([
                        'message' => 'Approved at manager level. Awaiting HR approval.',
                        'leave' => $leave->fresh(['leaveType', 'employee', 'managerApprover']),
            ]);
        }

        // ── Stage 2: HR final approval ─────────────────────────────────
        if ($leave->status === 'manager_approved') {
            if (!$this->hasAnyRoleDB(['hr_manager', 'hr_staff', 'super_admin'])) {
                return response()->json(['message' => 'Only HR can give final approval.'], 403);
            }

            $oldStatus = $leave->status;
            $leave->update([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
            $this->logLeaveActivity($leave, 'hr_approved', 'Leave request fully approved by HR.', [
                'from_status' => $oldStatus,
                'to_status' => 'approved',
            ]);

            if ($leave->leaveType?->is_annual) {
                $this->createLinkedRequests($leave, $leave->employee);
            }

            $this->service->updateLeaveBalance($leave, 'approve');
            /*
              |--------------------------------------------------------------------------
              | Notify Employee
              |--------------------------------------------------------------------------
             */
            $this->service->notifyEmployee($leave, 'hr_approved');

            return response()->json([
                        'message' => 'Leave fully approved by HR.',
                        'leave' => $leave->fresh(['leaveType', 'employee', 'approver', 'managerApprover']),
            ]);
        }

        return response()->json(['message' => "Cannot approve a leave with status '{$leave->status}'."], 422);
    }

    public function reject(Request $request, $id) {
        $request->validate(['reason' => 'required|string']);
        $leave = LeaveRequest::with(['leaveType', 'employee'])->findOrFail($id);
        $user = auth()->user();

        if ($this->isOwnLeaveRequest($leave, $user)) {
            return response()->json(['message' => 'You cannot reject your own leave request.'], 403);
        }

        // Track which stage the rejection occurred at
        $stage = match ($leave->status) {
            'pending' => 'manager',
            'manager_approved' => 'hr',
            default => 'unknown',
        };
        if ($leave->status === 'pending') {
            if (!$this->hasAnyRoleDB(['department_manager', 'finance_manager', 'hr_manager', 'hr_staff', 'super_admin'])) {
                return response()->json(['message' => 'Only a manager can reject at this stage.'], 403);
            }

            if (
                $this->hasAnyRoleDB(['department_manager', 'finance_manager']) &&
                !$this->hasAnyRoleDB(['hr_manager', 'hr_staff', 'super_admin']) &&
                (!$user->employee || (int) $leave->employee?->manager_id !== (int) $user->employee->id)
            ) {
                return response()->json(['message' => 'Only the employee direct manager can reject this leave request.'], 403);
            }

            $action = 'manager_rejected';
        } elseif ($leave->status === 'manager_approved') {
            if (!$this->hasAnyRoleDB(['hr_manager', 'hr_staff', 'super_admin'])) {
                return response()->json(['message' => 'Only HR can reject at this stage.'], 403);
            }

            $action = 'hr_rejected';
        } else {

            return response()->json([
                        'message' => "Cannot reject leave with status '{$leave->status}'."
                            ], 422);
        }


        $oldStatus = $leave->status;
        $leave->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'rejected_stage' => $stage,
            'approved_by' => $user->id,
        ]);
        $this->logLeaveActivity($leave, $action, "Leave request rejected at {$stage} stage.", [
            'from_status' => $oldStatus,
            'to_status' => 'rejected',
            'reason' => $request->reason,
            'stage' => $stage,
        ]);
        $this->service->updateLeaveBalance($leave, 'cancel');
        $this->service->notifyEmployee($leave, $action,$request->reason);
        return response()->json(['message' => "Leave rejected at {$stage} stage."]);
    }

    public function cancel(Request $request, $id) {
        $leave = LeaveRequest::findOrFail($id);
        if (!in_array($leave->status, ['pending', 'manager_approved', 'approved'])) {
            return response()->json(['message' => 'Cannot cancel this leave'], 422);
        }
        $oldStatus = $leave->status;
        if (!$leave->leaveType || !$this->isAnnualLeaveType($leave->leaveType)) {
            $this->service->updateLeaveBalance($leave, 'cancel');
        }
        // Dates the cancellation so annual leave cancelled after it started still counts as taken.
        $leave->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        if ($leave->leaveType && $this->isAnnualLeaveType($leave->leaveType)) {
            $this->service->updateLeaveBalance($leave, 'cancel');
        }
        $this->logLeaveActivity($leave, 'cancelled', 'Leave request cancelled.', [
            'from_status' => $oldStatus,
            'to_status' => 'cancelled',
            'reason' => $request->input('reason'),
        ]);
        $this->service->notifyEmployee($leave, 'cancelled',$request->input('reason'));
        /*
          |--------------------------------------------------------------------------
          | Notify HR
          |--------------------------------------------------------------------------
         */
        $this->service->notifyManager($leave, 'cancelled');
        return response()->json(['message' => 'Leave cancelled']);
    }

    public function balance(Request $request, $empId) {
        if (!$request->query('as_of')) {
            $allocations = LeaveAllocation::with(['leaveType', 'employee'])
                    ->where('employee_id', $empId)
                    ->where('year', now()->year)
                    ->whereHas('leaveType', fn($query) => $this->balanceSheetLeaveTypeScope($query))
                    ->get()
                    ->map(fn(LeaveAllocation $allocation) => $this->decorateAnnualBalanceForDate($allocation, now('Asia/Riyadh')->startOfDay(), true));

            return response()->json(['balances' => $this->mergeAnnualBalances($allocations)]);
        }

        $asOf = $request->query('as_of')
            ? Carbon::parse($request->query('as_of'))->startOfDay()
            : now('Asia/Riyadh')->startOfDay();
        $employee = \App\Models\Employee::findOrFail($empId);
        $annualPeriodYear = $this->annualPeriodStart($employee, $asOf)->year;

        $allocations = LeaveAllocation::with(['leaveType', 'employee'])
                ->where('employee_id', $empId)
                ->whereIn('year', array_values(array_unique([$asOf->year, $annualPeriodYear])))
                ->whereHas('leaveType', fn($query) => $this->balanceSheetLeaveTypeScope($query))
                ->get()
                ->filter(function (LeaveAllocation $allocation) use ($asOf, $annualPeriodYear) {
                    if (!$allocation->leaveType || !$this->isAnnualLeaveType($allocation->leaveType)) {
                        return (int) $allocation->year === (int) $asOf->year;
                    }

                    if (!$allocation->accrual_year_start) {
                        return (int) $allocation->year === (int) $annualPeriodYear;
                    }

                    $periodStart = Carbon::parse($allocation->accrual_year_start)->startOfDay();
                    $periodEnd = $periodStart->copy()->addYear()->subDay()->endOfDay();

                    return $asOf->betweenIncluded($periodStart, $periodEnd);
                })
                ->map(function (LeaveAllocation $allocation) use ($asOf) {
                    if ($allocation->leaveType && $this->isAnnualLeaveType($allocation->leaveType)) {
                        return $this->projectedAnnualAllocation($allocation, $asOf);
                    }

                    return $allocation;
                })
                ->values();

        return response()->json(['balances' => $allocations]);
    }

    private function mergeAnnualBalances($allocations) {
        $annual = $allocations->filter(fn(LeaveAllocation $allocation) =>
            $allocation->leaveType && $this->isAnnualLeaveType($allocation->leaveType)
        );

        if ($annual->count() <= 1) {
            return $allocations->values();
        }

        $display = $annual->first(fn(LeaveAllocation $allocation) =>
            strtoupper((string) $allocation->leaveType?->code) === 'AL'
        ) ?: $annual->first();

        $allocated = (float) $annual->max(fn(LeaveAllocation $allocation) =>
            (float) ($allocation->annual_entitlement ?: $allocation->allocated_days)
        );
        $carriedForward = (float) $annual->max(fn(LeaveAllocation $allocation) =>
            (float) ($allocation->carried_forward_days ?? 0)
        );
        $periodStart = $display->accrual_year_start
            ? Carbon::parse($display->accrual_year_start)->startOfDay()
            : $this->annualPeriodStart($display->employee, now('Asia/Riyadh'));
        $usage = $this->annualUsageWithCarryForward($display, $periodStart, now('Asia/Riyadh')->startOfDay(), $carriedForward);
        $used = (float) $annual->sum(fn(LeaveAllocation $allocation) => (float) $allocation->used_days);
        $pending = (float) $annual->sum(fn(LeaveAllocation $allocation) => (float) $allocation->pending_days);
        $usedHours = (float) $annual->sum(fn(LeaveAllocation $allocation) => (float) ($allocation->used_hours ?? 0));
        $pendingHours = (float) $annual->sum(fn(LeaveAllocation $allocation) => (float) ($allocation->pending_hours ?? 0));
        $remaining = round($allocated + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pending, 2);

        $display->setAttribute('allocated_days', $allocated);
        $display->setAttribute('annual_entitlement', $allocated);
        $display->setAttribute('carried_forward_days', $carriedForward);
        $display->setAttribute('active_carried_forward_days', $usage['active_carry_forward_remaining']);
        $display->setAttribute('carry_forward_used_days', $usage['carry_forward_used_days']);
        $display->setAttribute('used_days', $used);
        $display->setAttribute('pending_days', $pending);
        $display->setAttribute('remaining_days', $remaining);
        $display->setAttribute('used_hours', $usedHours);
        $display->setAttribute('pending_hours', $pendingHours);

        return $allocations
            ->reject(fn(LeaveAllocation $allocation) =>
                $allocation->leaveType && $this->isAnnualLeaveType($allocation->leaveType)
            )
            ->prepend($display)
            ->values();
    }

    private function balanceSheetLeaveTypeScope($query) {
        return $query->where(function ($scope) {
            $scope->where('is_annual', true)
                ->orWhereIn(DB::raw('UPPER(code)'), ['AL', 'BE', 'PE'])
                ->orWhere('name', 'like', '%Annual%')
                ->orWhere('name', 'like', '%Business Excuse%')
                ->orWhere('name', 'like', '%Personal%');
        });
    }

    private function annualLeaveTypeScope($query) {
        return $query->where(function ($scope) {
            $scope->where('is_annual', true)
                ->orWhere(DB::raw('UPPER(code)'), 'AL')
                ->orWhere('name', 'like', '%Annual%');
        });
    }

    private function overlappingLeaveRequest(int $employeeId, string $startDate, string $endDate): ?LeaveRequest {
        return LeaveRequest::with('leaveType')
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'manager_approved', 'approved'])
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->orderBy('start_date')
            ->first();
    }

    private function allocationForLeaveDate($employee, LeaveType $leaveType, Carbon $date): ?LeaveAllocation {
        $query = LeaveAllocation::with(['leaveType', 'employee'])
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id);

        if (!$this->isAnnualLeaveType($leaveType)) {
            return $query->where('year', $date->year)->first();
        }

        return (clone $query)
            ->whereDate('accrual_year_start', '<=', $date->toDateString())
            ->whereDate(DB::raw('DATE_ADD(accrual_year_start, INTERVAL 1 YEAR)'), '>', $date->toDateString())
            ->first()
            ?: $query->where('year', $this->annualPeriodStart($employee, $date)->year)->first();
    }

    private function projectedAnnualAllocation(LeaveAllocation $allocation, Carbon $asOf): LeaveAllocation {
        $employee = $allocation->employee;
        $leaveType = $allocation->leaveType;

        if (!$employee || !$leaveType) {
            return $allocation;
        }

        $periodStart = $allocation->accrual_year_start
            ? Carbon::parse($allocation->accrual_year_start)->startOfDay()
            : $this->annualPeriodStart($employee, $asOf);
        $periodEnd = $periodStart->copy()->addYear()->subDay()->endOfDay();
        $balanceDate = $asOf->copy()->min($periodEnd)->max($periodStart);
        $entitlement = $this->annualEntitlement($employee, $leaveType, $periodStart, $allocation);
        $accrued = min($entitlement, round($this->countWorkingDays($periodStart, $balanceDate) * ($entitlement / 260), 2));
        $carriedForward = $this->annualCarryForwardForPeriod($allocation, $periodStart);

        $base = LeaveRequest::query()
            ->where('employee_id', $allocation->employee_id)
            ->whereDate('start_date', '<=', $balanceDate->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString());

        $base->whereHas('leaveType', fn($query) =>
            $query->where('is_annual', true)
                ->orWhere('code', 'AL')
                ->orWhere('name', 'like', '%Annual%')
        );

        $pendingDays = (float) (clone $base)->pendingForBalance()->sum('total_days');
        $usage = $this->annualUsageWithCarryForward($allocation, $periodStart, $periodEnd, $carriedForward);
        $usedDays = $usage['total_used_days'];
        $remainingDays = round($usage['active_carry_forward_remaining'] + $accrued - $usage['annual_used_days'], 2);

        $allocation->setAttribute('allocated_days', $accrued);
        $allocation->setAttribute('carried_forward_days', $carriedForward);
        $allocation->setAttribute('active_carried_forward_days', $usage['active_carry_forward_remaining']);
        $allocation->setAttribute('used_days', $usedDays);
        $allocation->setAttribute('annual_used_days_after_carry_forward', $usage['annual_used_days']);
        $allocation->setAttribute('carry_forward_used_days', $usage['carry_forward_used_days']);
        $allocation->setAttribute('pending_days', $pendingDays);
        $allocation->setAttribute('remaining_days', $remainingDays);
        $allocation->setAttribute('earned_until_as_of', $accrued);
        $allocation->setAttribute('approved_taken_until_as_of', $usedDays);
        $allocation->setAttribute('approved_taken_contract_period', $usedDays);
        $allocation->setAttribute('annual_entitlement', $entitlement);
        $allocation->setAttribute('contract_year_allocated_days', $entitlement);
        $allocation->setAttribute('balance_as_of', $balanceDate->toDateString());
        $allocation->setAttribute('accrual_period_start', $periodStart->toDateString());
        $allocation->setAttribute('accrual_period_end', $periodEnd->toDateString());

        return $allocation;
    }

    private function annualCarryForwardForPeriod(LeaveAllocation $allocation, Carbon $periodStart): float {
        $value = LeaveAllocation::query()
            ->where('employee_id', $allocation->employee_id)
            ->where('year', $periodStart->year)
            ->whereHas('leaveType', fn($query) =>
                $query->where('is_annual', true)
                    ->orWhere('code', 'AL')
                    ->orWhere('name', 'like', '%Annual%')
            )
            ->max('carried_forward_days');

        return (float) ($value ?? $allocation->carried_forward_days ?? 0);
    }

    private function decorateAnnualBalanceForDate(LeaveAllocation $allocation, Carbon $asOf, bool $includeFullPeriodUsage = false): LeaveAllocation {
        if (!$allocation->leaveType || !$this->isAnnualLeaveType($allocation->leaveType)) {
            return $allocation;
        }

        $periodStart = $allocation->accrual_year_start
            ? Carbon::parse($allocation->accrual_year_start)->startOfDay()
            : $this->annualPeriodStart($allocation->employee, $asOf);
        $periodEnd = $periodStart->copy()->addYear()->subDay()->endOfDay();
        $balanceDate = $asOf->copy()->min($periodEnd)->max($periodStart);
        $carriedForward = (float) ($allocation->carried_forward_days ?? 0);
        $usageEndDate = $includeFullPeriodUsage ? $periodEnd->copy() : $balanceDate->copy();
        $usage = $this->annualUsageWithCarryForward($allocation, $periodStart, $usageEndDate, $carriedForward);
        $pending = (float) LeaveRequest::where('employee_id', $allocation->employee_id)
            ->where('leave_type_id', $allocation->leave_type_id)
            ->whereDate('start_date', '<=', $usageEndDate->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->pendingForBalance()->sum('total_days');
        $allocation->setAttribute('pending_days', $pending);
        $allocation->setAttribute('used_days', $usage['total_used_days']);
        $remaining = round((float) $allocation->allocated_days + $usage['active_carry_forward_remaining'] - $usage['annual_used_days'] - $pending, 2);

        $allocation->setAttribute('remaining_days', $remaining);
        $allocation->setAttribute('active_carried_forward_days', $usage['active_carry_forward_remaining']);
        $allocation->setAttribute('carry_forward_used_days', $usage['carry_forward_used_days']);
        $allocation->setAttribute('annual_used_days_after_carry_forward', $usage['annual_used_days']);

        return $allocation;
    }

    private function annualUsageWithCarryForward(LeaveAllocation $allocation, Carbon $periodStart, Carbon $asOf, float $carriedForward): array {
        // Carry-in does not expire during the year: it is added to the allowance, and
        // annual leave consumes it before the current year's own entitlement.
        $balanceDate = $asOf->copy()->startOfDay();
        $usedDays = 0.0;

        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $allocation->employee_id)
            ->approvedForBalance()
            ->whereDate('start_date', '<=', $balanceDate->toDateString())
            ->whereDate('end_date', '>=', $periodStart->toDateString())
            ->whereHas('leaveType', fn($query) =>
                $query->where('is_annual', true)
                    ->orWhere('code', 'AL')
                    ->orWhere('name', 'like', '%Annual%')
            )
            ->get();

        foreach ($requests as $request) {
            $requestStart = Carbon::parse($request->start_date)->startOfDay()->max($periodStart);
            $requestEnd = Carbon::parse($request->end_date)->startOfDay()->min($balanceDate);
            if ($requestEnd->lt($requestStart)) {
                continue;
            }

            $usedDays += $this->leaveDaysWithin($request, $requestStart, $requestEnd);
        }

        $carryForwardUsed = min($carriedForward, $usedDays);

        return [
            'total_used_days' => round($usedDays, 2),
            'carry_forward_used_days' => round($carryForwardUsed, 2),
            'annual_used_days' => round(max(0, $usedDays - $carryForwardUsed), 2),
            'active_carry_forward_remaining' => round(max(0, $carriedForward - $carryForwardUsed), 2),
        ];
    }

    private function leaveDaysWithin(LeaveRequest $request, Carbon $start, Carbon $end): float {
        if ($request->is_half_day && $start->isSameDay($end)) {
            return 0.5;
        }

        return (float) $this->service->calculateWorkingDays($start->toDateString(), $end->toDateString());
    }

    private function annualPeriodStart($employee, Carbon $date): Carbon {
        if (!$employee?->hire_date) {
            return Carbon::create($date->year, 1, 1)->startOfDay();
        }

        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();
        $periodStart = Carbon::create($date->year, $hireDate->month, $hireDate->day)->startOfDay();

        return $periodStart->gt($date) ? $periodStart->subYear() : $periodStart;
    }

    private function annualEntitlement($employee, LeaveType $leaveType, Carbon $periodStart, ?LeaveAllocation $allocation = null): float {
        if ($allocation?->annual_entitlement) {
            return (float) $allocation->annual_entitlement;
        }

        if ($employee?->hire_date && Carbon::parse($employee->hire_date)->diffInYears($periodStart) >= 5) {
            return 30.0;
        }

        return (float) ($leaveType->days_allowed ?: 22);
    }

    private function isAnnualLeaveType(LeaveType $leaveType): bool {
        return (bool) $leaveType->is_annual
            || strtoupper((string) $leaveType->code) === 'AL'
            || str_contains(strtolower((string) $leaveType->name), 'annual');
    }

    private function countWorkingDays(Carbon $from, Carbon $to): int {
        if ($from->gt($to)) {
            return 0;
        }

        $count = 0;
        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($current->lte($end)) {
            if (!in_array($current->dayOfWeek, [5, 6], true)) {
                $count++;
            }
            $current->addDay();
        }

        return $count;
    }

    public function calendar(Request $request) {
        $user = auth()->user();
        $employee = $user->employee;
        $isHRAdmin = $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']);

        if (!$isHRAdmin && (!$employee || !$employee->department_id)) {
            return response()->json([
                'leaves' => [],
                'department' => null,
                'scope' => 'department',
            ]);
        }

        $monthStart = null;
        $monthEnd = null;

        if ($request->month || $request->year) {
            $year = (int) ($request->year ?: now()->year);
            $month = (int) ($request->month ?: now()->month);
            $monthStart = Carbon::create($year, $month, 1)->startOfDay();
            $monthEnd = $monthStart->copy()->endOfMonth();
        }

        $approved = LeaveRequest::with(['employee.department', 'leaveType'])
                ->where('status', 'approved')
                ->when($monthStart && $monthEnd, fn($q) =>
                    $q->whereDate('start_date', '<=', $monthEnd->toDateString())
                      ->whereDate('end_date', '>=', $monthStart->toDateString())
                )
                ->when(!$isHRAdmin, fn($q) =>
                    $q->whereHas('employee', fn($eq) => $eq->where('department_id', $employee->department_id))
                )
                ->when($isHRAdmin && $request->department_id, fn($q) =>
                    $q->whereHas('employee', fn($eq) => $eq->where('department_id', $request->department_id))
                )
                ->orderBy('start_date')
                ->get()
                ->map(function (LeaveRequest $leave) {
                    $data = $leave->toArray();
                    $data['start_date'] = optional($leave->start_date)->format('Y-m-d');
                    $data['end_date'] = optional($leave->end_date)->format('Y-m-d');
                    return $data;
                });

        return response()->json([
            'leaves' => $approved,
            'department' => $employee?->department,
            'scope' => $isHRAdmin && !$request->department_id ? 'all' : 'department',
        ]);
    }

    public function update(Request $request, $id) {
        $leave = LeaveRequest::findOrFail($id);
        if ($leave->status !== 'pending')
            return response()->json(['message' => 'Cannot edit non-pending leave'], 422);
        $leave->update($request->only(['start_date', 'end_date', 'reason']));
        $this->logLeaveActivity($leave, 'updated', 'Leave request details updated.', [
            'to_status' => $leave->status,
            'notes' => $request->input('reason'),
        ]);
        return response()->json(['message' => 'Leave updated', 'request' => $leave]);
    }

    public function runAccrual() {
        try {
            Artisan::call('leave:accrue');
            $output = Artisan::output();
            return response()->json([
                        'message' => 'Leave accrual completed successfully.',
                        'output' => $output,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Accrual failed: ' . $e->getMessage()], 500);
        }
    }

    public function stats() {
        $user = auth()->user();
        $userRoles = rescue(fn() => $this->userRoles(), [], false);
        $isAdmin = (bool) array_intersect($userRoles, ['super_admin', 'hr_manager', 'hr_staff']);
        $isMgr = (bool) array_intersect($userRoles, ['department_manager', 'finance_manager']);
        $today = now()->toDateString();

        $baseQ = $this->leaveRequestScope($user, $isAdmin, $isMgr);
        $needsActionCount = $this->actionableLeaveRequestScope($user, $isAdmin, $isMgr)->count();
        $awaitingManagerCount = $this->activeEmployeeApprovalScope(
            (clone $baseQ)->where('status', 'pending'),
            $userRoles
        )->count();
        $awaitingHrCount = (clone $baseQ)->where('status', 'manager_approved')->count();
        $pendingCount = $awaitingManagerCount + $awaitingHrCount;
        $approvedCount = (clone $baseQ)->where('status', 'approved')->count();
        $rejectedCount = (clone $baseQ)->where('status', 'rejected')->count();

        $approvedMonth = (clone $baseQ)->where('status', 'approved')
                        ->whereMonth('start_date', now()->month)->whereYear('start_date', now()->year)->count();
        $onLeaveToday = (clone $baseQ)->where('status', 'approved')
                        ->where('start_date', '<=', $today)->where('end_date', '>=', $today)->count();
        $cancelledCount = (clone $baseQ)->where('status', 'cancelled')->count();

        return response()->json([
                    'pending_count' => $pendingCount,
                    'needs_action_count' => $needsActionCount,
                    'awaiting_manager_count' => $awaitingManagerCount,
                    'awaiting_hr_count' => $awaitingHrCount,
                    'approved_count' => $approvedCount,
                    'rejected_count' => $rejectedCount,
                    'approved_month' => $approvedMonth,
                    'on_leave_today' => $onLeaveToday,
                    'cancelled_count' => $cancelledCount,
        ]);
    }

    public function allBalances(Request $request) {
        $user = auth()->user();
        $isHRAdmin = $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']);
        $isMgr = $this->hasAnyRoleDB(['department_manager', 'finance_manager']);
        $year = $request->year ?? now()->year;
        $visibleEmployeeIds = collect();

        if (!$isHRAdmin) {
            if ($isMgr && $user->employee) {
                $visibleEmployeeIds = $user->employee->subordinates()->pluck('id');
                $visibleEmployeeIds->push($user->employee->id);
            } elseif ($user->employee) {
                $visibleEmployeeIds->push($user->employee->id);
            }
        }

        $availableYears = LeaveAllocation::query()
                ->whereHas('leaveType', fn($q) => $this->annualLeaveTypeScope($q))
                ->when(!$isHRAdmin, fn($q) => $q->whereIn('employee_id', $visibleEmployeeIds->values()))
                ->when($isHRAdmin && $request->department_id, fn($q) =>
                        $q->whereHas('employee', fn($eq) => $eq->where('department_id', $request->department_id))
                )
                ->when(($isHRAdmin || $isMgr) && $request->search, fn($q) =>
                        $q->whereHas('employee', fn($eq) =>
                                $eq->where('first_name', 'like', "%{$request->search}%")
                                ->orWhere('last_name', 'like', "%{$request->search}%")
                        )
                )
                ->select('year')
                ->whereNotNull('year')
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(fn($value) => (int) $value)
                ->values();

        if ($availableYears->isNotEmpty() && !$availableYears->contains((int) $year)) {
            $year = $availableYears->first();
        }

        $allocations = LeaveAllocation::with(['employee.department', 'leaveType'])
                ->where('year', $year)
                ->whereHas('leaveType', fn($q) => $this->annualLeaveTypeScope($q))
                ->when(!$isHRAdmin, fn($q) => $q->whereIn('employee_id', $visibleEmployeeIds->values()))
                ->when($isHRAdmin && $request->department_id, fn($q) =>
                        $q->whereHas('employee', fn($eq) => $eq->where('department_id', $request->department_id))
                )
                ->when(($isHRAdmin || $isMgr) && $request->search, fn($q) =>
                        $q->whereHas('employee', fn($eq) =>
                                $eq->where('first_name', 'like', "%{$request->search}%")
                                ->orWhere('last_name', 'like', "%{$request->search}%")
                        )
                )
                ->orderBy('employee_id')
                ->paginate(25);

        $now = now('Asia/Riyadh')->startOfDay();
        $allocations->getCollection()->transform(function (LeaveAllocation $allocation) use ($now) {
            if ($allocation->leaveType && $this->isAnnualLeaveType($allocation->leaveType)) {
                return $this->projectedAnnualAllocation($allocation, $now);
            }

            return $allocation;
        });

        $response = $allocations->toArray();
        $response['available_years'] = $availableYears;
        $response['selected_year'] = (int) $year;

        return response()->json($response);
    }

    public function downloadAnnualBalanceReport(Request $request) {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'You do not have permission to download this report.'], 403);
        }

        $asOf = now('Asia/Riyadh')->startOfDay();
        $annualTypeIds = LeaveType::query()
            ->where('is_annual', true)
            ->orWhere('code', 'AL')
            ->orWhere('name', 'like', '%Annual%')
            ->pluck('id');

        if ($annualTypeIds->isEmpty()) {
            return response()->json(['message' => 'Annual leave type is not configured.'], 404);
        }

        $employees = Employee::with(['department', 'unit', 'designation'])
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->when($request->department_id, fn($query) => $query->where('department_id', $request->department_id))
            ->when($request->search, fn($query) => $query->where(function ($sub) use ($request) {
                $sub->where('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name', 'like', "%{$request->search}%")
                    ->orWhere('employee_code', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->orderBy('employee_code')
            ->get();

        $filename = 'annual-leave-balance-report-' . $asOf->format('Y-m-d') . '.xlsx';
        $headers = [
            'Employee Code',
            'Employee Name',
            'Department',
            'Unit',
            'Designation',
            'Hire Date',
            'Report Date',
            'Total Allocated From Hire Date',
            'Total Taken From Hire Date Until Now',
            'Current Contract Start',
            'Current Contract End',
            'This Contract Allocated Leave',
            'This Contract Carry Forward Leave',
            'This Contract Taken Leave Until Now',
            'Leave Balance Until Report Date',
        ];

        $rows = [];

        foreach ($employees as $employee) {
            $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date)->startOfDay() : null;
            $periodStart = $this->annualPeriodStart($employee, $asOf);
            $periodEnd = $periodStart->copy()->addYear()->subDay()->startOfDay();

            $allocations = LeaveAllocation::query()
                ->where('employee_id', $employee->id)
                ->whereIn('leave_type_id', $annualTypeIds)
                ->when($hireDate, fn($query) => $query->where(function ($scope) use ($hireDate) {
                    $scope->whereNull('accrual_year_start')
                        ->orWhereDate('accrual_year_start', '>=', $hireDate->toDateString());
                }))
                ->get();

            $totalAllocated = (float) $allocations
                ->filter(fn($allocation) => !$allocation->accrual_year_start || Carbon::parse($allocation->accrual_year_start)->lte($asOf))
                ->sum('allocated_days');

            $currentAllocation = $allocations->first(fn($allocation) =>
                $allocation->accrual_year_start
                    && Carbon::parse($allocation->accrual_year_start)->isSameDay($periodStart)
            ) ?: LeaveAllocation::query()
                ->where('employee_id', $employee->id)
                ->whereIn('leave_type_id', $annualTypeIds)
                ->where('year', $periodStart->year)
                ->first();

            $contractAllocated = $currentAllocation
                ? (float) $currentAllocation->allocated_days
                : (float) $this->annualEntitlement($employee, LeaveType::find($annualTypeIds->first()), $periodStart);
            $contractCarryForward = (float) ($currentAllocation?->carried_forward_days ?? 0);

            $totalTaken = $this->approvedAnnualLeaveTaken($employee->id, $annualTypeIds, $hireDate ?: $periodStart);
            $contractTaken = $this->approvedAnnualLeaveTaken($employee->id, $annualTypeIds, $periodStart, $periodEnd);
            $balanceDate = $asOf->copy()->min($periodEnd)->max($periodStart);
            $earnedUntilReportDate = min($contractAllocated, round(
                $this->countWorkingDays($periodStart, $balanceDate) * ($contractAllocated / 260),
                2
            ));
            $carryForwardTaken = min(
                $contractCarryForward,
                $this->approvedAnnualLeaveTaken($employee->id, $annualTypeIds, $periodStart, $periodEnd)
            );
            $activeCarryForwardRemaining = max(0, $contractCarryForward - $carryForwardTaken);
            $annualTakenAfterCarryForward = max(0, $contractTaken - $carryForwardTaken);
            $balanceUntilReportDate = round(
                $earnedUntilReportDate + $activeCarryForwardRemaining - $annualTakenAfterCarryForward,
                2
            );

            $rows[] = [
                $employee->employee_code,
                $employee->full_name,
                $employee->department?->name ?? '',
                $employee->unit?->name ?? '',
                $employee->designation?->title ?? '',
                $hireDate?->toDateString() ?? '',
                $asOf->toDateString(),
                $this->formatReportNumber($totalAllocated),
                $this->formatReportNumber($totalTaken),
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                $this->formatReportNumber($contractAllocated),
                $this->formatReportNumber($contractCarryForward),
                $this->formatReportNumber($contractTaken),
                $this->formatReportNumber($balanceUntilReportDate),
            ];
        }

        return $this->xlsxDownload($filename, $headers, $rows);
    }

    private function approvedAnnualLeaveTaken(int $employeeId, $annualTypeIds, Carbon $from, ?Carbon $to = null): float {
        $used = 0.0;
        $requests = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('leave_type_id', $annualTypeIds)
            ->approvedForBalance()
            ->whereDate('end_date', '>=', $from->toDateString())
            ->when($to, fn($query) => $query->whereDate('start_date', '<=', $to->toDateString()))
            ->get();

        foreach ($requests as $leave) {
            $leaveStart = Carbon::parse($leave->start_date)->startOfDay();
            $leaveEnd = Carbon::parse($leave->end_date)->startOfDay();
            if ($leaveStart->gte($from) && (!$to || $leaveEnd->lte($to))) {
                $used += (float) $leave->total_days;
                continue;
            }

            $start = $leaveStart->max($from);
            $end = $to ? $leaveEnd->min($to) : $leaveEnd;
            if ($end->gte($start)) {
                $used += $this->leaveDaysWithin($leave, $start, $end);
            }
        }

        return round($used, 2);
    }

    private function formatReportNumber(float $value): string {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function xlsxDownload(string $filename, array $headers, array $rows) {
        $path = tempnam(sys_get_temp_dir(), 'annual_leave_report_');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypesXml());
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlsxSheetXml($headers, $rows));
        $zip->close();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function xlsxSheetXml(array $headers, array $rows): string {
        $allRows = array_merge([$headers], $rows);
        $lastColumn = $this->xlsxColumnName(count($headers));
        $lastRow = max(1, count($allRows));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $lastColumn . $lastRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>';

        for ($i = 1; $i <= count($headers); $i++) {
            $width = $i <= 2 ? 24 : 18;
            $xml .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
        }

        $xml .= '</cols><sheetData>';

        foreach ($allRows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="' . $excelRow . '">';
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $this->xlsxColumnName($columnIndex + 1) . $excelRow;
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . $this->xlsxEscape((string) $value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
    }

    private function xlsxColumnName(int $index): string {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private function xlsxEscape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function xlsxContentTypesXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelsXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbookXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Annual Leave Balance" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelsXml(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';
    }

    public function holidays(Request $request) {
        if ($request->boolean('manage')) {
            $year = (int) ($request->year ?? now()->year);
            $holidays = \App\Models\Holiday::query()
                    ->where(function ($query) use ($year) {
                        $query->whereYear('date', $year)
                                ->orWhereYear('end_date', $year)
                                ->orWhere('is_recurring', true);
                    })
                    ->orderBy('date')
                    ->get()
                    ->map(function ($holiday) use ($year) {
                        if (!$holiday->is_recurring) {
                            return $holiday;
                        }

                        $holidayDate = Carbon::parse($holiday->date)->startOfDay();
                        $holidayEndDate = Carbon::parse($holiday->end_date ?: $holiday->date)->startOfDay();
                        $durationDays = max(0, $holidayDate->diffInDays($holidayEndDate));
                        $displayDate = Carbon::create($year, $holidayDate->month, $holidayDate->day)->startOfDay();

                        $copy = $holiday->replicate();
                        $copy->id = $holiday->id;
                        $copy->exists = true;
                        $copy->date = $displayDate->toDateString();
                        $copy->end_date = $displayDate->copy()->addDays($durationDays)->toDateString();

                        return $copy;
                    });

            return response()->json(['holidays' => $holidays]);
        }

        if ($request->start_date && $request->end_date) {
            $holidays = $this->holidaysForRange(
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->startOfDay()
            );

            return response()->json(['holidays' => $holidays]);
        }

        $year = (int) ($request->year ?? now()->year);
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->endOfYear();
        $holidays = $this->holidaysForRange($start, $end);

        return response()->json(['holidays' => $holidays]);
    }

    public function storeHoliday(Request $request) {
        if (!$this->canManageHolidays()) {
            return response()->json(['message' => 'You do not have permission to manage holidays.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:date',
            'is_recurring' => 'nullable|boolean',
        ]);
        $holiday = \App\Models\Holiday::create($request->only(['name', 'date', 'end_date', 'is_recurring']));
        return response()->json(['holiday' => $holiday], 201);
    }

    public function updateHoliday(Request $request, $id) {
        if (!$this->canManageHolidays()) {
            return response()->json(['message' => 'You do not have permission to manage holidays.'], 403);
        }

        $holiday = \App\Models\Holiday::findOrFail($id);
        $request->validate([
            'name' => 'required|string|max:100',
            'date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:date',
            'is_recurring' => 'nullable|boolean',
        ]);

        $holiday->update($request->only(['name', 'date', 'end_date', 'is_recurring']));
        return response()->json(['holiday' => $holiday->fresh()]);
    }

    public function deleteHoliday($id) {
        if (!$this->canManageHolidays()) {
            return response()->json(['message' => 'You do not have permission to manage holidays.'], 403);
        }

        \App\Models\Holiday::findOrFail($id)->delete();
        return response()->json(['message' => 'Holiday deleted']);
    }

    private function holidaysForRange(Carbon $start, Carbon $end) {
        $years = range((int) $start->year, (int) $end->year);
        $holidays = \App\Models\Holiday::query()
                ->where(function ($query) use ($start, $end) {
                    $query->whereRaw('date <= ? AND COALESCE(end_date, date) >= ?', [
                                $end->toDateString(),
                                $start->toDateString(),
                            ])
                            ->orWhere('is_recurring', true);
                })
                ->orderBy('date')
                ->get();

        return $holidays->flatMap(function ($holiday) use ($years, $start, $end) {
            $holidayDate = Carbon::parse($holiday->date)->startOfDay();
            $holidayEndDate = Carbon::parse($holiday->end_date ?: $holiday->date)->startOfDay();
            $durationDays = max(0, $holidayDate->diffInDays($holidayEndDate));

            $expand = function (Carbon $rangeStart, Carbon $rangeEnd) use ($holiday, $start, $end) {
                return collect(CarbonPeriod::create($rangeStart, $rangeEnd))
                        ->filter(fn($day) => $day->betweenIncluded($start, $end))
                        ->map(function ($day) use ($holiday, $rangeStart, $rangeEnd) {
                            $copy = $holiday->replicate();
                            $copy->id = $holiday->id;
                            $copy->exists = true;
                            $copy->date = $day->toDateString();
                            $copy->start_date = $rangeStart->toDateString();
                            $copy->end_date = $rangeEnd->toDateString();
                            return $copy;
                        });
            };

            if (!$holiday->is_recurring) {
                return $expand($holidayDate, $holidayEndDate);
            }

            return collect($years)
                    ->flatMap(function (int $year) use ($holidayDate, $durationDays, $expand) {
                        $rangeStart = Carbon::create($year, $holidayDate->month, $holidayDate->day)->startOfDay();
                        return $expand($rangeStart, $rangeStart->copy()->addDays($durationDays));
                    })
                    ->values();
        })
                ->sortBy('date')
                ->values();
    }

    public function excuseUsage(Request $request) {
        $user = auth()->user();
        $empId = $request->employee_id ?? $user->employee?->id;
        $year = $request->year ?? now()->year;
        $month = $request->month ?? now()->month;
        $leaveTypeId = $request->leave_type_id ? (int) $request->leave_type_id : null;

        if (!$empId)
            return response()->json(['message' => 'Employee not found'], 404);

        return response()->json($this->service->monthlyExcuseUsage($empId, $year, $month, $leaveTypeId));
    }

    
}
