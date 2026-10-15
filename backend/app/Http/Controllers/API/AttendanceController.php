<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\LeaveRequest;
use App\Services\Attendance\AttendancePolicyService;
use App\Services\Attendance\MonthlyAttendanceReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles attendance: check-in, check-out, daily status, reports, and dashboard.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private MonthlyAttendanceReportService $monthlyReportService,
        private AttendancePolicyService $attendancePolicy
    ) {
    }

    // ── Check-in ──────────────────────────────────────────────────────────

    public function checkIn(Request $request): JsonResponse
    {
        $employee = auth()->user()->employee;
        $today    = now()->toDateString();

        $existing = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->first();

        if ($existing && $existing->check_in) {
            return response()->json([
                'message' => 'Already checked in today at ' . $existing->check_in,
                'log'     => $existing,
            ], 422);
        }

        $checkIn = now()->format('H:i:s');
        $log = AttendanceLog::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $today],
            [
                'check_in'   => $checkIn,
                'source'     => 'api',
                'ip_address' => $request->ip(),
                'status'     => $this->attendancePolicy->statusForCheckIn($checkIn),
            ]
        );

        return response()->json(['message' => 'Check-in recorded', 'log' => $log]);
    }

    // ── Check-out ─────────────────────────────────────────────────────────

    public function checkOut(Request $request): JsonResponse
    {
        $employee = auth()->user()->employee;
        $today    = now()->toDateString();

        $log           = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->firstOrFail();

        $checkOutCarbon = now();
        $checkInCarbon  = Carbon::parse($today . ' ' . $log->check_in);

        $log->update([
            'check_out'     => $checkOutCarbon->format('H:i:s'),
            'total_minutes' => (int) $checkInCarbon->diffInMinutes($checkOutCarbon),
        ]);

        return response()->json(['message' => 'Check-out recorded', 'log' => $log->fresh()]);
    }

    // ── Today ─────────────────────────────────────────────────────────────

    public function today(): JsonResponse
    {
        $employee = auth()->user()->employee;

        if (! $employee) {
            return response()->json(['log' => null]);
        }

        $log = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', now()->toDateString())
            ->first();

        return response()->json(['log' => $log]);
    }

    // ── Employee log ──────────────────────────────────────────────────────

    public function employeeLog(Request $request, int $empId): JsonResponse
    {
        $logs = AttendanceLog::where('employee_id', $empId)
            ->when($request->month, fn ($q) => $q->whereMonth('date', $request->month))
            ->when($request->year,  fn ($q) => $q->whereYear('date', $request->year))
            ->orderBy('date', 'desc')
            ->paginate(31);

        return response()->json($logs);
    }

    // ── Report ────────────────────────────────────────────────────────────

    public function report(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 10), 100);
        $user  = auth()->user();
        $roles = rescue(fn() => \DB::table('model_has_roles')
            ->join('roles','roles.id','=','model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->pluck('roles.name')->toArray(), [], false);
        $isHR  = (bool) array_intersect($roles, ['super_admin','ceo','hr_manager','hr_staff']);
        $isMgr = in_array('department_manager', $roles);

        $employeeId = $user->employee?->id;
        $departmentId = $user->employee?->department_id;
        $scope = $request->input('scope');

        $data = AttendanceLog::with('employee.department')
            ->select('attendance_logs.*')
            ->addSelect([
                'has_active_leave' => LeaveRequest::query()
                    ->selectRaw('1')
                    ->whereColumn('leave_requests.employee_id', 'attendance_logs.employee_id')
                    ->whereColumn('leave_requests.start_date', '<=', 'attendance_logs.date')
                    ->whereColumn('leave_requests.end_date', '>=', 'attendance_logs.date')
                    ->where('leave_requests.status', '!=', 'cancelled')
                    ->limit(1),
            ])
            ->when(!$isHR && !$isMgr && $user->employee, fn ($q) =>
                // Regular employee: own records only
                $q->where('employee_id', $user->employee->id)
            )
            ->when($isMgr && !$isHR && $scope === 'mine' && $employeeId, fn ($q) =>
                // Department manager personal tab: own records only
                $q->where('employee_id', $employeeId)
            )
            ->when($isMgr && !$isHR && $scope !== 'mine', fn ($q) =>
                // Department manager team tab: department employees plus direct reports, excluding own record
                $q->whereHas('employee', fn ($e) => $e->where(fn ($team) => $team
                        ->where('department_id', $departmentId)
                        ->when($employeeId, fn ($direct) => $direct->orWhere('manager_id', $employeeId))))
                  ->when($employeeId, fn ($team) => $team->where('employee_id', '!=', $employeeId))
            )
            ->when($request->department_id, fn ($q) =>
                $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id))
            )
            ->when($request->date_from, fn ($q) => $q->whereDate('date', '>=', $request->date_from))
            ->when($request->date_to,   fn ($q) => $q->whereDate('date', '<=', $request->date_to))
            ->when($request->status,    fn ($q) => $q->where('status', $request->status))
            ->when($request->employee_id, fn ($q) => $q->where('employee_id', $request->employee_id))
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        $data->getCollection()->each(function (AttendanceLog $log): void {
            $log->status = $this->attendancePolicy->statusForReport(
                $log->check_in ? (string) $log->check_in : null,
                (string) $log->status,
                $log->source ? (string) $log->source : null
            );
        });

        return response()->json($data);
    }

    /**
     * Employees who report directly to the signed-in employee (attendance log employee filter).
     */
    public function directReports(): JsonResponse
    {
        $employeeId = auth()->user()->employee?->id;

        $reports = $employeeId
            ? \App\Models\Employee::where('manager_id', $employeeId)
                ->whereIn('status', ['active', 'probation'])
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'employee_code', 'first_name', 'last_name', 'department_id', 'status'])
            : collect();

        return response()->json(['data' => $reports]);
    }

    public function monthlyReport(Request $request)
    {
        if (!$this->hasAnyRoleDB(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $this->monthlyReportService->download($request);
    }

    // ── Manual entry ──────────────────────────────────────────────────────

    public function manualEntry(Request $request): JsonResponse
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'date'        => 'required|date',
            'check_in'    => 'nullable|date_format:H:i',
            'check_out'   => 'nullable|date_format:H:i',
            'status'      => 'nullable|in:present,absent,late,half_day,on_leave,holiday',
            'notes'       => 'nullable|string|max:500',
        ]);

        $log = AttendanceLog::updateOrCreate(
            ['employee_id' => $request->employee_id, 'date' => $request->date],
            array_merge($request->except(['employee_id', 'date']), ['source' => 'manual'])
        );

        return response()->json(['log' => $log]);
    }

    // ── Dashboard stats ───────────────────────────────────────────────────

    /**
     * GET /api/v1/attendance/dashboard
     *
     * Returns different payloads depending on the caller's role:
     *  - HR / admin  → organisation-wide stats (today's presence, weekly trend,
     *                  department breakdown, late/absent alerts)
     *  - Employee    → personal stats (this month's summary, daily streak,
     *                  recent log, avg hours)
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->hasAnyRole(['super_admin', 'hr_manager', 'hr_staff'])) {
            return response()->json($this->adminDashboard());
        }

        if ($user->hasRole('department_manager')) {
            return response()->json($this->adminDashboard(
                $user->employee?->department_id,
                $user->employee?->id,
                'department'
            ));
        }

        return response()->json($this->employeeDashboard($user->employee));
    }

    // ── Admin dashboard payload ───────────────────────────────────────────

    private function adminDashboard(?int $departmentId = null, ?int $excludeEmployeeId = null, string $type = 'admin'): array
    {
        $today     = now()->toDateString();
        $weekStart = now()->startOfWeek(Carbon::SUNDAY)->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $employeeIds = \App\Models\Employee::query()
            ->whereIn('status', ['active', 'probation'])
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when($excludeEmployeeId, fn ($q) => $q->where('id', '!=', $excludeEmployeeId))
            ->pluck('id');

        // Today's totals
        $todayLogs    = AttendanceLog::whereDate('date', $today)
            ->when($employeeIds->isNotEmpty() || $departmentId, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get();
        $presentToday = $todayLogs->where('status', 'present')->count();
        $lateToday    = $todayLogs->where('status', 'late')->count();
        $absentToday  = $todayLogs->where('status', 'absent')->count();

        // Total active employees
        $totalActive  = $employeeIds->count();
        $notRecorded  = max(0, $totalActive - $todayLogs->count());

        // Attendance rate this month
        $monthLogs     = AttendanceLog::whereDate('date', '>=', $monthStart)
            ->whereDate('date', '<=', $today)
            ->when($employeeIds->isNotEmpty() || $departmentId, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get();
        $monthPresent  = $monthLogs->whereIn('status', ['present', 'late'])->count();
        $monthTotal    = $monthLogs->count();
        $monthRate     = $monthTotal > 0 ? round(($monthPresent / $monthTotal) * 100, 1) : 0;

        // Avg working hours this month
        $avgMinutes    = $monthLogs->where('total_minutes', '>', 0)->avg('total_minutes') ?? 0;
        $avgHours      = round($avgMinutes / 60, 1);

        // Weekly trend (last 7 days)
        $weeklyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = now()->subDays($i);
            $dayLogs = AttendanceLog::whereDate('date', $day->toDateString())
                ->when($employeeIds->isNotEmpty() || $departmentId, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->get();
            $weeklyTrend[] = [
                'day'     => $day->format('D'),
                'date'    => $day->toDateString(),
                'present' => $dayLogs->whereIn('status', ['present', 'late'])->count(),
                'absent'  => $dayLogs->where('status', 'absent')->count(),
                'late'    => $dayLogs->where('status', 'late')->count(),
                'total'   => $dayLogs->count(),
            ];
        }

        // Department breakdown today
        $deptBreakdown = AttendanceLog::with('employee.department')
            ->whereDate('date', $today)
            ->when($employeeIds->isNotEmpty() || $departmentId, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get()
            ->groupBy(fn ($log) => $log->employee?->department?->name ?? 'Unknown')
            ->map(fn ($logs, $dept) => [
                'department' => $dept,
                'present'    => $logs->whereIn('status', ['present', 'late'])->count(),
                'absent'     => $logs->where('status', 'absent')->count(),
                'total'      => $logs->count(),
            ])
            ->values()
            ->take(8);

        // Late / absent employees today
        $alerts = AttendanceLog::with('employee.department')
            ->whereDate('date', $today)
            ->when($employeeIds->isNotEmpty() || $departmentId, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereIn('status', ['absent', 'late'])
            ->orderBy('status')
            ->limit(10)
            ->get()
            ->map(fn ($log) => [
                'name'       => $log->employee?->full_name,
                'department' => $log->employee?->department?->name,
                'status'     => $log->status,
                'check_in'   => $log->check_in,
            ]);

        // Employees currently checked in (no checkout yet)
        $checkedInNow = AttendanceLog::whereDate('date', $today)
            ->when($employeeIds->isNotEmpty() || $departmentId, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->count();

        return [
            'type' => $type,
            'summary' => [
                'total_active'    => $totalActive,
                'present_today'   => $presentToday,
                'late_today'      => $lateToday,
                'absent_today'    => $absentToday,
                'not_recorded'    => $notRecorded,
                'checked_in_now'  => $checkedInNow,
                'attendance_rate' => $monthRate,
                'avg_hours'       => $avgHours,
            ],
            'weekly_trend'    => $weeklyTrend,
            'dept_breakdown'  => $deptBreakdown,
            'alerts'          => $alerts,
        ];
    }

    // ── Employee dashboard payload ─────────────────────────────────────────

    private function employeeDashboard(?\App\Models\Employee $employee): array
    {
        if (! $employee) {
            return ['type' => 'employee', 'summary' => [], 'recent' => [], 'weekly' => []];
        }

        $today      = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        // Today's log
        $todayLog = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', $today)
            ->first();

        // This month's logs
        $monthLogs = AttendanceLog::where('employee_id', $employee->id)
            ->whereDate('date', '>=', $monthStart)
            ->whereDate('date', '<=', $today)
            ->get();

        $presentDays = $monthLogs->whereIn('status', ['present', 'late'])->count();
        $absentDays  = $monthLogs->where('status', 'absent')->count();
        $lateDays    = $monthLogs->where('status', 'late')->count();
        $totalWorked = $monthLogs->sum('total_minutes');
        $avgMinutes  = $monthLogs->where('total_minutes', '>', 0)->avg('total_minutes') ?? 0;

        // Attendance rate this month
        $workingDays = now()->startOfMonth()->diffInWeekdays(now()) + 1;
        $rate = $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 1) : 0;

        // Current streak (consecutive present days)
        $streak = 0;
        $cursor = now();
        while ($streak < 30) {
            $dayStr = $cursor->toDateString();
            $log    = AttendanceLog::where('employee_id', $employee->id)
                ->whereDate('date', $dayStr)
                ->whereIn('status', ['present', 'late'])
                ->exists();
            if (! $log) break;
            $streak++;
            $cursor->subWeekday();
        }

        // Last 7 days personal log
        $weekly = [];
        for ($i = 6; $i >= 0; $i--) {
            $day    = now()->subDays($i);
            $dayLog = AttendanceLog::where('employee_id', $employee->id)
                ->whereDate('date', $day->toDateString())
                ->first();
            $weekly[] = [
                'day'           => $day->format('D'),
                'date'          => $day->toDateString(),
                'status'        => $dayLog?->status ?? 'no_record',
                'check_in'      => $dayLog?->check_in,
                'check_out'     => $dayLog?->check_out,
                'total_minutes' => $dayLog?->total_minutes,
            ];
        }

        // Recent 5 logs
        $recent = AttendanceLog::where('employee_id', $employee->id)
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($l) => [
                'date'          => $l->date->toDateString(),
                'check_in'      => $l->check_in,
                'check_out'     => $l->check_out,
                'total_minutes' => $l->total_minutes,
                'status'        => $l->status,
            ]);

        return [
            'type'      => 'employee',
            'today_log' => $todayLog,
            'summary'   => [
                'present_days'    => $presentDays,
                'absent_days'     => $absentDays,
                'late_days'       => $lateDays,
                'total_hours'     => round($totalWorked / 60, 1),
                'avg_hours'       => round($avgMinutes / 60, 1),
                'attendance_rate' => $rate,
                'streak'          => $streak,
            ],
            'weekly' => $weekly,
            'recent' => $recent,
        ];
    }

    // ── Update record ────────────────────────────────────────────────────

    /**
     * Update an existing attendance log (HR/admin only).
     * Allows correcting status, check_in, check_out and notes.
     *
     * PUT /api/v1/attendance/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $log = AttendanceLog::findOrFail($id);

        $request->validate([
            'check_in'  => 'nullable|date_format:H:i:s',
            'check_out' => 'nullable|date_format:H:i:s',
            'status'    => 'nullable|in:present,absent,late,half_day,on_leave,holiday',
            'notes'     => 'nullable|string|max:500',
        ]);

        $data = $request->only(['check_in', 'check_out', 'status', 'notes']);

        // Recalculate total_minutes if both times are present
        if (!empty($data['check_in']) && !empty($data['check_out'] ?? $log->check_out)) {
            $cin  = Carbon::parse($log->date->toDateString() . ' ' . ($data['check_in']  ?? $log->check_in));
            $cout = Carbon::parse($log->date->toDateString() . ' ' . ($data['check_out'] ?? $log->check_out));
            $data['total_minutes'] = (int) $cin->diffInMinutes($cout);
        }

        $log->update(array_merge($data, ['source' => 'manual']));

        return response()->json(['message' => 'Attendance record updated.', 'log' => $log->fresh()]);
    }

    // ── Settings ─────────────────────────────────────────────────────────

    /**
     * Get attendance policy settings stored in Laravel's config cache.
     * Defaults are returned if no custom settings have been saved.
     *
     * GET /api/v1/attendance/settings
     */
    public function getSettings(): JsonResponse
    {
        return response()->json($this->attendancePolicy->settings());
    }

    /**
     * Save attendance policy settings to a JSON file in storage.
     *
     * POST /api/v1/attendance/settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $request->validate([
            'work_start'         => 'required|date_format:H:i',
            'late_after_minutes' => 'required|integer|min:0|max:120',
            'half_day_hours'     => 'required|numeric|min:1|max:12',
            'full_day_hours'     => 'required|numeric|min:4|max:24',
            'grace_minutes'      => 'required|integer|min:0|max:60',
            'weekend_days'       => 'required|array',
        ]);

        $settings = $request->only([
            'work_start', 'late_after_minutes', 'half_day_hours',
            'full_day_hours', 'grace_minutes', 'weekend_days',
        ]);

        $this->attendancePolicy->save($settings);
        $reclassified = $this->attendancePolicy->reclassifyAutomaticLogsForDate(now(), $settings);

        return response()->json([
            'message' => 'Settings saved.',
            'settings' => $settings,
            'reclassified_today' => $reclassified,
        ]);
    }

    private function hasAnyRoleDB(array $roles): bool
    {
        $user = auth()->user();
        $userRoles = rescue(fn() => \DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', get_class($user))
            ->pluck('roles.name')
            ->toArray(), [], false);

        if (in_array('ceo', $userRoles, true) && !in_array('hr_manager', $userRoles, true)) {
            $userRoles[] = 'hr_manager';
        }

        return (bool) array_intersect($roles, $userRoles);
    }
}
