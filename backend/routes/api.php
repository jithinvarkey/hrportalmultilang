<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\EmployeeController;
use App\Http\Controllers\API\DepartmentController;
use App\Http\Controllers\API\DesignationController;
use App\Http\Controllers\API\PayrollController;
use App\Http\Controllers\API\LeaveController;
use App\Http\Controllers\API\ExcuseLimitController;
use App\Http\Controllers\API\LoanController;
use App\Http\Controllers\API\SeparationController;
use App\Http\Controllers\API\RequestManagementController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\BioTimeController;
use App\Http\Controllers\API\AnnouncementController;
use App\Http\Controllers\API\PolicyController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\AttendanceController;
use App\Http\Controllers\API\ContractController;
use App\Http\Controllers\API\ContractRenewalController;
use App\Http\Controllers\API\RecruitmentController;
use App\Http\Controllers\API\OnboardingController;
use App\Http\Controllers\API\PerformanceController;
use App\Http\Controllers\API\AssetController;
use App\Http\Controllers\API\OrgChartController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\PublicOnboardingController;
use App\Http\Controllers\API\LegacyMigrationController;
use App\Http\Controllers\API\UnitController;

/*
|--------------------------------------------------------------------------
| API Routes — HRMS v1
| All routes accessible at /api/v1/...
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── Public Auth ──────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('login',           [AuthController::class, 'login']);
        Route::post('verify-login-otp',[AuthController::class, 'verifyLoginOtp']);
        Route::post('resend-otp',      [AuthController::class, 'resendOtp']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password',  [AuthController::class, 'resetPassword']);
    });

    // ── Public Job Listings ──────────────────────────────────────────────
    Route::get('jobs',                    [RecruitmentController::class, 'publicJobs']);
    Route::post('jobs/{jobId}/apply',     [RecruitmentController::class, 'publicApply']);
    Route::get('public/onboarding/{token}', [PublicOnboardingController::class, 'show']);
    Route::post('public/onboarding/{token}', [PublicOnboardingController::class, 'submit']);

    // ── Protected Routes ─────────────────────────────────────────────────


    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout',   [AuthController::class, 'logout']);
            Route::get('me',        [AuthController::class, 'me']);
            Route::put('password',  [AuthController::class, 'changePassword']);
        });

        // Profile (authenticated user's own account)
        Route::prefix('profile')->group(function () {
            Route::get('/',         [ProfileController::class, 'show']);
            Route::put('/',         [ProfileController::class, 'update']);
            Route::post('avatar',   [ProfileController::class, 'uploadAvatar']);
            Route::put('password',  [ProfileController::class, 'changePassword']);
        });

        // Announcements (HR/Admin manage; everyone reads)
        Route::prefix('announcements')->group(function () {
            Route::get('/birthday-wishes/settings', [AnnouncementController::class, 'birthdayWishSettings']);
            Route::put('/birthday-wishes/settings', [AnnouncementController::class, 'updateBirthdayWishSettings']);
            Route::post('/birthday-wishes/send', [AnnouncementController::class, 'sendBirthdayWishes']);
            Route::get('/categories',          [AnnouncementController::class, 'categories']);
            Route::post('/categories',         [AnnouncementController::class, 'storeCategory']);
            Route::put('/categories/{id}',     [AnnouncementController::class, 'updateCategory'])->whereNumber('id');
            Route::delete('/categories/{id}',  [AnnouncementController::class, 'deleteCategory'])->whereNumber('id');

            Route::get('/',                    [AnnouncementController::class, 'index']);
            Route::post('/',                   [AnnouncementController::class, 'store']);
            Route::get('/{id}',                [AnnouncementController::class, 'show'])->whereNumber('id');
            Route::put('/{id}',                [AnnouncementController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}',             [AnnouncementController::class, 'destroy'])->whereNumber('id');
            Route::get('/{id}/attachment',     [AnnouncementController::class, 'downloadAttachment'])->whereNumber('id');
            Route::post('/{id}/read',          [AnnouncementController::class, 'markRead'])->whereNumber('id');
            Route::post('/{id}/react',         [AnnouncementController::class, 'react'])->whereNumber('id');
            Route::get('/{id}/stats',          [AnnouncementController::class, 'readStats'])->whereNumber('id');
        });

        // In-app notifications (bell)
        Route::prefix('notifications')->group(function () {
            Route::get('/',            [NotificationController::class, 'index']);
            Route::post('/read-all',   [NotificationController::class, 'markAllRead']);
            Route::post('/{id}/read',  [NotificationController::class, 'markRead'])->whereNumber('id');
        });

        // HR Policies (HR/Admin manage; everyone views & acknowledges)
        Route::prefix('policies')->group(function () {
            Route::get('/categories',          [PolicyController::class, 'categories']);
            Route::post('/categories',         [PolicyController::class, 'storeCategory']);
            Route::put('/categories/{id}',     [PolicyController::class, 'updateCategory'])->whereNumber('id');
            Route::delete('/categories/{id}',  [PolicyController::class, 'deleteCategory'])->whereNumber('id');

            Route::get('/',                    [PolicyController::class, 'index']);
            Route::post('/',                   [PolicyController::class, 'store']);
            Route::get('/{id}',                [PolicyController::class, 'show'])->whereNumber('id');
            Route::put('/{id}',                [PolicyController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}',             [PolicyController::class, 'destroy'])->whereNumber('id');
            Route::get('/{id}/attachment',     [PolicyController::class, 'downloadAttachment'])->whereNumber('id');
            Route::post('/{id}/acknowledge',   [PolicyController::class, 'acknowledge'])->whereNumber('id');
            Route::get('/{id}/acknowledgements',[PolicyController::class, 'acknowledgements'])->whereNumber('id');
            Route::get('/{id}/acknowledgements/export',[PolicyController::class, 'exportAcknowledgements'])->whereNumber('id');
            Route::post('/{id}/remind',        [PolicyController::class, 'remindPending'])->whereNumber('id');
        });

        // Dashboard
        Route::get('dashboard/stats',             [DashboardController::class, 'stats']);
        Route::get('dashboard/charts',            [DashboardController::class, 'charts']);
        Route::get('dashboard/recent-activities', [DashboardController::class, 'recentActivities']);

        // Employees
        Route::prefix('employees')->group(function () {
            Route::get('/',                              [EmployeeController::class, 'index']);
            Route::post('/',                             [EmployeeController::class, 'store']);
            Route::get('/export',                        [EmployeeController::class, 'export']);
            Route::get('/details-report',                [EmployeeController::class, 'downloadDetailsReport']);
            Route::get('/stats',                         [EmployeeController::class, 'stats']);
             Route::get('/manager-options',               [EmployeeController::class, 'managerOptions']);
             Route::get('/{id}/leave-balances',            [EmployeeController::class, 'leaveBalances'])->whereNumber('id');
             Route::put('/{id}/leave-balances/carry-forward', [EmployeeController::class, 'updateLeaveCarryForward'])->whereNumber('id');
             Route::get('/{id}',                          [EmployeeController::class, 'show']);
            Route::put('/{id}',                          [EmployeeController::class, 'update']);
            Route::delete('/{id}',                       [EmployeeController::class, 'destroy']);
            Route::post('/{id}/avatar',                  [EmployeeController::class, 'uploadAvatar']);
            Route::post('/{id}/documents',               [EmployeeController::class, 'uploadDocument']);
            Route::get('/{id}/documents',                [EmployeeController::class, 'listDocuments']);
            Route::delete('/{id}/documents/{docId}',     [EmployeeController::class, 'deleteDocument']);
            Route::post('/{id}/documents/{docId}/approve', [EmployeeController::class, 'approveDocument'])->whereNumber('id')->whereNumber('docId');
            Route::get('/{id}/dependents',               [EmployeeController::class, 'dependents'])->whereNumber('id');
            Route::post('/{id}/dependents',              [EmployeeController::class, 'storeDependent'])->whereNumber('id');
            Route::put('/{id}/dependents/{dependentId}', [EmployeeController::class, 'updateDependent'])->whereNumber('id')->whereNumber('dependentId');
            Route::delete('/{id}/dependents/{dependentId}', [EmployeeController::class, 'deleteDependent'])->whereNumber('id')->whereNumber('dependentId');
            Route::get('/{id}/dependents/{dependentId}/documents/{type}', [EmployeeController::class, 'downloadDependentDocument'])->whereNumber('id')->whereNumber('dependentId')->whereIn('type', ['passport', 'id']);
            Route::get('/{id}/documents/{docId}/download', [EmployeeController::class, 'downloadDocument']);
        });

        // Departments
        Route::prefix('departments')->group(function () {
            Route::get('/',               [DepartmentController::class, 'index']);
            Route::post('/',              [DepartmentController::class, 'store']);
            Route::get('/{id}',           [DepartmentController::class, 'show']);
            Route::put('/{id}',           [DepartmentController::class, 'update']);
            Route::delete('/{id}',        [DepartmentController::class, 'destroy']);
            Route::get('/{id}/headcount', [DepartmentController::class, 'headcount']);
        });

        // Units / Branches
        Route::apiResource('units', UnitController::class);

        // Designations
        Route::apiResource('designations', DesignationController::class);

        // Payroll
        Route::prefix('payroll')->middleware('role:super_admin|ceo|hr_manager')->group(function () {
            // ── Static / literal routes FIRST (before any {id} wildcards) ──────
            Route::get('/stats',                               [PayrollController::class, 'stats']);
            Route::get('/components',                          [PayrollController::class, 'components']);
            Route::post('/components',                         [PayrollController::class, 'storeComponent']);
            Route::get('/employee-options',                    [PayrollController::class, 'employeeOptions']);
            Route::get('/employee/{empId}',                    [PayrollController::class, 'employeeHistory'])->whereNumber('empId');
            Route::get('/payslip/generate',                    [PayrollController::class, 'generateEmployeePayslip']);
            Route::get('/payslip/{payslipId}/download',        [PayrollController::class, 'downloadPayslip'])->whereNumber('payslipId');
            Route::post('/run',                                [PayrollController::class, 'run']);

            // ── List + create ────────────────────────────────────────────────
            Route::get('/',                                    [PayrollController::class, 'index']);

            // ── Wildcard {id} routes LAST ────────────────────────────────────
            Route::get('/{id}',                                [PayrollController::class, 'show'])->whereNumber('id');
            Route::post('/{id}/approve',                       [PayrollController::class, 'approve'])->whereNumber('id');
            Route::post('/{id}/reject',                        [PayrollController::class, 'reject'])->whereNumber('id');
            Route::post('/{id}/mark-paid',                     [PayrollController::class, 'markPaid'])->whereNumber('id');
            Route::post('/{id}/reopen',                        [PayrollController::class, 'reopen'])->whereNumber('id');
            Route::post('/{id}/recalculate',                   [PayrollController::class, 'recalculate'])->whereNumber('id');
            Route::get('/{id}/payslips',                       [PayrollController::class, 'payslips'])->whereNumber('id');
            Route::get('/{id}/export',                         [PayrollController::class, 'export'])->whereNumber('id');
            Route::put('/{id}/payslips/{psId}',                [PayrollController::class, 'updatePayslip'])->whereNumber(['id','psId']);
            Route::delete('/{id}/payslips/{psId}',             [PayrollController::class, 'removeProbationPayslip'])->whereNumber(['id','psId']);
        });

        // Leave
        Route::prefix('leave')->group(function () {
            Route::post('/accrue',   [LeaveController::class, 'runAccrual']);   // manual trigger
            Route::get('/types',                   [LeaveController::class, 'types']);
            Route::get('/ticket-options',          [LeaveController::class, 'ticketOptions']);
            Route::post('/types',                  [LeaveController::class, 'storeType']);
            Route::get('/types/{id}/visibility',   [LeaveController::class, 'typeVisibility']);
            Route::post('/types/{id}/visibility',  [LeaveController::class, 'saveTypeVisibility']);
            Route::put('/types/{id}',              [LeaveController::class, 'updateType']);
            Route::get('/requests',                [LeaveController::class, 'index']);
            Route::get('/details-report',           [LeaveController::class, 'downloadDetailsReport']);
            Route::post('/requests',               [LeaveController::class, 'store']);
            Route::get('/requests/{id}',           [LeaveController::class, 'show']);
            Route::put('/requests/{id}',           [LeaveController::class, 'update']);
            Route::delete('/requests/{id}',        [LeaveController::class, 'cancel']);
            Route::post('/requests/{id}/approve',  [LeaveController::class, 'approve']);
            Route::post('/requests/{id}/reject',   [LeaveController::class, 'reject']);
            Route::get('/balance/{empId}',         [LeaveController::class, 'balance']);
            Route::get('/calendar',                [LeaveController::class, 'calendar']);
            Route::get('/stats',                   [LeaveController::class, 'stats']);
            Route::get('/excuse-usage',            [LeaveController::class, 'excuseUsage']);

        // Department excuse limits (admin)
        Route::prefix('excuse-limits')->group(function () {
            Route::get('/',        [ExcuseLimitController::class, 'index']);
            Route::post('/bulk',   [ExcuseLimitController::class, 'bulkUpsert']);
            Route::put('/{id}',    [ExcuseLimitController::class, 'update']);
        });
            Route::get('/all-balances',            [LeaveController::class, 'allBalances']);
            Route::get('/annual-balance-report',   [LeaveController::class, 'downloadAnnualBalanceReport']);
            Route::get('/holidays',                [LeaveController::class, 'holidays']);
            Route::post('/holidays',               [LeaveController::class, 'storeHoliday']);
            Route::put('/holidays/{id}',           [LeaveController::class, 'updateHoliday']);
            Route::delete('/holidays/{id}',        [LeaveController::class, 'deleteHoliday']);
        });

        // Attendance
        Route::prefix('attendance')->group(function () {
            Route::post('/checkin',           [AttendanceController::class, 'checkIn']);
            Route::post('/checkout',          [AttendanceController::class, 'checkOut']);
            Route::get('/today',              [AttendanceController::class, 'today']);
            Route::get('/dashboard',          [AttendanceController::class, 'dashboard']);
            Route::get('/report',             [AttendanceController::class, 'report']);
            Route::get('/direct-reports',     [AttendanceController::class, 'directReports']);
            Route::get('/monthly-report.xlsx', [AttendanceController::class, 'monthlyReport']);
            Route::post('/manual',            [AttendanceController::class, 'manualEntry']);
            Route::get('/employee/{empId}',   [AttendanceController::class, 'employeeLog']);
            Route::put('/{id}',              [AttendanceController::class, 'update'])->whereNumber('id');
            Route::get('/settings',          [AttendanceController::class, 'getSettings']);
            Route::post('/settings',         [AttendanceController::class, 'saveSettings']);
        });

        // BioTime / ZKTeco biometric devices
        Route::prefix('biotime')->group(function () {
            Route::post('/sync-all',                   [BioTimeController::class, 'syncAll']);

            Route::prefix('devices')->group(function () {
                Route::get('/',                        [BioTimeController::class, 'index']);
                Route::post('/',                       [BioTimeController::class, 'store']);
                Route::put('/{id}',                    [BioTimeController::class, 'update'])->whereNumber('id');
                Route::delete('/{id}',                 [BioTimeController::class, 'destroy'])->whereNumber('id');
                Route::post('/{id}/test',              [BioTimeController::class, 'testConnection'])->whereNumber('id');
                Route::post('/{id}/sync',              [BioTimeController::class, 'sync'])->whereNumber('id');
                Route::get('/{id}/employees',          [BioTimeController::class, 'employees'])->whereNumber('id');
                Route::get('/{id}/unmatched',          [BioTimeController::class, 'unmatched'])->whereNumber('id');
                Route::get('/{id}/logs',               [BioTimeController::class, 'logs'])->whereNumber('id');
                Route::get('/{id}/stats',              [BioTimeController::class, 'stats'])->whereNumber('id');
            });
        });

        // Recruitment
        Route::prefix('recruitment')->group(function () {
            Route::get('/stats',                       [RecruitmentController::class, 'stats']);
            Route::get('/jobs',                        [RecruitmentController::class, 'jobs']);
            Route::post('/jobs',                       [RecruitmentController::class, 'storeJob']);
            Route::put('/jobs/{id}',                   [RecruitmentController::class, 'updateJob']);
            Route::delete('/jobs/{id}',                [RecruitmentController::class, 'deleteJob']);
            Route::post('/apply/{jobId}',              [RecruitmentController::class, 'apply']);
            Route::get('/applications',                [RecruitmentController::class, 'applications']);
            Route::get('/applications/{id}',           [RecruitmentController::class, 'showApplication']);
            Route::put('/applications/{id}',           [RecruitmentController::class, 'updateApplication']);
            Route::put('/applications/{id}/stage',     [RecruitmentController::class, 'updateStage']);
            Route::post('/interviews',                 [RecruitmentController::class, 'scheduleInterview']);
            Route::put('/interviews/{id}',             [RecruitmentController::class, 'updateInterview']);

            // CV Bank
            Route::get('/cv-bank',                [RecruitmentController::class, 'cvBank']);
            Route::post('/cv-bank',               [RecruitmentController::class, 'storeCv']);
            Route::put('/cv-bank/{id}',           [RecruitmentController::class, 'updateCv'])->whereNumber('id');
            Route::delete('/cv-bank/{id}',        [RecruitmentController::class, 'deleteCv'])->whereNumber('id');
            Route::post('/cv-bank/{id}/link',     [RecruitmentController::class, 'linkCvToJob'])->whereNumber('id');
            Route::post('/offer/{applicationId}',      [RecruitmentController::class, 'sendOffer']);
            Route::post('/hire/{applicationId}',       [RecruitmentController::class, 'hire']);
        });

        // Onboarding
        Route::prefix('onboarding')->group(function () {
            Route::get('/{empId}/tasks',            [OnboardingController::class, 'tasks']);
            Route::post('/{empId}/tasks',           [OnboardingController::class, 'createTask']);
            Route::put('/tasks/{taskId}',           [OnboardingController::class, 'updateTask']);
            Route::post('/tasks/{taskId}/complete', [OnboardingController::class, 'completeTask']);
            Route::delete('/tasks/{taskId}',         [OnboardingController::class, 'deleteTask']);
        });

        // Performance
        Route::prefix('performance')->group(function () {
            Route::get('/stats',                       [PerformanceController::class, 'stats']);
            Route::get('/kpis',                        [PerformanceController::class, 'kpis']);
            Route::post('/kpis',                       [PerformanceController::class, 'storeKpi']);
            Route::put('/kpis/{id}',                   [PerformanceController::class, 'updateKpi'])->whereNumber('id');
            Route::delete('/kpis/{id}',                [PerformanceController::class, 'deleteKpi'])->whereNumber('id');
            Route::get('/reports/{empId}',             [PerformanceController::class, 'report'])->whereNumber('empId');

            // ── Cycles ─────────────────────────────────────────────────────
            Route::get('/',                            [PerformanceController::class, 'index']);
            Route::post('/',                           [PerformanceController::class, 'store']);
            Route::get('/{id}',                        [PerformanceController::class, 'show'])->whereNumber('id');
            Route::put('/{id}',                        [PerformanceController::class, 'updateCycle'])->whereNumber('id');
            Route::post('/{id}/initiate',              [PerformanceController::class, 'initiate'])->whereNumber('id');

            // ── Reviews ─────────────────────────────────────────────────────
            Route::post('/review/{reviewId}/self',     [PerformanceController::class, 'selfAssessment'])->whereNumber('reviewId');
            Route::post('/review/{reviewId}/manager',  [PerformanceController::class, 'managerReview'])->whereNumber('reviewId');
            Route::post('/review/{reviewId}/finalize', [PerformanceController::class, 'finalize'])->whereNumber('reviewId');
        });

        // Org Chart
        Route::prefix('org-chart')->group(function () {
            Route::get('/',             [OrgChartController::class, 'index']);
            Route::get('/stats',        [OrgChartController::class, 'stats']);
            Route::get('/search',       [OrgChartController::class, 'search']);
            Route::get('/dept/{id}',    [OrgChartController::class, 'department'])->whereNumber('id');
            Route::post('/dept',        [OrgChartController::class, 'storeDepartment']);
            Route::put('/dept/{id}',    [OrgChartController::class, 'updateDepartment'])->whereNumber('id');
        });

        // ── Loans ────────────────────────────────────────────────────────────
        Route::prefix('loans')->group(function () {
            // ── Static routes FIRST (must come before any {id} wildcards) ──
            Route::get('/stats',                            [LoanController::class, 'stats']);
            Route::get('/my',                               [LoanController::class, 'myLoans']);
            Route::post('/installments/mark-overdue',       [LoanController::class, 'markOverdue']);

            // ── Loan Types ────────────────────────────────────────────────
            Route::prefix('types')->group(function () {
                Route::get('/',         [LoanController::class, 'types']);
                Route::get('/all',      [LoanController::class, 'allTypes']);
                Route::post('/',        [LoanController::class, 'storeType']);
                Route::put('/{id}',     [LoanController::class, 'updateType'])->whereNumber('id');
            });

            // ── Loan CRUD ─────────────────────────────────────────────────
            Route::get('/',     [LoanController::class, 'index']);
            Route::get('/details-report', [LoanController::class, 'downloadDetailsReport']);
            Route::post('/',    [LoanController::class, 'store']);

            // ── Numeric-ID routes ─────────────────────────────────────────
            Route::get('/{id}',                             [LoanController::class, 'show'])->whereNumber('id');
            Route::post('/{id}/approve',                    [LoanController::class, 'approve'])->whereNumber('id');
            Route::post('/{id}/reject',                     [LoanController::class, 'reject'])->whereNumber('id');
            Route::post('/{id}/cancel',                     [LoanController::class, 'cancel'])->whereNumber('id');
            Route::delete('/{id}',                          [LoanController::class, 'destroy'])->whereNumber('id');
            Route::post('/{id}/disburse',                   [LoanController::class, 'disburse'])->whereNumber('id');
            Route::post('/{loanId}/installments/{instId}/pay',  [LoanController::class, 'payInstallment'])->whereNumber('loanId')->whereNumber('instId');
            Route::post('/{loanId}/installments/{instId}/skip', [LoanController::class, 'skipInstallment'])->whereNumber('loanId')->whereNumber('instId');
        });

        // ── Separations / Offboarding ────────────────────────────────────────
        Route::prefix('separations')->group(function () {
            Route::get('/stats',                                    [SeparationController::class, 'stats']);
            Route::get('/settlement-preview',                       [SeparationController::class, 'settlementPreview']);

            Route::prefix('templates')->group(function () {
                Route::get('/',         [SeparationController::class, 'templates']);
                Route::post('/',        [SeparationController::class, 'storeTemplate']);
                Route::put('/{id}',     [SeparationController::class, 'updateTemplate'])->whereNumber('id');
                Route::delete('/{id}',  [SeparationController::class, 'deleteTemplate'])->whereNumber('id');
            });

            Route::get('/',     [SeparationController::class, 'index']);
            Route::post('/',    [SeparationController::class, 'store']);
            Route::get('/{id}',                         [SeparationController::class, 'show'])->whereNumber('id');
            Route::put('/{id}',                         [SeparationController::class, 'update'])->whereNumber('id');
            Route::post('/{id}/approve',                [SeparationController::class, 'approve'])->whereNumber('id');
            Route::post('/{id}/reject',                 [SeparationController::class, 'reject'])->whereNumber('id');
            Route::post('/{id}/cancel',                 [SeparationController::class, 'cancel'])->whereNumber('id');
            Route::post('/{id}/complete',               [SeparationController::class, 'complete'])->whereNumber('id');
            Route::put('/{id}/settlement',              [SeparationController::class, 'updateSettlement'])->whereNumber('id');
            Route::post('/{id}/exit-interview',         [SeparationController::class, 'updateExitInterview'])->whereNumber('id');
            Route::put('/{id}/checklist/{itemId}',      [SeparationController::class, 'updateChecklistItem'])->whereNumber('id')->whereNumber('itemId');
        });


        // ── Request Management ───────────────────────────────────────────────
        Route::prefix('requests')->group(function () {
            Route::get('/stats',                    [RequestManagementController::class, 'stats']);
            Route::get('/assignable-users',         [RequestManagementController::class, 'assignableUsers']);
            Route::post('/mark-overdue',            [RequestManagementController::class, 'markOverdue']);

            Route::prefix('types')->group(function () {
                Route::get('/',         [RequestManagementController::class, 'types']);
                Route::get('/all',      [RequestManagementController::class, 'allTypes']);
                Route::post('/',        [RequestManagementController::class, 'storeType']);
                Route::put('/{id}',     [RequestManagementController::class, 'updateType'])->whereNumber('id');
                Route::delete('/{id}',  [RequestManagementController::class, 'deleteType'])->whereNumber('id');
            });

            Route::get('/',     [RequestManagementController::class, 'index']);
            Route::post('/',    [RequestManagementController::class, 'store']);
            Route::get('/{id}',                         [RequestManagementController::class, 'show'])->whereNumber('id');
            Route::post('/{id}/manager-approve',        [RequestManagementController::class, 'managerApprove'])->whereNumber('id');
            Route::post('/{id}/assign',                 [RequestManagementController::class, 'assign'])->whereNumber('id');
            Route::post('/{id}/complete',               [RequestManagementController::class, 'complete'])->whereNumber('id');
            Route::get('/{id}/completion-file',         [RequestManagementController::class, 'downloadCompletionFile'])->whereNumber('id');
            Route::post('/{id}/reject',                 [RequestManagementController::class, 'reject'])->whereNumber('id');
            Route::post('/{id}/cancel',                 [RequestManagementController::class, 'cancel'])->whereNumber('id');
            Route::post('/{id}/comments',               [RequestManagementController::class, 'addComment'])->whereNumber('id');
        });


        // ── Admin / RBAC ──────────────────────────────────────────────────────
        // ── Contracts ──────────────────────────────────────────────────────
        Route::prefix('contracts')->group(function () {
            Route::get('/stats',           [ContractController::class, 'stats']);
            Route::get('/active-employee-contracts-report', [ContractController::class, 'downloadActiveEmployeeContractsReport']);
            Route::get('/',                [ContractController::class, 'index']);
            Route::post('/',               [ContractController::class, 'store']);
            Route::get('/{id}',            [ContractController::class, 'show'])->whereNumber('id');
            Route::put('/{id}',            [ContractController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}',         [ContractController::class, 'destroy'])->whereNumber('id');
            Route::post('/{id}/approve',   [ContractController::class, 'approve'])->whereNumber('id');

            // Contract document (single attached PDF/DOC/DOCX)
            Route::post('/{id}/document',     [ContractController::class, 'uploadDocument'])->whereNumber('id');
            Route::get('/{id}/document',      [ContractController::class, 'downloadDocument'])->whereNumber('id');
            Route::delete('/{id}/document',   [ContractController::class, 'deleteDocument'])->whereNumber('id');

            // â”€â”€ Renewal requests â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            Route::prefix('renewals')->group(function () {
                Route::get('/stats',        [ContractRenewalController::class, 'stats']);
                Route::get('/',             [ContractRenewalController::class, 'index']);
                Route::post('/',            [ContractRenewalController::class, 'store']);
                Route::get('/{id}',         [ContractRenewalController::class, 'show'])->whereNumber('id');
                Route::post('/{id}/approve',[ContractRenewalController::class, 'approve'])->whereNumber('id');
                Route::post('/{id}/reject', [ContractRenewalController::class, 'reject'])->whereNumber('id');

                // Renewal supporting document
                Route::post('/{id}/document',   [ContractRenewalController::class, 'uploadDocument'])->whereNumber('id');
                Route::get('/{id}/document',    [ContractRenewalController::class, 'downloadDocument'])->whereNumber('id');
                Route::delete('/{id}/document', [ContractRenewalController::class, 'deleteDocument'])->whereNumber('id');
            });
        });
        Route::get('/employees/{empId}/contracts', [ContractController::class, 'forEmployee'])->whereNumber('empId');

        Route::prefix('admin')->group(function () {
            Route::get('/overview',                         [AdminController::class, 'overview']);
            Route::get('/permissions',                      [AdminController::class, 'permissions']);
            Route::get('/login-activities',                 [AdminController::class, 'loginActivities']);
            Route::get('/settings/loans',                   [AdminController::class, 'loanSettings']);
              Route::put('/settings/loans',                   [AdminController::class, 'updateLoanSettings']);
              Route::get('/settings/annual-tickets',          [AdminController::class, 'annualTicketSettings']);
              Route::put('/settings/annual-tickets',          [AdminController::class, 'updateAnnualTicketSettings']);
              Route::get('/settings/monthly-leave-reminder',   [AdminController::class, 'monthlyLeaveReminderSettings']);
              Route::put('/settings/monthly-leave-reminder',   [AdminController::class, 'updateMonthlyLeaveReminderSettings']);
              Route::get('/settings/unifonic',                 [AdminController::class, 'unifonicSettings']);
              Route::put('/settings/unifonic',                 [AdminController::class, 'updateUnifonicSettings']);
              Route::get('/settings/email',                    [AdminController::class, 'emailSettings']);
              Route::put('/settings/email',                    [AdminController::class, 'updateEmailSettings']);
              Route::get('/settings/hdf-template',             [AdminController::class, 'hdfTemplate']);
              Route::post('/settings/hdf-template',            [AdminController::class, 'uploadHdfTemplate']);
              Route::post('/legacy-migration/import',           [LegacyMigrationController::class, 'import']);

            Route::prefix('users')->group(function () {
                Route::get('/',         [AdminController::class, 'users']);
                Route::post('/',        [AdminController::class, 'storeUser']);
                Route::get('/{id}',     [AdminController::class, 'showUser'])->whereNumber('id');
                Route::put('/{id}',     [AdminController::class, 'updateUser'])->whereNumber('id');
                Route::post('/{id}/assign-role',    [AdminController::class, 'assignRole'])->whereNumber('id');
                Route::post('/{id}/toggle-status',  [AdminController::class, 'toggleUserStatus'])->whereNumber('id');
                Route::put('/{id}/otp-exemption',   [AdminController::class, 'updateUserOtpExemption'])->whereNumber('id');
            });

            Route::prefix('roles')->group(function () {
                Route::get('/',         [AdminController::class, 'roles']);
                Route::put('/{id}/permissions', [AdminController::class, 'updateRolePermissions'])->whereNumber('id');
            });
        });

        // â”€â”€ Assets â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        Route::prefix('assets')->group(function () {
            // Categories
            Route::get('/categories',          [AssetController::class, 'categories']);
            Route::post('/categories',         [AssetController::class, 'storeCategory']);
            Route::put('/categories/{id}',     [AssetController::class, 'updateCategory'])->whereNumber('id');
            Route::delete('/categories/{id}',  [AssetController::class, 'deleteCategory'])->whereNumber('id');

            // Statistics
            Route::get('/stats',               [AssetController::class, 'stats']);
            Route::get('/reports/assets',      [AssetController::class, 'assetReport']);
            Route::get('/reports/assignments', [AssetController::class, 'assignmentReport']);
            Route::get('/reports/maintenance', [AssetController::class, 'maintenanceReport']);

            // Employee assets (used in employee-detail tab)
            Route::get('/employee/{empId}',    [AssetController::class, 'forEmployee'])->whereNumber('empId');

            // Asset CRUD
            Route::get('/',                    [AssetController::class, 'index']);
            Route::post('/',                   [AssetController::class, 'store']);
            Route::get('/{id}',                [AssetController::class, 'show'])->whereNumber('id');
            Route::post('/{id}',               [AssetController::class, 'update'])->whereNumber('id'); // multipart PUT workaround
            Route::delete('/{id}',             [AssetController::class, 'destroy'])->whereNumber('id');
            Route::get('/{id}/attachment',     [AssetController::class, 'downloadAttachment'])->whereNumber('id');

            // Assignment (check-out / check-in)
            Route::post('/{id}/assign',        [AssetController::class, 'assign'])->whereNumber('id');
            Route::post('/{id}/return',        [AssetController::class, 'return'])->whereNumber('id');

            // Maintenance
            Route::post('/{id}/maintenance',               [AssetController::class, 'logMaintenance'])->whereNumber('id');
            Route::put('/{id}/maintenance/{mid}',          [AssetController::class, 'updateMaintenance'])->whereNumber('id')->whereNumber('mid');
    });
});
});
