<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\LoginActivity;
use App\Models\Payroll;
use App\Models\PerformanceReview;
use App\Models\Separation;
use App\Models\User;
use App\Services\LoanApprovalService;
use App\Services\AnnualTicketService;
use App\Services\MonthlyLeaveReminderService;
use App\Services\UnifonicSettingsService;
use App\Services\HdfTemplateService;
use App\Services\EmailSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    protected $loanApprovalService;
    protected $annualTicketService;
    protected $monthlyLeaveReminderService;
    protected $unifonicSettingsService;

    public function __construct(
        LoanApprovalService $loanApprovalService,
        AnnualTicketService $annualTicketService,
        MonthlyLeaveReminderService $monthlyLeaveReminderService,
        UnifonicSettingsService $unifonicSettingsService,
        protected HdfTemplateService $hdfTemplates,
        protected EmailSettingsService $emailSettings
    )
    {
        $this->loanApprovalService = $loanApprovalService;
        $this->annualTicketService = $annualTicketService;
        $this->monthlyLeaveReminderService = $monthlyLeaveReminderService;
        $this->unifonicSettingsService = $unifonicSettingsService;
    }

    public function emailSettings(): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage email settings.'], 403);
        }

        return response()->json(['settings' => $this->emailSettings->settings()]);
    }

    public function updateEmailSettings(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage email settings.'], 403);
        }

        $data = $request->validate([
            'reply_to_email' => 'required|email:rfc|max:255',
        ]);

        return response()->json([
            'message' => 'Email notification settings updated.',
            'settings' => $this->emailSettings->updateSettings($data),
        ]);
    }

    public function hdfTemplate(): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage the HDF template.'], 403);
        }

        return response()->json(['template' => $this->hdfTemplates->info()]);
    }

    public function uploadHdfTemplate(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage the HDF template.'], 403);
        }

        $request->validate([
            'file' => 'required|file|mimes:pdf,doc,docx|max:10240',
        ]);

        return response()->json([
            'message' => 'HDF template uploaded successfully. The previous HDF file was replaced.',
            'template' => $this->hdfTemplates->replace($request->file('file')),
        ]);
    }

    public function unifonicSettings(): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage SMS settings.'], 403);
        }

        return response()->json(['settings' => $this->unifonicSettingsService->settings()]);
    }

    public function updateUnifonicSettings(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage SMS settings.'], 403);
        }

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'api_url' => 'required|url|max:255',
            'app_sid' => 'required|string|max:255',
            'sender' => 'required|string|max:100',
        ]);

        return response()->json([
            'message' => 'Unifonic SMS settings updated.',
            'settings' => $this->unifonicSettingsService->updateSettings($data),
        ]);
    }

    public function monthlyLeaveReminderSettings(): JsonResponse
    {
        if (!$this->canManageLeaveReminderSettings()) {
            return response()->json(['message' => 'Only HR Manager or Super Admin can manage monthly leave reminder settings.'], 403);
        }

        return response()->json(['settings' => $this->monthlyLeaveReminderService->settings()]);
    }

    public function updateMonthlyLeaveReminderSettings(Request $request): JsonResponse
    {
        if (!$this->canManageLeaveReminderSettings()) {
            return response()->json(['message' => 'Only HR Manager or Super Admin can manage monthly leave reminder settings.'], 403);
        }

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'day' => 'required|integer|min:1|max:31',
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:2000',
        ]);

        return response()->json([
            'message' => 'Monthly leave reminder settings updated.',
            'settings' => $this->monthlyLeaveReminderService->updateSettings($data),
        ]);
    }

    public function annualTicketSettings(): JsonResponse
    {
        if (!$this->isSuperAdmin()) return response()->json(['message' => 'Only a super admin can manage system settings.'], 403);
        return response()->json(['settings' => $this->annualTicketService->settings()]);
    }

    public function updateAnnualTicketSettings(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) return response()->json(['message' => 'Only a super admin can manage system settings.'], 403);
        $data = $request->validate([
            'saudi_employee_tickets' => 'required|integer|in:1',
            'non_saudi_employee_tickets' => 'required|integer|in:1',
            'non_saudi_max_dependents' => 'required|integer|min:0|max:3',
        ]);
        return response()->json(['message' => 'Annual ticket policy updated.', 'settings' => $this->annualTicketService->updateSettings($data)]);
    }

    public function loanSettings(): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage system settings.'], 403);
        }

        return response()->json([
            'settings' => ['approval_levels' => $this->loanApprovalService->levels()],
        ]);
    }

    public function updateLoanSettings(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage system settings.'], 403);
        }

        $validated = $request->validate([
            'approval_levels' => 'required|integer|in:2,3',
        ]);

        $levels = $this->loanApprovalService->updateLevels((int) $validated['approval_levels']);

        return response()->json([
            'message' => 'Loan approval workflow updated.',
            'settings' => ['approval_levels' => $levels],
        ]);
    }

    // ── Overview ──────────────────────────────────────────────────────────

    public function overview(): JsonResponse
    {
        $today = now()->toDateString();
        $month = now()->month;
        $year  = now()->year;

        // Employees
        $totalActive  = Employee::where('status', 'active')->count();
        $newThisMonth = Employee::whereMonth('hire_date', $month)->whereYear('hire_date', $year)->count();
        $terminated   = Employee::whereMonth('termination_date', $month)->whereYear('termination_date', $year)->count();

        // Attendance today
        $attLogs      = AttendanceLog::whereDate('date', $today)->get();
        $presentToday = $attLogs->whereIn('status', ['present', 'late'])->count();
        $attRate      = $totalActive > 0 ? round(($presentToday / max($totalActive, 1)) * 100, 1) : 0;

        // Leave
        $pendingLeave = LeaveRequest::where('status', 'pending')->count();
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today)->count();

        // Payroll
        $pendingPayroll = Payroll::whereIn('status', ['draft', 'pending_approval'])->count();
        $payrollErrors  = Payroll::where('status', 'rejected')->count();

        // Loans (guarded — table may not exist yet)
        try {
            $pendingLoans = Loan::where('status', 'pending')->count();
            $activeLoans  = Loan::where('status', 'active')->count();
            $overdueLoans = Loan::where('status', 'overdue')->count();
        } catch (\Exception $e) {
            $pendingLoans = $activeLoans = $overdueLoans = 0;
        }

        // Separations (guarded)
        try {
            $pendingSep = Separation::where('status', 'pending')->count();
            $activeSep  = Separation::whereIn('status', ['approved', 'in_progress'])->count();
        } catch (\Exception $e) {
            $pendingSep = $activeSep = 0;
        }

        // Requests (guarded)
        try {
            $pendingReq = EmployeeRequest::where('status', 'pending')->count();
            $openReq    = EmployeeRequest::whereIn('status', ['pending', 'manager_approved'])->count();
        } catch (\Exception $e) {
            $pendingReq = $openReq = 0;
        }

        // Recruitment
        try {
            $openJobs = JobPosting::where('status', 'open')->count();
            $newApps  = JobApplication::whereDate('created_at', '>=', now()->subDays(7))->count();
        } catch (\Exception $e) {
            $openJobs = $newApps = 0;
        }

        // Performance (guarded)
        try {
            $overdueReviews = PerformanceReview::where('status', 'pending')
                ->where('review_date', '<', $today)->count();
        } catch (\Exception $e) {
            $overdueReviews = 0;
        }

        // Users
        $totalUsers      = User::count();
        $unassignedUsers = User::doesntHave('roles')->count();

        return response()->json([
            // System access / users
            'total_users'      => $totalUsers,
            'unassigned_users' => $unassignedUsers,
            'total_roles'      => Role::count(),
            'total_permissions'=> Permission::count(),
            'users_by_role'    => DB::table('roles')
                ->leftJoin('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->select('roles.id', 'roles.name', DB::raw('COUNT(model_has_roles.role_id) as users_count'))
                ->groupBy('roles.id', 'roles.name')
                ->get()
                ->map(fn ($r) => [
                    'role'  => $r->name,
                    'label' => $this->roleLabel($r->name),
                    'count' => $r->users_count,
                    'color' => $this->roleColor($r->name),
                    'icon'  => $this->roleIcon($r->name),
                ]),

            // Workforce
            'total_active_employees' => $totalActive,
            'new_this_month'         => $newThisMonth,
            'terminated_this_month'  => $terminated,
            'on_leave_today'         => $onLeaveToday,

            // Attention items — things needing action
            'attention' => [
                ['label' => 'Pending Leave Requests',   'value' => $pendingLeave,    'color' => '#f59e0b', 'icon' => 'event_available',    'route' => '/leave'],
                ['label' => 'Pending Payroll',          'value' => $pendingPayroll,  'color' => '#10b981', 'icon' => 'payments',           'route' => '/payroll'],
                ['label' => 'Payroll Errors',           'value' => $payrollErrors,   'color' => '#ef4444', 'icon' => 'error_outline',      'route' => '/payroll'],
                ['label' => 'Loan Applications',        'value' => $pendingLoans,    'color' => '#3b82f6', 'icon' => 'account_balance',    'route' => '/loans'],
                ['label' => 'Active Loans',             'value' => $activeLoans,     'color' => '#6366f1', 'icon' => 'monetization_on',    'route' => '/loans'],
                ['label' => 'Overdue Loans',            'value' => $overdueLoans,    'color' => '#ef4444', 'icon' => 'warning',            'route' => '/loans'],
                ['label' => 'Pending Separations',      'value' => $pendingSep,      'color' => '#f97316', 'icon' => 'exit_to_app',        'route' => '/separations'],
                ['label' => 'Active Separations',       'value' => $activeSep,       'color' => '#f59e0b', 'icon' => 'transfer_within_a_station', 'route' => '/separations'],
                ['label' => 'Pending Requests',         'value' => $pendingReq,      'color' => '#8b5cf6', 'icon' => 'inbox',              'route' => '/requests'],
                ['label' => 'Open Requests',            'value' => $openReq,         'color' => '#0ea5e9', 'icon' => 'pending_actions',    'route' => '/requests'],
                ['label' => 'Open Positions',           'value' => $openJobs,        'color' => '#10b981', 'icon' => 'work_outline',       'route' => '/recruitment'],
                ['label' => 'New Applications (7d)',    'value' => $newApps,         'color' => '#3b82f6', 'icon' => 'person_add',         'route' => '/recruitment'],
                ['label' => 'Overdue Reviews',          'value' => $overdueReviews,  'color' => '#ef4444', 'icon' => 'rate_review',        'route' => '/performance'],
                ['label' => 'Unassigned Users',         'value' => $unassignedUsers, 'color' => '#ef4444', 'icon' => 'person_off',         'route' => '/admin'],
            ],

            // Attendance today
            'attendance_today' => [
                'present'    => $presentToday,
                'rate'       => $attRate,
                'total'      => $totalActive,
            ],
        ]);
    }

    // ── Users ─────────────────────────────────────────────────────────────

    public function users(Request $request): JsonResponse
    {
        $query = User::with(['roles', 'employee.department', 'employee.designation'])
            ->when($request->role,   fn ($q) => $q->role($request->role))
            ->when($request->search, fn ($q) =>
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
            )
            ->orderBy('name');

        return response()->json($query->paginate(20));
    }

    public function showUser(int $id): JsonResponse
    {
        return response()->json([
            'user' => User::with(['roles', 'permissions', 'employee.department'])->findOrFail($id),
        ]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:120',
            'email'       => 'required|email|unique:users',
            'password'    => 'required|min:8',
            'role'        => 'required|exists:roles,name',
            'employee_id' => 'nullable|exists:employees,id',
            'otp_exempt'  => 'sometimes|boolean',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'otp_exempt' => (bool) $request->boolean('otp_exempt'),
        ]);
        $user->assignRole($request->role);

        if ($request->employee_id) {
            Employee::where('id', $request->employee_id)->update(['user_id' => $user->id]);
        }

        return response()->json(['message' => 'User created.', 'user' => $user->load('roles', 'employee')], 201);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $request->validate([
            'name'  => 'sometimes|string|max:120',
            'email' => "sometimes|email|unique:users,email,{$id}",
            'otp_exempt' => 'sometimes|boolean',
        ]);

        $user->update($request->only('name', 'email', 'otp_exempt'));

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        return response()->json(['user' => $user->fresh('roles', 'employee')]);
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $request->validate(['role' => 'required|exists:roles,name']);
        $user = User::findOrFail($id);
        $user->syncRoles([$request->role]);

        return response()->json(['message' => "Role '{$request->role}' assigned.", 'user' => $user->fresh('roles')]);
    }

    public function toggleUserStatus(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->tokens()->delete();

        return response()->json(['message' => 'User tokens revoked. User must re-login.']);
    }

    public function updateUserOtpExemption(Request $request, int $id): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can manage OTP exemptions.'], 403);
        }

        $data = $request->validate([
            'otp_exempt' => 'required|boolean',
        ]);

        $user = User::with('roles', 'employee.department', 'employee.designation')->findOrFail($id);
        $user->forceFill(['otp_exempt' => (bool) $data['otp_exempt']])->save();

        return response()->json([
            'message' => $user->otp_exempt ? 'User excluded from OTP login.' : 'User will be required to verify OTP.',
            'user' => $user->fresh('roles', 'employee.department', 'employee.designation'),
        ]);
    }

    public function loginActivities(Request $request): JsonResponse
    {
        if (!$this->canViewLoginActivities()) {
            return response()->json(['message' => 'Only Super Admin or HR Manager can view login history.'], 403);
        }

        $data = $request->validate([
            'search' => 'nullable|string|max:120',
            'event' => 'nullable|string|max:40',
            'status' => 'nullable|string|max:20',
            'user_id' => 'nullable|integer|exists:users,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = LoginActivity::query()
            ->with(['user.employee.department'])
            ->when($data['event'] ?? null, fn ($q, $event) => $q->where('event', $event))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('occurred_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('occurred_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($data['search'] ?? null, function ($q, $search) {
                $like = '%' . $search . '%';
                $q->where(function ($sub) use ($like) {
                    $sub->where('login_identifier', 'like', $like)
                        ->orWhere('ip_address', 'like', $like)
                        ->orWhereHas('user', function ($userQuery) use ($like) {
                            $userQuery->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like);
                        })
                        ->orWhereHas('user.employee', function ($employeeQuery) use ($like) {
                            $employeeQuery->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('employee_code', 'like', $like);
                        });
                });
            })
            ->latest('occurred_at');

        return response()->json($query->paginate((int) ($data['per_page'] ?? 20)));
    }

    // ── Roles ─────────────────────────────────────────────────────────────

    public function roles(): JsonResponse
    {
        // Use raw DB queries to avoid Eloquent relationship exceptions
        $roleRows = DB::table('roles')->orderBy('id')->get();

        // Get all permissions grouped by role id
        $pivotTable = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $permTable  = config('permission.table_names.permissions', 'permissions');

        $permsByRole = DB::table($pivotTable)
            ->join($permTable, "{$permTable}.id", '=', "{$pivotTable}.permission_id")
            ->select("{$pivotTable}.role_id", "{$permTable}.name")
            ->get()
            ->groupBy('role_id')
            ->map(fn ($g) => $g->pluck('name')->values()->toArray());

        // Count users per role
        $modelRolesTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $userCounts = DB::table($modelRolesTable)
            ->select('role_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('role_id')
            ->pluck('cnt', 'role_id');

        $roles = $roleRows->map(fn ($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'label'       => $this->roleLabel($r->name),
            'color'       => $this->roleColor($r->name),
            'icon'        => $this->roleIcon($r->name),
            'description' => $this->roleDescription($r->name),
            'users_count' => $userCounts[$r->id] ?? 0,
            'permissions' => $permsByRole[$r->id] ?? [],
        ]);

        return response()->json(['roles' => $roles]);
    }

    public function updateRolePermissions(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if ($role->name === 'super_admin') {
            return response()->json(['message' => 'Super admin permissions cannot be modified.'], 403);
        }

        $request->validate(['permissions' => 'required|array']);
        $role->syncPermissions($request->permissions);

        return response()->json(['message' => 'Permissions updated.', 'role' => $role->fresh('permissions')]);
    }

    // ── Permissions ───────────────────────────────────────────────────────

    public function permissions(): JsonResponse
    {
        $perms = rescue(function () {
            // Group by module prefix (before the first dot).
            // If permissions use flat format (view_employees), treat the whole name as the key.
            return Permission::all()
                ->groupBy(fn ($p) => str_contains($p->name, '.') ? explode('.', $p->name)[0] : $p->name)
                ->map(fn ($group) => $group->map(fn ($p) => ['name' => $p->name])->values()->toArray());
        }, [], false);

        return response()->json(['permissions' => $perms]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function roleLabel(string $name): string
    {
        return [
            'super_admin'        => 'Super Admin',
            'ceo'                => 'CEO',
            'hr_manager'         => 'HR Manager',
            'hr_staff'           => 'HR Staff',
            'it_manager'         => 'IT Manager',
            'it_supervisor'      => 'IT Supervisor',
            'cybersecurity_officer' => 'Cybersecurity Officer',
            'finance_manager'    => 'Finance Manager',
            'department_manager' => 'Department Manager',
            'employee'           => 'Employee',
        ][$name] ?? ucfirst(str_replace('_', ' ', $name));
    }

    private function isSuperAdmin(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->where('roles.name', 'super_admin')
            ->exists();
    }

    private function canManageLeaveReminderSettings(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->whereIn('roles.name', ['super_admin', 'ceo', 'hr_manager'])
            ->exists();
    }

    private function canViewLoginActivities(): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->where('model_has_roles.model_type', get_class(auth()->user()))
            ->whereIn('roles.name', ['super_admin', 'ceo', 'hr_manager'])
            ->exists();
    }

    private function roleColor(string $name): string
    {
        return [
            'super_admin'        => '#ef4444',
            'ceo'                => '#7c3aed',
            'hr_manager'         => '#6366f1',
            'hr_staff'           => '#8b5cf6',
            'it_manager'         => '#0ea5e9',
            'it_supervisor'      => '#06b6d4',
            'cybersecurity_officer' => '#14b8a6',
            'finance_manager'    => '#10b981',
            'department_manager' => '#f59e0b',
            'employee'           => '#3b82f6',
        ][$name] ?? '#8b949e';
    }

    private function roleIcon(string $name): string
    {
        return [
            'super_admin'        => 'shield',
            'ceo'                => 'business_center',
            'hr_manager'         => 'manage_accounts',
            'hr_staff'           => 'badge',
            'it_manager'         => 'computer',
            'it_supervisor'      => 'inventory_2',
            'cybersecurity_officer' => 'security',
            'finance_manager'    => 'account_balance',
            'department_manager' => 'supervisor_account',
            'employee'           => 'person',
        ][$name] ?? 'person';
    }

    private function roleDescription(string $name): string
    {
        return [
            'super_admin'        => 'Full system access — all modules and admin tools',
            'ceo'                => 'Full HR operations — employees, payroll, leave, loans, separations',
            'hr_manager'         => 'Full HR operations — employees, payroll, leave, loans, separations',
            'hr_staff'           => 'Day-to-day HR processing — requests, leave, employee records',
            'finance_manager'    => 'Financial approvals — payroll, loan finance, final settlements',
            'department_manager' => 'Team management — approve leave, loans, view team data',
            'employee'           => 'Self-service — requests, leave, payslips, loans',
        ][$name] ?? '';
    }
}
