<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\JobPosting;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard data endpoints.
 * All model queries are individually try/caught so a single missing table
 * or column never crashes the whole response.
 */
class DashboardController extends Controller
{
    private function canViewPayroll(): bool
    {
        $user = auth()->user();

        return $user && DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->whereIn('roles.name', ['super_admin', 'ceo', 'hr_manager'])
            ->exists();
    }

    private function dashboardScope(): array
    {
        $user = auth()->user();
        $roles = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->pluck('roles.name')
            ->all();

        $isGlobal = (bool) array_intersect($roles, ['super_admin', 'ceo', 'hr_manager', 'hr_staff']);
        $employee = Employee::where('user_id', $user->id)->first();

        if ($isGlobal) {
            return ['type' => 'global', 'employee_ids' => null, 'department_id' => null, 'employee_id' => $employee?->id, 'direct_report_ids' => null];
        }

        if (in_array('department_manager', $roles, true) && $employee?->department_id) {
            return [
                'type' => 'department',
                'employee_ids' => Employee::where('department_id', $employee->department_id)->pluck('id')->all(),
                'department_id' => (int) $employee->department_id,
                'employee_id' => $employee->id,
                'direct_report_ids' => Employee::where('manager_id', $employee->id)->pluck('id')->all(),
            ];
        }

        return [
            'type' => 'personal',
            'employee_ids' => $employee ? [$employee->id] : [],
            'department_id' => $employee?->department_id ? (int) $employee->department_id : null,
            'employee_id' => $employee?->id,
            'direct_report_ids' => [],
        ];
    }

    private function applyEmployeeScope($query, array $scope, string $column = 'employee_id')
    {
        return $scope['employee_ids'] === null
            ? $query
            : $query->whereIn($column, $scope['employee_ids']);
    }

    private function applyDepartmentScope($query, array $scope, string $column = 'department_id')
    {
        if ($scope['type'] === 'global') return $query;
        if ($scope['type'] === 'department' && $scope['department_id']) {
            return $query->where($column, $scope['department_id']);
        }
        return $query->whereRaw('1 = 0');
    }

    // ── Stats ─────────────────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $today = now()->toDateString();
        $month = now()->month;
        $year  = now()->year;
        $scope = $this->dashboardScope();
        $canViewPayroll = $this->canViewPayroll();

        // ── Helpers ────────────────────────────────────────────────────
        $safe = fn (callable $fn, $default = 0) => rescue($fn, $default, false);

        // ── Employees ──────────────────────────────────────────────────
        $employeeQuery = fn () => $this->applyEmployeeScope(DB::table('employees')->whereNull('deleted_at'), $scope, 'employees.id');
        $totalEmp     = $safe(fn () => $employeeQuery()->count());
        $activeEmp    = $safe(fn () => $employeeQuery()->where('status', 'active')->count());
        $probation    = $safe(fn () => $employeeQuery()->where('status', 'probation')->count());
        $onLeave      = $safe(fn () => $employeeQuery()->where('status', 'on_leave')->count());
        $newThisMonth = $safe(fn () => $employeeQuery()->whereMonth('hire_date', $month)->whereYear('hire_date', $year)->count());
        $terminated   = $safe(fn () => $employeeQuery()->whereMonth('termination_date', $month)->whereYear('termination_date', $year)->count());

        // ── Leave ──────────────────────────────────────────────────────
        $leaveQuery = fn () => $this->applyEmployeeScope(DB::table('leave_requests'), $scope);
        $pendingLeave  = $safe(function () use ($scope, $leaveQuery) {
            if ($scope['type'] === 'department') {
                return DB::table('leave_requests')
                    ->whereIn('employee_id', $scope['direct_report_ids'] ?? [])
                    ->where('status', 'pending')
                    ->count();
            }

            return $leaveQuery()->whereIn('status', ['pending', 'manager_approved'])->count();
        });
        $approvedLeave = $safe(fn () => $leaveQuery()->where('status', 'approved')->count());
        $rejectedLeave = $safe(fn () => $leaveQuery()->where('status', 'rejected')->count());
        $onLeaveToday  = $safe(fn () => $leaveQuery()
            ->where('status', 'approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)->count());
        $approvedMonth = $safe(fn () => $leaveQuery()
            ->where('status', 'approved')
            ->whereMonth('updated_at', $month)->count());
        $totalLeave    = $safe(fn () => $leaveQuery()->count());

        // ── Attendance ─────────────────────────────────────────────────
        $attendanceQuery = fn () => $this->applyEmployeeScope(DB::table('attendance_logs'), $scope);
        $presentToday = $safe(fn () => $attendanceQuery()
            ->whereDate('date', $today)
            ->whereIn('status', ['present', 'late'])->count());
        $lateToday    = $safe(fn () => $attendanceQuery()
            ->whereDate('date', $today)->where('status', 'late')->count());
        $absentToday  = $safe(fn () => $attendanceQuery()
            ->whereDate('date', $today)->where('status', 'absent')->count());
        $attRate      = $activeEmp > 0 ? round(($presentToday / max($activeEmp, 1)) * 100, 1) : 0;

        // ── Payroll ────────────────────────────────────────────────────
        $payrollQuery = fn () => $this->applyEmployeeScope(DB::table('payrolls'), $scope);
        $payProcessed = $safe(fn () => $payrollQuery()->where('status', 'approved')->count());
        $payPending   = $safe(fn () => $payrollQuery()->whereIn('status', ['pending_approval', 'draft'])->count());
        $payErrors    = $safe(fn () => $payrollQuery()->where('status', 'rejected')->count());
        $payOnHold    = $safe(fn () => $payrollQuery()->where('status', 'on_hold')->count());
        $payDue       = $safe(fn () => $payrollQuery()->whereMonth('created_at', $month)->count());
        $payTotal     = $safe(fn () => $payrollQuery()->count());

        // ── Recruitment ────────────────────────────────────────────────
        $jobQuery = fn () => $this->applyDepartmentScope(DB::table('job_postings'), $scope, 'job_postings.department_id');
        $applicationQuery = fn () => $this->applyDepartmentScope(
            DB::table('job_applications')->join('job_postings', 'job_postings.id', '=', 'job_applications.job_posting_id'),
            $scope,
            'job_postings.department_id'
        );
        $openJobs        = $safe(fn () => $jobQuery()->where('job_postings.status', 'open')->count());
        $totalApplicants = $safe(fn () => $applicationQuery()->count());
        $newApplicants   = $safe(fn () => $applicationQuery()->where('job_applications.created_at', '>=', now()->subDays(7))->count());
        $offersSent      = $safe(fn () => $applicationQuery()->where('job_applications.stage', 'offer')->count());
        $hiredThisMonth  = $safe(fn () => $applicationQuery()->where('job_applications.stage', 'hired')->whereMonth('job_applications.updated_at', $month)->count());

        // ── Performance ────────────────────────────────────────────────
        $performanceQuery = fn () => $this->applyEmployeeScope(DB::table('performance_reviews'), $scope);
        $perfPending  = $safe(fn () => $performanceQuery()->where('status', 'pending')->count());
        $perfProgress = $safe(fn () => $performanceQuery()->whereIn('status', ['self_submitted', 'manager_reviewed'])->count());
        $perfDone     = $safe(fn () => $performanceQuery()->where('status', 'finalized')->count());
        // Reviews still pending self-assessment past the cycle self_assessment_deadline
        $perfOverdue  = $safe(fn () => $this->applyEmployeeScope(DB::table('performance_reviews'), $scope, 'performance_reviews.employee_id')
            ->join('performance_cycles', 'performance_cycles.id', '=', 'performance_reviews.cycle_id')
            ->where('performance_reviews.status', 'pending')
            ->where('performance_cycles.self_assessment_deadline', '<', $today)
            ->count());
        $perfTotal    = $safe(fn () => $performanceQuery()->count());
        $perfAvg      = $safe(fn () => $performanceQuery()->whereNotNull('final_rating')->avg('final_rating'), null);

        // ── Departments ────────────────────────────────────────────────
        $depts       = $safe(fn () => $scope['type'] === 'global'
            ? DB::table('departments')->whereNull('deleted_at')->get()
            : DB::table('departments')->whereNull('deleted_at')->where('id', $scope['department_id'])->get(), collect());
        $totalDepts  = is_object($depts) ? $depts->count() : 0;
        $withManager = is_object($depts) ? $depts->filter(fn ($d) => !empty($d->manager_id))->count() : 0;
        $vacantMgr   = $totalDepts - $withManager;

        // ── Loans ──────────────────────────────────────────────────────
        $loanQuery = fn () => $this->applyEmployeeScope(DB::table('loans'), $scope);
        $loanPending  = $safe(fn () => $loanQuery()->where('status', 'pending')->count());
        $loanActive   = $safe(fn () => $loanQuery()->where('status', 'active')->count());
        $loanOverdue  = $safe(fn () => $loanQuery()->where('status', 'overdue')->count());

        // ── Separations ────────────────────────────────────────────────
        $separationQuery = fn () => $this->applyEmployeeScope(DB::table('separations'), $scope);
        $sepPending = $safe(fn () => $separationQuery()->where('status', 'pending')->count());
        $sepActive  = $safe(fn () => $separationQuery()->whereIn('status', ['approved', 'in_progress'])->count());

        // ── Requests ──────────────────────────────────────────────────
        $requestQuery = fn () => $this->applyEmployeeScope(DB::table('employee_requests'), $scope);
        $reqPending     = $safe(fn () => $requestQuery()->whereIn('status', ['pending', 'pending_manager'])->count());
        $reqInProgress  = $safe(fn () => $requestQuery()->where('status', 'in_progress')->count());
        $reqCompleted   = $safe(fn () => $requestQuery()->where('status', 'completed')->count());
        $reqOverdue     = $safe(fn () => $requestQuery()->where('is_overdue', true)->whereNotIn('status', ['completed', 'rejected', 'cancelled'])->count());
        $reqOpen        = $safe(fn () => $requestQuery()->whereNotIn('status', ['completed', 'rejected', 'cancelled'])->count());

        $recentEmployees = $safe(function () use ($scope) {
            $query = $this->applyEmployeeScope(Employee::with('department'), $scope, 'employees.id');
            if ($scope['type'] === 'department') {
                $query->orderByRaw('employees.id = ? DESC', [$scope['employee_id']]);
            }
            return $query->latest()->limit(5)->get()->each(function ($employee) {
                $employee->setAttribute('name', $employee->full_name);
                $employee->setAttribute('employee_id', $employee->employee_code);
            });
        }, collect());
        $recentLeaves = $safe(function () use ($scope) {
            $query = $this->applyEmployeeScope(LeaveRequest::with(['employee', 'leaveType'])->latest(), $scope);
            if ($scope['type'] === 'department') {
                $query->whereIn('employee_id', $scope['direct_report_ids'] ?? [])
                    ->where('status', 'pending');
            } elseif ($scope['type'] !== 'personal') {
                $query->whereIn('status', ['pending', 'manager_approved']);
            }
            if ($scope['type'] === 'global' && auth()->user()->employee) {
                $query->where('employee_id', '!=', auth()->user()->employee->id);
            }
            return $query->limit(5)->get();
        }, collect());
        $recentJobs = $safe(fn () => $this->applyDepartmentScope(JobPosting::with('department')->where('status', 'open')->latest(), $scope)->limit(5)->get(), collect());
        $recentReviews = $safe(fn () => $this->applyEmployeeScope(PerformanceReview::with(['employee', 'cycle'])->latest(), $scope)->limit(5)->get()
            ->each(fn ($review) => $review->setAttribute('due_date', $review->cycle?->manager_review_deadline ?? $review->cycle?->end_date)), collect());

        return response()->json([
            'scope' => $scope['type'],
            'employees' => [
                'total'                 => $totalEmp,
                'active'                => $activeEmp,
                'probation'             => $probation,
                'on_leave'              => $onLeave,
                'new_this_month'        => $newThisMonth,
                'terminated_this_month' => $terminated,
                'contracts_expiring'    => $safe(fn () => DB::table('employee_contracts')
                    ->when($scope['employee_ids'] !== null, fn ($query) => $query->whereIn('employee_id', $scope['employee_ids']))
                    ->where('status', 'active')
                    ->whereNotNull('end_date')
                    ->whereBetween('end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count()),
            ],
            'leave' => [
                'pending'             => $pendingLeave,
                'approved'            => $approvedLeave,
                'rejected'            => $rejectedLeave,
                'on_leave_today'      => $onLeaveToday,
                'approved_this_month' => $approvedMonth,
                'total'               => $totalLeave,
            ],
            'attendance' => [
                'present_today' => $presentToday,
                'late_today'    => $lateToday,
                'absent_today'  => $absentToday,
                'total_active'  => $activeEmp,
                'rate'          => $attRate,
            ],
            'payroll' => $canViewPayroll ? [
                'processed'         => $payProcessed,
                'pending_approvals' => $payPending,
                'errors'            => $payErrors,
                'on_hold'           => $payOnHold,
                'due_this_month'    => $payDue,
                'total'             => $payTotal,
            ] : null,
            'recruitment' => [
                'open_positions'  => $openJobs,
                'applicants'      => $totalApplicants,
                'new_this_week'   => $newApplicants,
                'interviews_today'=> 0,
                'offers_sent'     => $offersSent,
                'hired_this_month'=> $hiredThisMonth,
            ],
            'performance' => [
                'pending'    => $perfPending,
                'in_progress'=> $perfProgress,
                'completed'  => $perfDone,
                'overdue'    => $perfOverdue,
                'total'      => $perfTotal,
                'avg_score'  => $perfAvg ? round((float) $perfAvg, 1) : '—',
            ],
            'departments' => [
                'total'      => $totalDepts,
                'teams'      => $totalDepts,
                'managers'   => $withManager,
                'vacant_mgr' => $vacantMgr,
            ],
            'loans' => [
                'pending' => $loanPending,
                'active'  => $loanActive,
                'overdue' => $loanOverdue,
            ],
            'separations' => [
                'pending' => $sepPending,
                'active'  => $sepActive,
            ],
            'requests' => [
                'pending'     => $reqPending,
                'in_progress' => $reqInProgress,
                'completed'   => $reqCompleted,
                'overdue'     => $reqOverdue,
                'open'        => $reqOpen,
            ],
            'recent' => [
                'employees' => $recentEmployees,
                'leave_requests' => $recentLeaves,
                'open_jobs' => $recentJobs,
                'reviews' => $recentReviews,
            ],
        ]);
    }

    // ── Charts ────────────────────────────────────────────────────────────

    public function charts(): JsonResponse
    {
        $safe = fn (callable $fn, $default = []) => rescue($fn, $default, false);
        $scope = $this->dashboardScope();

        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));

        $hireTrend = $safe(fn () => $months->map(fn ($m) => [
            'month' => $m->format('M'),
            'count' => $this->applyEmployeeScope(DB::table('employees'), $scope, 'employees.id')
                ->whereNull('deleted_at')
                ->whereYear('hire_date', $m->year)
                ->whereMonth('hire_date', $m->month)->count(),
        ]));

        $exitTrend = $safe(fn () => $months->map(fn ($m) => [
            'month' => $m->format('M'),
            'count' => $this->applyEmployeeScope(DB::table('employees'), $scope, 'employees.id')
                ->whereNull('deleted_at')
                ->whereYear('termination_date', $m->year)
                ->whereMonth('termination_date', $m->month)->count(),
        ]));

        $payrollTrend = $this->canViewPayroll() ? $safe(fn () => $months->map(fn ($m) => [
            'month' => $m->format('M'),
            'total' => (int) ($this->applyEmployeeScope(DB::table('payrolls'), $scope)
                ->whereYear('created_at', $m->year)
                ->whereMonth('created_at', $m->month)
                ->where('status', 'approved')
                ->sum('total_net') ?? 0),
        ])) : null;

        $deptDist = $safe(fn () => $this->applyEmployeeScope(DB::table('departments')
            ->whereNull('deleted_at')
            ->join('employees', 'departments.id', '=', 'employees.department_id')
            ->whereNull('employees.deleted_at')
            ->where('employees.status', 'active'), $scope, 'employees.id')
            ->selectRaw('departments.name, COUNT(employees.id) as count')
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('count')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => $r->count]));

        $leaveByType = $safe(fn () => $this->applyEmployeeScope(DB::table('leave_requests')
            ->join('leave_types', 'leave_requests.leave_type_id', '=', 'leave_types.id')
            ->where('leave_requests.status', 'approved')
            ->whereYear('leave_requests.created_at', now()->year), $scope, 'leave_requests.employee_id')
            ->selectRaw('leave_types.name as leave_type, COUNT(*) as count')
            ->groupBy('leave_types.id', 'leave_types.name')
            ->get()
            ->map(fn ($r) => ['leave_type' => $r->leave_type, 'count' => $r->count]));

        $perfRatings = $safe(fn () => $this->applyEmployeeScope(DB::table('performance_reviews')
            ->whereNotNull('final_rating'), $scope, 'performance_reviews.employee_id')
            ->selectRaw("
                CASE
                    WHEN final_rating >= 4.5 THEN 'Excellent'
                    WHEN final_rating >= 3.5 THEN 'Good'
                    WHEN final_rating >= 2.5 THEN 'Average'
                    ELSE 'Needs Work'
                END as rating,
                COUNT(*) as count
            ")
            ->groupBy('rating')
            ->get()
            ->map(fn ($r) => ['rating' => $r->rating, 'count' => $r->count]));

        $attTrend = $safe(fn () => collect(range(6, 0))->map(fn ($i) => now()->subDays($i))->map(fn ($day) => [
            'day'     => $day->format('D'),
            'date'    => $day->toDateString(),
            'present' => $this->applyEmployeeScope(DB::table('attendance_logs'), $scope)->whereDate('date', $day)->whereIn('status', ['present', 'late'])->count(),
            'absent'  => $this->applyEmployeeScope(DB::table('attendance_logs'), $scope)->whereDate('date', $day)->where('status', 'absent')->count(),
        ]));

        return response()->json([
            'hire_trend'          => $hireTrend,
            'exit_trend'          => $exitTrend,
            'payroll_trend'       => $payrollTrend,
            'dept_distribution'   => $deptDist,
            'leave_by_type'       => $leaveByType,
            'performance_ratings' => $perfRatings,
            'attendance_trend'    => $attTrend,
        ]);
    }

    // ── Recent activities ─────────────────────────────────────────────────

    public function recentActivities(): JsonResponse
    {
        $safe = fn (callable $fn) => rescue($fn, [], false);
        $scope = $this->dashboardScope();

        $activities = $safe(function () use ($scope) {
            $items = collect();

            // Recent leave requests
            $leaves = $this->applyEmployeeScope(DB::table('leave_requests')
                ->join('employees', 'leave_requests.employee_id', '=', 'employees.id')
                ->join('leave_types', 'leave_requests.leave_type_id', '=', 'leave_types.id'), $scope, 'leave_requests.employee_id')
                ->select('leave_requests.created_at', 'leave_requests.status',
                         'employees.first_name', 'employees.last_name', 'leave_types.name as type_name')
                ->orderByDesc('leave_requests.created_at')->limit(5)->get()
                ->map(fn ($r) => [
                    'action'     => 'leave_request',
                    'module'     => 'Leave',
                    'icon'       => 'event_available',
                    'color'      => '#f59e0b',
                    'title'      => "{$r->first_name} {$r->last_name} requested {$r->type_name} leave",
                    'subtitle'   => ucfirst($r->status),
                    'created_at' => $r->created_at,
                ]);
            $items = $items->merge($leaves);

            // Recent hires
            $hires = $this->applyEmployeeScope(DB::table('employees')->whereNull('deleted_at'), $scope, 'employees.id')
                ->orderByDesc('created_at')->limit(3)->get()
                ->map(fn ($e) => [
                    'action'     => 'joined',
                    'module'     => 'Employees',
                    'icon'       => 'person_add_alt_1',
                    'color'      => '#10b981',
                    'title'      => "{$e->first_name} {$e->last_name} joined",
                    'subtitle'   => ucfirst($e->employment_type ?? 'Employee'),
                    'created_at' => $e->created_at,
                ]);
            $items = $items->merge($hires);

            // Recent performance reviews
            $reviews = $this->applyEmployeeScope(DB::table('performance_reviews')
                ->join('employees', 'performance_reviews.employee_id', '=', 'employees.id')
                ->join('performance_cycles', 'performance_reviews.cycle_id', '=', 'performance_cycles.id'), $scope, 'performance_reviews.employee_id')
                ->select('performance_reviews.updated_at', 'performance_reviews.status',
                         'employees.first_name', 'employees.last_name', 'performance_cycles.name as cycle_name')
                ->whereNotIn('performance_reviews.status', ['pending'])
                ->orderByDesc('performance_reviews.updated_at')->limit(3)->get()
                ->map(fn ($r) => [
                    'action'     => 'performance',
                    'module'     => 'Performance',
                    'icon'       => 'insights',
                    'color'      => '#6366f1',
                    'title'      => "{$r->first_name} {$r->last_name} — {$r->cycle_name}",
                    'subtitle'   => str_replace('_', ' ', ucfirst($r->status)),
                    'created_at' => $r->updated_at,
                ]);
            $items = $items->merge($reviews);

            return $items->sortByDesc('created_at')->values()->take(15)->all();
        });

        return response()->json($activities);
    }
}
