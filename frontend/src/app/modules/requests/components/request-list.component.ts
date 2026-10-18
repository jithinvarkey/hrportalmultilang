import {
  Component, OnInit,
  OnDestroy, ChangeDetectionStrategy, ChangeDetectorRef
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';
import { TranslateService } from '@ngx-translate/core';
import { Subject, interval } from 'rxjs';
import { takeUntil } from 'rxjs/operators';

@Component({
  standalone: false,
  selector: 'app-request-list',
  templateUrl: './request-list.component.html',
  styleUrls: ['./request-list.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RequestListComponent implements OnInit, OnDestroy {

  // ── Tabs ────────────────────────────────────────────────────────────────
  activeTab = 'mine';
  activeStatus = '';
  loading = false;
  submitting = false;

  // ── Data ─────────────────────────────────────────────────────────────────
  requests: any[] = [];
  requestTypes: any[] = [];
  allRequestTypes: any[] = [];
  stats: any = {};
  statItems: any[] = [];
  pagination: any = null;
  currentPage = 1;
  pageSize = 10;

  // ── Filters ──────────────────────────────────────────────────────────────
  filterSearch = '';
  filterCategory = '';
  filterTypeId = '';

  // ── Role flags ───────────────────────────────────────────────────────────
  isHR = false;
  isMgr = false;
  currentUserId: number | null = null;
  currentEmployeeId: number | null = null;
  currentUserdepartment: number | null = null;

  // ── Panels ───────────────────────────────────────────────────────────────
  showNew = false;
  showDetail = false;
  showReject = false;
  showComplete = false;
  showTypeForm = false;
  showAssign = false;

  selectedReq: any = null;
  rejectTarget: any = null;
  assignTarget: any = null;
  rejectReason = '';
  newComment = '';
  sendingComment = false;

  // ── New request form ──────────────────────────────────────────────────────
  form: any = { request_type_id: '', details: '', required_by: '', copies_needed: 1 };
  formError = '';
  selectedType: any = null;
  selectedFile: File | null = null;
  fileError = '';

  // ── Complete form ─────────────────────────────────────────────────────────
  completeForm: any = { completion_notes: '', hr_notes: '' };
  completionFile: File | null = null;

  // ── Assign form ───────────────────────────────────────────────────────────
  assignForm = { assigned_to: '', notes: '' };
  assignableGroups: any[] = [];
  assigning = false;

  // ── Type form ─────────────────────────────────────────────────────────────
  typeForm: any = {
    name: '', name_ar: '', code: '', category: 'documents', description: '', instructions: '',
    sla_days: 3, requires_attachment: false, requires_manager_approval: false,
    is_active: true, sort_order: 0, icon: 'description', color: '#6366f1', handling_department_id: ''
  };
  typeEditId: number | null = null;
  typeSaving = false;
  typeToDelete: any = null;
  typeDeleting = false;

  // ── Table columns ─────────────────────────────────────────────────────────
  displayedColumns = ['ref', 'employee', 'type', 'details', 'required_by', 'status', 'sla', 'actions'];
  mineColumns = ['ref', 'type', 'details', 'required_by', 'status', 'sla', 'actions'];
  typeColumns = ['name', 'category', 'sla', 'approval', 'actions'];

  // Labels below are translation keys, translated in the template.
  tabs = [
    { id: 'mine', label: 'REQUESTS.TABS.MINE', icon: 'person' },
    { id: 'all', label: 'REQUESTS.TABS.ALL', icon: 'list_alt' },
    { id: 'types', label: 'REQUESTS.TABS.TYPES', icon: 'tune' },
  ];

  statusTabs = [
    { id: '', label: 'COMMON.ALL' },
    { id: 'pending', label: 'STATUS.pending' },
    { id: 'in_progress', label: 'STATUS.in_progress' },
    { id: 'completed', label: 'STATUS.completed' },
    { id: 'rejected', label: 'STATUS.rejected' },
  ];

  categories = [
    { id: 'visa', label: 'REQUESTS.CATEGORY.visa', icon: 'flight_takeoff', color: '#3b82f6' },
    { id: 'travel', label: 'REQUESTS.CATEGORY.travel', icon: 'airplane_ticket', color: '#f59e0b' },
    { id: 'documents', label: 'REQUESTS.CATEGORY.documents', icon: 'description', color: '#10b981' },
    { id: 'hr', label: 'REQUESTS.CATEGORY.hr', icon: 'badge', color: '#8b5cf6' },
    { id: 'it', label: 'REQUESTS.CATEGORY.it', icon: 'computer', color: '#ef4444' },
    { id: 'admin', label: 'REQUESTS.CATEGORY.admin', icon: 'admin_panel_settings', color: '#ec4899' },
    { id: 'finance', label: 'REQUESTS.CATEGORY.finance', icon: 'payments', color: '#0ea5e9' },
    { id: 'other', label: 'REQUESTS.CATEGORY.other', icon: 'help_outline', color: '#6b7280' },
  ];

  materialIcons = [
    'description', 'flight_takeoff', 'airplane_ticket', 'family_restroom', 'payments',
    'badge', 'account_balance', 'verified', 'mail', 'computer', 'lock_open', 'email',
    'admin_panel_settings', 'local_parking', 'contact_page', 'inventory_2',
    'monetization_on', 'manage_accounts', 'home_work', 'school', 'workspace_premium',
    'health_and_safety', 'business_center', 'swap_horiz', 'help_outline',
  ];

  departments: any[] = [];
  private readonly destroy$ = new Subject<void>();

  /** Category → department keyword mapping for smart assignment */
  private categoryDeptMap: Record<string, string> = {
    it: 'IT',
    finance: 'Finance',
    admin: 'Admin',
    hr: 'Human Resources',
    travel: 'Admin',
    visa: 'Admin',
    documents: 'Human Resources',
    other: '',
  };

  constructor(
    private http: HttpClient,
    private auth: AuthService,
    private cdr: ChangeDetectorRef,
    private lang: LanguageService,
    private translate: TranslateService,
  ) { }

  ngOnInit(): void {
    this.isHR = this.auth.isHRRole();
    this.isMgr = this.auth.isManagerRole();
    this.currentUserId = this.auth.getUser()?.id ?? null;
    this.currentEmployeeId = this.auth.getUser()?.employee?.id ?? null;
    this.currentUserdepartment = this.auth.getUser()?.employee?.departmentId ?? null;
    // OnPush: re-render labels built in code (SLA chips, names) when the language changes
    this.translate.onLangChange.pipe(takeUntil(this.destroy$)).subscribe(() => this.cdr.markForCheck());
    this.loadStats();
    this.loadRequestTypes();
    this.load();
    if (this.isHR || this.isMgr) { this.loadDepartments(); this.loadAssignableUsers(); }
  }

  // ── Data loaders ────────────────────────────────────────────────────────

  loadStats(): void {
    this.http.get<any>('/api/v1/requests/stats').subscribe({
      next: r => {
        this.stats = r;
        this.statItems = [
          { label: 'STATUS.pending', value: r.pending, icon: 'hourglass_empty', color: '#f59e0b' },
          { label: 'STATUS.in_progress', value: r.in_progress, icon: 'sync', color: '#3b82f6' },
          { label: 'STATUS.completed', value: r.completed, icon: 'check_circle', color: '#10b981' },
          { label: 'STATUS.overdue', value: r.overdue, icon: 'warning', color: '#ef4444' },
        ];
        this.cdr.markForCheck();
      },
    });
  }

  loadRequestTypes(): void {
    this.http.get<any>('/api/v1/requests/types').subscribe({
      next: r => { this.requestTypes = r?.types || []; this.cdr.markForCheck(); },
    });
    this.http.get<any>('/api/v1/requests/types/all').subscribe({
      next: r => { this.allRequestTypes = r?.types || []; this.cdr.markForCheck(); },
    });
  }
  loadDepartments(): void {
    this.http.get<any>('/api/v1/departments').pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => { this.departments = r?.data ?? r ?? []; this.cdr.markForCheck(); },
      error: () => { },
    });
  }

  load(page = 1): void {
    this.loading = true;
    this.currentPage = page;
    const params: any = { per_page: this.pageSize, page };
    if (this.activeTab === 'mine') params.scope = 'mine';
    if (this.activeStatus) params.status = this.activeStatus;
    if (this.filterCategory) params.category = this.filterCategory;
    if (this.filterTypeId) params.request_type_id = this.filterTypeId;
    if (this.filterSearch) params.search = this.filterSearch;

    this.http.get<any>('/api/v1/requests', { params }).subscribe({
      next: r => {
        this.requests = r?.data || [];
        this.pagination = r;
        this.loading = false;
        this.cdr.markForCheck();
      },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  loadAssignableUsers(): void {
    this.http.get<any>('/api/v1/requests/assignable-users').subscribe({
      next: r => { this.assignableGroups = r?.groups || []; this.cdr.markForCheck(); },
    });
  }

  // ── Tab / filter ────────────────────────────────────────────────────────

  switchTab(id: string): void {
    this.activeTab = id;
    this.activeStatus = '';
    this.filterCategory = '';
    if (id !== 'types') this.load();
  }

  switchStatus(id: string): void { this.activeStatus = id; this.load(1); }

  changePageSize(): void { this.load(1); }

  // ── Detail view ─────────────────────────────────────────────────────────

  viewReq(r: any): void {
    this.http.get<any>(`/api/v1/requests/${r.id}`).subscribe({
      next: res => {
        this.selectedReq = res.request;
        this.showDetail = true;
        this.newComment = '';
        this.cdr.markForCheck();
      },
    });
  }

  reloadDetail(): void {
    if (!this.selectedReq) return;
    this.http.get<any>(`/api/v1/requests/${this.selectedReq.id}`).subscribe({
      next: r => { this.selectedReq = r.request; this.cdr.markForCheck(); },
    });
  }

  // ── New request ─────────────────────────────────────────────────────────

  openNew(): void {
    this.form = { request_type_id: '', details: '', required_by: '', copies_needed: 1 };
    this.formError = '';
    this.selectedType = null;
    this.selectedFile = null;
    this.fileError = '';
    this.showNew = true;
    this.cdr.markForCheck();
  }

  onTypeSelect(): void {
    this.selectedType = this.requestTypes.find(t => t.id == this.form.request_type_id) || null;
    this.cdr.markForCheck();
  }

  submitRequest(): void {
    if (!this.form.request_type_id || !this.form.details) {
      this.formError = this.lang.t('REQUESTS.MSG.REQUIRED_FIELDS'); return;
    }
    if (this.selectedType?.requires_attachment && !this.selectedFile) {
      this.formError = this.lang.t('REQUESTS.MSG.ATTACHMENT_REQUIRED'); return;
    }
    this.submitting = true;
    this.formError = '';
    const fd = new FormData();
    Object.entries(this.form).forEach(([k, v]) => { if (v) fd.append(k, String(v)); });
    if (this.selectedFile) fd.append('attachment', this.selectedFile, this.selectedFile.name);

    this.http.post<any>('/api/v1/requests', fd).subscribe({
      next: () => {
        this.submitting = false;
        this.showNew = false;
        this.selectedFile = null;
        this.load();
        this.loadStats();
        this.cdr.markForCheck();
      },
      error: err => {
        this.submitting = false;
        this.formError = err?.error?.message || this.lang.t('REQUESTS.MSG.SUBMIT_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  onFileSelected(event: Event): void {
    const f = (event.target as HTMLInputElement).files?.[0] ?? null;
    this.fileError = '';
    if (!f) { this.selectedFile = null; return; }
    if (f.size > 10 * 1024 * 1024) { this.fileError = this.lang.t('REQUESTS.MSG.FILE_TOO_LARGE'); return; }
    this.selectedFile = f;
  }

  onCompletionFileSelected(event: Event): void {
    this.completionFile = (event.target as HTMLInputElement).files?.[0] ?? null;
  }

  // ── Manager approve ─────────────────────────────────────────────────────

  managerApprove(req: any): void {
    this.http.post(`/api/v1/requests/${req.id}/manager-approve`, {}).subscribe({
      next: () => {
        this.load(this.currentPage);
        this.loadStats();
        if (this.showDetail) this.reloadDetail();
        this.cdr.markForCheck();
      },
      error: (e: any) => alert(this.lang.t('REQUESTS.MSG.APPROVE_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.SERVER_ERROR') })),
    });
  }

  // ── Assign ───────────────────────────────────────────────────────────────

  openAssign(req: any): void {
    this.assignTarget = req;
    this.assignForm = { assigned_to: '', notes: '' };
    this.assigning = false;
    this.showAssign = true;
    this.cdr.markForCheck();
  }

  submitAssign(): void {
    if (!this.assignTarget) return;
    this.assigning = true;
    const body: any = { hr_notes: this.assignForm.notes };
    if (this.assignForm.assigned_to) body.assigned_to = this.assignForm.assigned_to;

    this.http.post(`/api/v1/requests/${this.assignTarget.id}/assign`, body).subscribe({
      next: () => {
        this.assigning = false;
        this.showAssign = false;
        this.load(this.currentPage);
        this.loadStats();
        if (this.showDetail) this.reloadDetail();
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        this.assigning = false;
        this.cdr.markForCheck();
        alert(this.lang.t('REQUESTS.MSG.ASSIGN_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.SERVER_ERROR') }));
      },
    });
  }

  /** Returns staff in the recommended department based on request category */
  recommendedUsers(req: any): any[] {
    const cat = req?.request_type?.category || '';
    const keyword = (this.categoryDeptMap[cat] || '').toLowerCase();
    if (!keyword) return [];
    return this.assignableGroups
      .filter(g => g.department.toLowerCase().includes(keyword))
      .flatMap(g => g.users);
  }

  // ── Complete ─────────────────────────────────────────────────────────────

  openComplete(req: any): void {
    this.selectedReq = req;
    this.completeForm = { completion_notes: '', hr_notes: '' };
    this.completionFile = null;
    this.showComplete = true;
    this.cdr.markForCheck();
  }

  submitComplete(): void {
    const fd = new FormData();
    if (this.completeForm.completion_notes) fd.append('completion_notes', this.completeForm.completion_notes);
    if (this.completeForm.hr_notes) fd.append('hr_notes', this.completeForm.hr_notes);
    if (this.completionFile) fd.append('completion_file', this.completionFile, this.completionFile.name);

    this.http.post(`/api/v1/requests/${this.selectedReq.id}/complete`, fd).subscribe({
      next: () => {
        this.showComplete = false;
        this.completionFile = null;
        this.load(this.currentPage);
        this.loadStats();
        if (this.showDetail) this.reloadDetail();
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        alert(this.lang.t('REQUESTS.MSG.COMPLETE_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.SERVER_ERROR') }));
        this.cdr.markForCheck();
      },
    });
  }

  downloadCompletionFile(req: any): void {
    if (!req?.id) return;

    this.http.get(`/api/v1/requests/${req.id}/completion-file`, {
      observe: 'response',
      responseType: 'blob',
    }).subscribe({
      next: (response) => {
        const blob = response.body;
        if (!blob) return;

        const disposition = response.headers.get('content-disposition') || '';
        const match = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(disposition);
        const filename = match
          ? decodeURIComponent(match[1].replace(/"/g, ''))
          : `${req.reference || 'request'}-completion`;

        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        window.URL.revokeObjectURL(url);
      },
      error: (e: any) => alert(this.lang.t('REQUESTS.MSG.DOWNLOAD_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.SERVER_ERROR') })),
    });
  }

  // ── Reject ──────────────────────────────────────────────────────────────

  openReject(req: any): void {
    this.rejectTarget = req;
    this.rejectReason = '';
    this.showReject = true;
    this.cdr.markForCheck();
  }

  confirmReject(): void {
    if (!this.rejectReason.trim()) return;
    this.http.post(`/api/v1/requests/${this.rejectTarget.id}/reject`, { reason: this.rejectReason }).subscribe({
      next: () => {
        this.showReject = false;
        this.load(this.currentPage);
        this.loadStats();
        if (this.showDetail) this.showDetail = false;
        this.cdr.markForCheck();
      },
      error: (e: any) => alert(this.lang.t('REQUESTS.MSG.REJECT_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.SERVER_ERROR') })),
    });
  }

  // ── Cancel ──────────────────────────────────────────────────────────────

  cancelReq(req: any): void {
    if (!confirm(this.lang.t('REQUESTS.MSG.CONFIRM_CANCEL'))) return;
    this.http.post(`/api/v1/requests/${req.id}/cancel`, {}).subscribe({
      next: () => {
        this.load(this.currentPage);
        this.loadStats();
        if (this.showDetail) this.showDetail = false;
        this.cdr.markForCheck();
      },
      error: (e: any) => alert(this.lang.t('REQUESTS.MSG.CANCEL_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.SERVER_ERROR') })),
    });
  }

  // ── Comments ────────────────────────────────────────────────────────────

  sendComment(): void {
    if (!this.newComment.trim() || !this.selectedReq) return;
    this.sendingComment = true;
    this.http.post(`/api/v1/requests/${this.selectedReq.id}/comments`, { comment: this.newComment }).subscribe({
      next: () => {
        this.sendingComment = false;
        this.newComment = '';
        this.reloadDetail();
        this.cdr.markForCheck();
      },
      error: () => { this.sendingComment = false; this.cdr.markForCheck(); },
    });
  }

  // ── Type CRUD ────────────────────────────────────────────────────────────

  openTypeForm(t?: any): void {
    if (t) {
      this.typeEditId = t.id;
      this.typeForm = { ...t };
    } else {
      this.typeEditId = null;
      this.typeForm = {
        name: '', name_ar: '', code: '', category: 'documents', description: '', instructions: '',
        sla_days: 3, requires_attachment: false, requires_manager_approval: false,
        is_active: true, sort_order: 0, icon: 'description', color: '#6366f1', handling_department_id: ''
      };
    }
    this.showTypeForm = true;
    this.cdr.markForCheck();
  }

  saveType(): void {
    if (!this.typeForm.name || !this.typeForm.code) return;
    this.typeSaving = true;
    const req = this.typeEditId
      ? this.http.put(`/api/v1/requests/types/${this.typeEditId}`, this.typeForm)
      : this.http.post('/api/v1/requests/types', this.typeForm);

    req.subscribe({
      next: () => {
        this.typeSaving = false;
        this.showTypeForm = false;
        this.loadRequestTypes();
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        this.typeSaving = false;
        alert(this.lang.t('REQUESTS.MSG.SAVE_FAILED', { error: e?.error?.message || e?.error?.errors?.code?.[0] || this.lang.t('REQUESTS.MSG.SERVER_ERROR') }));
        this.cdr.markForCheck();
      },
    });
  }

  openDeleteFromEdit(): void {
    this.typeToDelete = { id: this.typeEditId, ...this.typeForm };
    this.showTypeForm = false;
    this.cdr.markForCheck();
  }

  confirmDeleteType(): void {
    if (!this.typeToDelete) return;
    this.typeDeleting = true;
    this.http.delete(`/api/v1/requests/types/${this.typeToDelete.id}`).subscribe({
      next: () => {
        this.typeToDelete = null;
        this.typeDeleting = false;
        this.loadRequestTypes();
        this.cdr.markForCheck();
      },
      error: (e: any) => {
        this.typeDeleting = false;
        this.typeToDelete = null;
        alert(this.lang.t('REQUESTS.MSG.DELETE_FAILED', { error: e?.error?.message || this.lang.t('REQUESTS.MSG.TYPE_IN_USE') }));
        this.cdr.markForCheck();
      },
    });
  }

  // ── Helpers ──────────────────────────────────────────────────────────────

  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    const lastPage = this.pagination.last_page;
    const start = Math.max(1, Math.min(this.currentPage - 2, lastPage - 4));
    const end = Math.min(lastPage, start + 4);
    return Array.from({ length: Math.max(0, end - start + 1) }, (_, i) => start + i);
  }

  /** Request-type management is an HR function; other roles only see their own / assigned requests. */
  get visibleTabs(): any[] {
    return this.isHR ? this.tabs : this.tabs.filter(t => t.id !== 'types');
  }

  /** Approvers must not act on their own request (the "My Requests" tab lists them too). */
  private isOwnReq(req: any): boolean {
    const empId = req?.employee_id ?? req?.employee?.id;
    return this.currentEmployeeId !== null && empId !== undefined && empId !== null
      && String(empId) === String(this.currentEmployeeId);
  }

  get columns(): string[] {
    return this.activeTab === 'mine' ? this.mineColumns : this.displayedColumns;
  }

  catInfo(id: string): any {
    return this.categories.find(c => c.id === id) || { label: id, icon: 'help_outline', color: '#8b949e' };
  }

  groupedTypes(): any[] {
    const grouped: any = {};
    for (const t of this.requestTypes) {
      if (!grouped[t.category]) grouped[t.category] = { ...this.catInfo(t.category), types: [] };
      grouped[t.category].types.push(t);
    }
    return Object.values(grouped);
  }

  /** Employee display name: Arabic name when Arabic is active and one is recorded. */
  empName(e: any): string {
    if (!e) return '';
    if (this.lang.current() === 'ar' && e.arabic_name) return e.arabic_name;
    return `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
  }

  statusCls(s: string): string {
    const map: any = {
      pending: 'badge-yellow', pending_manager: 'badge-orange',
      in_progress: 'badge-blue', completed: 'badge-green',
      rejected: 'badge-red', cancelled: 'badge-draft',
    };
    return map[s] || 'badge-draft';
  }

  statusIcon(s: string): string {
    const map: any = {
      pending: 'hourglass_empty', pending_manager: 'manage_accounts',
      in_progress: 'sync', completed: 'check_circle',
      rejected: 'cancel', cancelled: 'block',
    };
    return map[s] || 'help';
  }

  slaStatus(req: any): { label: string; cls: string } | null {
    if (!req.due_date || ['completed', 'rejected', 'cancelled'].includes(req.status)) return null;
    const days = Math.ceil((new Date(req.due_date).getTime() - Date.now()) / 86400000);
    if (days < 0) return { label: this.lang.t('REQUESTS.SLA_STATE.OVERDUE', { days: Math.abs(days) }), cls: 'sla-red' };
    if (days === 0) return { label: this.lang.t('REQUESTS.SLA_STATE.DUE_TODAY'), cls: 'sla-red' };
    if (days <= 1) return { label: this.lang.t('REQUESTS.SLA_STATE.LEFT', { days }), cls: 'sla-orange' };
    return { label: this.lang.t('REQUESTS.SLA_STATE.LEFT', { days }), cls: 'sla-green' };
  }

  avatarColor(name: string): string {
    const colors = ['#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#10b981', '#f59e0b', '#ef4444', '#0ea5e9'];
    return colors[(name?.charCodeAt(0) || 0) % colors.length];
  }

  // ── Permission helpers ────────────────────────────────────────────────────

  canMgrApprove(req: any): boolean {
    if (req.status !== 'pending_manager' || this.isOwnReq(req)) {
      return false;
    }

    // HR can always approve
    if (this.isHR) {
      return true;
    }

    // Manager can approve only employees from the same department
    if (this.isMgr) {
      const employeeDeptId = req?.employee?.department_id;
      return this.currentUserdepartment === employeeDeptId;
    }
    return false;

  }

  canAssign(req: any): boolean {
    if (this.isOwnReq(req)) return false;
    if (this.isHR) return ['pending', 'in_progress'].includes(req.status);
    return req.status === 'pending' && this.isMgr;
  }

  canComplete(req: any): boolean {
    if (req.status !== 'in_progress') return false;
    return this.isHR || req.assigned_to?.id === this.currentUserId;
  }

  canReject(req: any): boolean {

    if (!['pending', 'pending_manager', 'in_progress'].includes(req.status) || this.isOwnReq(req)) {
      return false;
    }

    // HR can always reject
    if (this.isHR) {
      return true;
    }

    // Manager restriction
    if (this.isMgr) {

      const currentDeptId = this.currentUserdepartment;

      const employeeDeptId =
        req?.employee?.department_id ??
        req?.employee?.department?.id;

      // Manager cannot reject in-progress requests from own department
      if (req.status === 'in_progress' && currentDeptId === employeeDeptId) {
        return false;
      }

      return true;
    }

    return false;
  }

  canCancel(req: any): boolean {
    return ['pending', 'pending_manager'].includes(req.status);
  }
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
