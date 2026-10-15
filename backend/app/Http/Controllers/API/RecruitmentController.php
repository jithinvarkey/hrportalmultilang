<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\{Employee, JobPosting, JobApplication, Interview};
use App\Services\NewHireOnboardingService;
use App\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecruitmentController extends Controller {
    protected $service;
    public function __construct(
        RecruitmentService $service,
        private NewHireOnboardingService $onboardingLinks,
    ) { $this->service = $service; }

    public function jobs(Request $request) {
        $departmentScope = $this->managerDepartmentScope($request);

        $jobs = $this->scopeJobs(JobPosting::query(), $departmentScope)
            ->with(['department','designation'])
            ->withCount('applications')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->department_id && $departmentScope === null, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->search, fn($q) => $q->where('title','like',"%{$request->search}%"))
            ->orderBy('created_at','desc')
            ->paginate((int)($request->per_page ?? 15));
        return response()->json($jobs);
    }

    public function stats(): \Illuminate\Http\JsonResponse
    {
        $departmentScope = $this->managerDepartmentScope(request());
        $safe = fn($fn) => rescue($fn, 0, false);
        return response()->json([
            'open_jobs'       => $safe(fn() => $this->scopeJobs(JobPosting::query(), $departmentScope)->where('status','open')->count()),
            'total_jobs'      => $safe(fn() => $this->scopeJobs(JobPosting::query(), $departmentScope)->count()),
            'total_applicants'=> $safe(fn() => $this->scopeApplications(JobApplication::query(), $departmentScope)->count()),
            'new_this_week'   => $safe(fn() => $this->scopeApplications(JobApplication::query(), $departmentScope)->where('created_at','>=',now()->subDays(7))->count()),
            'in_interview'    => $safe(fn() => $this->scopeApplications(JobApplication::query(), $departmentScope)->where('stage','interview')->count()),
            'offers_sent'     => $safe(fn() => $this->scopeApplications(JobApplication::query(), $departmentScope)->where('stage','offer')->count()),
            'hired'           => $safe(fn() => $this->scopeApplications(JobApplication::query(), $departmentScope)->where('stage','hired')->count()),
            'rejected'        => $safe(fn() => $this->scopeApplications(JobApplication::query(), $departmentScope)->where('stage','rejected')->count()),
        ]);
    }

    public function publicJobs() {
        $jobs = JobPosting::with(['department'])->where('status','open')->where(function($q){ $q->whereNull('closing_date')->orWhere('closing_date','>=',now()); })->get();
        return response()->json(['jobs' => $jobs]);
    }

    public function storeJob(Request $request) {
        $this->ensureCanManageRecruitment($request);
        $data = $request->validate([
            'title'           => 'required|string|max:150',
            'employment_type' => 'required|in:full_time,part_time,contract,intern',
            'description'     => 'nullable|string',
            'status'          => 'sometimes|in:draft,open,closed,on_hold',
            'vacancies'       => 'sometimes|integer|min:1',
            'department_id'   => 'nullable|exists:departments,id',
            'designation_id'  => 'nullable|exists:designations,id',
            'salary_min'      => 'nullable|numeric|min:0',
            'salary_max'      => 'nullable|numeric|min:0',
            'closing_date'    => 'nullable|date|after:today',
        ]);

        $departmentScope = $this->managerDepartmentScope($request);
        if ($departmentScope !== null) {
            $data['department_id'] = $departmentScope;
        }

        $this->validateDesignationDepartment($data['designation_id'] ?? null, $data['department_id'] ?? null);

        $data['description'] = trim((string) ($data['description'] ?? '')) ?: ($data['title'] . ' position');

        $job = JobPosting::create(array_merge($data, [
            'created_by' => auth()->id(),
            'status'     => $request->status ?? 'open',
        ]));
        if (!$request->user()?->hasRole('hr_manager')) {
            $this->service->notifyHrManagersOfNewJob($job);
        }
        return response()->json(['job' => $job->load('department','designation')], 201);
    }

    public function updateJob(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        $departmentScope = $this->managerDepartmentScope($request);
        $job = $this->scopeJobs(JobPosting::query(), $departmentScope)->findOrFail($id);
        $data = $request->all();

        if ($departmentScope !== null) {
            unset($data['department_id']);
        }

        $departmentId = $departmentScope ?? ($data['department_id'] ?? $job->department_id);
        $this->validateDesignationDepartment($data['designation_id'] ?? $job->designation_id, $departmentId);

        $job->update($data);
        return response()->json(['job' => $job]);
    }

    public function deleteJob(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        $departmentScope = $this->managerDepartmentScope($request);
        $this->scopeJobs(JobPosting::query(), $departmentScope)->findOrFail($id)->delete();
        return response()->json(['message' => 'Job posting deleted']);
    }

    public function apply(Request $request, $jobId) {
        $request->validate([
            'applicant_name'  => 'required|string|max:150',
            'applicant_email' => 'required|email|max:191',
            'applicant_phone' => 'required|string|max:20',
            'cv_path'         => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'expected_salary' => 'nullable|numeric',
            'available_from'  => 'nullable|date',
            'cover_letter_text' => 'nullable|string',
            'stage'           => 'nullable|in:applied,screening,interview,offer',
        ]);

        // HR can add applicants to any job; public applicants can only apply to open jobs
        $isHR = auth()->check() && rescue(fn() => \DB::table('model_has_roles')
            ->join('roles','roles.id','=','model_has_roles.role_id')
            ->where('model_has_roles.model_id', auth()->id())
            ->whereIn('roles.name', ['super_admin','hr_manager','hr_staff'])
            ->exists(), false, false);

        $departmentScope = $this->managerDepartmentScope($request);
        $jobQuery = $this->scopeJobs(JobPosting::query(), $departmentScope);
        $job = $isHR || $departmentScope !== null
            ? $jobQuery->findOrFail($jobId)
            : $jobQuery->where('status','open')->findOrFail($jobId);

        $cvPath = $request->hasFile('cv_path')
            ? $request->file('cv_path')->store("recruitment/cvs/{$jobId}", 'public')
            : null;

        $application = JobApplication::create(array_merge(
            $request->only(['applicant_name','applicant_email','applicant_phone','expected_salary','available_from','cover_letter_text']),
            [
                'job_posting_id' => $jobId,
                'cv_path'        => $cvPath,
                'stage'          => $request->stage ?? 'applied',
            ]
        ));

        if (!$request->user()?->hasRole('hr_manager')) {
            $this->service->notifyHrManagersOfNewApplication($application, $request->user());
        }

        return response()->json(['message' => 'Application submitted', 'application' => $application->load('jobPosting')], 201);
    }

    public function publicApply(Request $request, $jobId) { return $this->apply($request, $jobId); }

    public function applications(Request $request) {
        $departmentScope = $this->managerDepartmentScope($request);

        $apps = $this->scopeApplications(JobApplication::query(), $departmentScope)
            ->with(['jobPosting.department'])
            ->when($request->job_posting_id, fn($q) => $q->where('job_posting_id', $request->job_posting_id))
            ->when($request->stage, fn($q) => $q->where('stage', $request->stage))
            ->orderBy('created_at','desc')->paginate(20);
        $this->hideExpectedSalary($apps->getCollection(), $request);
        return response()->json($apps);
    }

    public function showApplication(Request $request, $id) {
        $departmentScope = $this->managerDepartmentScope($request);
        $app = $this->scopeApplications(JobApplication::query(), $departmentScope)
            ->with(['jobPosting','latestInterview'])
            ->findOrFail($id);
        $employeeId = $request->user()?->employee?->id;
        $applicationDepartmentId = $app->department_id ?: $app->jobPosting?->department_id;
        if ($employeeId && $departmentScope !== null && (int) $departmentScope !== (int) $applicationDepartmentId) {
            $app->setRelation('latestInterview', $app->interviews()
                ->whereJsonContains('interviewer_employee_ids', (int) $employeeId)
                ->orderByDesc('scheduled_at')
                ->first());
        }
        $this->hideExpectedSalary(collect([$app]), $request);
        return response()->json(['application' => $app]);
    }

    public function updateApplication(Request $request, $id) {
        abort_unless($request->user()?->hasAnyRole(['super_admin', 'hr_manager']), 403, 'Only HR Manager and System Admin can edit applicant details.');
        $app = JobApplication::findOrFail($id);
        $data = $request->validate([
            'job_posting_id' => 'sometimes|required|integer|exists:job_postings,id,deleted_at,NULL',
            'applicant_name' => 'sometimes|required|string|max:255',
            'applicant_email' => 'sometimes|required|email|max:255',
            'applicant_phone' => 'sometimes|required|string|max:30',
            'expected_salary' => 'sometimes|nullable|numeric|min:0|max:99999999.99',
            'available_from' => 'sometimes|nullable|date_format:Y-m-d',
            'cover_letter_text' => 'sometimes|nullable|string|max:20000',
            'hr_notes' => 'sometimes|nullable|string|max:20000',
        ]);
        if (isset($data['job_posting_id']) && (int) $data['job_posting_id'] !== (int) $app->job_posting_id) {
            $job = JobPosting::findOrFail($data['job_posting_id']);
            $data['department_id'] = $job->department_id;
            $data['position_applied'] = $job->title;
        }
        $app->update($data);
        return response()->json(['application' => $app->fresh(['jobPosting.department', 'jobPosting.designation']), 'message' => 'Applicant details updated.']);
    }

    public function updateStage(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        if ($request->exists('hr_notes')) {
            abort_unless($request->user()?->hasAnyRole(['super_admin', 'hr_manager']), 403, 'Only HR Manager and System Admin can edit applicant notes.');
        }
        $request->validate(['stage'=>'required|in:applied,screening,interview,offer,hired,rejected']);
        $departmentScope = $this->managerDepartmentScope($request);
        $app = $this->scopeApplications(JobApplication::query(), $departmentScope)->findOrFail($id);

        if ($request->stage === 'offer') {
            return response()->json([
                'message' => 'Please use Send Offer so the offer letter is generated and emailed.',
            ], 422);
        }

        if ($request->stage === 'hired') {
            return response()->json([
                'message' => 'Please use Confirm Hire after the offer stage is complete.',
            ], 422);
        }

        $isNewRejection = $request->stage === 'rejected' && $app->stage !== 'rejected';

        if ($isNewRejection) {
            $this->service->sendApplicantRejection($app);
        }

        $app->update($request->only(['stage', 'hr_notes']));
        return response()->json([
            'application' => $app,
            'email_sent' => $isNewRejection,
            'message' => $isNewRejection
                ? 'Applicant rejected and rejection email sent.'
                : 'Application stage updated.',
        ]);
    }

    public function scheduleInterview(Request $request) {
        $this->ensureCanManageRecruitment($request);
        $data = $request->validate([
            'application_id' => 'required|exists:job_applications,id',
            'round' => 'required|string|max:100',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'format' => 'nullable|in:video,in_person,phone',
            'location_or_link' => 'required|string|max:500',
            'interviewer_employee_ids' => 'required|array|min:1',
            'interviewer_employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $interviewers = Employee::query()
            ->whereIn('id', $data['interviewer_employee_ids'])
            ->where(function ($eligibilityQuery) {
                $eligibilityQuery->where('status', 'active')
                    ->orWhere(function ($probationQuery) {
                        $probationQuery->where('status', 'probation')
                            ->whereRaw('LOWER(email) LIKE ?', ['%@dbroker.com.sa']);
                    });
            })
            ->whereRaw('LOWER(COALESCE(email, ?)) <> ?', ['', 'admin@hrms.com'])
            ->get(['id', 'first_name', 'last_name', 'email', 'employee_code']);

        if ($interviewers->count() !== count(array_unique($data['interviewer_employee_ids']))) {
            return response()->json([
                'message' => 'Select an eligible employee as interviewer; admin@hrms.com cannot be selected.',
            ], 422);
        }

        $data['interviewers'] = $interviewers
            ->map(fn (Employee $employee) => trim($employee->first_name . ' ' . $employee->last_name))
            ->filter()
            ->values()
            ->all();
        $data['interviewer_employee_ids'] = array_values(array_unique(array_map('intval', $data['interviewer_employee_ids'])));

        $departmentScope = $this->managerDepartmentScope($request);
        $this->scopeApplications(JobApplication::query(), $departmentScope)->findOrFail($data['application_id']);

        $interview = Interview::create($data);
        $interview->application()->update(['stage' => 'interview']);
        $this->service->sendInterviewInvite($interview, $interviewers);
        return response()->json([
            'message' => 'Interview scheduled and invitation email sent.',
            'interview' => $interview->fresh('application.jobPosting'),
        ], 201);
    }

    public function updateInterview(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        $interview = Interview::findOrFail($id);
        $interview->update($request->all());
        return response()->json(['interview' => $interview]);
    }

    public function sendOffer(Request $request, $applicationId) {
        $this->ensureCanManageRecruitment($request);
        $data = $request->validate([
            'basic_salary' => 'required|numeric|min:0',
            'housing_allowance' => 'nullable|numeric|min:0',
            'transport_allowance' => 'nullable|numeric|min:0',
            'other_allowance' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $app = $this->scopeApplications(JobApplication::query(), $this->managerDepartmentScope($request))
            ->findOrFail($applicationId);
        if (!$app->applicant_email) {
            return response()->json(['message' => 'Applicant email is missing. Offer email cannot be sent.'], 422);
        }

        $offer = $this->service->generateOfferLetter($app, $data);
        $app->update(['stage' => 'offer']);
        return response()->json([
            'message' => 'Offer letter sent',
            'offer' => $offer,
            'email_to' => $app->applicant_email,
            'email_cc' => $offer['cc'] ?? [],
        ]);
    }

    public function hire(Request $request, $applicationId) {
        $this->ensureCanManageRecruitment($request);
        $request->validate([
            'joining_date'    => 'required|date',
            'salary'          => 'required|numeric|min:0',
            'department_id'   => 'nullable|exists:departments,id',
            'designation_id'  => 'nullable|exists:designations,id',
            'manager_id'      => 'nullable|exists:employees,id',
            'employment_type' => 'nullable|in:full_time,part_time,contract,intern',
            'probation_period'=> 'nullable|integer|min:0|max:365',
            'custom_tasks'    => 'nullable|array',
            'custom_tasks.*'  => 'string|max:200',
        ]);

        $app = $this->scopeApplications(JobApplication::query(), $this->managerDepartmentScope($request))
            ->with('jobPosting')
            ->findOrFail($applicationId);
        if (!in_array($app->stage, ['offer', 'hired'], true)) {
            return response()->json([
                'message' => 'Hire confirmation is allowed only after the offer stage is complete or for an already hired applicant.',
            ], 422);
        }

        $result = $this->service->hireApplicant($app, $request->all());
        $app->update(['stage' => 'hired']);

        if ($app->jobPosting) {
            $app->jobPosting->update(['status' => 'closed']);
        }

        $onboarding = $this->onboardingLinks->createAndEmailLink(
            $result['employee'],
            $result['login_email'] ?? null,
            $result['temp_password'] ?? null
        );

        $isNewEmployee = (bool) ($result['is_new_employee'] ?? true);

        return response()->json([
            'message'          => $isNewEmployee
                ? 'Employee record created successfully.'
                : 'Existing employee credentials updated and confirmation email sent.',
            'employee'         => $result['employee'],
            'employee_code'    => $result['employee_code'],
            'login_email'      => $result['login_email'],
            'temp_password'    => $result['temp_password'],
            'is_new_account'   => $result['is_new'],
            'is_new_employee'  => $isNewEmployee,
            'onboarding_tasks' => $result['onboarding_tasks'],
            'onboarding_url'   => $onboarding['url'] ?? null,
            'onboarding_email' => [
                'sent' => (bool) ($onboarding['email_sent'] ?? false),
                'to' => $onboarding['email_to'] ?? null,
                'cc' => $onboarding['email_cc'] ?? [],
                'attachments' => collect($onboarding['attachments'] ?? [])->pluck('path')->values(),
                'error' => $onboarding['error'] ?? null,
            ],
        ], $isNewEmployee ? 201 : 200);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CV BANK
    // ══════════════════════════════════════════════════════════════════════

    /** List CV bank entries and uploaded applicant CVs */
    public function cvBank(Request $request) {
        $departmentScope = $this->managerDepartmentScope($request);

        $cvs = $this->scopeApplications(JobApplication::query(), $departmentScope)
            ->with(['department', 'jobPosting.department'])
            ->where(fn($q) => $q->where('is_cv_bank', true)->orWhereNotNull('cv_path'))
            ->when($request->search, fn($q) =>
                $q->where(fn($s) =>
                    $s->where('applicant_name', 'like', "%{$request->search}%")
                      ->orWhere('applicant_email', 'like', "%{$request->search}%")
                      ->orWhere('position_applied', 'like', "%{$request->search}%")
                      ->orWhere('skills', 'like', "%{$request->search}%")
                      ->orWhereHas('jobPosting', fn($job) => $job->where('title', 'like', "%{$request->search}%"))
                )
            )
            ->when($request->department_id && $departmentScope === null, fn($q, $departmentId) =>
                $q->where(fn($dept) =>
                    $dept->where('department_id', $departmentId)
                         ->orWhereHas('jobPosting', fn($job) => $job->where('department_id', $departmentId))
                )
            )
            ->when($request->position, fn($q, $position) =>
                $q->where(fn($pos) =>
                    $pos->where('position_applied', $position)
                        ->orWhereHas('jobPosting', fn($job) => $job->where('title', $position))
                )
            )
            ->when($request->rating,  fn($q) => $q->where('rating', $request->rating))
            ->when($request->source,  fn($q) => $q->where('source', $request->source))
            ->orderBy('created_at', 'desc')
            ->paginate((int)($request->per_page ?? 20));
        return response()->json($cvs);
    }

    /** Add a CV to the bank (no specific job) */
    public function storeCv(Request $request) {
        $this->ensureCanManageRecruitment($request);
        $data = $request->validate([
            'applicant_name'    => 'required|string|max:150',
            'applicant_email'   => 'required|email|max:191',
            'applicant_phone'   => 'required|string|max:20',
            'department_id'      => 'nullable|exists:departments,id',
            'position_applied'  => 'nullable|string|max:150',
            'nationality'       => 'nullable|string|max:100',
            'experience_years'  => 'nullable|integer|min:0|max:50',
            'skills'            => 'nullable|string',
            'source'            => 'nullable|string|max:80',
            'expected_salary'   => 'nullable|numeric',
            'available_from'    => 'nullable|date',
            'notes'             => 'nullable|string',
            'cv_file'           => 'nullable|file|mimes:pdf,doc,docx|max:5120',
        ]);

        $departmentScope = $this->managerDepartmentScope($request);
        if ($departmentScope !== null) {
            $data['department_id'] = $departmentScope;
        }

        $cvPath = null;
        if ($request->hasFile('cv_file')) {
            $cvPath = $request->file('cv_file')->store('recruitment/cv-bank', 'public');
        }

        $cv = JobApplication::create(array_merge(
            $data,
            [
                'cv_path'    => $cvPath,
                'is_cv_bank' => true,
                'stage'      => 'applied',
                'rating'     => $request->rating ?? 'hold',
            ]
        ));

        return response()->json(['cv' => $cv], 201);
    }

    /** Update rating/notes for a CV bank entry */
    public function updateCv(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        $departmentScope = $this->managerDepartmentScope($request);
        $cv = $this->scopeApplications(JobApplication::query(), $departmentScope)->where('is_cv_bank', true)->findOrFail($id);
        $data = $request->only(['rating', 'notes', 'skills', 'department_id', 'position_applied',
                                     'experience_years', 'expected_salary', 'source',
                                     'nationality', 'available_from']);
        if ($departmentScope !== null) {
            unset($data['department_id']);
        }
        $cv->update($data);
        return response()->json(['cv' => $cv]);
    }

    /** Delete a CV bank entry */
    public function deleteCv(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        $departmentScope = $this->managerDepartmentScope($request);
        $cv = $this->scopeApplications(JobApplication::query(), $departmentScope)->where('is_cv_bank', true)->findOrFail($id);
        if ($cv->cv_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($cv->cv_path);
        }
        $cv->delete();
        return response()->json(['message' => 'CV removed from bank.']);
    }

    /** Move a CV bank entry to a job application */
    public function linkCvToJob(Request $request, $id) {
        $this->ensureCanManageRecruitment($request);
        $request->validate(['job_posting_id' => 'required|exists:job_postings,id']);
        $departmentScope = $this->managerDepartmentScope($request);
        $cv = $this->scopeApplications(JobApplication::query(), $departmentScope)->where('is_cv_bank', true)->findOrFail($id);
        $job = $this->scopeJobs(JobPosting::query(), $departmentScope)->findOrFail($request->job_posting_id);

        $cv->update([
            'job_posting_id' => $job->id,
            'is_cv_bank'     => false,
            'stage'          => 'applied',
        ]);
        return response()->json(['message' => 'CV linked to job posting.', 'application' => $cv]);
    }

    private function managerDepartmentScope(Request $request): ?int
    {
        $user = $request->user();
        if (!$user || $user->hasAnyRole(['super_admin', 'hr_manager', 'hr_staff'])) {
            return null;
        }

        if (!$user->hasRole('department_manager')) {
            // Authenticated non-recruitment users are restricted to interviews
            // where they were explicitly selected as an interviewer.
            return -1;
        }

        $departmentId = $user->employee?->department_id;
        abort_if(!$departmentId, 403, 'Your account is not linked to a department.');

        return (int) $departmentId;
    }

    private function scopeJobs($query, ?int $departmentId)
    {
        if ($departmentId === null) return $query;

        $employeeId = request()->user()?->employee?->id;
        return $query->where(function ($scope) use ($departmentId, $employeeId) {
            if ($departmentId > 0) $scope->where('department_id', $departmentId);
            if ($employeeId) {
                $method = $departmentId > 0 ? 'orWhereHas' : 'whereHas';
                $scope->{$method}('applications.interviews', fn ($interview) =>
                    $interview->whereJsonContains('interviewer_employee_ids', (int) $employeeId)
                );
            }
        });
    }

    private function scopeApplications($query, ?int $departmentId)
    {
        if ($departmentId === null) {
            return $query;
        }

        $employeeId = request()->user()?->employee?->id;
        return $query->where(function ($scope) use ($departmentId, $employeeId) {
            if ($departmentId > 0) {
                $scope->where('department_id', $departmentId)
                    ->orWhereHas('jobPosting', fn ($job) => $job->where('department_id', $departmentId));
            }
            if ($employeeId) {
                $method = $departmentId > 0 ? 'orWhereHas' : 'whereHas';
                $scope->{$method}('interviews', fn ($interview) =>
                    $interview->whereJsonContains('interviewer_employee_ids', (int) $employeeId)
                );
            }
        });
    }

    private function hideExpectedSalary($applications, Request $request): void
    {
        if ($request->user()?->hasAnyRole(['super_admin', 'hr_manager', 'hr_staff'])) return;
        $applications->each(fn (JobApplication $application) => $application->makeHidden('expected_salary'));
    }

    private function ensureCanManageRecruitment(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(['super_admin', 'hr_manager', 'hr_staff', 'department_manager']),
            403,
            'You have read-only access to assigned interviews.'
        );
    }

    private function validateDesignationDepartment($designationId, $departmentId): void
    {
        if (!$designationId || !$departmentId) {
            return;
        }

        $belongsToDepartment = \App\Models\Designation::whereKey($designationId)
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department_id')->orWhere('department_id', $departmentId);
            })
            ->exists();

        if (!$belongsToDepartment) {
            throw ValidationException::withMessages([
                'designation_id' => 'The selected job position does not belong to the selected department.',
            ]);
        }
    }
}
