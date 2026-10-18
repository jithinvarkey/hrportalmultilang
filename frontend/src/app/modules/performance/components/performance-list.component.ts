import {
  Component, OnInit, OnDestroy,
  ChangeDetectionStrategy, ChangeDetectorRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { FormControl } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import { TranslateService } from '@ngx-translate/core';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

@Component({
  standalone: false, selector: 'app-performance-list',
  templateUrl: './performance-list.component.html',
  styleUrls:   ['./performance-list.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PerformanceListComponent implements OnInit, OnDestroy {

  // ── Data ──────────────────────────────────────────────────────────────
  cycles:    any[] = [];
  reviews:   any[] = [];
  kpis:      any[] = [];
  stats:     any   = {};
  pagination: any  = null;
  currentPage = 1;

  // ── View ──────────────────────────────────────────────────────────────
  activeTab         = 'cycles';
  loading           = false;
  statsLoading      = true;
  submitting        = false;
  successMsg        = '';
  errorMsg          = '';

  showCycleForm     = false;
  showDetail        = false;
  showSelfForm      = false;
  showManagerForm   = false;
  showFinalizeForm  = false;
  showKpiForm       = false;

  editCycleId: number | null = null;
  selectedCycle:  any        = null;
  selectedReview: any        = null;
  editKpiId: number | null   = null;

  isHR  = false;
  isMgr = false;
  private employeeId: number | null = null;

  // ── Filters ───────────────────────────────────────────────────────────
  reviewStatus  = '';
  searchControl = new FormControl('');

  // ── Forms ─────────────────────────────────────────────────────────────
  cycleForm!: FormGroup;
  selfForm!:  FormGroup;
  mgrForm!:   FormGroup;
  finalForm!: FormGroup;
  kpiForm!:   FormGroup;

  // ── Options ───────────────────────────────────────────────────────────
  // Labels are translation keys, resolved in the template so a language switch updates them.
  readonly cycleTypes = [
    { value: 'annual',     label: 'PERFORMANCE.CYCLE_TYPES.annual'    },
    { value: 'mid_year',   label: 'PERFORMANCE.CYCLE_TYPES.mid_year'  },
    { value: 'quarterly',  label: 'PERFORMANCE.CYCLE_TYPES.quarterly' },
    { value: 'probation',  label: 'PERFORMANCE.CYCLE_TYPES.probation' },
  ];
  readonly bands = [
    { value: 'excellent',      label: 'PERFORMANCE.BANDS.excellent',      color: '#10b981' },
    { value: 'good',           label: 'PERFORMANCE.BANDS.good',           color: '#3b82f6' },
    { value: 'average',        label: 'PERFORMANCE.BANDS.average',        color: '#f59e0b' },
    { value: 'below_average',  label: 'PERFORMANCE.BANDS.below_average',  color: '#f97316' },
    { value: 'poor',           label: 'PERFORMANCE.BANDS.poor',           color: '#ef4444' },
  ];
  readonly statuses = ['pending','self_submitted','manager_reviewed','finalized'];
  readonly reviewCols = ['employee','cycle','status','self_rating','manager_rating','final_rating','band','actions'];
  readonly cycleCols  = ['name','type','period','reviews','status','actions'];
  readonly kpiCols    = ['title','category','target','weight','actions'];

  private readonly api      = '/api/v1/performance';
  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly http: HttpClient,
    private readonly fb:   FormBuilder,
    private readonly auth: AuthService,
    private readonly cdr:  ChangeDetectorRef,
    private readonly lang: LanguageService,
    private readonly translate: TranslateService,
  ) {}

  ngOnInit(): void {
    this.isHR  = this.auth.isHRRole();
    this.isMgr = this.auth.isManagerRole();
    this.employeeId = this.auth.getUser()?.employee?.id ?? null;

    this.cycleForm = this.fb.group({
      name:                     ['', Validators.required],
      type:                     ['annual', Validators.required],
      start_date:               ['', Validators.required],
      end_date:                 ['', Validators.required],
      self_assessment_deadline: [''],
      manager_review_deadline:  [''],
      status:                   ['draft'],
    });

    this.selfForm = this.fb.group({
      rating:   [null, [Validators.required, Validators.min(1), Validators.max(5)]],
      comments: ['', [Validators.required, Validators.minLength(10)]],
    });

    this.mgrForm = this.fb.group({
      rating:   [null, [Validators.required, Validators.min(1), Validators.max(5)]],
      comments: ['', [Validators.required, Validators.minLength(10)]],
    });

    this.finalForm = this.fb.group({
      final_rating:     [null, [Validators.required, Validators.min(1), Validators.max(5)]],
      performance_band: ['', Validators.required],
      development_plan: [''],
      hr_notes:         [''],
    });

    this.kpiForm = this.fb.group({
      title:         ['', Validators.required],
      category:      ['', Validators.required],
      year:          [new Date().getFullYear(), Validators.required],
      target_value:  [''],
      unit:          [''],
      weight:        [null],
      description:   [''],
    });

    // OnPush: re-render labels, dates and names when the language changes.
    this.translate.onLangChange.pipe(takeUntil(this.destroy$)).subscribe(() => this.cdr.markForCheck());

    this.loadStats();
    this.loadCycles();
    this.loadKpis();

    this.searchControl.valueChanges.pipe(
      debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$),
    ).subscribe(() => this.loadReviews(1));
  }

  // ── Data loading ──────────────────────────────────────────────────────

  loadStats(): void {
    this.http.get<any>(`${this.api}/stats`).pipe(takeUntil(this.destroy$)).subscribe({
      next: s => { this.stats = s; this.statsLoading = false; this.cdr.markForCheck(); },
      error: () => { this.statsLoading = false; this.cdr.markForCheck(); },
    });
  }

  loadCycles(page = 1): void {
    this.loading = true; this.currentPage = page;
    this.http.get<any>(this.api, { params: { page, per_page: 12 } }).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.cycles = r.data ?? []; this.pagination = r.meta ?? r; this.loading = false; this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  loadReviews(page = 1): void {
    this.loading = true; this.currentPage = page;
    const params: any = { view: 'reviews', page, per_page: 20 };
    if (this.reviewStatus)         params.status = this.reviewStatus;
    if (this.searchControl.value)  params.search = this.searchControl.value;

    this.http.get<any>(this.api, { params }).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.reviews = r.data ?? []; this.pagination = r.meta ?? r; this.loading = false; this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  loadKpis(): void {
    this.http.get<any>(`${this.api}/kpis`, { params: { year: new Date().getFullYear() } })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: r => { this.kpis = r.kpis ?? []; this.cdr.markForCheck(); },
        error: () => {},
      });
  }

  // ── Cycle CRUD ────────────────────────────────────────────────────────

  openCycleForm(cycle?: any): void {
    this.editCycleId = cycle?.id ?? null;
    if (cycle) {
      this.cycleForm.patchValue({
        name: cycle.name, type: cycle.type, status: cycle.status,
        start_date: this.toDateInput(cycle.start_date), end_date: this.toDateInput(cycle.end_date),
        self_assessment_deadline: this.toDateInput(cycle.self_assessment_deadline),
        manager_review_deadline:  this.toDateInput(cycle.manager_review_deadline),
      });
    } else {
      this.cycleForm.reset({ type: 'annual', status: 'draft' });
    }
    this.showCycleForm = true; this.cdr.markForCheck();
  }

  saveCycle(): void {
    if (this.cycleForm.invalid) { this.cycleForm.markAllAsTouched(); return; }
    this.submitting = true;
    const req = this.editCycleId
      ? this.http.put(`${this.api}/${this.editCycleId}`, this.cycleForm.value)
      : this.http.post(this.api, this.cycleForm.value);
    req.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.submitting = false; this.showCycleForm = false;
        this.successMsg = this.lang.t(this.editCycleId ? 'PERFORMANCE.MSG.CYCLE_UPDATED' : 'PERFORMANCE.MSG.CYCLE_CREATED');
        this.loadCycles(); this.loadStats();
        this._clearSuccess();
        this.cdr.markForCheck();
      },
      error: (e: any) => { this.submitting = false; this.errorMsg = e?.error?.message ?? this.lang.t('MESSAGES.SAVE_FAILED'); this.cdr.markForCheck(); },
    });
  }

  initiateCycle(cycle: any): void {
    if (!confirm(this.lang.t('PERFORMANCE.MSG.CONFIRM_INITIATE', { name: cycle.name }))) return;
    this.http.post<any>(`${this.api}/${cycle.id}/initiate`, {}).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => { this.successMsg = r.message; this.loadCycles(); this.loadStats(); this._clearSuccess(); this.cdr.markForCheck(); },
      error: (e: any) => { this.errorMsg = e?.error?.message ?? this.lang.t('PERFORMANCE.MSG.INITIATE_FAILED'); this.cdr.markForCheck(); },
    });
  }

  closeCycle(cycle: any): void {
    this.http.put(`${this.api}/${cycle.id}`, { status: 'closed' }).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { cycle.status = 'closed'; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  viewCycle(cycle: any): void {
    this.selectedCycle = cycle;
    this.http.get<any>(`${this.api}/${cycle.id}`).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => {
        this.selectedCycle = r.cycle;
        this.showDetail    = true;
        this.cdr.markForCheck();
      },
      error: () => {},
    });
  }

  // ── Review actions ────────────────────────────────────────────────────

  openSelfAssess(review: any): void {
    this.selectedReview = review;
    this.selfForm.reset({ rating: review.self_rating ?? null, comments: review.self_comments ?? '' });
    this.showSelfForm = true; this.cdr.markForCheck();
  }

  submitSelf(): void {
    if (this.selfForm.invalid) { this.selfForm.markAllAsTouched(); return; }
    this.submitting = true;
    this.http.post<any>(`${this.api}/review/${this.selectedReview.id}/self`, this.selfForm.value)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.submitting = false; this.showSelfForm = false; this.loadReviews(this.currentPage); this.loadStats(); this.successMsg = this.lang.t('PERFORMANCE.MSG.SELF_SUBMITTED'); this._clearSuccess(); this.cdr.markForCheck(); },
        error: (e: any) => { this.submitting = false; this.errorMsg = e?.error?.message ?? this.lang.t('MESSAGES.GENERIC_ERROR'); this.cdr.markForCheck(); },
      });
  }

  openManagerReview(review: any): void {
    this.selectedReview = review;
    this.mgrForm.reset({ rating: review.manager_rating ?? null, comments: review.manager_comments ?? '' });
    this.showManagerForm = true; this.cdr.markForCheck();
  }

  submitManager(): void {
    if (this.mgrForm.invalid) { this.mgrForm.markAllAsTouched(); return; }
    this.submitting = true;
    this.http.post<any>(`${this.api}/review/${this.selectedReview.id}/manager`, this.mgrForm.value)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.submitting = false; this.showManagerForm = false; this.loadReviews(this.currentPage); this.loadStats(); this.successMsg = this.lang.t('PERFORMANCE.MSG.MANAGER_SUBMITTED'); this._clearSuccess(); this.cdr.markForCheck(); },
        error: (e: any) => { this.submitting = false; this.errorMsg = e?.error?.message ?? this.lang.t('MESSAGES.GENERIC_ERROR'); this.cdr.markForCheck(); },
      });
  }

  openFinalize(review: any): void {
    this.selectedReview = review;
    this.finalForm.reset({
      final_rating: review.final_rating ?? null,
      performance_band: review.performance_band ?? '',
      development_plan: review.development_plan ?? '',
      hr_notes: review.hr_notes ?? '',
    });
    this.showFinalizeForm = true; this.cdr.markForCheck();
  }

  submitFinalize(): void {
    if (this.finalForm.invalid) { this.finalForm.markAllAsTouched(); return; }
    this.submitting = true;
    this.http.post<any>(`${this.api}/review/${this.selectedReview.id}/finalize`, this.finalForm.value)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.submitting = false; this.showFinalizeForm = false; this.loadReviews(this.currentPage); this.loadStats(); this.successMsg = this.lang.t('PERFORMANCE.MSG.FINALIZED'); this._clearSuccess(); this.cdr.markForCheck(); },
        error: (e: any) => { this.submitting = false; this.errorMsg = e?.error?.message ?? this.lang.t('MESSAGES.GENERIC_ERROR'); this.cdr.markForCheck(); },
      });
  }

  // ── KPIs ──────────────────────────────────────────────────────────────

  openKpiForm(kpi?: any): void {
    this.editKpiId = kpi?.id ?? null;
    if (kpi) { this.kpiForm.patchValue(kpi); }
    else      { this.kpiForm.reset({ year: new Date().getFullYear() }); }
    this.showKpiForm = true; this.cdr.markForCheck();
  }

  saveKpi(): void {
    if (this.kpiForm.invalid) { this.kpiForm.markAllAsTouched(); return; }
    this.submitting = true;
    const req = this.editKpiId
      ? this.http.put(`${this.api}/kpis/${this.editKpiId}`, this.kpiForm.value)
      : this.http.post(`${this.api}/kpis`, this.kpiForm.value);
    req.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.submitting = false; this.showKpiForm = false; this.loadKpis(); this.cdr.markForCheck(); },
      error: (e: any) => { this.submitting = false; this.errorMsg = e?.error?.message ?? this.lang.t('MESSAGES.SAVE_FAILED'); this.cdr.markForCheck(); },
    });
  }

  deleteKpi(id: number, event: Event): void {
    event.stopPropagation();
    if (!confirm(this.lang.t('PERFORMANCE.MSG.CONFIRM_DELETE_KPI'))) return;
    this.http.delete(`${this.api}/kpis/${id}`).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.loadKpis(); this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  // ── Tab + helpers ─────────────────────────────────────────────────────

  switchTab(id: string): void {
    this.activeTab = id;
    if (id === 'reviews') this.loadReviews(1);
    if (id === 'kpis')    this.loadKpis();
    this.cdr.markForCheck();
  }

  /** PerformanceController::selfAssessment only accepts the caller's own review, while pending or self_submitted. */
  canSelfAssess(r: any):    boolean { return this.isOwnReview(r) && ['pending','self_submitted'].includes(r.status); }
  /** Managers (non-HR) can never review themselves (backend returns 403). */
  canMgrReview(r: any):     boolean { return ['pending','self_submitted'].includes(r.status) && (this.isHR || (this.isMgr && !this.isOwnReview(r))); }
  private isOwnReview(r: any): boolean {
    return this.employeeId !== null && String(r?.employee_id ?? r?.employee?.id) === String(this.employeeId);
  }
  /** Date cast values arrive as UTC ISO timestamps ("2026-09-30T21:00:00Z" = 1 Oct in Riyadh); date inputs need local YYYY-MM-DD. */
  private toDateInput(value: any): string {
    if (!value) return '';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }
  canFinalize(r: any):      boolean { return r.status === 'manager_reviewed' && this.isHR; }

  /** Translation key for a cycle type / performance band code (unknown codes fall back to the raw code). */
  cycleTypeKey(t: string): string { return this.cycleTypes.some(x => x.value === t) ? `PERFORMANCE.CYCLE_TYPES.${t}` : t; }
  bandKey(b: string): string      { return this.bands.some(x => x.value === b) ? `PERFORMANCE.BANDS.${b}` : b; }
  /** Employee display name — Arabic name when Arabic is active and one is recorded. */
  empName(e: any): string {
    if (!e) return '';
    if (this.lang.current() === 'ar' && e.arabic_name) return e.arabic_name;
    return `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
  }
  statusCls(s: string): string {
    return ({ pending:'badge-gray', self_submitted:'badge-yellow', manager_reviewed:'badge-blue', finalized:'badge-green' } as any)[s] ?? 'badge-gray';
  }
  bandCls(b: string): string {
    return ({ excellent:'band-excellent', good:'band-good', average:'band-average', below_average:'band-below', poor:'band-poor' } as any)[b] ?? '';
  }
  cycleStatusCls(s: string): string {
    return ({ draft:'badge-gray', active:'badge-green', closed:'badge-gray' } as any)[s] ?? 'badge-gray';
  }
  ratingColor(r: number | null): string {
    if (!r) return 'var(--text3)';
    if (r >= 4.5) return '#10b981';
    if (r >= 3.5) return '#3b82f6';
    if (r >= 2.5) return '#f59e0b';
    if (r >= 1.5) return '#f97316';
    return '#ef4444';
  }
  stars(r: number | null): number[] { return [1,2,3,4,5]; }
  avatarColor(n?: string|null): string { const p=['#3b82f6','#10b981','#f59e0b','#ef4444','#6366f1']; return p[(n?.charCodeAt(0)??0)%p.length]; }
  initial(n?: string|null): string { return n?.charAt(0)?.toUpperCase() ?? '?'; }
  get pages(): number[] { if(!this.pagination?.last_page) return []; return Array.from({length:Math.min(this.pagination.last_page,8)},(_,i)=>i+1); }

  private _clearSuccess(): void { setTimeout(() => { this.successMsg = ''; this.errorMsg = ''; this.cdr.markForCheck(); }, 3500); }
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
