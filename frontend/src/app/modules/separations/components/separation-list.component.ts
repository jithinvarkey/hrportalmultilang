import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { ActivatedRoute } from '@angular/router';
import { formatDate as ngFormatDate } from '@angular/common';
import { LanguageService } from '../../../core/services/language.service';

@Component({
  standalone: false,
  selector: 'app-separation-list',
  templateUrl: './separation-list.component.html',
  styleUrls: ['./separation-list.component.scss'],
})
export class SeparationListComponent implements OnInit {

  // ── Tabs ────────────────────────────────────────────────────────────────
  activeTab    = 'all';
  activeStatus = '';
  loading      = false;
  submitting   = false;

  // ── Data ─────────────────────────────────────────────────────────────────
  separations:  any[] = [];
  templates:    any[] = [];
  stats:        any   = {};
  statItems:    any[] = [];
  pagination:   any   = null;
  currentPage         = 1;
  isHR = false;
  isHRManager = false;
  isFinanceManager = false;
  isDeptManager = false;
  isSuperAdmin = false;
  isCEO = false;
  isApprover = false;
  currentEmployeeId: any = null;

  // ── Filters ──────────────────────────────────────────────────────────────
  filterSearch = '';
  filterType   = '';

  // ── Panels ───────────────────────────────────────────────────────────────
  showNewForm      = false;
  showDetail       = false;
  showReject       = false;
  showApproveHR    = false;  // HR approval with settlement
  showExitInterview= false;
  showComplete     = false;
  showTemplateForm = false;
  selectedSep: any = null;
  detailTab: 'overview' | 'it' | 'finance' | 'tasks' = 'overview';
  rejectTarget:any = null;
  rejectReason     = '';
  checklistError   = '';
  /** Error from an approve / reject / cancel / exit-interview / template action. */
  actionError      = '';
  /** Separation ids with an approve request in flight (prevents a double click from advancing two stages). */
  approvingIds = new Set<number>();
  checklistUpdatingIds = new Set<number>();

  // ── New separation form ──────────────────────────────────────────────────
  employees:   any[] = [];
  form: any = {
    employee_id:'', type:'resignation', reason:'', reason_category:'personal',
    last_working_day:'', notice_waived: false, notice_waived_reason:'', hr_notes:''
  };
  formError     = '';
  completeError = '';
  settlementPreview: any = null;

  // ── HR Approval form ──────────────────────────────────────────────────────
  hrApprovalForm: any = { other_additions: 0, other_deductions: 0, hr_notes: '' };

  // ── Exit interview form ───────────────────────────────────────────────────
  exitForm: any = { date: '', notes: '' };

  // ── Complete form ─────────────────────────────────────────────────────────
  completeForm: any = { settlement_paid: false, settlement_notes: '' };

  // ── Template form ─────────────────────────────────────────────────────────
  templateForm: any = { title:'', category:'hr', description:'', is_required:true, is_active:true, sort_order:0 };
  templateEditId: number | null = null;

  // ── Table columns ─────────────────────────────────────────────────────────
  displayedColumns = ['ref','employee','type','last_working_day','notice','status','actions'];
  templateColumns  = ['title','category','required','actions'];

  allTabs = [
    { id:'all',        labelKey:'SEPARATIONS.TABS.ALL',         icon:'list_alt'        },
    { id:'pending',    labelKey:'SEPARATIONS.TABS.PENDING',     icon:'hourglass_empty' },
    { id:'offboarding',labelKey:'SEPARATIONS.TABS.OFFBOARDING', icon:'checklist'       },
    { id:'completed',  labelKey:'SEPARATIONS.TABS.COMPLETED',   icon:'task_alt'        },
    { id:'templates',  labelKey:'SEPARATIONS.TABS.TEMPLATES',   icon:'tune'            },
  ];
  tabs = [...this.allTabs];

  /** Status filter codes; '' = all. Labels come from STATUS.* via the statusLabel pipe. */
  statusTabs = [
    { id:'' }, { id:'pending_manager' }, { id:'pending_hr' }, { id:'approved' },
    { id:'offboarding' }, { id:'completed' }, { id:'rejected' }, { id:'cancelled' },
  ];

  allSeparationTypes = [
    { id:'resignation',      labelKey:'SEPARATIONS.TYPES.resignation',      icon:'exit_to_app', color:'#f59e0b' },
    { id:'termination',      labelKey:'SEPARATIONS.TYPES.termination',      icon:'block',       color:'#ef4444' },
    { id:'end_of_contract',  labelKey:'SEPARATIONS.TYPES.end_of_contract',  icon:'event_busy',  color:'#6366f1' },
    { id:'retirement',       labelKey:'SEPARATIONS.TYPES.retirement',       icon:'elderly',     color:'#10b981' },
    { id:'abandonment',      labelKey:'SEPARATIONS.TYPES.abandonment',      icon:'person_off',  color:'#8b5cf6' },
    { id:'mutual_agreement', labelKey:'SEPARATIONS.TYPES.mutual_agreement', icon:'handshake',   color:'#0ea5e9' },
  ];
  separationTypes = [...this.allSeparationTypes];

  reasonCategories = [
    { id:'personal',          labelKey:'SEPARATIONS.REASONS.personal'           },
    { id:'better_opportunity',labelKey:'SEPARATIONS.REASONS.better_opportunity' },
    { id:'relocation',        labelKey:'SEPARATIONS.REASONS.relocation'         },
    { id:'health',            labelKey:'SEPARATIONS.REASONS.health'             },
    { id:'misconduct',        labelKey:'SEPARATIONS.REASONS.misconduct'         },
    { id:'performance',       labelKey:'SEPARATIONS.REASONS.performance'        },
    { id:'restructuring',     labelKey:'SEPARATIONS.REASONS.restructuring'      },
    { id:'contract_end',      labelKey:'SEPARATIONS.REASONS.contract_end'       },
    { id:'other',             labelKey:'SEPARATIONS.REASONS.other'              },
  ];

  checklistCategories = ['it','hr','finance','admin','general'];

  constructor(private http: HttpClient, private auth: AuthService, private route: ActivatedRoute, private lang: LanguageService) {}

  ngOnInit() {
    const user = this.auth.getUser();
    this.currentEmployeeId = user?.employee?.id || user?.employee_id || null;
    this.isHR = this.auth.isHRRole() || this.auth.isSuperAdmin();
    this.isHRManager = this.auth.isHRManager() || this.auth.isSuperAdmin();
    this.isFinanceManager = this.auth.isFinanceManager();
    this.isDeptManager = this.auth.isDeptManager();
    this.isSuperAdmin = this.auth.isSuperAdmin();
    this.isCEO = this.auth.hasRole('ceo');
    this.isApprover = this.isDeptManager || this.isHRManager || this.isFinanceManager || this.isSuperAdmin || this.isCEO;
    this.tabs = this.isApprover
      ? this.allTabs.filter(t => t.id !== 'templates' || this.canManageOffboarding())
      : this.allTabs.filter(t => t.id === 'all');
    this.separationTypes = this.isHR ? [...this.allSeparationTypes] : this.allSeparationTypes.filter(t => t.id === 'resignation');
    this.loadStats();
    this.load();
    this.loadEmployees();
    if (this.canManageOffboarding()) this.loadTemplates();

    const separationId = Number(this.route.snapshot.queryParamMap.get('separation_id'));
    if (Number.isInteger(separationId) && separationId > 0) {
      this.viewSep({ id: separationId });
    }
  }

  loadStats() {
    this.http.get<any>('/api/v1/separations/stats').subscribe({ next: r => {
      this.stats = r;
      this.statItems = [
        { labelKey:'SEPARATIONS.STATS.PENDING_MANAGER', value: r.pending_manager, icon:'manage_accounts', color:'#f59e0b' },
        { labelKey:'SEPARATIONS.STATS.PENDING_HR',      value: r.pending_hr,      icon:'badge',           color:'#6366f1' },
        { labelKey:'SEPARATIONS.STATS.OFFBOARDING',     value: r.offboarding,     icon:'checklist',       color:'#3b82f6' },
        { labelKey:'SEPARATIONS.STATS.COMPLETED_YTD',   value: r.completed_ytd,   icon:'task_alt',        color:'#10b981' },
      ];
    }});
  }

  load(page = 1) {
    this.loading = true; this.currentPage = page;
    const params: any = { per_page: 15, page };
    if (this.activeStatus) params.status = this.activeStatus;
    if (this.filterType)   params.type   = this.filterType;
    if (this.filterSearch) params.search = this.filterSearch;
    if (this.activeTab === 'offboarding') params.status = 'offboarding';
    if (this.activeTab === 'completed')   params.status = 'completed';
    if (this.activeTab === 'pending')     params.status = 'pending_manager,pending_hr,approved';
    this.http.get<any>('/api/v1/separations', { params }).subscribe({
      next: r => { this.separations = r?.data || []; this.pagination = r; this.loading = false; },
      error: () => this.loading = false
    });
  }

  loadEmployees() {
    if (!this.isHR) {
      const user = this.auth.getUser();
      const employee = user?.employee;
      if (employee) {
        this.employees = [employee];
        this.form.employee_id = employee.id;
      }
      return;
    }
    this.http.get<any>('/api/v1/employees?per_page=500&status=active').subscribe({
      next: r => this.employees = r?.data || r?.employees || []
    });
  }

  loadTemplates() {
    this.http.get<any>('/api/v1/separations/templates').subscribe({ next: r => this.templates = r?.templates || [] });
  }

  switchTab(id: string) {
    if (id === 'templates' && !this.isApprover) return;
    this.activeTab = id; this.activeStatus = '';
    if (id !== 'templates') this.load();
  }

  switchStatus(id: string) { this.activeStatus = id; this.load(); }

  // ── View detail ────────────────────────────────────────────────────────
  viewSep(sep: any) {
    this.http.get<any>(`/api/v1/separations/${sep.id}`).subscribe({ next: r => {
      this.selectedSep = r.separation;
      this.ensureAvailableDetailTab();
      this.showDetail  = true;
    }});
  }

  reloadDetail() {
    if (this.selectedSep) {
      this.http.get<any>(`/api/v1/separations/${this.selectedSep.id}`).subscribe({ next: r => {
        this.selectedSep = r.separation;
        this.ensureAvailableDetailTab();
      }});
    }
  }

  private ensureAvailableDetailTab(): void {
    if (this.detailTab === 'it' && !this.selectedSep?.clearance_access?.it) this.detailTab = 'overview';
    if (this.detailTab === 'finance' && !this.selectedSep?.clearance_access?.finance) this.detailTab = 'overview';
    if (this.detailTab === 'tasks' && !this.canViewOffboardingTasks(this.selectedSep)) this.detailTab = 'overview';
  }

  hasClearanceTabs(sep: any): boolean {
    return sep?.type === 'resignation'
      && (sep?.clearance_access?.it || sep?.clearance_access?.finance);
  }

  // ── New separation ──────────────────────────────────────────────────────
  openNew() {
    const d = new Date(); d.setDate(d.getDate() + 30);
    this.form = { employee_id: this.isHR ? '' : this.currentEmployeeId, type:'resignation', reason:'', reason_category:'personal',
      last_working_day: this.localYmd(d), notice_waived: false, notice_waived_reason:'', hr_notes:'' };
    this.formError = ''; this.settlementPreview = null;
    this.showNewForm = true;
  }

  onEmployeeOrTypeChange() {
    if (!this.isHR) return;
    if (this.form.employee_id && this.form.last_working_day) {
      this.http.get<any>('/api/v1/separations/settlement-preview', { params: {
        employee_id: this.form.employee_id, type: this.form.type, last_working_day: this.form.last_working_day
      }}).subscribe({ next: r => this.settlementPreview = r });
    }
  }

  submitSeparation() {
    if (!this.isHR) {
      this.form.type = 'resignation';
      this.form.employee_id = this.currentEmployeeId;
      this.form.hr_notes = '';
    }
    if (!this.form.employee_id || !this.form.reason || !this.form.last_working_day) {
      this.formError = this.lang.t('SEPARATIONS.MSG.REQUIRED_FIELDS'); return;
    }
    this.submitting = true; this.formError = '';
    this.http.post<any>('/api/v1/separations', this.form).subscribe({
      next: () => {
        this.submitting = false; this.showNewForm = false;
        this.load(); this.loadStats();
      },
      error: err => { this.submitting = false; this.formError = err?.error?.message || this.lang.t('SEPARATIONS.MSG.SUBMIT_FAILED'); }
    });
  }

  // ── Approve ────────────────────────────────────────────────────────────
  openApprove(sep: any) {
    this.actionError = '';
    if (sep.status === 'pending_hr') {
      this.selectedSep = sep; this.showApproveHR = true;
      this.hrApprovalForm = { other_additions: 0, other_deductions: 0, hr_notes: '' };
    } else {
      this.quickApprove(sep);
    }
  }

  quickApprove(sep: any) {
    this.postApprove(sep.id, {});
  }

  submitHRApproval() {
    this.postApprove(this.selectedSep.id, this.hrApprovalForm, () => this.showApproveHR = false);
  }

  startOffboarding(sep: any) {
    this.actionError = '';
    this.postApprove(sep.id, {});
  }

  isApproving(sep: any): boolean {
    return this.approvingIds.has(sep?.id);
  }

  /**
   * The same endpoint advances whichever stage the request is in, so a second click
   * while the first request is in flight would approve the next stage too
   * (e.g. HR approval followed by an unintended "start offboarding").
   */
  private postApprove(id: number, body: any, onSuccess?: () => void) {
    if (this.approvingIds.has(id)) return;
    this.approvingIds.add(id);
    this.http.post(`/api/v1/separations/${id}/approve`, body).subscribe({
      next: () => {
        this.approvingIds.delete(id);
        onSuccess?.();
        this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.reloadDetail();
      },
      error: err => { this.approvingIds.delete(id); this.actionError = this.errorText(err); }
    });
  }

  private errorText(err: any): string {
    const errors = err?.error?.errors;
    if (errors) {
      const first = Object.values(errors)[0];
      if (Array.isArray(first) && first.length) return String(first[0]);
    }
    return err?.error?.message || this.lang.t('MESSAGES.GENERIC_ERROR');
  }

  /** yyyy-MM-dd in the browser's timezone (toISOString() is UTC: the previous day before 03:00 in Riyadh). */
  private localYmd(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  // ── Reject ─────────────────────────────────────────────────────────────
  openReject(sep: any) { this.rejectTarget = sep; this.rejectReason = ''; this.actionError = ''; this.showReject = true; }

  confirmReject() {
    if (!this.rejectReason.trim() || this.submitting) return;
    this.submitting = true; this.actionError = '';
    this.http.post(`/api/v1/separations/${this.rejectTarget.id}/reject`, { reason: this.rejectReason }).subscribe({
      next: () => { this.submitting = false; this.showReject = false; this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.showDetail = false; },
      error: err => { this.submitting = false; this.actionError = this.errorText(err); }
    });
  }

  cancel(sep: any) {
    if (!confirm(this.lang.t('SEPARATIONS.MSG.CONFIRM_CANCEL'))) return;
    this.actionError = '';
    this.http.post(`/api/v1/separations/${sep.id}/cancel`, {}).subscribe({
      next: () => { this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.showDetail = false; },
      error: err => this.actionError = this.errorText(err)
    });
  }

  // ── Checklist item ──────────────────────────────────────────────────────
  toggleChecklistItem(item: any, status: string) {
    if (this.checklistUpdatingIds.has(item.id)) return;
    this.checklistError = '';
    this.checklistUpdatingIds.add(item.id);
    const previousItem = { ...item };
    item.status = status;
    this.http.put(`/api/v1/separations/${this.selectedSep.id}/checklist/${item.id}`, { status }).subscribe({
      next: (response: any) => {
        Object.assign(item, response?.item || { status });
        this.checklistUpdatingIds.delete(item.id);
      },
      error: err => {
        Object.assign(item, previousItem);
        this.checklistUpdatingIds.delete(item.id);
        this.checklistError = err?.error?.message || this.lang.t('SEPARATIONS.MSG.CHECKLIST_FAILED');
      }
    });
  }

  isChecklistUpdating(item: any): boolean {
    return this.checklistUpdatingIds.has(item?.id);
  }

  checklistProgress(sep: any): number {
    if (!sep?.checklist_items?.length) return 0;
    const done = sep.checklist_items.filter((i: any) => ['completed','skipped','na'].includes(i.status)).length;
    return Math.round((done / sep.checklist_items.length) * 100);
  }

  pendingChecklistCount(sep: any): number {
    return sep?.checklist_items?.filter((item: any) => item.status === 'pending').length || 0;
  }

  checklistByCategory(items: any[]): any[] {
    if (!items) return [];
    const cats: any = {};
    for (const item of items) {
      if (!cats[item.category]) cats[item.category] = { category: item.category, items: [] };
      cats[item.category].items.push(item);
    }
    return Object.values(cats);
  }

  // ── Exit interview ──────────────────────────────────────────────────────
  openExitInterview(sep: any) {
    this.selectedSep = sep; this.exitForm = { date: this.localYmd(new Date()), notes: '' };
    this.actionError = '';
    this.showExitInterview = true;
  }

  submitExitInterview() {
    this.actionError = '';
    this.http.post(`/api/v1/separations/${this.selectedSep.id}/exit-interview`, this.exitForm).subscribe({
      next: () => { this.showExitInterview = false; this.reloadDetail(); },
      error: err => this.actionError = this.errorText(err)
    });
  }

  // ── Complete ────────────────────────────────────────────────────────────
  openComplete(sep: any) {
    if (!this.canCompleteSeparation() || !this.allChecklistTasksResolved(sep)) return;
    this.selectedSep = sep; this.completeForm = { settlement_paid: false, settlement_notes: '' };
    this.completeError = '';
    this.showComplete = true;
  }

  submitComplete() {
    this.http.post(`/api/v1/separations/${this.selectedSep.id}/complete`, this.completeForm).subscribe({
      next: () => { this.showComplete = false; this.load(this.currentPage); this.loadStats(); this.showDetail = false; },
      error: err => { this.completeError = err?.error?.message || this.lang.t('SEPARATIONS.MSG.COMPLETE_FAILED'); }
    });
  }

  // ── Templates ───────────────────────────────────────────────────────────
  openTemplateForm(t?: any) {
    if (t) { this.templateEditId = t.id; this.templateForm = { ...t }; }
    else   { this.templateEditId = null; this.templateForm = { title:'', category:'hr', description:'', is_required:true, is_active:true, sort_order:0 }; }
    this.actionError = '';
    this.showTemplateForm = true;
  }

  saveTemplate() {
    if (!this.templateForm.title) return;
    const req = this.templateEditId
      ? this.http.put(`/api/v1/separations/templates/${this.templateEditId}`, this.templateForm)
      : this.http.post('/api/v1/separations/templates', this.templateForm);
    req.subscribe({
      next: () => { this.showTemplateForm = false; this.loadTemplates(); },
      error: err => this.actionError = this.errorText(err)
    });
  }

  deleteTemplate(id: number) {
    if (!confirm(this.lang.t('SEPARATIONS.MSG.CONFIRM_DELETE_TEMPLATE'))) return;
    this.actionError = '';
    this.http.delete(`/api/v1/separations/templates/${id}`).subscribe({
      next: () => this.loadTemplates(),
      error: err => this.actionError = this.errorText(err)
    });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    return Array.from({ length: Math.min(this.pagination.last_page, 8) }, (_, i) => i + 1);
  }

  typeInfo(type: string): any {
    return this.separationTypes.find(t => t.id === type) || { labelKey: '', icon: 'help', color: '#8b949e' };
  }

  /** Translated separation type label (falls back to the raw code). */
  typeLabel(type: string): string {
    return this.translateOr(`SEPARATIONS.TYPES.${type}`, type);
  }

  /** Translated reason category label (falls back to the raw code). */
  reasonLabel(code: string): string {
    return this.translateOr(`SEPARATIONS.REASONS.${code}`, code);
  }

  /** Employee display name: Arabic name when Arabic is active and available. */
  empName(e: any): string {
    if (!e) return '';
    if (this.lang.current() === 'ar' && e.arabic_name) return e.arabic_name;
    return `${e.first_name || ''} ${e.last_name || ''}`.trim();
  }

  private translateOr(key: string, fallback: string): string {
    const label = this.lang.t(key);
    return label !== key ? label : fallback;
  }

  statusCls(s: string): string {
    const map: any = {
      draft:'badge-gray', submitted:'badge-blue', pending_manager:'badge-yellow', pending_hr:'badge-purple',
      approved:'badge-green', offboarding:'badge-teal', completed:'badge-green-solid',
      cancelled:'badge-gray', rejected:'badge-red'
    };
    return map[s] || 'badge-gray';
  }

  statusIcon(s: string): string {
    const map: any = {
      draft:'draft', submitted:'send', pending_manager:'manage_accounts', pending_hr:'badge',
      approved:'check_circle', offboarding:'checklist', completed:'task_alt',
      cancelled:'block', rejected:'cancel'
    };
    return map[s] || 'help';
  }

  noticeDaysRemaining(sep: any): number {
    if (!sep.last_working_day) return 0;
    return Math.max(0, Math.ceil((new Date(sep.last_working_day).getTime() - Date.now()) / 86400000));
  }

  canApprove(sep: any): boolean {
    if (!sep) return false;
    if (sep.status === 'pending_manager') {
      return this.isDirectManager(sep) || this.isHRManager || this.isSuperAdmin;
    }
    if (sep.status === 'pending_hr') {
      return sep.type === 'termination' || sep.type === 'resignation'
        ? this.isHRManager || this.isSuperAdmin
        : this.isHRManager || this.isFinanceManager || this.isSuperAdmin;
    }
    return false;
  }

  canCancel(sep: any): boolean {
    if (!sep) return false;
    if (sep.type === 'termination') {
      return ['draft', 'pending_hr', 'approved'].includes(sep.status)
        && (this.isHRManager || this.isSuperAdmin);
    }
    if (sep.status === 'approved') {
      // Backend also requires: own request, the direct manager, or an HR-stage approver.
      const isOwn = Number(sep.employee_id) === Number(this.currentEmployeeId);
      const hrStage = sep.type === 'resignation'
        ? this.isHRManager || this.isSuperAdmin
        : this.isHRManager || this.isFinanceManager || this.isSuperAdmin;
      return this.canManageOffboarding() && (isOwn || this.isDirectManager(sep) || hrStage);
    }
    if (!['draft', 'pending_manager', 'pending_hr'].includes(sep.status)) return false;

    const isOwnRequest = Number(sep.employee_id) === Number(this.currentEmployeeId);
    return isOwnRequest || this.canReject(sep);
  }

  canReject(sep: any): boolean {
    if (!sep) return false;
    if (sep.status === 'pending_manager') {
      return this.isDirectManager(sep) || this.isHRManager || this.isSuperAdmin;
    }
    if (sep.status === 'pending_hr') {
      return sep.type === 'termination' || sep.type === 'resignation'
        ? this.isHRManager || this.isSuperAdmin
        : this.isHRManager || this.isFinanceManager || this.isSuperAdmin;
    }
    return false;
  }

  canManageOffboarding(): boolean {
    return this.isHRManager || this.isFinanceManager || this.isSuperAdmin || this.isCEO;
  }

  canCompleteSeparation(): boolean {
    return this.auth.isHRManager() || this.auth.isSuperAdmin();
  }

  canPrintClearance(sep: any): boolean {
    return sep?.status === 'completed' && (this.auth.isHRManager() || this.auth.isSuperAdmin());
  }

  printClearance(sep: any): void {
    if (!this.canPrintClearance(sep)) return;
    const printWindow = window.open('', '_blank', 'width=1000,height=800');
    if (!printWindow) {
      this.checklistError = this.lang.t('SEPARATIONS.MSG.ALLOW_POPUPS');
      return;
    }

    const t = (key: string, params?: Record<string, any>) => this.escapeHtml(this.lang.t(`SEPARATIONS.${key}`, params));
    const isRtl = this.lang.isRtl();
    const groups = this.checklistByCategory(sep.checklist_items || []);
    const departmentSections = groups.map((group: any) => {
      const rows = group.items.map((item: any) => {
        const completedBy = item.completed_by_name
          || (typeof item.completed_by === 'object' ? item.completed_by?.name : null)
          || item.completed_by_user?.name;
        return `<tr>
          <td>${this.escapeHtml(item.title)}</td>
          <td class="status">${this.escapeHtml(this.taskStatusLabel(item.status))}</td>
          <td>${this.escapeHtml(completedBy || '—')}</td>
          <td>${this.escapeHtml(this.printDate(item.completed_at))}</td>
        </tr>`;
      }).join('');
      return `<section>
        <h2>${t('PRINT.CATEGORY_CLEARANCE', { category: this.categoryLabel(group.category) })}</h2>
        <table><thead><tr><th>${t('PRINT.TASK')}</th><th>${t('PRINT.STATUS')}</th><th>${t('PRINT.COMPLETED_BY')}</th><th>${t('PRINT.DATE')}</th></tr></thead>
        <tbody>${rows}</tbody></table>
        <div class="signoff"><span>${t('PRINT.DEPT_SIGNATURE')}</span><span>${t('PRINT.DATE')}</span></div>
      </section>`;
    }).join('');

    const employeeName = this.empName(sep.employee);
    printWindow.document.write(`<!doctype html><html lang="${this.lang.current()}" dir="${isRtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8">
      <title>${t('PRINT.WINDOW_TITLE', { ref: sep.reference })}</title>
      <style>
        @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:${isRtl ? "'IBM Plex Sans Arabic',Tahoma,Arial" : 'Arial'},sans-serif;color:#172033;margin:0;font-size:12px}
        .header{border-bottom:3px solid #1e3a5f;padding-bottom:12px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-end}
        h1{font-size:21px;color:#1e3a5f;margin:0 0 4px}.sub{color:#64748b}.ref{font-weight:700;color:#1e3a5f}
        .meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}.meta div{border:1px solid #d9e2ec;border-radius:6px;padding:9px}
        .label{display:block;font-size:9px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:3px}
        section{margin:0 0 20px;break-inside:avoid}h2{font-size:14px;color:#1e3a5f;margin:0;padding:8px 10px;background:#edf3f8;border-inline-start:4px solid #2563eb}
        table{width:100%;border-collapse:collapse}th,td{border:1px solid #d9e2ec;padding:7px;text-align:start}th{background:#f8fafc;font-size:10px;text-transform:uppercase;color:#475569}.status{font-weight:700}
        .signoff{display:grid;grid-template-columns:2fr 1fr;gap:30px;margin-top:22px}.signoff span{border-top:1px solid #64748b;padding-top:5px;color:#64748b;font-size:10px}
        .footer{margin-top:25px;padding-top:10px;border-top:1px solid #d9e2ec;color:#64748b;font-size:10px;text-align:center}
        .actions{position:fixed;inset-inline-end:16px;top:16px}@media print{.actions{display:none}}
      </style></head><body>
      <button class="actions" onclick="window.print()">${t('PRINT.PRINT')}</button>
      <div class="header"><div><h1>${t('PRINT.TITLE')}</h1><div class="sub">${t('PRINT.SUBTITLE')}</div></div><div class="ref" dir="ltr">${this.escapeHtml(sep.reference)}</div></div>
      <div class="meta">
        <div><span class="label">${t('PRINT.EMPLOYEE')}</span>${this.escapeHtml(employeeName)}</div>
        <div><span class="label">${t('PRINT.DEPARTMENT')}</span>${this.escapeHtml(this.locField(sep.employee?.department, 'name') || '—')}</div>
        <div><span class="label">${t('PRINT.DESIGNATION')}</span>${this.escapeHtml(this.locField(sep.employee?.designation, 'title') || '—')}</div>
        <div><span class="label">${t('PRINT.SEPARATION_TYPE')}</span>${this.escapeHtml(this.typeLabel(sep.type))}</div>
        <div><span class="label">${t('PRINT.LAST_WORKING_DAY')}</span>${this.escapeHtml(this.printDate(sep.last_working_day))}</div>
        <div><span class="label">${t('PRINT.COMPLETED')}</span>${this.escapeHtml(this.printDate(sep.updated_at))}</div>
      </div>
      ${departmentSections || `<p>${t('PRINT.NO_TASKS')}</p>`}
      <div class="signoff"><span>${t('PRINT.HR_FINAL_APPROVAL')}</span><span>${t('PRINT.DATE')}</span></div>
      <div class="footer">${t('PRINT.GENERATED_ON', { date: this.printDate(new Date().toISOString()) })}</div>
      </body></html>`);
    printWindow.document.close();
    printWindow.focus();
  }

  private taskStatusLabel(status: string): string {
    return this.translateOr(`STATUS.${status}`, status);
  }

  /** Bilingual master-data field (name / name_ar, title / title_ar) for plain-text output. */
  private locField(record: any, field: string): string {
    if (!record) return '';
    const ar = record[`${field}_ar`];
    if (this.lang.current() === 'ar' && ar) return ar;
    return record[field] || '';
  }

  private printDate(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    try { return ngFormatDate(date, 'dd MMM yyyy', this.lang.locale(), '+0300'); } catch { return String(value); }
  }

  private escapeHtml(value: any): string {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    } as Record<string, string>)[character]);
  }

  allChecklistTasksResolved(sep: any): boolean {
    const items = sep?.checklist_items || [];
    return items.length > 0 && items.every((item: any) => ['completed', 'skipped', 'na'].includes(item.status));
  }

  canViewOffboardingTasks(sep: any): boolean {
    return ['offboarding', 'completed'].includes(sep?.status)
      && (this.isHR || this.isFinanceManager || this.isDeptManager || this.isSuperAdmin);
  }

  private isDirectManager(sep: any): boolean {
    return this.isDeptManager && Number(sep?.employee?.manager_id) === Number(this.currentEmployeeId);
  }

  avatarColor(name: string): string {
    const colors = ['#3b82f6','#6366f1','#8b5cf6','#ec4899','#10b981','#f59e0b','#ef4444','#0ea5e9'];
    return colors[(name?.charCodeAt(0) || 0) % colors.length];
  }

  categoryLabel(c: string): string {
    return this.translateOr(`SEPARATIONS.CATEGORIES.${c}`, c);
  }

  categoryColor(c: string): string {
    return ({ it:'#3b82f6', hr:'#6366f1', finance:'#10b981', admin:'#f59e0b', general:'#8b949e' } as any)[c] || '#8b949e';
  }

  itemStatusCls(s: string): string {
    return ({ pending:'item-pending', completed:'item-done', skipped:'item-skip', na:'item-na' } as any)[s] || '';
  }

  approvalSteps(sep: any): any[] {
    const isTermination = sep.type === 'termination' || sep.type === 'abandonment';
    const hrFirst = sep.type === 'resignation'
      && (sep.hr_approved_at || (sep.status === 'pending_hr' && !sep.manager_approved_at));
    const steps = [
      { labelKey:'SEPARATIONS.STEPS.REQUEST', done: true, active: false, by: sep.initiated_by?.name, date: sep.created_at },
    ];
    if (hrFirst) {
      steps.push(
        { labelKey:'SEPARATIONS.STEPS.HR', done: !!sep.hr_approved_at, active: sep.status === 'pending_hr', by: sep.hr_approver?.name, date: sep.hr_approved_at },
        { labelKey:'SEPARATIONS.STEPS.MANAGER', done: !!sep.manager_approved_at, active: sep.status === 'pending_manager', by: sep.manager_approver?.name, date: sep.manager_approved_at }
      );
    }
    if (!isTermination && !hrFirst) {
      steps.push({
        labelKey:'SEPARATIONS.STEPS.MANAGER', done: !!sep.manager_approved_at, active: sep.status === 'pending_manager',
        by: sep.manager_approver?.name, date: sep.manager_approved_at
      });
    }
    if (!hrFirst) {
      steps.push({ labelKey:'SEPARATIONS.STEPS.HR', done: !!sep.hr_approved_at, active: sep.status === 'pending_hr', by: sep.hr_approver?.name, date: sep.hr_approved_at });
    }
    steps.push(
      { labelKey:'SEPARATIONS.STEPS.OFFBOARDING', done: sep.status === 'offboarding' || sep.status === 'completed', active: sep.status === 'approved', by: '', date: null },
      { labelKey:'SEPARATIONS.STEPS.COMPLETED', done: sep.status === 'completed', active: false, by: '', date: null }
    );
    return steps;
  }
}
