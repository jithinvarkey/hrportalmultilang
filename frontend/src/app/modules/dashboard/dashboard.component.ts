import { Component, OnInit, AfterViewInit, OnDestroy, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { Router } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { formatDate } from '@angular/common';
import { Subscription } from 'rxjs';
import { TranslateService } from '@ngx-translate/core';
import { AuthService } from '../../core/services/auth.service';
import { LanguageService } from '../../core/services/language.service';

@Component({
  standalone: false,
  selector: 'app-dashboard',
  templateUrl: './dashboard.component.html',
  styleUrls: ['./dashboard.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DashboardComponent implements OnInit, AfterViewInit, OnDestroy {

  loading = true;
  private st: any = null;

  // ── Pre-computed arrays (NOT methods — avoids change detection loop) ──────
  kpiItems:   any[] = [];
  statItems:  any[] = [];

  /** Mobile "My services" tiles: employee self-service pages the user can open. */
  serviceTiles: { path: string; label: string; icon: string; color: string }[] = [];

  employees:  any[] = [];
  leaveReqs:  any[] = [];
  openJobs:   any[] = [];
  reviews:    any[] = [];
  activity:   any[] = [];

  private charts: any[] = [];
  private chartRetries = 0;
  private readonly MAX_RETRIES = 10;
  /** Last /dashboard/charts payload (undefined = charts not built yet), reused to rebuild on language change. */
  private chartData: any = undefined;
  private langSub?: Subscription;
  /** Pending chart-build timers; cleared on destroy so they never draw onto a later dashboard's canvases. */
  private timers: any[] = [];
  private destroyed = false;

  constructor(
    private http: HttpClient, private router: Router, private cdr: ChangeDetectorRef, public auth: AuthService,
    public lang: LanguageService, private translate: TranslateService,
  ) {}

  get canViewPayroll(): boolean { return this.auth.hasAnyRole(['super_admin', 'ceo', 'hr_manager']); }

  // ── Greeting / Date ───────────────────────────────────────────────────────
  readonly greetingKey = (() => {
    const h = new Date().getHours();
    return h < 12 ? 'DASHBOARD.GREETING.MORNING' : h < 17 ? 'DASHBOARD.GREETING.AFTERNOON' : 'DASHBOARD.GREETING.EVENING';
  })();

  private readonly storedUser: any = (() => {
    try { return JSON.parse(sessionStorage.getItem('hrms_user') || localStorage.getItem('hrms_user') || '{}') || {}; }
    catch { return {}; }
  })();

  /** First name of the signed-in user; Arabic name when Arabic is active and one is available. */
  get userName(): string {
    const u = this.storedUser;
    const name = (this.lang.isRtl() && (u?.arabic_name || u?.employee?.arabic_name)) || u?.name;
    return name?.split(' ')[0] || this.lang.t('DASHBOARD.DEFAULT_USER');
  }

  readonly today = new Date();

  // ── Helpers ───────────────────────────────────────────────────────────────
  pct(a: number, b: number) { return b ? Math.round((a / b) * 100) : 0; }
  /** Overdue = due date before today (a task due today is not overdue). Date-only strings are read as local dates. */
  isOD(d: string) {
    if (!d) return false;
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(d));
    const due = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(d);
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return due < today;
  }
  go(path: string)          { this.router.navigateByUrl(path); }

  empStatusCls(s: string)   { return ({ active:'badge-green', on_leave:'badge-yellow', probation:'badge-blue', inactive:'badge-gray', terminated:'badge-red' } as any)[s] || 'badge-gray'; }
  leaveCls(s: string)       { return ({ pending:'badge-yellow', approved:'badge-green', rejected:'badge-red', cancelled:'badge-gray' } as any)[s] || 'badge-gray'; }
  jobCls(s: string)         { return ({ open:'badge-green', paused:'badge-yellow', closed:'badge-gray', filled:'badge-blue' } as any)[s] || 'badge-gray'; }
  perfCls(s: string)        { return ({ pending:'badge-yellow', self_submitted:'badge-blue', manager_reviewed:'badge-purple', finalized:'badge-green' } as any)[s] || 'badge-gray'; }

  initial(n?: string) { return n?.charAt(0)?.toUpperCase() || '?'; }
  avCol(n?: string) {
    const c = ['#3b82f6','#10b981','#f59e0b','#ef4444','#6366f1','#0ea5e9','#f97316','#a78bfa'];
    return c[(n?.charCodeAt(0) ?? 0) % c.length];
  }
  actIcon(a?: string) {
    return ({ created:'add_circle_outline', updated:'edit', deleted:'delete_outline', approved:'check_circle_outline',
              rejected:'cancel', joined:'person_add_alt_1', resigned:'person_remove' } as any)[a||''] || 'fiber_manual_record';
  }
  actColor(a?: string) {
    return ({ created:'#10b981', updated:'#3b82f6', deleted:'#ef4444', approved:'#10b981',
              rejected:'#ef4444', joined:'#6366f1', resigned:'#f59e0b' } as any)[a||''] || '#8b949e';
  }

  /** Activity verb (created, updated …) in the active language; unknown codes are shown as sent. */
  actLabel(a?: string) { return this.tOr('DASHBOARD.ACTIVITY.ACTIONS.' + (a || ''), a || ''); }

  /** Employee display name; Arabic name when Arabic is active and available. */
  personName(e: any): string {
    if (!e) return '';
    if (this.lang.isRtl() && e.arabic_name) return e.arabic_name;
    return e.name || [e.first_name, e.last_name].filter(Boolean).join(' ');
  }

  /** Translate a key, or fall back to the given text when the key has no translation. */
  private tOr(key: string, fallback: string): string {
    const v = this.lang.t(key);
    return v && v !== key ? v : fallback;
  }

  // ── Lifecycle ─────────────────────────────────────────────────────────────
  ngOnInit() {
    this.serviceTiles = this.buildServiceTiles();
    this.loadAll();
    // Chart.js labels are drawn on canvas, so rebuild the charts when the language changes.
    this.langSub = this.translate.onLangChange.subscribe(() => {
      const C = (window as any).Chart;
      if (!C || this.chartData === undefined) return;
      this.destroyCharts();
      this.buildCharts(this.chartData, C);
      this.cdr.markForCheck();
    });
  }
  ngAfterViewInit() { this.later(() => { this.destroyCharts(); this.tryBuildCharts(); }, 1000); }
  ngOnDestroy()     {
    this.destroyed = true;
    this.timers.forEach(t => clearTimeout(t));
    this.timers = [];
    this.langSub?.unsubscribe();
    this.destroyCharts();
  }

  private later(fn: () => void, ms: number) {
    this.timers.push(setTimeout(() => { if (!this.destroyed) fn(); }, ms));
  }

  refresh() {
    this.loading = true;
    this.chartRetries = 0;
    this.destroyCharts();      // destroy first
    this.loadAll();
    // Delay chart build until after DOM settles with new data
    this.later(() => { this.destroyCharts(); this.tryBuildCharts(); }, 1200);
  }

  private destroyCharts() {
    this.charts.forEach(c => { try { c.destroy(); } catch {} });
    this.charts = [];
  }

  // ── Data loading ──────────────────────────────────────────────────────────
  private loadAll() {
    const get = (url: string, cb: (d: any) => void) =>
      this.http.get<any>(url).subscribe({
        next: d => { cb(d); this.cdr.markForCheck(); },
        error: () => {}
      });

    get('/api/v1/dashboard/stats', d => {
      this.st = d;
      this.employees = d?.recent?.employees || [];
      this.leaveReqs = d?.recent?.leave_requests || [];
      this.openJobs = d?.recent?.open_jobs || [];
      this.reviews = d?.recent?.reviews || [];
      this.loading = false;
      this.buildKpis();
      this.buildStatCards();
    });

    get('/api/v1/dashboard/recent-activities',                    r => this.activity  = Array.isArray(r) ? r : r?.data || []);
  }

  private buildServiceTiles() {
    const visible = new Set(this.auth.getVisibleNavItems().map(i => i.path));
    visible.add('/profile');
    return [
      { path: '/leave',         label: 'DASHBOARD.QUICK.REQUEST_LEAVE', icon: 'event_available', color: '#f59e0b' },
      { path: '/loans',         label: 'DASHBOARD.QUICK.REQUEST_LOAN',  icon: 'account_balance', color: '#10b981' },
      { path: '/requests',      label: 'DASHBOARD.QUICK.MY_REQUESTS',   icon: 'inbox',           color: '#6366f1' },
      { path: '/profile',       label: 'LAYOUT.MY_PROFILE',             icon: 'person',          color: '#3b82f6' },
      { path: '/policies',      label: 'NAV.POLICIES',                  icon: 'policy',          color: '#0ea5e9' },
      { path: '/announcements', label: 'NAV.ANNOUNCEMENTS',             icon: 'campaign',        color: '#ef4444' },
      { path: '/contracts',     label: 'NAV.CONTRACTS',                 icon: 'description',     color: '#8b5cf6' },
      { path: '/assets',        label: 'NAV.ASSET_MANAGEMENT',          icon: 'inventory_2',     color: '#14b8a6' },
    ].filter(t => visible.has(t.path));
  }

  // ── Build arrays once (called after stats load) ────────────────────────────
  private buildKpis() {
    const d = this.st || {};
    const showRecruitment = d.scope !== 'personal';
    this.kpiItems = [
      { label: 'DASHBOARD.KPI.TOTAL_EMPLOYEES', value: d.employees?.total          ?? 0,    color: '#3b82f6', icon: 'group' },
      { label: 'DASHBOARD.KPI.ON_LEAVE_TODAY', value: d.leave?.on_leave_today     ?? 0,    color: '#f59e0b', icon: 'event_busy' },
      { label: 'DASHBOARD.KPI.PENDING_LEAVES', value: d.leave?.pending            ?? 0,    color: d.leave?.pending > 5 ? '#ef4444' : '#f59e0b', icon: 'pending_actions' },
      ...(this.canViewPayroll ? [{ label: 'DASHBOARD.KPI.PAYROLL_PROCESSED', value: d.payroll?.processed ?? 0, color: '#10b981', icon: 'payments' }] : []),
      ...(showRecruitment ? [{ label: 'DASHBOARD.KPI.OPEN_POSITIONS', value: d.recruitment?.open_positions ?? 0, color: '#6366f1', icon: 'work_outline' }] : []),
      { label: 'DASHBOARD.KPI.PENDING_REVIEWS', value: d.performance?.pending      ?? 0,    color: '#0ea5e9', icon: 'rate_review' },
      { label: 'DASHBOARD.KPI.ATTENDANCE_RATE', value: (d.attendance?.rate ?? 0) + '%',     color: d.attendance?.rate >= 90 ? '#10b981' : '#f59e0b', icon: 'fingerprint' },
      { label: 'DASHBOARD.KPI.EXPIRING_CONTRACTS', value: d.employees?.contracts_expiring ?? 0, color: d.employees?.contracts_expiring > 0 ? '#ef4444' : '#8b949e', icon: 'description' },
    ];
  }

  private buildStatCards() {
    const d = this.st || {};
    const showRecruitment = d.scope !== 'personal';
    this.statItems = [
      {
        title: 'DASHBOARD.CARDS.WORKFORCE.TITLE',  icon: 'people_alt', color: '#3b82f6',
        main: d.employees?.total ?? 0, mainLabel: 'DASHBOARD.CARDS.WORKFORCE.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.WORKFORCE.ACTIVE', value: d.employees?.active    ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.WORKFORCE.PROBATION', value: d.employees?.probation ?? 0, color: '#f59e0b' },
          { label: 'DASHBOARD.CARDS.WORKFORCE.ON_LEAVE', value: d.employees?.on_leave  ?? 0, color: '#6366f1' },
          { label: 'DASHBOARD.CARDS.WORKFORCE.NEW_MONTH', value: d.employees?.new_this_month ?? 0, color: '#0ea5e9' },
        ],
        route: '/employees?dashboard_scope=1',
        alert: d.employees?.contracts_expiring > 0 ? 'DASHBOARD.CARDS.WORKFORCE.ALERT' : null, alertParams: { count: d.employees?.contracts_expiring },
        alertColor: '#f59e0b',
        progress: this.pct(d.employees?.active, d.employees?.total),
        progressColor: '#3b82f6', progressLabel: 'DASHBOARD.CARDS.WORKFORCE.PROGRESS',
      },
      {
        title: 'DASHBOARD.CARDS.LEAVE.TITLE', icon: 'event_available', color: '#f59e0b',
        main: d.leave?.pending ?? 0, mainLabel: 'DASHBOARD.CARDS.LEAVE.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.LEAVE.APPROVED', value: d.leave?.approved      ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.LEAVE.ACTIVE_NOW', value: d.leave?.on_leave_today ?? 0, color: '#f59e0b' },
          { label: 'DASHBOARD.CARDS.LEAVE.REJECTED', value: d.leave?.rejected       ?? 0, color: '#ef4444' },
          { label: 'DASHBOARD.CARDS.LEAVE.THIS_MONTH', value: d.leave?.approved_this_month ?? 0, color: '#6366f1' },
        ],
        route: '/leave',
        alert: d.leave?.pending > 0 ? 'DASHBOARD.CARDS.LEAVE.ALERT' : null, alertParams: { count: d.leave?.pending },
        alertColor: '#ef4444',
        progress: this.pct(d.leave?.approved, d.leave?.total),
        progressColor: '#10b981', progressLabel: 'DASHBOARD.CARDS.LEAVE.PROGRESS',
      },
      ...(showRecruitment ? [{
        title: 'DASHBOARD.CARDS.RECRUITMENT.TITLE', icon: 'work', color: '#6366f1',
        main: d.recruitment?.open_positions ?? 0, mainLabel: 'DASHBOARD.CARDS.RECRUITMENT.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.RECRUITMENT.APPLICANTS', value: d.recruitment?.applicants        ?? 0, color: '#3b82f6' },
          { label: 'DASHBOARD.CARDS.RECRUITMENT.INTERVIEWS', value: d.recruitment?.interviews_today  ?? 0, color: '#f59e0b' },
          { label: 'DASHBOARD.CARDS.RECRUITMENT.OFFERS_SENT', value: d.recruitment?.offers_sent       ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.RECRUITMENT.HIRED_MONTH', value: d.recruitment?.hired_this_month  ?? 0, color: '#0ea5e9' },
        ],
        route: '/recruitment',
        alert: null,
        progress: this.pct(d.recruitment?.offers_sent, d.recruitment?.applicants),
        progressColor: '#6366f1', progressLabel: 'DASHBOARD.CARDS.RECRUITMENT.PROGRESS',
      }] : []),
      {
        title: 'DASHBOARD.CARDS.PERFORMANCE.TITLE', icon: 'insights', color: '#0ea5e9',
        main: d.performance?.pending ?? 0, mainLabel: 'DASHBOARD.CARDS.PERFORMANCE.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.PERFORMANCE.IN_PROGRESS', value: d.performance?.in_progress ?? 0, color: '#0ea5e9' },
          { label: 'DASHBOARD.CARDS.PERFORMANCE.COMPLETED', value: d.performance?.completed   ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.PERFORMANCE.OVERDUE', value: d.performance?.overdue     ?? 0, color: '#ef4444' },
          { label: 'DASHBOARD.CARDS.PERFORMANCE.AVG_SCORE', value: d.performance?.avg_score   ?? '—', color: '#6366f1' },
        ],
        route: '/performance',
        alert: d.performance?.overdue > 0 ? 'DASHBOARD.CARDS.PERFORMANCE.ALERT' : null, alertParams: { count: d.performance?.overdue },
        alertColor: '#ef4444',
        progress: this.pct(d.performance?.completed, d.performance?.total),
        progressColor: '#0ea5e9', progressLabel: 'DASHBOARD.CARDS.PERFORMANCE.PROGRESS',
      },
      ...(this.canViewPayroll ? [{
        title: 'DASHBOARD.CARDS.PAYROLL.TITLE', icon: 'account_balance_wallet', color: '#10b981',
        main: d.payroll?.due_this_month ?? 0, mainLabel: 'DASHBOARD.CARDS.PAYROLL.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.PAYROLL.PROCESSED', value: d.payroll?.processed         ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.PAYROLL.PENDING', value: d.payroll?.pending_approvals ?? 0, color: '#f59e0b' },
          { label: 'DASHBOARD.CARDS.PAYROLL.ERRORS', value: d.payroll?.errors            ?? 0, color: d.payroll?.errors > 0 ? '#ef4444' : '#8b949e' },
          { label: 'DASHBOARD.CARDS.PAYROLL.ON_HOLD', value: d.payroll?.on_hold           ?? 0, color: '#6366f1' },
        ],
        route: '/payroll',
        alert: d.payroll?.errors > 0 ? 'DASHBOARD.CARDS.PAYROLL.ALERT' : null, alertParams: { count: d.payroll?.errors },
        alertColor: '#ef4444',
        progress: this.pct(d.payroll?.processed, d.payroll?.total),
        progressColor: '#10b981', progressLabel: 'DASHBOARD.CARDS.PAYROLL.PROGRESS',
      }] : []),
      {
        title: 'DASHBOARD.CARDS.REQUESTS.TITLE', icon: 'inbox', color: '#f97316',
        main: d.requests?.pending ?? 0, mainLabel: 'DASHBOARD.CARDS.REQUESTS.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.REQUESTS.IN_PROGRESS', value: d.requests?.in_progress ?? 0, color: '#3b82f6' },
          { label: 'DASHBOARD.CARDS.REQUESTS.COMPLETED', value: d.requests?.completed   ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.REQUESTS.OVERDUE', value: d.requests?.overdue     ?? 0, color: '#ef4444' },
          { label: 'DASHBOARD.CARDS.REQUESTS.OPEN_TOTAL', value: d.requests?.open        ?? 0, color: '#f97316' },
        ],
        route: '/requests',
        alert: d.requests?.overdue > 0 ? 'DASHBOARD.CARDS.REQUESTS.ALERT' : null, alertParams: { count: d.requests?.overdue },
        alertColor: '#ef4444',
        progress: this.pct(d.requests?.completed, (d.requests?.completed ?? 0) + (d.requests?.pending ?? 0)),
        progressColor: '#f97316', progressLabel: 'DASHBOARD.CARDS.REQUESTS.PROGRESS',
      },
      {
        title: 'DASHBOARD.CARDS.ORGANISATION.TITLE', icon: 'account_tree', color: '#a78bfa',
        main: d.departments?.total ?? 0, mainLabel: 'DASHBOARD.CARDS.ORGANISATION.MAIN',
        items: [
          { label: 'DASHBOARD.CARDS.ORGANISATION.TEAMS', value: d.departments?.teams      ?? 0, color: '#a78bfa' },
          { label: 'DASHBOARD.CARDS.ORGANISATION.MANAGERS', value: d.departments?.managers   ?? 0, color: '#10b981' },
          { label: 'DASHBOARD.CARDS.ORGANISATION.NO_MANAGER', value: d.departments?.vacant_mgr ?? 0, color: d.departments?.vacant_mgr > 0 ? '#f59e0b' : '#8b949e' },
          { label: 'DASHBOARD.CARDS.ORGANISATION.HEADCOUNT', value: d.employees?.total        ?? 0, color: '#3b82f6' },
        ],
        route: '/org-chart',
        alert: null,
        progress: this.pct(d.departments?.managers, d.departments?.total),
        progressColor: '#a78bfa', progressLabel: 'DASHBOARD.CARDS.ORGANISATION.PROGRESS',
      },
    ];
  }

  // ── Charts ────────────────────────────────────────────────────────────────
  private tryBuildCharts() {
    if (this.destroyed || this.chartRetries >= this.MAX_RETRIES) return; // stop retrying
    const C = (window as any).Chart;
    if (!C) {
      this.chartRetries++;
      this.later(() => this.tryBuildCharts(), 500);
      return;
    }
    this.chartRetries = 0;
    this.http.get<any>('/api/v1/dashboard/charts').subscribe({
      next:  d => { if (this.destroyed) return; this.destroyCharts(); this.chartData = d; this.buildCharts(d, C); this.cdr.markForCheck(); },
      error: () => { if (this.destroyed) return; this.destroyCharts(); this.chartData = null; this.buildCharts(null, C); this.cdr.markForCheck(); }
    });
  }

  private buildCharts(d: any, C: any) {
    this.mkTrend(d, C);
    this.mkDept(d, C);
    this.mkLeave(d, C);
    this.mkPerf(d, C);
    if (this.canViewPayroll) this.mkPayroll(d, C);
  }

  /** Chart.js text direction follows the active language (legend / tooltip order). */
  private get rtl() { return this.lang.isRtl(); }

  private chartOpts(extra = {}) {
    return {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index' as const, intersect: false },
      plugins: {
        legend: { rtl: this.rtl, labels: { color: '#8b949e', font: { size: 11 }, boxWidth: 12, padding: 12 } },
        tooltip: { rtl: this.rtl },
      },
      scales: {
        x: { reverse: this.rtl, grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8b949e', font: { size: 10 } } },
        y: { position: this.rtl ? 'right' as const : 'left' as const, grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8b949e', font: { size: 10 } }, beginAtZero: true }
      }, ...extra
    };
  }

  private donut(elId: string, labels: string[], vals: number[], colors: string[], C: any) {
    const el = document.getElementById(elId) as HTMLCanvasElement;
    if (!el) return;
    this.charts.push(new C(el, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: vals, backgroundColor: colors, borderWidth: 0, hoverOffset: 6 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '72%',
        plugins: {
          legend: { position: 'bottom' as const, rtl: this.rtl, labels: { color: '#8b949e', font: { size: 10 }, boxWidth: 10, padding: 10 } },
          tooltip: { rtl: this.rtl },
        } }
    }));
  }

  /** Short month names for the placeholder series (Aug → Jan), in the active language. */
  private sampleMonths(): string[] {
    return [7, 8, 9, 10, 11, 0].map(m => formatDate(new Date(2025, m, 1), 'MMM', this.lang.locale()));
  }

  /** Rating labels from the API are free text / codes: translate known ones, keep the rest as sent. */
  private ratingLabel(r: any): string {
    const raw = String(r ?? '');
    const key = raw.trim().toUpperCase().replace(/[\s-]+/g, '_');
    return this.tOr('DASHBOARD.CHARTS.RATINGS.' + key, raw);
  }

  /** Bilingual master-data record from the API: Arabic name when Arabic is active and available. */
  private locField(x: any, field: string): string {
    const ar = x?.[field + '_ar'];
    return this.rtl && ar ? ar : x?.[field];
  }

  private mkTrend(d: any, C: any) {
    const el = document.getElementById('trendChart') as HTMLCanvasElement;
    if (!el) return;
    const hires  = d?.hire_trend || [];
    const exits  = d?.exit_trend || [];
    const labels = hires.length ? hires.map((x: any) => x.month) : this.sampleMonths();
    this.charts.push(new C(el, {
      type: 'line',
      data: { labels, datasets: [
        { label: this.lang.t('DASHBOARD.CHARTS.NEW_HIRES'), data: hires.length ? hires.map((x:any) => x.count) : [3,5,4,7,4,6],
          borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.08)', tension: .4, fill: true,
          pointRadius: 4, pointBackgroundColor: '#3b82f6', pointBorderColor: '#161b22', pointBorderWidth: 2 },
        { label: this.lang.t('DASHBOARD.CHARTS.EXITS'), data: exits.length ? exits.map((x:any) => x.count) : [1,2,1,2,1,2],
          borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.05)', tension: .4, fill: true,
          pointRadius: 4, pointBackgroundColor: '#ef4444', pointBorderColor: '#161b22', pointBorderWidth: 2 },
      ]},
      options: this.chartOpts()
    }));
  }

  private mkDept(d: any, C: any) {
    const raw    = d?.dept_distribution || [];
    const sample = ['ENGINEERING','HR','FINANCE','OPERATIONS','SALES'].map(k => this.lang.t('DASHBOARD.CHARTS.SAMPLE_DEPTS.' + k));
    const labels = raw.length ? raw.map((x:any) => this.locField(x, 'name')) : sample;
    const vals   = raw.length ? raw.map((x:any) => x.count) : [12,4,6,8,5];
    this.donut('deptChart', labels, vals, ['#3b82f6','#10b981','#f59e0b','#6366f1','#0ea5e9','#a78bfa','#f472b6'], C);
  }

  private mkLeave(d: any, C: any) {
    const raw    = d?.leave_by_type || [];
    const sample = ['ANNUAL','SICK','EMERGENCY','UNPAID'].map(k => this.lang.t('DASHBOARD.CHARTS.SAMPLE_LEAVE.' + k));
    const labels = raw.length ? raw.map((x:any) => this.locField(x, 'leave_type')) : sample;
    const vals   = raw.length ? raw.map((x:any) => x.count)      : [8,4,2,1];
    this.donut('leaveChart', labels, vals, ['#10b981','#ef4444','#f97316','#8b949e','#a78bfa'], C);
  }

  private mkPerf(d: any, C: any) {
    const raw    = d?.performance_ratings || [];
    const sample = ['EXCELLENT','GOOD','AVERAGE','NEEDS_WORK'].map(k => this.lang.t('DASHBOARD.CHARTS.RATINGS.' + k));
    const labels = raw.length ? raw.map((x:any) => this.ratingLabel(x.rating)) : sample;
    const vals   = raw.length ? raw.map((x:any) => x.count)  : [5,12,6,2];
    this.donut('perfChart', labels, vals, ['#10b981','#3b82f6','#f59e0b','#ef4444'], C);
  }

  private mkPayroll(d: any, C: any) {
    const el = document.getElementById('payrollChart') as HTMLCanvasElement;
    if (!el) return;
    const raw    = d?.payroll_trend || [];
    const labels = raw.length ? raw.map((x:any) => x.month)  : this.sampleMonths();
    const vals   = raw.length ? raw.map((x:any) => x.total)  : [120000,133000,125000,141000,137000,148000];
    const k      = this.lang.t('DASHBOARD.CHARTS.THOUSANDS_SUFFIX');
    this.charts.push(new C(el, {
      type: 'bar',
      data: { labels, datasets: [{ label: this.lang.t('DASHBOARD.CHARTS.PAYROLL_AMOUNT'), data: vals,
        backgroundColor: 'rgba(16,185,129,.6)', borderRadius: 6, borderWidth: 0,
        hoverBackgroundColor: 'rgba(16,185,129,.85)' }] },
      options: { ...this.chartOpts(),
        plugins: { legend: { display: false }, tooltip: { rtl: this.rtl } },
        scales: {
          x: { reverse: this.rtl, grid: { display: false }, ticks: { color: '#8b949e', font: { size: 10 } } },
          y: { position: this.rtl ? 'right' as const : 'left' as const, grid: { color: 'rgba(255,255,255,.04)' },
               title: { display: true, text: this.lang.t('COMMON.SAR'), color: '#8b949e', font: { size: 10 } },
               ticks: { color: '#8b949e', callback: (v: any) => (v/1000) + k } }
        }
      }
    }));
  }
}
