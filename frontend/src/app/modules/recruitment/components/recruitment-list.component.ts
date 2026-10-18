import {
  Component, OnInit, OnDestroy,
  ChangeDetectionStrategy, ChangeDetectorRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { formatDate as ngFormatDate } from '@angular/common';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { FormControl } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';
import { humanize } from '../../../shared/pipes/i18n.pipes';

@Component({
  standalone: false, selector: 'app-recruitment-list',
  templateUrl: './recruitment-list.component.html',
  styleUrls: ['./recruitment-list.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecruitmentListComponent implements OnInit, OnDestroy {

  jobs: any[] = []; applications: any[] = []; departments: any[] = []; designations: any[] = []; employees: any[] = [];
  stats: any = {}; pagination: any = null; currentPage = 1;
  activeTab = 'jobs'; loading = false; appsLoading = false;
  statsLoading = true; submitting = false;
  successMsg = ''; errorMsg = '';
  showJobForm = false; showAppDetail = false; showHireForm = false; showHireSuccess = false;
  hireResult: any = null;
  editJobId: number | null = null; selectedApp: any = null;
  selectedJobFilter: number | null = null; isHR = false;
  canEditApplicant = false;
  isDeptManager = false;
  isInterviewOnly = false;
  canManageRecruitment = false;
  managerDepartmentId: number | null = null;

  searchControl = new FormControl('');
  statusFilter = ''; stageFilter = '';

  jobForm!: FormGroup; hireForm!: FormGroup;

  readonly jobStatuses = [
    // Labels come from STATUS.<value> (statusLabel pipe)
    { value: 'draft',   color: '#8b949e' },
    { value: 'open',    color: '#10b981' },
    { value: 'on_hold', color: '#f59e0b' },
    { value: 'closed',  color: '#6b7280' },
  ];
  readonly stages = [
    // Labels come from STATUS.<value> (statusLabel pipe)
    { value: 'applied',   color: '#3b82f6', icon: 'send'          },
    { value: 'screening', color: '#6366f1', icon: 'manage_search' },
    { value: 'interview', color: '#f59e0b', icon: 'video_call'    },
    { value: 'offer',     color: '#0ea5e9', icon: 'local_offer'   },
    { value: 'hired',     color: '#10b981', icon: 'how_to_reg'    },
    { value: 'rejected',  color: '#ef4444', icon: 'cancel'        },
  ];
  readonly employmentTypes = [
    { value: 'full_time', label: 'RECRUITMENT.EMPLOYMENT_TYPE.full_time' },
    { value: 'part_time', label: 'RECRUITMENT.EMPLOYMENT_TYPE.part_time' },
    { value: 'contract',  label: 'RECRUITMENT.EMPLOYMENT_TYPE.contract'  },
    { value: 'intern',    label: 'RECRUITMENT.EMPLOYMENT_TYPE.intern'    },
  ];
  readonly statTiles = [
    { key: 'open_jobs',        label: 'RECRUITMENT.STATS.OPEN_JOBS',        color: '#10b981', icon: 'work'        },
    { key: 'total_applicants', label: 'RECRUITMENT.STATS.TOTAL_APPLICANTS', color: '#3b82f6', icon: 'people'      },
    { key: 'new_this_week',    label: 'RECRUITMENT.STATS.NEW_THIS_WEEK',    color: '#6366f1', icon: 'person_add'  },
    { key: 'in_interview',     label: 'RECRUITMENT.STATS.IN_INTERVIEW',     color: '#f59e0b', icon: 'video_call'  },
    { key: 'offers_sent',      label: 'RECRUITMENT.STATS.OFFERS_SENT',      color: '#0ea5e9', icon: 'local_offer' },
    { key: 'hired',            label: 'RECRUITMENT.STATS.HIRED',            color: '#10b981', icon: 'how_to_reg'  },
  ];
  readonly displayedColumns = ['title', 'department', 'type', 'applicants', 'status', 'actions'];

  // ── CV Bank ──────────────────────────────────────────────────────────────
  cvBankEntries: any[]  = [];
  cvBankLoading  = false;
  cvBankSearch   = '';
  cvBankRating   = '';
  cvBankSource   = '';
  cvBankPosition = '';
  cvBankDepartment = '';
  showCvForm     = false;
  cvFormSubmitting = false;
  selectedCv: any = null;
  showCvDetail   = false;
  linkJobId      = '';
  showLinkModal  = false;

  // ── Add Applicant ────────────────────────────────────────────────────────
  showAddApplicant   = false;
  addApplicantBusy   = false;
  applicantFile: File | null = null;
  applicantFileError = '';
  applicantForm: any = {
    job_posting_id: '', applicant_name: '', applicant_email: '',
    applicant_phone: '', expected_salary: null, available_from: '',
    cover_letter_text: '', stage: 'applied',
  };

  // ── Offer & Interview ────────────────────────────────────────────────────
  showOfferForm    = false;
  showInterviewForm = false;
  offerForm: any   = {
    basic_salary: '',
    housing_allowance: '',
    transport_allowance: '',
    other_allowance: '',
    notes: '',
  };
  offerSubmitting  = false;
  interviewForm: any = {
    round: 'HR', scheduled_at: '', duration_minutes: 60,
    format: 'video', location_or_link: '', interviewer_employee_ids: [],
  };
  interviewSubmitting = false;
  interviewerSearch = '';
  editingNotes = false;
  hrNotesText  = '';
  editingApplicant = false;
  applicantEditBusy = false;
  applicantEditError = '';
  applicantEditForm!: FormGroup;
  applicantJobOptions: any[] = [];
  applicantJobsLoading = false;

  cvForm: any = {
    applicant_name: '', applicant_email: '', applicant_phone: '',
    position_applied: '', nationality: '', experience_years: null,
    department_id: '', skills: '', source: 'LinkedIn', expected_salary: null,
    available_from: '', notes: '', rating: 'hold',
  };
  cvFile: File | null = null;
  cvFileError = '';

  // Source values are stored as-is in the database; their display text comes from RECRUITMENT.SOURCE.*
  readonly sources  = ['LinkedIn', 'Walk-in', 'Referral', 'Website', 'Agency', 'Job Portal', 'Other'];
  private readonly sourceKeys: Record<string, string> = {
    'LinkedIn': 'LINKEDIN', 'Walk-in': 'WALK_IN', 'Referral': 'REFERRAL', 'Website': 'WEBSITE',
    'Agency': 'AGENCY', 'Job Portal': 'JOB_PORTAL', 'Other': 'OTHER',
  };
  readonly ratings  = [
    { value: 'shortlist', label: 'RECRUITMENT.RATING.shortlist', color: '#10b981' },
    { value: 'hold',      label: 'RECRUITMENT.RATING.hold',      color: '#f59e0b' },
    { value: 'reject',    label: 'RECRUITMENT.RATING.reject',    color: '#ef4444' },
  ];
  // Round values are stored as-is in the database; labels come from RECRUITMENT.ROUND.*
  readonly interviewRounds = [
    { value: 'HR',        label: 'RECRUITMENT.ROUND.HR'        },
    { value: 'Technical', label: 'RECRUITMENT.ROUND.TECHNICAL' },
    { value: 'Manager',   label: 'RECRUITMENT.ROUND.MANAGER'   },
    { value: 'Final',     label: 'RECRUITMENT.ROUND.FINAL'     },
  ];
  readonly onboardingTaskKeys = [
    'LAPTOP', 'ACCOUNTS', 'ID_BADGE', 'WORKSTATION', 'CONTRACT', 'PERSONAL_DOCS', 'BANK_PAYROLL', 'COMPLIANCE', 'TEAM_INTRO',
  ];
  readonly requiredDocKeys = [
    'NATIONAL_ID', 'CERTIFICATES', 'BANK_DETAILS', 'SIGNED_CONTRACT', 'PASSPORT', 'MEDICAL',
  ];

  private readonly api = '/api/v1/recruitment';
  private readonly defaultInterviewMapLink = 'https://maps.app.goo.gl/sZWsrahVFX8zsE3XA';
  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly http: HttpClient,
    private readonly fb: FormBuilder,
    private readonly auth: AuthService,
    private readonly cdr: ChangeDetectorRef,
    private readonly lang: LanguageService,
  ) {}

  ngOnInit(): void {
    const user = this.auth.getUser();
    this.isHR = this.auth.isHRRole();
    this.canEditApplicant = this.auth.isHRManager() || this.auth.isSuperAdmin();
    this.isDeptManager = this.auth.isDeptManager();
    this.isInterviewOnly = !this.isHR && !this.isDeptManager;
    this.canManageRecruitment = this.isHR || this.isDeptManager;
    this.managerDepartmentId = this.isDeptManager && !this.isHR
      ? Number(user?.employee?.departmentId ?? user?.employee?.department_id ?? 0) || null
      : null;
    this.jobForm = this.fb.group({
      title:           ['', Validators.required],
      employment_type: ['full_time', Validators.required],
      status:          ['open'],
      vacancies:       [1],
      department_id:   [''],
      designation_id:  ['', Validators.required],
      location:        [''],
      salary_min:      [null],
      salary_max:      [null],
      closing_date:    [''],
      description:     [''],
      requirements:    [''],
      benefits:        [''],
    });
    this.hireForm = this.fb.group({
      joining_date:     [this.localYmd(new Date()), Validators.required],
      salary:           [null, Validators.required],
      department_id:    [''],
      designation_id:   [''],
      employment_type:  ['full_time'],
      probation_period: [90],
      custom_tasks:     [''],
    });
    if (this.isInterviewOnly) this.activeTab = 'pipeline';
    this.loadStats();
    if (!this.isInterviewOnly) { this.loadJobs(); this.loadDepartments(); this.loadDesignations(); this.loadEmployees(); }
    this.loadApplications();
    this.searchControl.valueChanges.pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => this.loadJobs(1));
  }

  loadJobs(page = 1): void {
    this.loading = true; this.currentPage = page;
    const params: any = { page, per_page: 15 };
    if (this.searchControl.value) params.search = this.searchControl.value;
    if (this.statusFilter)        params.status = this.statusFilter;
    this.http.get<any>(`${this.api}/jobs`, { params }).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.jobs = r.data ?? []; this.pagination = r.meta ?? (r.last_page ? r : null); this.loading = false; this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  loadStats(): void {
    this.http.get<any>(`${this.api}/stats`).pipe(takeUntil(this.destroy$)).subscribe({
      next: s => { this.stats = s; this.statsLoading = false; this.cdr.markForCheck(); },
      error: () => { this.statsLoading = false; this.cdr.markForCheck(); },
    });
  }

  loadDepartments(): void {
    this.http.get<any>('/api/v1/departments').pipe(takeUntil(this.destroy$)).subscribe({
      next: r => {
        const departments = r?.data ?? r ?? [];
        this.departments = this.managerDepartmentId
          ? departments.filter((d: any) => Number(d.id) === Number(this.managerDepartmentId))
          : departments;
        this.cdr.markForCheck();
      },
      error: () => {},
    });
  }

  loadDesignations(): void {
    this.http.get<any>('/api/v1/designations').pipe(takeUntil(this.destroy$)).subscribe({
      next: r => {
        this.designations = (Array.isArray(r) ? r : (r?.data ?? []))
          .filter((d: any) => d?.is_active !== false)
          .filter((d: any) => !this.managerDepartmentId || !d?.department_id || Number(d.department_id) === Number(this.managerDepartmentId));
        this.cdr.markForCheck();
      },
      error: () => {},
    });
  }

  loadEmployees(): void {
    this.http.get<any>('/api/v1/employees', {
      params: { per_page: 500, interviewer_eligible: 1, dashboard_scope: 1 },
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.employees = r?.data ?? []; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  loadApplications(jobId?: number): void {
    this.appsLoading = true;
    const params: any = { per_page: 100 };
    if (jobId)            params.job_posting_id = jobId;
    if (this.stageFilter) params.stage = this.stageFilter;
    this.http.get<any>(`${this.api}/applications`, { params }).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.applications = r.data ?? []; this.appsLoading = false; this.cdr.markForCheck(); },
      error: () => { this.appsLoading = false; this.cdr.markForCheck(); },
    });
  }

  viewApplications(job: any): void {
    this.selectedJobFilter = job.id; this.activeTab = 'pipeline';
    this.loadApplications(job.id);
  }

  viewApp(app: any): void {
    this.editingApplicant = false;
    this.editingNotes = false;
    this.applicantEditError = '';
    this.selectedApp = app;
    this.showAppDetail = true;
    this.cdr.markForCheck();

    const requestedId = app.id;
    this.http.get<any>(`${this.api}/applications/${requestedId}`).pipe(takeUntil(this.destroy$)).subscribe({
      next: response => {
        if (this.selectedApp === app && !this.editingApplicant && !this.applicantEditBusy) this.selectedApp = response?.application ?? app;
        this.cdr.markForCheck();
      },
      error: () => this.cdr.markForCheck(),
    });
  }

  editApplicant(): void {
    if (!this.canEditApplicant || this.applicantEditBusy) return;
    const app = this.selectedApp;
    this.applicantEditForm = this.fb.group({
      job_posting_id: [app.job_posting_id, Validators.required],
      applicant_name: [app.applicant_name, [Validators.required, Validators.maxLength(255)]],
      applicant_email: [app.applicant_email, [Validators.required, Validators.email, Validators.maxLength(255)]],
      applicant_phone: [app.applicant_phone, [Validators.required, Validators.maxLength(30)]],
      expected_salary: [app.expected_salary ?? null, [Validators.min(0), Validators.max(99999999.99)]],
      available_from: [this.dateInputValue(app.available_from)],
      cover_letter_text: [app.cover_letter_text ?? '', Validators.maxLength(20000)],
    });
    this.applicantEditError = '';
    this.editingApplicant = true;
    this.applicantJobOptions = app.job_posting ? [app.job_posting] : [];
    this.loadApplicantJobOptions();
  }

  private loadApplicantJobOptions(page = 1): void {
    this.applicantJobsLoading = true;
    this.http.get<any>(`${this.api}/jobs`, { params: { page, per_page: 100 } })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: response => {
          const options = new Map(this.applicantJobOptions.map(job => [job.id, job]));
          (response.data ?? []).forEach((job: any) => options.set(job.id, job));
          this.applicantJobOptions = Array.from(options.values());
          if (page < (response.last_page ?? response.meta?.last_page ?? 1)) {
            this.loadApplicantJobOptions(page + 1);
          } else {
            this.applicantJobsLoading = false;
          }
          this.cdr.markForCheck();
        },
        error: () => {
          this.applicantJobsLoading = false;
          this.applicantEditError = this.lang.t('RECRUITMENT.MSG.JOB_POSITIONS_LOAD_FAILED');
          this.cdr.markForCheck();
        },
      });
  }

  saveApplicantDetails(): void {
    if (!this.canEditApplicant || this.applicantEditBusy) return;
    const body = { ...this.applicantEditForm.value };
    ['applicant_name', 'applicant_email', 'applicant_phone'].forEach(key => body[key] = String(body[key] ?? '').trim());
    this.applicantEditForm.patchValue(body);
    if (this.applicantEditForm.invalid) {
      this.applicantEditForm.markAllAsTouched();
      this.applicantEditError = this.lang.t('RECRUITMENT.MSG.APPLICANT_EDIT_INVALID');
      return;
    }
    ['expected_salary', 'available_from', 'cover_letter_text'].forEach(key => { if (body[key] === '') body[key] = null; });
    const id = this.selectedApp.id;
    this.applicantEditBusy = true;
    this.applicantEditError = '';
    this.http.put<any>(`${this.api}/applications/${id}`, body).pipe(takeUntil(this.destroy$)).subscribe({
      next: response => {
        this.applicantEditBusy = false;
        this.applications = this.applications.map(app => app.id === id ? { ...app, ...response.application } : app);
        if (this.selectedApp?.id === id) {
          this.selectedApp = { ...this.selectedApp, ...response.application };
          this.editingApplicant = false;
        }
        this.successMsg = this.lang.t('RECRUITMENT.MSG.APPLICANT_UPDATED');
        this.loadApplications(this.selectedJobFilter ?? undefined);
        this.loadJobs(this.currentPage);
        this.cdr.markForCheck();
      },
      error: err => {
        this.applicantEditBusy = false;
        this.applicantEditError = this.firstError(err) || this.lang.t('RECRUITMENT.MSG.APPLICANT_SAVE_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  moveStage(app: any, stage: string, event: Event): void {
    event.stopPropagation();
    this.errorMsg = '';
    if (stage === 'hired') {
      this.openHireForm(app, event);
      return;
    }

    if (stage === 'offer') {
      this.openOfferForm(app);
      return;
    }

    if (stage === 'interview') {
      this.openInterviewForm(app);
      return;
    }

    if (stage === 'rejected' && !confirm(this.lang.t('RECRUITMENT.CONFIRM.REJECT_APPLICANT', { name: app.applicant_name, email: app.applicant_email }))) {
      return;
    }

    this.http.put(`${this.api}/applications/${app.id}/stage`, { stage }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r: any) => {
        this.syncStage(app, stage);
        this.successMsg = r?.message || this.lang.t('RECRUITMENT.MSG.MOVED_TO', { stage: this.stageLabel(stage) });
        this.loadStats();
        setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        this.errorMsg = this.firstError(e) || e?.error?.message || this.lang.t('RECRUITMENT.MSG.STAGE_UPDATE_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  openOfferForm(app: any, event?: Event): void {
    event?.preventDefault();
    event?.stopPropagation();
    this.selectedApp = app;
    this.showAppDetail = false;
    this.showHireForm = false;
    this.showInterviewForm = false;
    const basic = Number(app.expected_salary || 0);
    this.offerForm = {
      basic_salary: basic || '',
      housing_allowance: basic ? this.roundMoney(basic * 0.25) : '',
      transport_allowance: basic ? this.roundMoney(basic * 0.10) : '',
      other_allowance: '',
      notes: '',
    };
    this.offerSubmitting = false;
    this.showOfferForm = true;
    this.cdr.detectChanges();
  }

  onOfferBasicSalaryChange(value: any): void {
    const basic = Number(value || 0);
    this.offerForm.housing_allowance = basic ? this.roundMoney(basic * 0.25) : '';
    this.offerForm.transport_allowance = basic ? this.roundMoney(basic * 0.10) : '';
  }

  offerGrossSalary(): number {
    return this.roundMoney(
      Number(this.offerForm.basic_salary || 0) +
      Number(this.offerForm.housing_allowance || 0) +
      Number(this.offerForm.transport_allowance || 0) +
      Number(this.offerForm.other_allowance || 0)
    );
  }

  private roundMoney(value: number): number {
    return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100;
  }

  sendOffer(): void {
    if (!this.offerForm.basic_salary) return;
    this.offerSubmitting = true;
    this.http.post(`${this.api}/offer/${this.selectedApp.id}`, this.offerForm)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r: any) => {
          this.offerSubmitting  = false;
          this.showOfferForm    = false;
          this.syncStage(this.selectedApp, 'offer');
          const to = r?.email_to || this.lang.t('RECRUITMENT.MSG.THE_CANDIDATE');
          const cc = (Array.isArray(r?.email_cc) ? r.email_cc : [r?.email_cc]).filter(Boolean).join(', ');
          this.successMsg = cc
            ? this.lang.t('RECRUITMENT.MSG.OFFER_SENT_CC', { to, cc })
            : this.lang.t('RECRUITMENT.MSG.OFFER_SENT', { to });
          this.loadStats();
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
          this.cdr.markForCheck();
        },
        error: (e: any) => {
          this.offerSubmitting = false;
          const validation = e?.error?.errors ? Object.values(e.error.errors).flat().join(' ') : '';
          this.errorMsg = validation || e?.error?.message || this.lang.t('RECRUITMENT.MSG.OFFER_FAILED');
          this.cdr.markForCheck();
        },
      });
  }

  openInterviewForm(app: any): void {
    this.selectedApp = app;
    const now = new Date();
    now.setHours(9, 0, 0, 0);
    this.interviewForm = {
      round: 'HR', duration_minutes: 60, format: 'in_person',
      location_or_link: this.defaultInterviewMapLink, interviewer_employee_ids: [],
      scheduled_at: this.toLocalDateTimeInput(now),
    };
    this.interviewSubmitting = false;
    this.interviewerSearch = '';
    this.showInterviewForm = true;
    this.cdr.markForCheck();
  }

  onInterviewFormatChange(format: string): void {
    this.interviewForm.format = format;
    this.interviewForm.location_or_link = format === 'in_person' ? this.defaultInterviewMapLink : '';
  }

  interviewLocationLabel(): string {
    if (this.interviewForm.format === 'in_person') return this.lang.t('RECRUITMENT.INTERVIEW.LOCATION');
    if (this.interviewForm.format === 'phone') return this.lang.t('RECRUITMENT.INTERVIEW.PHONE_DETAILS');
    return this.lang.t('RECRUITMENT.INTERVIEW.MEETING_LINK');
  }

  interviewLocationPlaceholder(): string {
    if (this.interviewForm.format === 'in_person') return this.defaultInterviewMapLink;
    if (this.interviewForm.format === 'phone') return this.lang.t('RECRUITMENT.INTERVIEW.PHONE_PLACEHOLDER');
    return this.lang.t('RECRUITMENT.INTERVIEW.LINK_PLACEHOLDER');
  }

  isInterviewerSelected(employeeId: number): boolean {
    return (this.interviewForm.interviewer_employee_ids || []).map(Number).includes(Number(employeeId));
  }

  toggleInterviewer(employeeId: number, checked: boolean): void {
    const ids = new Set((this.interviewForm.interviewer_employee_ids || []).map(Number));
    if (checked) ids.add(Number(employeeId));
    else ids.delete(Number(employeeId));
    this.interviewForm.interviewer_employee_ids = Array.from(ids);
  }

  selectedInterviewersText(): string {
    const ids = new Set((this.interviewForm.interviewer_employee_ids || []).map(Number));
    return this.employees
      .filter(e => ids.has(Number(e.id)))
      .map(e => `${e.first_name} ${e.last_name}`)
      .join(', ');
  }

  private toLocalDateTimeInput(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  filteredInterviewers(): any[] {
    const search = this.interviewerSearch.trim().toLocaleLowerCase();
    if (!search) return this.employees;
    return this.employees.filter(employee => [
      employee.first_name,
      employee.last_name,
      `${employee.first_name ?? ''} ${employee.last_name ?? ''}`,
      employee.employee_code,
      employee.email,
      employee.department?.name,
    ].some(value => String(value ?? '').toLocaleLowerCase().includes(search)));
  }

  submitInterview(): void {
    if (!this.interviewForm.scheduled_at || !this.interviewForm.location_or_link || !this.interviewForm.interviewer_employee_ids?.length) return;
    this.interviewSubmitting = true;
    const body = { ...this.interviewForm, application_id: this.selectedApp.id };
    this.http.post(`${this.api}/interviews`, body).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r: any) => {
        this.interviewSubmitting  = false;
        this.showInterviewForm    = false;
        if (this.selectedApp) this.selectedApp.stage = 'interview';
        const app = this.applications.find(a => a.id === this.selectedApp?.id);
        if (app) app.stage = 'interview';
        this.successMsg = r?.message || this.lang.t('RECRUITMENT.MSG.INTERVIEW_SCHEDULED');
        this.loadStats();
        setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        this.interviewSubmitting = false;
        this.errorMsg = e?.error?.message ?? this.lang.t('RECRUITMENT.MSG.INTERVIEW_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  saveHrNotes(app: any): void {
    if (!this.canEditApplicant) return;
    this.http.put(`${this.api}/applications/${app.id}`, { hr_notes: this.hrNotesText })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { app.hr_notes = this.hrNotesText; this.editingNotes = false; this.cdr.markForCheck(); },
        error: err => { this.applicantEditError = this.firstError(err) || this.lang.t('RECRUITMENT.MSG.NOTES_SAVE_FAILED'); this.cdr.markForCheck(); },
      });
  }

  openHireForm(app: any, event?: Event): void {
    event?.preventDefault();
    event?.stopPropagation();
    this.selectedApp = app;
    this.errorMsg = '';
    this.showAppDetail = false;
    this.showOfferForm = false;
    this.showInterviewForm = false;
    this.hireForm.reset({
      joining_date:     this.localYmd(new Date()),
      salary:           app.expected_salary ?? null,
      department_id:    app.job_posting?.department_id ?? '',
      designation_id:   app.job_posting?.designation_id ?? '',
      employment_type:  app.job_posting?.employment_type ?? 'full_time',
      probation_period: 90,
      custom_tasks:     '',
    });
    this.showHireForm = true;
    this.cdr.detectChanges();
  }

  confirmHire(): void {
    if (this.hireForm.invalid) return;
    this.submitting = true;
    const body = { ...this.hireForm.value };
    // The linked job posting is the source of truth for placement.
    delete body.department_id;
    delete body.designation_id;
    // Split custom tasks string into array
    if (body.custom_tasks) {
      body.custom_tasks = String(body.custom_tasks).split('\n').map((t: string) => t.trim()).filter((t: string) => t);
    } else {
      delete body.custom_tasks;
    }

    this.http.post<any>(`${this.api}/hire/${this.selectedApp.id}`, body).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => {
        this.submitting      = false;
        this.showHireForm    = false;
        this.showAppDetail   = false;
        this.selectedApp.stage = 'hired';
        this.hireResult      = r;
        this.showHireSuccess = true;
        this.loadStats(); this.loadJobs(this.currentPage);
        if (this.activeTab === 'pipeline') this.loadApplications(this.selectedJobFilter ?? undefined);
        this.cdr.markForCheck();
      },
      error: (err: any) => {
        this.submitting = false;
        this.errorMsg = err?.error?.message ?? this.lang.t('RECRUITMENT.MSG.HIRE_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  hireDepartmentName(): string {
    const job = this.selectedApp?.job_posting;
    const dept = job?.department ?? this.departments.find(d => Number(d.id) === Number(job?.department_id));
    return this.locName(dept) || this.lang.t('RECRUITMENT.COMMON.NOT_SPECIFIED');
  }

  hirePositionName(): string {
    const job = this.selectedApp?.job_posting;
    const designation = job?.designation ?? this.designations.find(d => Number(d.id) === Number(job?.designation_id));
    return this.locName(designation, 'title')
      || job?.title
      || this.lang.t('RECRUITMENT.COMMON.NOT_SPECIFIED');
  }

  openJobForm(job?: any): void {
    this.editJobId = job?.id ?? null;
    if (job) {
      this.jobForm.patchValue({
        ...job,
        department_id: job.department_id ?? '',
        designation_id: job.designation_id ?? '',
        closing_date: this.dateInputValue(job.closing_date),
      });
    } else {
      this.jobForm.reset({
        employment_type: 'full_time',
        status: 'open',
        vacancies: 1,
        department_id: this.managerDepartmentId ?? '',
      });
    }
    this.showJobForm = true; this.cdr.markForCheck();
  }

  onJobDepartmentChange(): void {
    const selected = this.selectedJobPosition;
    if (selected?.department_id && String(selected.department_id) !== String(this.jobForm.value.department_id || '')) {
      this.jobForm.patchValue({ designation_id: '', title: '' });
    }
  }

  onJobPositionChange(): void {
    const selected = this.selectedJobPosition;
    if (!selected) {
      this.jobForm.patchValue({ title: '' });
      return;
    }
    this.jobForm.patchValue({
      title: selected.title,
      department_id: this.jobForm.value.department_id || selected.department_id || '',
      salary_min: this.jobForm.value.salary_min ?? selected.min_salary ?? null,
      salary_max: this.jobForm.value.salary_max ?? selected.max_salary ?? null,
    });
  }

  saveJob(): void {
    const selected = this.selectedJobPosition;
    if (selected && !this.jobForm.value.title) {
      this.jobForm.patchValue({ title: selected.title });
    }
    if (this.jobForm.invalid) {
      this.jobForm.markAllAsTouched();
      this.errorMsg = this.jobForm.get('designation_id')?.invalid
        ? this.lang.t('RECRUITMENT.MSG.SELECT_JOB_POSITION')
        : this.lang.t('MESSAGES.FILL_REQUIRED');
      this.cdr.markForCheck();
      return;
    }
    this.submitting = true;
    this.errorMsg = '';
    const body = { ...this.jobForm.value };
    body.description = String(body.description || '').trim() || `${body.title} position`;
    ['department_id', 'designation_id', 'salary_min', 'salary_max', 'closing_date'].forEach(key => {
      if (body[key] === '') body[key] = null;
    });
    const req = this.editJobId
      ? this.http.put(`${this.api}/jobs/${this.editJobId}`, body)
      : this.http.post(`${this.api}/jobs`, body);
    req.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.submitting = false; this.showJobForm = false;
        this.successMsg = this.lang.t(this.editJobId ? 'RECRUITMENT.MSG.JOB_UPDATED' : 'RECRUITMENT.MSG.JOB_POSTED');
        this.loadJobs(this.currentPage); this.loadStats();
        setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
        this.cdr.markForCheck();
      },
      error: (err: any) => {
        this.submitting = false;
        this.errorMsg = this.firstError(err) || err?.error?.message || this.lang.t('MESSAGES.SAVE_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  deleteJob(id: number, event: Event): void {
    event.stopPropagation();
    if (!confirm(this.lang.t('RECRUITMENT.CONFIRM.DELETE_JOB'))) return;
    this.http.delete(`${this.api}/jobs/${id}`).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.loadJobs(this.currentPage); this.loadStats(); this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  changeJobStatus(job: any, status: string, event: Event): void {
    event.stopPropagation();
    this.http.put(`${this.api}/jobs/${job.id}`, { status }).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { job.status = status; this.loadStats(); this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  switchTab(id: string): void {
    this.activeTab = id;
    if (id === 'pipeline') this.loadApplications(this.selectedJobFilter ?? undefined);
    if (id === 'cv_bank')  this.loadCvBank();
    this.cdr.markForCheck();
  }

  // ── CV Bank methods ────────────────────────────────────────────────────────

  loadCvBank(): void {
    this.cvBankLoading = true;
    const params: any = {};
    if (this.cvBankSearch) params.search = this.cvBankSearch;
    if (this.cvBankRating) params.rating = this.cvBankRating;
    if (this.cvBankSource) params.source = this.cvBankSource;
    if (this.cvBankPosition) params.position = this.cvBankPosition;
    if (this.cvBankDepartment && !this.managerDepartmentId) params.department_id = this.cvBankDepartment;
    this.http.get<any>(`${this.api}/cv-bank`, { params }).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.cvBankEntries = r.data ?? []; this.cvBankLoading = false; this.cdr.markForCheck(); },
      error: () => { this.cvBankLoading = false; this.cdr.markForCheck(); },
    });
  }

  openCvForm(): void {
    this.cvForm = {
      applicant_name: '', applicant_email: '', applicant_phone: '',
      position_applied: '', nationality: '', experience_years: null,
      department_id: this.managerDepartmentId ?? '', skills: '', source: 'LinkedIn', expected_salary: null,
      available_from: '', notes: '', rating: 'hold',
    };
    this.cvFile = null;
    this.cvFileError = '';
    this.showCvForm = true;
    this.cdr.markForCheck();
  }

  onCvFileSelected(event: Event): void {
    const f = (event.target as HTMLInputElement).files?.[0] ?? null;
    this.cvFileError = '';
    if (!f) { this.cvFile = null; return; }
    if (f.size > 5 * 1024 * 1024) { this.cvFileError = this.lang.t('RECRUITMENT.MSG.MAX_FILE_SIZE'); return; }
    this.cvFile = f;
  }

  submitCv(): void {
    if (!this.cvForm.applicant_name || !this.cvForm.applicant_email || !this.cvForm.applicant_phone) {
      this.errorMsg = this.lang.t('RECRUITMENT.MSG.CV_REQUIRED_FIELDS');
      this.cdr.markForCheck();
      return;
    }
    this.cvFormSubmitting = true;
    const fd = new FormData();
    Object.entries(this.cvForm).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, String(v)); });
    if (this.cvFile) fd.append('cv_file', this.cvFile, this.cvFile.name);

    this.http.post<any>(`${this.api}/cv-bank`, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.cvFormSubmitting = false;
        this.showCvForm = false;
        this.loadCvBank();
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        this.cvFormSubmitting = false;
        this.errorMsg = e?.error?.message || this.lang.t('RECRUITMENT.MSG.CV_SAVE_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  viewCv(cv: any): void {
    this.selectedCv = cv;
    this.showCvDetail = true;
    this.cdr.markForCheck();
  }

  updateCvRating(cv: any, rating: string): void {
    this.http.put(`${this.api}/cv-bank/${cv.id}`, { rating }).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { cv.rating = rating; this.cdr.markForCheck(); },
    });
  }

  deleteCv(cv: any): void {
    if (!confirm(this.lang.t('RECRUITMENT.CONFIRM.REMOVE_CV', { name: cv.applicant_name }))) return;
    this.http.delete(`${this.api}/cv-bank/${cv.id}`).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.cvBankEntries = this.cvBankEntries.filter(c => c.id !== cv.id); this.cdr.markForCheck(); },
      error: (e: any) => alert(e?.error?.message || this.lang.t('MESSAGES.DELETE_FAILED')),
    });
  }

  openLinkModal(cv: any): void {
    this.selectedCv = cv;
    this.linkJobId  = '';
    this.showLinkModal = true;
    this.cdr.markForCheck();
  }

  confirmLink(): void {
    if (!this.linkJobId) return;
    this.http.post(`${this.api}/cv-bank/${this.selectedCv.id}/link`, { job_posting_id: this.linkJobId })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.showLinkModal = false;
          this.successMsg = this.lang.t('RECRUITMENT.MSG.CV_LINKED');
          this.loadCvBank();
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 4000);
          this.cdr.markForCheck();
        },
        error: (e: any) => alert(e?.error?.message || this.lang.t('RECRUITMENT.MSG.LINK_FAILED')),
      });
  }

  ratingColor(r: string): string {
    return ({ shortlist:'#10b981', hold:'#f59e0b', reject:'#ef4444' } as any)[r] ?? '#8b949e';
  }
  ratingLabel(r: string): string {
    return ['shortlist', 'hold', 'reject'].includes(r) ? this.lang.t('RECRUITMENT.RATING.' + r) : (r ?? '');
  }
  sourceLabel(source: string): string {
    const key = this.sourceKeys[source];
    return key ? this.lang.t('RECRUITMENT.SOURCE.' + key) : source;
  }
  roundLabel(round: string | null): string {
    const match = this.interviewRounds.find(r => r.value === round);
    return match ? this.lang.t(match.label) : (round || '—');
  }
  employmentTypeLabel(type: string | null): string {
    const match = this.employmentTypes.find(t => t.value === type);
    return match ? this.lang.t(match.label) : humanize(type || '');
  }
  stageLabel(stage: string): string {
    const key = `STATUS.${stage}`;
    const label = this.lang.t(key);
    return label !== key ? label : humanize(stage || '');
  }
  /** Arabic column of bilingual master data (departments.name_ar, designations.title_ar) when Arabic is active. */
  locName(record: any, field = 'name'): string {
    if (!record) return '';
    if (this.lang.current() === 'ar') {
      const ar = record[`${field}_ar`];
      if (ar !== null && ar !== undefined && String(ar).trim() !== '') return ar;
    }
    return record[field] ?? '';
  }
  cvDepartmentName(cv: any): string {
    return this.locName(cv?.department) || this.locName(cv?.job_posting?.department) || this.lang.t('RECRUITMENT.CV.NO_DEPARTMENT');
  }
  cvPositionLabel(cv: any): string {
    return cv?.position_applied || cv?.job_posting?.title || this.lang.t('RECRUITMENT.CV.POSITION_NOT_SPECIFIED');
  }
  get jobPositionOptions(): string[] {
    const values = [
      ...this.designations.map(d => d?.title),
      ...this.jobs.map(j => j?.title),
      ...this.cvBankEntries.map(cv => cv?.position_applied || cv?.job_posting?.title),
    ].filter(Boolean).map(v => String(v).trim()).filter(Boolean);
    return Array.from(new Set(values)).sort((a, b) => a.localeCompare(b));
  }
  get jobPositionList(): any[] {
    const departmentId = this.jobForm?.value?.department_id;
    return this.designations
      .filter(d => !departmentId || !d.department_id || String(d.department_id) === String(departmentId))
      .sort((a, b) => String(a.title).localeCompare(String(b.title)));
  }
  get selectedJobPosition(): any {
    const id = this.jobForm?.value?.designation_id;
    if (!id) return null;
    return this.designations.find(d => String(d.id) === String(id)) ?? null;
  }
  canDeleteCv(cv: any): boolean {
    return this.canManageRecruitment && !!cv?.is_cv_bank;
  }

  appsByStage(stage: string): any[] { return this.applications.filter(a => a.stage === stage); }
  stageData(s: string): any { return this.stages.find(x => x.value === s) ?? { value: s, color: '#8b949e', icon: 'help' }; }
  avatarColor(name?: string|null): string { const p=['#3b82f6','#10b981','#f59e0b','#ef4444','#6366f1','#0ea5e9','#f97316','#a78bfa']; return p[(name?.charCodeAt(0)??0)%p.length]; }
  initial(name?: string|null): string { return name?.charAt(0)?.toUpperCase() ?? '?'; }
  formatDate(d: string|null): string {
    if (!d) return '—';
    try { return ngFormatDate(d, 'dd MMM y', this.lang.locale()); } catch { return String(d); }
  }
  formatDateTime(d: string|null): string {
    if (!d) return '—';
    try { return ngFormatDate(d, 'dd MMM y, HH:mm', this.lang.locale()); } catch { return String(d); }
  }

  interviewFormat(format: string|null): string {
    const keys: Record<string, string> = { video: 'VIDEO', in_person: 'IN_PERSON', phone: 'PHONE' };
    const key = keys[format ?? ''];
    return key ? this.lang.t('RECRUITMENT.INTERVIEW.FORMAT.' + key) : '—';
  }

  isWebLink(value: string|null): boolean { return /^https?:\/\//i.test(value ?? ''); }
  get pages(): number[] { if(!this.pagination?.last_page) return []; return Array.from({length:Math.min(this.pagination.last_page,8)},(_,i)=>i+1); }
  get f() { return this.jobForm.controls; }

  /** Set the stage on the given application, the open drawer copy and the kanban list item. */
  private syncStage(app: any, stage: string): void {
    if (app) app.stage = stage;
    if (this.selectedApp && this.selectedApp.id === app?.id) this.selectedApp.stage = stage;
    const listItem = this.applications.find(a => a.id === app?.id);
    if (listItem) listItem.stage = stage;
  }

  /** yyyy-MM-dd in the browser's timezone (toISOString() is UTC: the previous day before 03:00 in Riyadh). */
  private localYmd(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  /**
   * Value for <input type="date"> from an API date. Laravel `date` casts serialise as UTC ISO
   * ("2026-10-19T21:00:00.000000Z" for 20 Oct in Asia/Riyadh), so slicing the string gives the previous day.
   */
  private dateInputValue(value: string | null | undefined): string {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value).slice(0, 10) : this.localYmd(date);
  }

  private firstError(err: any): string {
    if (err?.error?.errors) {
      const first = Object.values(err.error.errors)[0];
      if (Array.isArray(first) && first.length) return String(first[0]);
    }
    return err?.error?.message || '';
  }

  openAddApplicantForm(): void {
    this.applicantForm = {
      job_posting_id: this.selectedJobFilter ?? '',
      applicant_name: '', applicant_email: '',
      applicant_phone: '', expected_salary: null,
      available_from: '', cover_letter_text: '', stage: 'applied',
    };
    this.applicantFile = null;
    this.applicantFileError = '';
    this.errorMsg = '';
    this.showAddApplicant = true;
    this.cdr.markForCheck();
  }

  onApplicantFileSelected(event: Event): void {
    const f = (event.target as HTMLInputElement).files?.[0] ?? null;
    this.applicantFileError = '';
    if (!f) { this.applicantFile = null; return; }
    if (f.size > 5 * 1024 * 1024) { this.applicantFileError = this.lang.t('RECRUITMENT.MSG.MAX_FILE_SIZE'); return; }
    this.applicantFile = f;
  }

  submitApplicant(): void {
    if (!this.applicantForm.job_posting_id || !this.applicantForm.applicant_name || !this.applicantForm.applicant_email || !this.applicantForm.applicant_phone) {
      this.errorMsg = this.lang.t('RECRUITMENT.MSG.APPLICANT_REQUIRED_FIELDS');
      this.cdr.markForCheck();
      return;
    }
    this.addApplicantBusy = true;
    this.errorMsg = '';

    const fd = new FormData();
    Object.entries(this.applicantForm).forEach(([k, v]) => {
      if (v !== null && v !== '' && v !== undefined) fd.append(k, String(v));
    });
    if (this.applicantFile) fd.append('cv_path', this.applicantFile, this.applicantFile.name);

    this.http.post<any>(`${this.api}/apply/${this.applicantForm.job_posting_id}`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.addApplicantBusy = false;
          this.showAddApplicant = false;
          this.successMsg = this.lang.t('RECRUITMENT.MSG.APPLICANT_ADDED', { name: this.applicantForm.applicant_name });
          this.loadApplications(this.selectedJobFilter ?? undefined);
          this.loadStats();
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 4000);
          this.cdr.markForCheck();
        },
        error: (e: any) => {
          this.addApplicantBusy = false;
          const validation = e?.error?.errors ? Object.values(e.error.errors).flat().join(' ') : '';
          this.errorMsg = validation || e?.error?.message || this.lang.t('RECRUITMENT.MSG.APPLICANT_ADD_FAILED');
          this.cdr.markForCheck();
        },
      });
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
