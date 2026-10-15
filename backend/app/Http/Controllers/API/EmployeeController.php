<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Models\EmployeeDependent;
use App\Models\LeaveAllocation;
use App\Mail\EmployeeDocumentUploadedMail;
use App\Services\EmployeeService;
use App\Services\XlsxReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmployeeController extends Controller {

    public function __construct(protected EmployeeService $service, protected XlsxReportService $xlsxReports) {
        
    }

    // ── Role helper ───────────────────────────────────────────────────────

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

    private function hasAnyRoleDB(array $roles): bool {
        return (bool) array_intersect($this->userRoles(), $roles);
    }

    public function managerOptions(Request $request): JsonResponse {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $editingEmployeeId = (int) $request->query('employee_id', 0);
        $currentManagerId = $editingEmployeeId ? (int) Employee::whereKey($editingEmployeeId)->value('manager_id') : 0;
        $roleUserIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'department_manager')
            ->pluck('model_has_roles.model_id');
        $departmentManagerIds = DB::table('departments')->whereNotNull('manager_id')->pluck('manager_id');

        $managers = Employee::with(['department:id,name', 'designation:id,title,level'])
            ->where('id', '<>', $editingEmployeeId)
            ->where(function ($query) use ($roleUserIds, $departmentManagerIds, $currentManagerId) {
                $query->where(function ($active) use ($roleUserIds, $departmentManagerIds) {
                    $active->whereIn('status', ['active', 'probation'])->where(function ($candidate) use ($roleUserIds, $departmentManagerIds) {
                        $candidate->whereIn('user_id', $roleUserIds)
                            ->orWhereIn('id', $departmentManagerIds)
                            ->orWhereHas('designation', fn ($designation) => $designation->whereIn('level', ['manager', 'management', 'executive']))
                            ->orWhereHas('subordinates');
                    });
                });
                if ($currentManagerId) $query->orWhere('id', $currentManagerId);
            })
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'department_id', 'unit_id', 'designation_id', 'employee_code', 'first_name', 'last_name', 'status']);

        return response()->json(['managers' => $managers]);
    }

    // ── Index ─────────────────────────────────────────────────────────────

    /**
     * Return a paginated employee list with a 'meta' envelope.
     *
     * FIX: previously returned Laravel's raw paginator which puts pagination
     * keys at the top level. Now returns { data, meta } structure matching
     * the test contract and API Resources standard.
     */
    public function index(Request $request): JsonResponse {
        $user = auth()->user();

        // ── Role check via raw DB (no Spatie, no guard issues) ────────────────
        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
        $isMgr = (bool) array_intersect($userRoles, ['department_manager', 'finance_manager']);

        $query = Employee::with(['department', 'unit', 'designation', 'manager', 'user'])
                ->when(!$isHRAdmin, fn ($q) => $q->whereIn('status', ['active', 'probation']))
                // ── Role-based filtering ──────────────────────────────
                ->when(!$isHRAdmin, function ($q) use ($user, $isMgr, $request) {

                    if (!$user->employee) {
                        $q->whereRaw('1 = 0');
                        return;
                    }

                    // Department and Finance Managers → own department
                    if ($isMgr && $user->employee->department_id) {

                        $q->where('department_id', $user->employee->department_id);
                        if (!$request->boolean('dashboard_scope')) {
                            $q->where('id', '!=', $user->employee->id);
                        }
                    } else {

                        // Normal Employee → own record only
                        $q->where(
                                'id',
                                $user->employee->id
                        );
                    }
                })
                ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
                ->when($request->unit_id, fn($q) => $q->where('unit_id', $request->unit_id))
                ->when($request->boolean('interviewer_eligible'), function ($q) {
                    $q->where(function ($eligibilityQuery) {
                        $eligibilityQuery->where('status', 'active')
                            ->orWhere(function ($probationQuery) {
                                $probationQuery->where('status', 'probation')
                                    ->whereRaw('LOWER(email) LIKE ?', ['%@dbroker.com.sa']);
                            });
                    })->whereRaw('LOWER(COALESCE(email, ?)) <> ?', ['', 'admin@hrms.com']);
                }, fn ($q) => $q->when($request->status, fn ($statusQuery) => $statusQuery->where('status', $request->status)))
                ->when($request->employment_type, fn($q) => $q->where('employment_type', $request->employment_type))
                ->when($request->search, fn($q) => $q->where(function ($sub) use ($request) {
                            $sub->where('first_name', 'like', "%{$request->search}%")
                            ->orWhere('last_name', 'like', "%{$request->search}%")
                            ->orWhere('email', 'like', "%{$request->search}%")
                            ->orWhere('employee_code', 'like', "%{$request->search}%");
                        }))
                ->orderBy($request->sort_by ?? 'created_at', $request->sort_dir ?? 'desc');

        $paginator = $query->paginate((int) ($request->per_page ?? 15));

        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager'])) {
            $paginator->getCollection()->each->makeHidden([
                'salary', 'housing_allowance', 'transport_allowance',
                'other_allowances', 'mobile_allowance', 'food_allowance',
                'bank_name', 'bank_account',
            ]);
        }

        return response()->json([
                    'data' => $paginator->items(),
                    'meta' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'from' => $paginator->firstItem(),
                        'to' => $paginator->lastItem(),
                    ],
                    'links' => [
                        'first' => $paginator->url(1),
                        'last' => $paginator->url($paginator->lastPage()),
                        'prev' => $paginator->previousPageUrl(),
                        'next' => $paginator->nextPageUrl(),
                    ],
        ]);
    }

    // ── Stats ─────────────────────────────────────────────────────────────

    public function stats(Request $request): JsonResponse {

        $user = auth()->user();

        $userRoles = rescue(fn() => DB::table('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.model_id', $user->id)
                        ->pluck('roles.name')
                        ->toArray(), [], false);

        $isHRAdmin = (bool) array_intersect($userRoles, [
                    'super_admin',
                    'ceo',
                    'hr_manager',
                    'hr_staff'
        ]);

        $isMgr = (bool) array_intersect($userRoles, ['department_manager', 'finance_manager']);

        $baseQuery = DB::table('employees')
                ->when(!$isHRAdmin, fn ($q) => $q->whereIn('status', ['active', 'probation']))
                ->whereNull('deleted_at');

        if (!$isHRAdmin) {

            if ($isMgr && $user->employee?->department_id) {

                // Manager: employees in same department except himself
                $baseQuery->where('department_id', $user->employee->department_id);
                if (!$request->boolean('dashboard_scope')) {
                    $baseQuery->where('id', '!=', $user->employee->id);
                }
            } elseif ($user->employee) {

                // Normal employee: only himself
                $baseQuery->where('id', $user->employee->id);
            } else {
                $baseQuery->whereRaw('1 = 0');
            }
        }

        $safe = fn(callable $fn) => rescue($fn, 0, false);

        $month = now()->month;
        $year = now()->year;

        return response()->json([
                    'total' => $safe(
                            fn() => (clone $baseQuery)->count()
                    ),
                    'active' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'active')
                                    ->count()
                    ),
                    'probation' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'probation')
                                    ->count()
                    ),
                    'on_leave' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'on_leave')
                                    ->count()
                    ),
                    'terminated' => $safe(
                            fn() => (clone $baseQuery)
                                    ->where('status', 'terminated')
                                    ->count()
                    ),
                    'new_this_month' => $safe(
                            fn() => (clone $baseQuery)
                                    ->whereMonth('hire_date', $month)
                                    ->whereYear('hire_date', $year)
                                    ->count()
                    ),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────

    /**
     * FIX: Added role guard (returns 403 for non-HR users).
     * FIX: Temp password now includes a random component so it's unique per call.
     * FIX: Messages include trailing period to match test contract.
     */
    public function store(Request $request): JsonResponse {
        // Role guard — only HR roles can create employees
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:employees,email',
            'hire_date' => 'required|date',
            'department_id' => 'nullable|exists:departments,id',
            'unit_id' => 'nullable|exists:units,id',
            'designation_id' => 'nullable|exists:designations,id',
            'manager_id' => 'nullable|exists:employees,id',
            'employment_type' => 'required|in:full_time,part_time,contract,intern',
            'status' => 'sometimes|in:active,inactive,terminated,on_leave,probation',
            'salary' => 'required|numeric|min:0',
            'confirmation_date' => 'nullable|date',
            'termination_date' => 'nullable|date',
            'probation_period' => 'nullable|integer|min:0',
            'years_of_experience' => 'nullable|integer|min:0',
            'dob' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request): JsonResponse {
                    // Unique temp password: date + random suffix
                    $tempPassword = 'Hrms@' . now()->format('dmy') . strtoupper(substr(md5(uniqid('', true)), 0, 6)) . '!';

                    $user = User::create([
                                'name' => $request->first_name . ' ' . $request->last_name,
                                'email' => $request->email,
                                'password' => Hash::make($tempPassword),
                    ]);
                    $user->assignRole('employee');

                    $code = $this->service->generateCode();

                    $employeeData = $request->only([
                        'first_name', 'last_name', 'email', 'phone', 'hire_date',
                        'department_id', 'unit_id', 'designation_id', 'manager_id', 'employment_type',
                        'status', 'salary', 'confirmation_date', 'termination_date',
                        'probation_period', 'years_of_experience', 'dob',
                        'nationality', 'gender', 'marital_status',
                        'housing_allowance', 'transport_allowance', 'mobile_allowance',
                        'food_allowance', 'other_allowances', 'mode_of_employment'
                    ]);

                    $employee = Employee::create(array_merge($employeeData, [
                                'user_id' => $user->id,
                                'employee_code' => $code,
                    ]));

                    $this->service->createDefaultLeaveAllocations($employee);
                    $this->service->createOnboardingTasks($employee);

                    return response()->json([
                        'message' => 'Employee created successfully.',
                        'employee' => $employee->load(['department', 'designation']),
                        'temp_password' => $tempPassword,
                            ], 201);
                });
    }

    // ── Show ──────────────────────────────────────────────────────────────

    public function show(int $id): JsonResponse {
        try {
            $employee = Employee::with([
                        'department', 'unit', 'designation', 'manager',
                        'leaveAllocations.leaveType',
                        'onboardingTasks',
                        'dependents',
                    ])->findOrFail($id);

            if ($this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']) || (int) auth()->user()?->employee?->id === $employee->id) {
                $employee->makeVisible(['national_id', 'bank_account']);
            }

            if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager'])) {
                $employee->makeHidden([
                    'salary', 'housing_allowance', 'transport_allowance',
                    'other_allowances', 'mobile_allowance', 'food_allowance',
                    'bank_name', 'bank_account',
                ]);
            }

            return response()->json(['employee' => $employee]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'Employee not found'], 404);
        }
    }

    public function leaveBalances(Request $request, int $id): JsonResponse {
        $employee = Employee::findOrFail($id);

        if (!$this->canViewEmployeeLeaveBalances($employee)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $asOf = now();
        $periods = $this->contractPeriodsFor($employee, $asOf);
        $requestedStart = $request->string('period_start')->toString();
        $selected = collect($periods)->firstWhere('start_date', $requestedStart)
            ?? collect($periods)->firstWhere('is_current', true)
            ?? collect($periods)->first();

        if (!$selected) {
            return response()->json([
                'periods' => [],
                'selected_period' => null,
                'balances' => [],
            ]);
        }

        $periodStart = Carbon::parse($selected['start_date']);
        $periodEnd = Carbon::parse($selected['end_date']);

        $allocations = LeaveAllocation::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where(function ($query) use ($periodStart, $periodEnd) {
                $query->whereBetween('accrual_year_start', [$periodStart->toDateString(), $periodEnd->toDateString()])
                    ->orWhere('year', $periodStart->year);
            })
            ->get();

        $setters = User::whereIn('id', $allocations->pluck('carry_forward_overridden_by')->filter()->unique())
            ->pluck('name', 'id')->all();

        $allocations = $allocations
            ->sortBy(fn ($allocation) => $allocation->leaveType?->name ?? '')
            ->values()
            ->map(fn ($allocation) => [
                'id' => $allocation->id,
                'year' => $allocation->year,
                'allocated_days' => (float) $allocation->allocated_days,
                'remaining_days' => (float) $allocation->remaining_days,
                'used_days' => (float) $allocation->used_days,
                'pending_days' => (float) $allocation->pending_days,
                'carried_forward_days' => (float) ($allocation->carried_forward_days ?? 0),
                'carry_forward_overridden' => (bool) $allocation->carry_forward_overridden_at,
                'carry_forward_overridden_at' => optional($allocation->carry_forward_overridden_at)->toDateTimeString(),
                'carry_forward_overridden_by_name' => $setters[$allocation->carry_forward_overridden_by] ?? null,
                'accrual_year_start' => optional($allocation->accrual_year_start)->toDateString(),
                'annual_entitlement' => $allocation->annual_entitlement,
                'leave_type' => $allocation->leaveType ? [
                    'id' => $allocation->leaveType->id,
                    'name' => $allocation->leaveType->name,
                    'code' => $allocation->leaveType->code,
                ] : null,
            ]);

        return response()->json([
            'periods' => $periods,
            'selected_period' => $selected,
            'balances' => $allocations,
            'can_set_carry_forward' => $this->canSetCarryForward($employee),
        ]);
    }

    /** HR managers and system admins may correct the carry-forward of an active employee. */
    private function canSetCarryForward(Employee $employee): bool {
        return $employee->status === 'active'
            && $this->hasAnyRoleDB(['super_admin', 'hr_manager']);
    }

    public function updateLeaveCarryForward(Request $request, int $id): JsonResponse {
        $employee = Employee::findOrFail($id);

        if (!$this->canSetCarryForward($employee)) {
            return response()->json([
                'message' => $employee->status === 'active'
                    ? 'Only an HR manager or system admin can set carry forward.'
                    : 'Carry forward can only be set for active employees.',
            ], 403);
        }

        $data = $request->validate([
            'period_start' => 'required|date',
            // null clears the manual figure and hands the period back to the normal rule.
            'carried_forward_days' => 'present|nullable|numeric|min:0|max:999.9',
        ]);

        $periodStart = Carbon::parse($data['period_start'])->startOfDay();

        if (!collect($this->contractPeriodsFor($employee, now()))->firstWhere('start_date', $periodStart->toDateString())) {
            return response()->json(['message' => 'That contract period does not exist for this employee.'], 422);
        }

        $allocation = LeaveAllocation::with(['employee', 'leaveType'])
            ->where('employee_id', $employee->id)
            ->whereHas('leaveType', fn ($query) => $query->where('is_annual', true)
                ->orWhere('code', 'AL')
                ->orWhere('name', 'like', '%Annual%'))
            ->where(function ($query) use ($periodStart) {
                $query->whereDate('accrual_year_start', $periodStart->toDateString())
                    ->orWhere(function ($legacy) use ($periodStart) {
                        $legacy->whereNull('accrual_year_start')->where('year', $periodStart->year);
                    });
            })
            ->first();

        if (!$allocation) {
            return response()->json(['message' => 'No annual leave allocation exists for that contract period yet.'], 422);
        }

        $days = $data['carried_forward_days'] === null ? null : (float) $data['carried_forward_days'];
        $userId = auth()->id() === null ? null : (int) auth()->id();
        app(\App\Services\AnnualLeaveAllocationService::class)->setCarryForward($allocation, $days, $userId);

        activity('leave_allocation')
            ->causedBy(auth()->user())
            ->performedOn($allocation)
            ->withProperties([
                'employee_id' => $employee->id,
                'contract_period_start' => $periodStart->toDateString(),
                'carried_forward_days' => (float) $allocation->carried_forward_days,
                'cleared' => $days === null,
            ])
            ->log($days === null ? 'Annual leave carry forward reset to the calculated value' : 'Annual leave carry forward set manually');

        return response()->json([
            'message' => $days === null
                ? 'Carry forward reset to the calculated value.'
                : 'Carry forward updated.',
            'carried_forward_days' => (float) $allocation->carried_forward_days,
            'remaining_days' => (float) $allocation->remaining_days,
            'carry_forward_overridden' => (bool) $allocation->carry_forward_overridden_at,
        ]);
    }

    private function contractPeriodsFor(Employee $employee, Carbon $asOf): array {
        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date)->startOfDay() : $asOf->copy()->startOfYear();
        $cursor = $hireDate->copy();
        $periods = [];
        $guard = 0;

        while ($cursor->lte($asOf) && $guard < 80) {
            $start = $cursor->copy();
            $end = $start->copy()->addYear()->subDay();
            $periods[] = [
                'label' => $start->format('d M Y') . ' - ' . $end->format('d M Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'year' => $start->year,
                'is_current' => $asOf->betweenIncluded($start, $end),
            ];
            $cursor->addYear();
            $guard++;
        }

        return array_reverse($periods);
    }

    private function canViewEmployeeLeaveBalances(Employee $employee): bool {
        if ($this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return true;
        }

        $authEmployee = auth()->user()?->employee;
        if (!$authEmployee) {
            return false;
        }

        if ((int) $authEmployee->id === (int) $employee->id) {
            return true;
        }

        if ((int) $employee->manager_id === (int) $authEmployee->id) {
            return true;
        }

        return $this->hasAnyRoleDB(['department_manager'])
            && (int) $authEmployee->department_id === (int) $employee->department_id;
    }

    public function dependents(int $id): JsonResponse {
        $employee = Employee::findOrFail($id);
        $user = auth()->user();
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']) && (int) $user->employee?->id !== $employee->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        return response()->json(['dependents' => $employee->dependents()->orderBy('full_name')->get()]);
    }

    public function storeDependent(Request $request, int $id): JsonResponse {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $employee = Employee::findOrFail($id);
        $data = $this->validateDependent($request);
        $data = $this->storeDependentFiles($request, $id, $data);
        return response()->json(['dependent' => $employee->dependents()->create($data)], 201);
    }

    public function updateDependent(Request $request, int $id, int $dependentId): JsonResponse {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $dependent = EmployeeDependent::where('employee_id', $id)->findOrFail($dependentId);
        $data = $this->storeDependentFiles($request, $id, $this->validateDependent($request), $dependent);
        $dependent->update($data);
        return response()->json(['dependent' => $dependent->fresh()]);
    }

    public function deleteDependent(int $id, int $dependentId): JsonResponse {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $dependent = EmployeeDependent::where('employee_id', $id)->findOrFail($dependentId);
        Storage::delete(array_filter([$dependent->passport_file_path, $dependent->id_file_path]));
        $dependent->delete();
        return response()->json(['message' => 'Dependent deleted.']);
    }

    public function downloadDependentDocument(int $id, int $dependentId, string $type) {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $dependent = EmployeeDependent::where('employee_id', $id)->findOrFail($dependentId);
        $path = $type === 'passport' ? $dependent->passport_file_path : $dependent->id_file_path;
        $name = $type === 'passport' ? $dependent->passport_file_name : $dependent->id_file_name;
        if (!$path || !Storage::exists($path)) return response()->json(['message' => 'Document not found.'], 404);
        return Storage::download($path, $name ?: basename($path));
    }

    private function validateDependent(Request $request): array {
        return $request->validate([
            'full_name' => 'required|string|max:200',
            'relationship' => 'required|in:spouse,son,daughter,father,mother,other',
            'date_of_birth' => 'nullable|date|before:today',
            'nationality' => 'nullable|string|max:100',
            'id_number' => 'required|string|max:50',
            'id_expiry' => 'nullable|date',
            'passport_number' => 'nullable|string|max:50',
            'passport_expiry' => 'nullable|date',
            'passport_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'id_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function storeDependentFiles(Request $request, int $employeeId, array $data, ?EmployeeDependent $dependent = null): array {
        unset($data['passport_file'], $data['id_file']);
        foreach (['passport', 'id'] as $type) {
            $field = "{$type}_file";
            if (!$request->hasFile($field)) continue;
            $pathColumn = "{$type}_file_path";
            $nameColumn = "{$type}_file_name";
            if ($dependent?->{$pathColumn}) Storage::delete($dependent->{$pathColumn});
            $data[$pathColumn] = $request->file($field)->store("employees/{$employeeId}/dependents");
            $data[$nameColumn] = $request->file($field)->getClientOriginalName();
        }
        return $data;
    }

    private function canManageDependents(int $employeeId): bool {
        $userEmployee = auth()->user()?->employee;

        return $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])
            || (int) $userEmployee?->id === $employeeId
            || (
                $this->hasAnyRoleDB(['department_manager'])
                && $userEmployee
                && Employee::whereKey($employeeId)
                    ->where('department_id', $userEmployee->department_id)
                    ->exists()
            );
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse {
        $employee = Employee::findOrFail($id);

        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']) && (int) auth()->user()?->employee?->id !== $employee->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($request->has('extension') && !$this->hasAnyRoleDB(['super_admin', 'hr_manager'])) {
            return response()->json(['message' => 'Only an HR manager or system administrator can update the telephone extension.'], 403);
        }

        $data = $request->validate([
            'prefix' => 'sometimes|nullable|string|max:10',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'arabic_name' => 'sometimes|nullable|string|max:200',
            'email' => 'sometimes|required|email|unique:employees,email,' . $id,
            'phone' => 'sometimes|nullable|string|max:30',
            'work_phone' => 'sometimes|nullable|string|max:30',
            'extension' => 'sometimes|nullable|string|max:10',
            'dob' => 'sometimes|nullable|date|before:today',
            'gender' => 'sometimes|nullable|in:male,female,other',
            'marital_status' => 'sometimes|nullable|in:single,married,divorced,widowed',
            'nationality' => 'sometimes|nullable|string|max:100',
            'national_id' => 'sometimes|nullable|string|max:50',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:100',
            'country' => 'sometimes|nullable|string|max:100',
            'department_id' => 'sometimes|nullable|exists:departments,id',
            'unit_id' => 'sometimes|nullable|exists:units,id',
            'designation_id' => 'sometimes|nullable|exists:designations,id',
            'manager_id' => 'sometimes|nullable|exists:employees,id',
            'employment_type' => 'sometimes|required|in:full_time,part_time,contract,intern',
            'mode_of_employment' => 'sometimes|nullable|in:direct,agency,outsourced,secondment',
            'role' => 'sometimes|nullable|string|max:50',
            'status' => 'sometimes|required|in:active,inactive,terminated,on_leave,probation',
            'hire_date' => 'sometimes|required|date',
            'confirmation_date' => 'sometimes|nullable|date',
            'termination_date' => 'sometimes|nullable|date',
            'probation_period' => 'sometimes|nullable|integer|min:0|max:365',
            'years_of_experience' => 'sometimes|nullable|integer|min:0|max:60',
            'salary' => 'sometimes|required|numeric|min:0|max:9999999.99',
            'housing_allowance' => 'sometimes|nullable|numeric|min:0|max:9999999.99',
            'transport_allowance' => 'sometimes|nullable|numeric|min:0|max:9999999.99',
            'mobile_allowance' => 'sometimes|nullable|numeric|min:0|max:9999999.99',
            'food_allowance' => 'sometimes|nullable|numeric|min:0|max:9999999.99',
            'other_allowances' => 'sometimes|nullable|numeric|min:0|max:9999999.99',
            'bank_name' => 'sometimes|nullable|string|max:100',
            'bank_account' => 'sometimes|nullable|string|max:50',
            'emergency_contact_name' => 'sometimes|nullable|string|max:100',
            'emergency_contact_phone' => 'sometimes|nullable|string|max:30',
            'emergency_contact_relation' => 'sometimes|nullable|string|max:50',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        $employee->update($data);

        if ($employee->user && ($request->has('first_name') || $request->has('last_name') || $request->has('email'))) {
            $employee->user->update([
                'name' => $employee->full_name,
                'email' => $employee->email,
            ]);
        }

        return response()->json([
                    'message' => 'Employee updated successfully.',
                    'employee' => $employee->load(['department', 'unit', 'designation']),
        ]);
    }

    // ── Destroy ───────────────────────────────────────────────────────────

    /**
     * FIX: message now ends with period to match test contract.
     */
    public function destroy(int $id): JsonResponse {
        $employee = Employee::findOrFail($id);
        $employee->update(['status' => 'terminated', 'termination_date' => now()]);
        $employee->delete();
        $employee->user?->tokens()->delete();

        return response()->json(['message' => 'Employee terminated and archived.']);
    }

    // ── Avatar / Documents / Export ───────────────────────────────────────

    public function uploadAvatar(Request $request, int $id): JsonResponse {
        $request->validate(['avatar' => 'required|image|max:2048']);
        $employee = Employee::findOrFail($id);
        if ($employee->avatar)
            Storage::delete($employee->avatar);
        $path = $request->file('avatar')->store('avatars', 'public');
        $employee->update(['avatar' => $path]);
        return response()->json(['avatar_url' => asset('storage/' . $path)]);
    }

    public function uploadDocument(Request $request, int $id): JsonResponse {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $request->validate([
            'title' => 'required|string|max:100',
            'type' => 'required|in:contract,id,certificate,visa,passport,medical,other',
            'file' => 'required|file|max:10240',
            'expiry_date' => 'nullable|date',
        ]);
        $employee = Employee::findOrFail($id);
        $isHrUpload = $this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff']);
        $path = $request->file('file')->store("employees/{$id}/documents");
        $doc = $employee->documents()->create([
            'title' => $request->title,
            'type' => $request->type,
            'file_path' => $path,
            'file_name' => $request->file('file')->getClientOriginalName(),
            'mime_type' => $request->file('file')->getMimeType(),
            'file_size' => $request->file('file')->getSize(),
            'expiry_date' => $request->expiry_date,
            'is_verified' => $isHrUpload,
            'uploaded_by' => auth()->id(),
            'verified_by' => $isHrUpload ? auth()->id() : null,
            'verified_at' => $isHrUpload ? now() : null,
        ]);

        if (!$isHrUpload) {
            $this->notifyHrDocumentUploaded($employee, $doc);
        }

        return response()->json(['document' => $doc], 201);
    }

    private function notifyHrDocumentUploaded(Employee $employee, EmployeeDocument $document): void {
        try {
            $hrEmails = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['hr_manager', 'hr_staff']))
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique()
                ->values();

            if ($hrEmails->isEmpty()) {
                return;
            }

            $primaryEmail = $hrEmails->shift();
            Mail::to($primaryEmail)
                ->cc($hrEmails->all())
                ->send(new EmployeeDocumentUploadedMail($employee, $document));
        } catch (\Throwable $e) {
            Log::warning('Employee document verification email failed.', [
                'employee_id' => $employee->id,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function listDocuments(int $id): JsonResponse {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        return response()->json(['documents' => Employee::findOrFail($id)->documents()->latest()->get()]);
    }

    public function deleteDocument(int $id, int $docId): JsonResponse {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $doc = Employee::findOrFail($id)->documents()->findOrFail($docId);
        Storage::delete($doc->file_path);
        $doc->delete();
        return response()->json(['message' => 'Document deleted']);
    }

    public function approveDocument(int $id, int $docId): JsonResponse {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'Only HR or a super admin can approve documents.'], 403);
        }
        $doc = Employee::findOrFail($id)->documents()->findOrFail($docId);
        $doc->update([
            'is_verified' => true,
            'verified_by' => auth()->id(),
            'verified_at' => now(),
        ]);
        return response()->json(['message' => 'Document approved.', 'document' => $doc->fresh()]);
    }

    public function downloadDocument(int $id, int $docId) {
        if (!$this->canManageDependents($id)) return response()->json(['message' => 'Unauthorized.'], 403);
        $doc = Employee::findOrFail($id)->documents()->findOrFail($docId);
        if (!Storage::exists($doc->file_path)) {
            return response()->json(['message' => 'File not found'], 404);
        }
        return Storage::download($doc->file_path, $doc->file_name);
    }

    public function export(Request $request): mixed {
        return $this->service->export($request->all());
    }

    public function downloadDetailsReport(Request $request) {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = Employee::with(['department', 'unit', 'designation', 'manager'])
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->unit_id, fn($q) => $q->where('unit_id', $request->unit_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->employment_type, fn($q) => $q->where('employment_type', $request->employment_type))
            ->when($request->search, fn($q) => $q->where(function ($sub) use ($request) {
                $search = "%{$request->search}%";
                $sub->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhere('employee_code', 'like', $search);
            }))
            ->orderBy('employee_code');

        $headers = [
            'Employee ID', 'Full Name', 'Email', 'Phone', 'Extension', 'Department', 'Unit', 'Designation',
            'Manager', 'Employment Type', 'Status', 'Hire Date', 'Date of Birth', 'Gender',
            'Marital Status', 'Nationality', 'Address', 'City', 'Country', 'ID / Iqama',
            'ID / Iqama Expiry', 'Passport Number', 'Passport Expiry', 'Bank Name',
            'Bank Account / IBAN', 'Monthly Salary', 'Emergency Contact Name',
            'Emergency Contact Phone',
        ];

        $rows = $query->get()->map(fn(Employee $employee) => [
            $employee->employee_code,
            $employee->full_name,
            $employee->email,
            $employee->phone,
            $employee->extension,
            $employee->department?->name,
            $employee->unit?->name,
            $employee->designation?->title,
            $employee->manager?->full_name,
            str_replace('_', ' ', (string) $employee->employment_type),
            $employee->status,
            optional($employee->hire_date)->format('Y-m-d'),
            optional($employee->dob)->format('Y-m-d'),
            $employee->gender,
            $employee->marital_status,
            $employee->nationality,
            $employee->address,
            $employee->city,
            $employee->country,
            $employee->national_id,
            optional($employee->id_expiry_date)->format('Y-m-d'),
            $employee->passport_number,
            optional($employee->passport_expiry_date)->format('Y-m-d'),
            $employee->bank_name,
            $employee->bank_account,
            $employee->salary,
            $employee->emergency_contact_name,
            $employee->emergency_contact_phone,
        ])->toArray();

        return $this->xlsxReports->download(
            'employee-details-report-' . now()->format('Y-m-d') . '.xlsx',
            $headers,
            $rows
        );
    }
}
