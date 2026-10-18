import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

@Component({
  standalone: false,
  selector: 'app-leave-list',
  templateUrl: './leave-list.component.html',
  styleUrls: ['./leave-list.component.scss'],
})
export class LeaveListComponent implements OnInit {

  // ── State ────────────────────────────────────────────────────────────────
  activeTab = 'requests';   // requests | calendar | balances | types
  activeStatus = 'needs_action';
  loading = false;
  submitting = false;
  actionLoadingId: number | null = null;
  actionLoadingType: 'approve' | 'reject' | 'cancel' | null = null;

  // Data
  requests: any[] = [];
  leaveTypes: any[] = [];
  myBalances: any[] = [];
  annualBalanceToday: any = null;
  formBalances: any[] = [];
  allBalances: any[] = [];
  stats: any = {};
  calendarEvents: any[] = [];
  calendarMeta: any = { department: null, scope: 'department' };
  holidays: any[] = [];
  managedHolidays: any[] = [];
  formHolidays: any[] = [];
  pagination: any = null;
  balancePagination: any = null;

  // Modals / drawers
  showNewRequest = false;
  showReject = false;
  showDetail = false;
  detailDrawerTab: 'details' | 'activity' = 'details';
  showTypeForm = false;
  showHolidayForm = false;
  showVisibilityPanel = false;

  selectedRequest: any = null;
  rejectTarget: any = null;
  rejectReason = '';
  rejectError = '';

  // Filters
  filterSearch = '';
  filterType = '';
  filterDept = '';
  balanceYear = new Date().getFullYear();
  balanceYears: number[] = [new Date().getFullYear()];
  private searchTimer: any = null;
  currentPage = 1;
  pageSize = 10;

  // Calendar
  calYear = new Date().getFullYear();
  calMonth = new Date().getMonth(); // 0-based
  calDays: any[] = [];
  loadingCalendar = false;
  selectedCalendarCell: any = null;
  holidayYear = new Date().getFullYear();
  holidayLoading = false;

  // Leave type form
  typeForm: any = { name: '', name_ar: '', code: '', days_allowed: 0, is_paid: true, carry_forward: false, max_carry_forward: 10, carry_forward_all: false, requires_document: false, description: '' };
  typeEditId: number | null = null;
  typeError = '';
  typeSaving = false;

  // Holiday form
  holidayForm = { name: '', date: '', end_date: '', is_recurring: false };
  holidayEditId: number | null = null;
  holidayError = '';
  holidaySaving = false;

  // New request form
  form: any = { leave_type_id: '', start_date: '', end_date: '', start_time: '08:00', end_time: '09:00', reason: '', employee_id: '', is_half_day: false, half_day_period: 'morning', requires_exit_reentry: false, requires_ticket: false, ticket_dependent_ids: [], destination_country: '' };
  selectedFile: File | null = null;
  fileError = '';
  formError = '';
  ticketOptions: any = null;
  ticketOptionsLoading = false;

  // Department limits panel
  showLimitsPanel = false;
  limitsLeaveType: any = null;
  deptLimits: any[] = [];
  limitsLoading = false;
  limitsSaving = false;
  limitsError = '';
  limitsDirty = false;

  // Department visibility panel
  visibilityLeaveType: any = null;
  deptVisibility: any[] = [];
  visibilityLoading = false;
  visibilitySaving = false;
  visibilityError = '';
  visibilityMessage = '';
  visibilityDirty = false;

  // Stat cards
  statItems: any[] = [];
  excuseUsage: any = null;   // monthly hourly excuse usage
  loadingUsage = false;

  // Table columns
  displayedColumns = ['employee', 'type', 'dates', 'days', 'reason', 'status', 'actions'];
  isHR = false;
  canManageHolidays = false;
  isMgr = false;
  /** Roles the backend accepts at the manager stage (LeaveController::approve/reject). */
  private isLeaveManagerApprover = false;
  showMyRequestsTab = true;
  userId = '';
  employeeId = '';
  currentUserName = '';
  balanceColumns = ['employee', 'leave_type', 'allocated', 'carry_forward', 'used', 'pending', 'remaining', 'bar'];
  typeColumns = ['name', 'name_ar', 'code', 'days', 'paid', 'carry', 'actions'];

  tabs = [
    // label = translation key, translated in the template so language switches apply live
    { id: 'my_requests', label: 'LEAVE.TABS.MY_REQUESTS', icon: 'person_pin' },
    { id: 'requests', label: 'LEAVE.TABS.TEAM_REQUESTS', icon: 'event_note' },
    { id: 'calendar', label: 'LEAVE.TABS.CALENDAR', icon: 'calendar_month' },
    { id: 'balances', label: 'LEAVE.TABS.BALANCES', icon: 'account_balance_wallet' },
    { id: 'types', label: 'LEAVE.TABS.LEAVE_TYPES', icon: 'tune' },
    { id: 'holidays', label: 'LEAVE.TABS.HOLIDAYS', icon: 'star' },
  ];

  statusTabs = [
    { id: 'needs_action', label: 'STATUS.needs_action' },
    { id: 'pending', label: 'LEAVE.STAGE.AWAITING_MANAGER' },
    { id: 'manager_approved', label: 'LEAVE.STAGE.AWAITING_HR' },
    { id: 'approved', label: 'STATUS.approved' },
    { id: 'rejected', label: 'STATUS.rejected' },
    { id: 'cancelled', label: 'STATUS.cancelled' },
    { id: '', label: 'COMMON.ALL' },
  ];

  constructor(private http: HttpClient, private auth: AuthService, private route: ActivatedRoute, public lang: LanguageService) { }

  ngOnInit() {
    const portalType = this.auth.getPortalType();
    this.showMyRequestsTab = portalType !== 'employee';
    this.tabs = this.tabs.map(tab =>
      tab.id === 'requests'
        ? { ...tab, label: portalType === 'employee' ? 'LEAVE.TABS.REQUESTS' : 'LEAVE.TABS.TEAM_REQUESTS' }
        : tab
    );
    const openPersonalTab = this.route.snapshot.url.some(segment => segment.path === 'my');
    if (openPersonalTab) {
      this.activeTab = 'my_requests';
      this.activeStatus = '';
    } else if (portalType === 'employee') {
      this.activeTab = 'requests';
      this.activeStatus = '';
    }
    this.loadStats();
    this.loadTypes();
    this.loadMyBalance();
    this.loadAnnualBalanceToday();
    this.load();

    const requestId = Number(this.route.snapshot.queryParamMap.get('request_id'));
    if (Number.isInteger(requestId) && requestId > 0) {
      this.viewRequest({ id: requestId });
    }

    const attendanceDate = this.route.snapshot.queryParamMap.get('apply_date');
    if (attendanceDate && /^\d{4}-\d{2}-\d{2}$/.test(attendanceDate)) {
      this.openNewRequest();
      this.form.start_date = attendanceDate;
      this.form.end_date = attendanceDate;
    }
  }

  // ── Stats ─────────────────────────────────────────────────────────────────
  loadStats() {
    // Role detection
    // Backend treats only these roles as leave HR (approve final stage, manage types, reports).
    this.isHR = this.auth.hasAnyRole(['super_admin', 'hr_manager', 'hr_staff']);
    this.isLeaveManagerApprover = this.auth.hasAnyRole(['super_admin', 'hr_manager', 'hr_staff', 'department_manager', 'finance_manager']);
    this.canManageHolidays = this.auth.hasAnyRole(['super_admin', 'hr_manager', 'hr_staff']);
    this.isMgr = this.auth.isManagerRole() || this.auth.isFinanceManager();
    const user = this.auth.getUser();
    this.userId = user?.id ?? '';
    this.employeeId = user?.employee?.id ?? user?.employee_id ?? null;
    this.currentUserName = (user?.employee?.full_name || user?.name || '').trim().toLowerCase();
    this.typeColumns = this.isHR ? ['name', 'name_ar', 'code', 'days', 'paid', 'carry', 'actions'] : ['name', 'name_ar', 'code', 'days', 'paid', 'carry'];

    this.http.get<any>('/api/v1/leave/stats').subscribe({
      next: r => {
        this.stats = r;
        this.statItems = [
          { label: 'LEAVE.STATS.PENDING_REQUESTS', value: r.pending_count, icon: 'pending_actions', color: '#f59e0b' },
          { label: 'LEAVE.STATS.ON_LEAVE_TODAY', value: r.on_leave_today, icon: 'beach_access', color: '#6366f1' },
          { label: 'LEAVE.STATS.APPROVED_THIS_MONTH', value: r.approved_month, icon: 'check_circle', color: '#10b981' },
          { label: 'STATUS.cancelled', value: r.cancelled_count, icon: 'cancel', color: '#ef4444' },
        ];
      }
    });
  }

  // ── Requests ──────────────────────────────────────────────────────────────
  load(page = 1) {
    this.loading = true;
    this.currentPage = page;
    const params: any = { per_page: this.pageSize, page };
    if (this.activeTab === 'my_requests') {
      params.own = '1';
    }
    if (this.activeStatus === 'needs_action' && this.activeTab !== 'my_requests') {
      params.needs_action = '1';
    } else if (this.activeStatus) {
      params.status = this.activeStatus;
    }
    if (this.filterType) params.leave_type_id = this.filterType;
    if (this.filterSearch) params.search = this.filterSearch;

    this.http.get<any>('/api/v1/leave/requests', { params }).subscribe({
      next: r => { this.requests = r?.data || []; this.pagination = r; this.loading = false; },
      error: () => this.loading = false
    });
  }

  switchStatus(id: string) { this.activeStatus = id; this.currentPage = 1; this.load(); }

  requestStatusTabs(): any[] {
    return this.activeTab === 'my_requests'
      ? this.statusTabs.filter(t => t.id !== 'needs_action')
      : this.statusTabs;
  }

  changePageSize() { this.load(1); }

  onRequestSearchChange() {
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.load(1), 350);
  }

  statusCount(statusId: string): number {
    const counts: Record<string, number> = {
      needs_action: this.stats?.needs_action_count || 0,
      pending: this.stats?.awaiting_manager_count || 0,
      manager_approved: this.stats?.awaiting_hr_count || 0,
      approved: this.stats?.approved_count || 0,
      rejected: this.stats?.rejected_count || 0,
      cancelled: this.stats?.cancelled_count || 0,
    };
    return counts[statusId] || 0;
  }

  viewRequest(r: any) {
    this.selectedRequest = r;
    this.detailDrawerTab = 'details';
    this.showDetail = true;
    this.http.get<any>(`/api/v1/leave/requests/${r.id}`).subscribe({
      next: res => this.selectedRequest = res?.request || r,
      error: () => this.selectedRequest = r
    });
  }

  approve(r: any) {
    if (this.isActionLoading()) return;
    if (!confirm(this.lang.t('LEAVE.CONFIRM.APPROVE', { count: r.total_days, name: this.employeeName(r.employee) }))) return;
    this.setActionLoading(r.id, 'approve');
    this.http.post(`/api/v1/leave/requests/${r.id}/approve`, {})
      .pipe(finalize(() => this.clearActionLoading()))
      .subscribe({
        next: () => { this.load(this.currentPage); this.loadStats(); this.loadMyBalance(); this.loadAnnualBalanceToday(); if (this.showDetail) this.showDetail = false; },
        error: err => { alert(err?.error?.message || this.lang.t('MESSAGES.GENERIC_ERROR')); this.load(this.currentPage); }
      });
  }

  openReject(r: any) {
    if (this.isActionLoading()) return;
    this.rejectTarget = r;
    this.rejectReason = '';
    this.rejectError = '';
    this.showReject = true;
  }

  confirmReject() {
    if (!this.rejectReason.trim() || !this.rejectTarget || this.isActionLoading()) return;
    this.rejectError = '';
    this.setActionLoading(this.rejectTarget.id, 'reject');
    this.http.post(`/api/v1/leave/requests/${this.rejectTarget.id}/reject`, { reason: this.rejectReason })
      .pipe(finalize(() => this.clearActionLoading()))
      .subscribe({
        next: () => { this.showReject = false; this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.showDetail = false; },
        error: err => { this.rejectError = err?.error?.message || this.lang.t('LEAVE.REJECT.ERR_FAILED'); }
      });
  }

  cancel(r: any) {
    if (this.isActionLoading()) return;
    if (!confirm(this.lang.t('LEAVE.CONFIRM.CANCEL'))) return;
    this.setActionLoading(r.id, 'cancel');
    this.http.delete(`/api/v1/leave/requests/${r.id}`)
      .pipe(finalize(() => this.clearActionLoading()))
      .subscribe({
        next: () => { this.load(this.currentPage); this.loadStats(); this.loadMyBalance(); this.loadAnnualBalanceToday(); if (this.showDetail) this.showDetail = false; },
        error: err => { alert(err?.error?.message || this.lang.t('MESSAGES.GENERIC_ERROR')); this.load(this.currentPage); }
      });
  }

  setActionLoading(id: number, type: 'approve' | 'reject' | 'cancel'): void {
    this.actionLoadingId = id;
    this.actionLoadingType = type;
  }

  clearActionLoading(): void {
    this.actionLoadingId = null;
    this.actionLoadingType = null;
  }

  isActionLoading(r?: any, type?: 'approve' | 'reject' | 'cancel'): boolean {
    if (this.actionLoadingId === null) return false;
    if (!r && !type) return true;
    const matchesRow = !r || this.actionLoadingId === r.id;
    const matchesType = !type || this.actionLoadingType === type;
    return matchesRow && matchesType;
  }

  // ── New Request ───────────────────────────────────────────────────────────
  openNewRequest() {
    this.form = { leave_type_id: '', start_date: '', end_date: '', start_time: '08:00', end_time: '09:00', reason: '', employee_id: '', is_half_day: false, half_day_period: 'morning', requires_exit_reentry: false, requires_ticket: false, ticket_dependent_ids: [], destination_country: '' };
    this.ticketOptions = null;
    this.formBalances = this.myBalances.map(balance =>
      this.annualBalanceToday && this.isAnnualBalance(balance) ? this.annualBalanceToday : balance
    );
    this.formError = '';
    this.selectedFile = null;
    this.fileError = '';
    this.showNewRequest = true;
  }

  submitRequest() {
    const isExcuse = this.isHourlyExcuse;
    if (!this.form.leave_type_id || !this.form.start_date || !this.form.reason) {
      this.formError = this.lang.t('LEAVE.FORM.ERR_ALL_REQUIRED'); return;
    }
    if (!isExcuse && !this.form.end_date) {
      this.formError = this.lang.t('LEAVE.FORM.ERR_END_DATE'); return;
    }
    const minChars = isExcuse ? 5 : 10;
    if (this.form.reason.length < minChars) {
      this.formError = this.lang.t('LEAVE.FORM.ERR_REASON_MIN', { min: minChars }); return;
    }
    // Check required document
    const lt = this.selectedType;
    if (lt?.requires_document && !this.selectedFile) {
      this.formError = this.lang.t('LEAVE.FORM.ERR_DOC_REQUIRED', { type: this.locName(lt) }); return;
    }
    if (this.form.requires_ticket && this.ticketOptions?.already_used) {
      this.formError = this.lang.t('LEAVE.FORM.ERR_TICKET_USED', { year: this.ticketOptions.year }); return;
    }
    if ((this.form.ticket_dependent_ids?.length || 0) > (this.ticketOptions?.max_dependents ?? 0)) {
      this.formError = this.lang.t('LEAVE.FORM.ERR_MAX_DEPENDENTS', { max: this.ticketOptions.max_dependents }); return;
    }
    this.submitting = true; this.formError = '';

    // Build multipart FormData so file is included
    const fd = new FormData();
    const booleanFields = ['is_half_day', 'requires_exit_reentry', 'requires_ticket'];
    Object.entries(this.form).forEach(([k, v]) => {
      if (k === 'ticket_dependent_ids') {
        (v as number[] || []).forEach(id => fd.append('ticket_dependent_ids[]', String(id)));
        return;
      }
      if (booleanFields.includes(k)) {
        // Always send booleans as '1'/'0' so Laravel's boolean validation passes
        fd.append(k, v ? '1' : '0');
      } else if (v !== null && v !== undefined && v !== '') {
        fd.append(k, String(v));
      }
    });
    if (this.selectedFile) fd.append('document', this.selectedFile, this.selectedFile.name);

    this.http.post<any>('/api/v1/leave/requests', fd).subscribe({
      next: () => {
        this.submitting = false; this.showNewRequest = false;
        this.form = { leave_type_id: '', start_date: '', end_date: '', start_time: '08:00', end_time: '09:00', reason: '', employee_id: '', is_half_day: false, half_day_period: 'morning', requires_exit_reentry: false, requires_ticket: false, ticket_dependent_ids: [], destination_country: '' };
        this.selectedFile = null; this.excuseUsage = null;
        this.load(1); this.loadStats(); this.loadMyBalance(); this.loadAnnualBalanceToday();
      },
      error: err => { this.submitting = false; this.formError = err?.error?.message || this.lang.t('LEAVE.FORM.ERR_SUBMIT'); }
    });
  }

  // ── My balance ───────────────────────────────────────────────────────────
  loadMyBalance() {
    const user = JSON.parse(sessionStorage.getItem('hrms_user') || localStorage.getItem('hrms_user') || '{}');
    const empId = user?.employee?.id || user?.employee_id;
    if (!empId) return;
    this.http.get<any>(`/api/v1/leave/balance/${empId}`).subscribe({
      next: r => this.myBalances = r?.balances || []
    });
  }

  loadAnnualBalanceToday() {
    const user = JSON.parse(sessionStorage.getItem('hrms_user') || localStorage.getItem('hrms_user') || '{}');
    const empId = user?.employee?.id || user?.employee_id;
    if (!empId) return;
    this.http.get<any>(`/api/v1/leave/balance/${empId}`, {
      params: { as_of: this.dateInputValue(new Date()) }
    }).subscribe({
      next: r => {
        const balances = r?.balances || [];
        this.annualBalanceToday = balances.find((b: any) => this.isAnnualBalance(b)) || null;
      },
      error: () => this.annualBalanceToday = null
    });
  }

  loadFormBalance(asOf?: string) {
    const user = JSON.parse(sessionStorage.getItem('hrms_user') || localStorage.getItem('hrms_user') || '{}');
    const empId = user?.employee?.id || user?.employee_id;
    if (!empId) return;
    const params: any = {};
    if (asOf) params.as_of = asOf;
    this.http.get<any>(`/api/v1/leave/balance/${empId}`, { params }).subscribe({
      next: r => this.formBalances = r?.balances || []
    });
  }

  // ── Leave Types ──────────────────────────────────────────────────────────
  loadTypes() {
    this.http.get<any>('/api/v1/leave/types').subscribe({
      next: r => this.leaveTypes = r?.types || r || []
    });
  }

  openTypeForm(t?: any) {
    if (t) {
      this.typeEditId = t.id;
      this.typeForm = { ...t, carry_forward_all: !!t.carry_forward_all, max_carry_forward: Number(t.max_carry_forward) };
    } else {
      this.typeEditId = null;
      this.typeForm = { name: '', name_ar: '', code: '', days_allowed: 0, is_paid: true, carry_forward: false, max_carry_forward: 10, carry_forward_all: false, requires_document: false, description: '', skip_manager_approval: false };
    }
    this.typeError = '';
    this.showTypeForm = true;
  }

  saveType() {
    if (!this.typeForm.name || !this.typeForm.code) { this.typeError = this.lang.t('LEAVE.TYPES.ERR_REQUIRED'); return; }
    if (!this.typeForm.carry_forward_all && (this.typeForm.max_carry_forward == null || !Number.isInteger(this.typeForm.max_carry_forward) || this.typeForm.max_carry_forward < 0 || this.typeForm.max_carry_forward > 65535)) {
      this.typeError = this.lang.t('LEAVE.TYPES.ERR_CF_MAX'); return;
    }
    if (this.typeForm.carry_forward_all) this.typeForm.max_carry_forward = 0;
    this.typeSaving = true; this.typeError = '';
    const req = this.typeEditId
      ? this.http.put(`/api/v1/leave/types/${this.typeEditId}`, this.typeForm)
      : this.http.post('/api/v1/leave/types', this.typeForm);
    req.subscribe({
      next: () => { this.typeSaving = false; this.showTypeForm = false; this.loadTypes(); },
      error: err => { this.typeSaving = false; this.typeError = err?.error?.message || this.lang.t('MESSAGES.SAVE_FAILED'); }
    });
  }

  // ── All Balances tab ─────────────────────────────────────────────────────
  loadAllBalances(page = 1) {
    const params: any = { page, per_page: 25, year: this.balanceYear };
    if (this.filterSearch) params.search = this.filterSearch;
    if (this.filterDept) params.department_id = this.filterDept;
    this.http.get<any>('/api/v1/leave/all-balances', { params }).subscribe({
      next: r => {
        this.allBalances = r?.data || [];
        this.balancePagination = r;
        const years = (r?.available_years || []).map((value: any) => Number(value)).filter((value: number) => !!value);
        this.balanceYears = years.length ? years : [this.balanceYear];
        if (r?.selected_year) this.balanceYear = Number(r.selected_year);
      }
    });
  }

  onBalanceYearChange(): void {
    this.loadAllBalances(1);
  }

  downloadAnnualBalanceReport(): void {
    const params: any = {};
    if (this.filterSearch) params.search = this.filterSearch;
    if (this.filterDept) params.department_id = this.filterDept;

    this.http.get('/api/v1/leave/annual-balance-report', {
      params,
      responseType: 'blob',
      observe: 'response',
    }).subscribe({
      next: response => this.downloadBlob(response, `annual-leave-balance-report-${new Date().toISOString().slice(0, 10)}.xlsx`)
    });
  }

  downloadLeaveDetailsReport(): void {
    const params: any = {};
    if (this.activeStatus === 'needs_action') {
      params.needs_action = '1';
    } else if (this.activeStatus) {
      params.status = this.activeStatus;
    }
    if (this.filterType) params.leave_type_id = this.filterType;
    if (this.filterSearch) params.search = this.filterSearch;

    this.http.get('/api/v1/leave/details-report', {
      params,
      responseType: 'blob',
      observe: 'response',
    }).subscribe({
      next: response => this.downloadBlob(response, `leave-details-report-${new Date().toISOString().slice(0, 10)}.xlsx`)
    });
  }

  private downloadBlob(response: any, fallbackFilename: string): void {
    const blob = response.body as Blob;
    const disposition = response.headers.get('content-disposition') || '';
    const match = disposition.match(/filename="?([^"]+)"?/i);
    const filename = match?.[1] || fallbackFilename;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
  }

  // ── Calendar ──────────────────────────────────────────────────────────────
  loadCalendar() {
    this.loadingCalendar = true;
    this.selectedCalendarCell = null;
    this.http.get<any>('/api/v1/leave/calendar', { params: { month: this.calMonth + 1, year: this.calYear } }).subscribe({
      next: r => {
        this.calendarEvents = r?.leaves || [];
        this.calendarMeta = { department: r?.department || null, scope: r?.scope || 'department' };
        this.loadingCalendar = false;
        this.buildCalendar();
      },
      error: () => {
        this.calendarEvents = [];
        this.loadingCalendar = false;
        this.buildCalendar();
      }
    });
    this.http.get<any>('/api/v1/leave/holidays', { params: { year: this.calYear } }).subscribe({
      next: r => { this.holidays = r?.holidays || []; this.buildCalendar(); }
    });
  }

  buildCalendar() {
    const firstDay = new Date(this.calYear, this.calMonth, 1).getDay();
    const daysInMonth = new Date(this.calYear, this.calMonth + 1, 0).getDate();
    // Shift: week starts Sunday (0)
    const startPad = firstDay;
    const cells: any[] = [];

    for (let i = 0; i < startPad; i++) cells.push(null);

    for (let d = 1; d <= daysInMonth; d++) {
      const dateStr = `${this.calYear}-${String(this.calMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      const dow = new Date(this.calYear, this.calMonth, d).getDay();
      const isWeekend = dow === 5 || dow === 6; // Fri=5, Sat=6
      const holiday = this.holidays.find(h => h.date?.slice(0, 10) === dateStr);
      const leaves = this.calendarEvents.filter(e => e.start_date?.slice(0, 10) <= dateStr && e.end_date?.slice(0, 10) >= dateStr);
      const isToday = dateStr === this.dateInputValue(new Date());
      cells.push({ d, dateStr, isWeekend, holiday, leaves, isToday });
    }
    this.calDays = cells;
  }

  prevMonth() { if (this.calMonth === 0) { this.calMonth = 11; this.calYear--; } else this.calMonth--; this.loadCalendar(); }
  nextMonth() { if (this.calMonth === 11) { this.calMonth = 0; this.calYear++; } else this.calMonth++; this.loadCalendar(); }
  selectCalendarDay(cell: any) { if (cell?.leaves?.length) this.selectedCalendarCell = cell; }

  // ── Tab switch ────────────────────────────────────────────────────────────
  switchTab(id: string) {
    if (id === 'types' && !this.isHR) return;
    if (id === 'my_requests' && !this.isMgr) return;
    this.activeTab = id;
    if (id === 'requests' && !this.activeStatus) this.activeStatus = 'needs_action';
    if (id === 'my_requests' && this.activeStatus === 'needs_action') this.activeStatus = '';
    if (id === 'requests' || id === 'my_requests') this.load(1);
    if (id === 'calendar') this.loadCalendar();
    if (id === 'balances') this.loadAllBalances();
    if (id === 'holidays') this.loadHolidayManagement();
  }

  // ── Holidays ──────────────────────────────────────────────────────────────
  loadHolidayManagement() {
    this.holidayLoading = true;
    this.http.get<any>('/api/v1/leave/holidays', { params: { year: this.holidayYear, manage: '1' } }).subscribe({
      next: r => { this.managedHolidays = r?.holidays || []; this.holidayLoading = false; },
      error: () => { this.managedHolidays = []; this.holidayLoading = false; }
    });
  }

  openHolidayForm(h?: any) {
    this.holidayEditId = h?.id ?? null;
    this.holidayError = '';
    this.holidayForm = h
      ? { name: h.name || '', date: this.dateInputValue(h.date), end_date: this.dateInputValue(h.end_date), is_recurring: !!h.is_recurring }
      : { name: '', date: '', end_date: '', is_recurring: false };
    this.showHolidayForm = true;
  }

  dateInputValue(value: any): string {
    if (!value) return '';
    if (typeof value === 'string') return value.slice(0, 10);
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  formatHolidayDate(value: any, options: Intl.DateTimeFormatOptions = { day: '2-digit', month: 'short', year: 'numeric' }): string {
    const dateValue = this.dateInputValue(value);
    if (!dateValue) return '';
    const [year, month, day] = dateValue.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString(this.intlLocale(), options);
  }

  closeHolidayForm() {
    this.showHolidayForm = false;
    this.holidayEditId = null;
    this.holidayError = '';
  }

  saveHoliday() {
    if (!this.holidayForm.name || !this.holidayForm.date) {
      this.holidayError = this.lang.t('LEAVE.HOLIDAYS.ERR_REQUIRED');
      return;
    }
    this.holidaySaving = true;
    this.holidayError = '';
    const payload = {
      ...this.holidayForm,
      date: this.dateInputValue(this.holidayForm.date),
      end_date: this.dateInputValue(this.holidayForm.end_date),
    };
    const req = this.holidayEditId
      ? this.http.put(`/api/v1/leave/holidays/${this.holidayEditId}`, payload)
      : this.http.post('/api/v1/leave/holidays', payload);

    req.subscribe({
      next: () => {
        this.holidaySaving = false;
        this.closeHolidayForm();
        this.loadCalendar();
        if (this.activeTab === 'holidays') this.loadHolidayManagement();
      },
      error: err => {
        this.holidaySaving = false;
        this.holidayError = err?.error?.message || this.lang.t('LEAVE.HOLIDAYS.ERR_SAVE');
      }
    });
  }

  deleteHoliday(id: number) {
    if (!confirm(this.lang.t('LEAVE.HOLIDAYS.CONFIRM_DELETE'))) return;
    this.http.delete(`/api/v1/leave/holidays/${id}`).subscribe({
      next: () => {
        this.loadCalendar();
        if (this.activeTab === 'holidays') this.loadHolidayManagement();
      }
    });
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  get selectedType(): any {
    return this.leaveTypes.find(t => t.id == this.form.leave_type_id) || null;
  }

  get isHourlyExcuse(): boolean {
    return this.selectedType?.is_hourly === true;
  }

  get isBusinessExcuse(): boolean {
    return this.isHourlyExcuse;
  }

  get isAnnualLeave(): boolean {
    const t = this.selectedType;
    if (!t) return false;
    // Use is_annual flag if set, otherwise detect by name
    return t.is_annual === true || (t.name || '').toLowerCase().includes('annual');
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.fileError = '';
    if (!file) { this.selectedFile = null; return; }
    if (file.size > 5 * 1024 * 1024) {
      this.fileError = this.lang.t('LEAVE.FORM.ERR_FILE_SIZE'); this.selectedFile = null; return;
    }
    const allowed = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!allowed.includes(file.type)) {
      this.fileError = this.lang.t('LEAVE.FORM.ERR_FILE_TYPE'); this.selectedFile = null; return;
    }
    this.selectedFile = file;
  }

  excuseHoursPreview(): number {
    if (!this.form.start_time || !this.form.end_time) return 0;
    const [sh, sm] = this.form.start_time.split(':').map(Number);
    const [eh, em] = this.form.end_time.split(':').map(Number);
    const diff = (eh * 60 + em) - (sh * 60 + sm);
    return diff > 0 ? Math.round(diff / 60 * 100) / 100 : 0;
  }

  onHalfDayChange(): void {
    if (this.form.is_half_day) {
      // Sync end date to start date — half day is always one day
      if (this.form.start_date) this.form.end_date = this.form.start_date;
      if (!this.form.half_day_period) this.form.half_day_period = 'morning';
    }
    this.loadMyBalanceForFormDate();
    this.loadFormHolidays();
  }

  onLeaveTypeChange() {
    if (this.isHourlyExcuse) {
      this.loadExcuseUsage();
    } else {
      this.excuseUsage = null;
    }
    this.loadMyBalanceForFormDate();
  }

  loadExcuseUsage() {
    const user = JSON.parse(sessionStorage.getItem('hrms_user') || localStorage.getItem('hrms_user') || '{}');
    const empId = user?.employee?.id || user?.employee_id;
    if (!empId) return;
    this.loadingUsage = true;
    const now = new Date();
    this.http.get<any>('/api/v1/leave/excuse-usage', {
      params: { employee_id: empId, leave_type_id: this.form.leave_type_id, year: now.getFullYear(), month: now.getMonth() + 1 }
    }).subscribe({
      next: r => { this.excuseUsage = r; this.loadingUsage = false; },
      error: () => this.loadingUsage = false
    });
  }

  workingDaysPreview(): number {
    if (!this.form.start_date || !this.form.end_date) return 0;
    // Parse as local dates: `new Date('YYYY-MM-DD')` is UTC midnight and shifts the weekday/date by the timezone.
    const start = new Date(`${this.form.start_date}T00:00:00`);
    const end = new Date(`${this.form.end_date}T00:00:00`);
    if (end < start) return 0;
    let count = 0;
    const cur = new Date(start);
    const holidayDates = new Set((this.formHolidays || []).map(h => (h.date || '').slice(0, 10)));
    while (cur <= end) {
      const d = cur.getDay();
      const dateStr = this.dateInputValue(cur);
      if (d !== 5 && d !== 6 && !holidayDates.has(dateStr)) count++;
      cur.setDate(cur.getDate() + 1);
    }
    return count;
  }

  excuseUsagePercent(): number {
    if (!this.excuseUsage || this.excuseUsage.is_unlimited || !this.excuseUsage.limit_hours) return 0;
    return Math.min(100, (this.excuseUsage.used_hours / this.excuseUsage.limit_hours) * 100);
  }

  balancePct(b: any): number {
    const total = this.balanceTotalDays(b);
    if (!total) return 0;
    return Math.min(100, Math.round(((total - Number(b?.remaining_days || 0)) / total) * 100));
  }

  balanceColor(pct: number): string {
    if (pct >= 80) return 'var(--danger)';
    if (pct >= 50) return 'var(--warning)';
    return 'var(--success)';
  }

  isAnnualBalance(b: any): boolean {
    const code = String(b?.leave_type?.code || '').toUpperCase();
    const name = String(b?.leave_type?.name || '').toLowerCase();
    return b?.leave_type?.is_annual === true || code === 'AL' || name.includes('annual');
  }

  contractYearAllocation(b: any): number | null {
    if (!this.isAnnualBalance(b)) return null;
    const value = b?.contract_year_allocated_days ?? b?.annual_entitlement;
    return value === null || value === undefined || value === '' ? null : Number(value);
  }

  activeCarryForwardTotal(b: any): number {
    if (!this.isAnnualBalance(b)) return 0;
    return Number(b?.active_carried_forward_days || 0) + Number(b?.carry_forward_used_days || 0);
  }

  balanceTotalDays(b: any): number {
    const allocated = Number(b?.allocated_days || 0);
    return this.isAnnualBalance(b) ? allocated + this.activeCarryForwardTotal(b) : allocated;
  }

  carryForwardSummary(b: any): string {
    const included = this.activeCarryForwardTotal(b);
    if (!included) return '';

    const active = Number(b?.active_carried_forward_days || 0);
    const used = Number(b?.carry_forward_used_days || 0);
    return this.lang.t('LEAVE.BALANCE.CARRY_SUMMARY', { included, active, used });
  }

  selectedTypeBalance(): any {
    if (!this.form.leave_type_id) return null;
    const balances = this.showNewRequest ? this.formBalances : this.myBalances;
    return balances.find(b => b.leave_type_id == this.form.leave_type_id);
  }

  balanceHintLabel(): string {
    if (this.isAnnualLeave && this.balanceAsOfDate()) {
      return this.lang.t('LEAVE.FORM.AVAILABLE_UNTIL', { date: this.formatHolidayDate(this.balanceAsOfDate()) });
    }

    return this.lang.t('LEAVE.FORM.AVAILABLE');
  }

  private balanceAsOfDate(): string {
    if (!this.isAnnualLeave || this.isHourlyExcuse) return '';
    if (this.form.is_half_day && this.form.start_date) return this.form.start_date;
    if (this.form.end_date) return this.form.end_date;
    return this.dateInputValue(new Date());
  }

  private loadMyBalanceForFormDate(): void {
    this.loadFormBalance(this.balanceAsOfDate() || undefined);
  }

  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    const lastPage = this.pagination.last_page;
    const start = Math.max(1, Math.min(this.currentPage - 2, lastPage - 4));
    const end = Math.min(lastPage, start + 4);
    return Array.from({ length: Math.max(0, end - start + 1) }, (_, i) => start + i);
  }

  onTicketRequirementChange(): void {
    if (!this.form.requires_ticket) {
      this.form.ticket_dependent_ids = [];
      this.ticketOptions = null;
      return;
    }
    this.loadTicketOptions();
  }

  onLeaveStartDateChange(): void {
    if (this.form.is_half_day) this.form.end_date = this.form.start_date;
    if (this.form.requires_ticket) this.loadTicketOptions();
    this.loadMyBalanceForFormDate();
    this.loadFormHolidays();
  }

  onLeaveEndDateChange(): void {
    this.loadMyBalanceForFormDate();
    this.loadFormHolidays();
  }

  loadFormHolidays(): void {
    if (!this.form.start_date || !this.form.end_date) {
      this.formHolidays = [];
      return;
    }

    this.http.get<any>('/api/v1/leave/holidays', {
      params: { start_date: this.form.start_date, end_date: this.form.end_date }
    }).subscribe({
      next: r => this.formHolidays = r?.holidays || [],
      error: () => this.formHolidays = []
    });
  }

  loadTicketOptions(): void {
    const year = this.form.start_date ? new Date(`${this.form.start_date}T00:00:00`).getFullYear() : new Date().getFullYear();
    this.ticketOptionsLoading = true;
    this.http.get<any>('/api/v1/leave/ticket-options', { params: { year } }).subscribe({
      next: r => { this.ticketOptions = r.ticket_options; this.ticketOptionsLoading = false; },
      error: err => { this.ticketOptionsLoading = false; this.formError = err?.error?.message || this.lang.t('LEAVE.FORM.ERR_TICKET_LOAD'); }
    });
  }

  toggleTicketDependent(id: number): void {
    const selected = this.form.ticket_dependent_ids as number[];
    const index = selected.indexOf(id);
    if (index >= 0) selected.splice(index, 1);
    else if (selected.length < (this.ticketOptions?.max_dependents || 0)) selected.push(id);
  }

  get calMonthLabel(): string {
    return new Date(this.calYear, this.calMonth, 1).toLocaleString(this.intlLocale(), { month: 'long', year: 'numeric' });
  }

  /** Day codes; display text comes from LEAVE.CALENDAR.DOW.<code>. */
  get calWeekDays() { return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; }

  /** Intl locale for toLocale*String: Gregorian calendar and Western digits in Arabic too. */
  private intlLocale(): string {
    return this.lang.current() === 'ar' ? 'ar-SA-u-ca-gregory-nu-latn' : 'en';
  }

  get calendarScopeLabel(): string {
    if (this.calendarMeta?.scope === 'all') return this.lang.t('LEAVE.CALENDAR.SCOPE_ALL');
    const department = this.locName(this.calendarMeta?.department);
    return department
      ? this.lang.t('LEAVE.CALENDAR.SCOPE_DEPT', { department })
      : this.lang.t('LEAVE.CALENDAR.SCOPE_DEPT_DEFAULT');
  }

  get approvedCalendarCount(): number {
    return this.calendarEvents.length;
  }

  get selectedCalendarLeaves(): any[] {
    return this.selectedCalendarCell?.leaves || [];
  }

  canApprove(r: any): boolean {
    if (r?.can_approve === false) return false;
    if (this.isOwnRequest(r)) return false;
    if (r.status === 'pending' && this.isLeaveManagerApprover && !this.isHR) {
      return !!this.employeeId && String(r?.employee?.manager_id) === String(this.employeeId);
    }
    if (r.status === 'pending') return this.isLeaveManagerApprover;
    if (r.status === 'manager_approved') return this.isHR;
    return false;
  }

  canReject(r: any): boolean {
    if (r?.can_reject === false) return false;
    return this.canApprove(r);
  }

  isOwnRequest(r: any): boolean {
    const requestEmployeeName = `${r?.employee?.first_name || ''} ${r?.employee?.last_name || ''}`.trim().toLowerCase();
    return String(r?.employee_id) === String(this.employeeId)
      || String(r?.employee?.user_id) === String(this.userId)
      || (!!requestEmployeeName && requestEmployeeName === this.currentUserName);
  }

  canCancel(r: any): boolean {

    // HR can cancel anything the backend still allows to be cancelled
    if (this.isHR) {
      return ['pending', 'manager_approved', 'approved'].includes(r?.status);
    }

    // Employee can cancel only own pending requests
    const isOwner = r.employee_id === this.employeeId;

    return isOwner &&
      ['pending', 'pending_manager'].includes(r.status);
  }

  approveLabel(r: any): string {
    if (r.status === 'pending') return this.lang.t('LEAVE.APPROVE.MANAGER_LEVEL');
    if (r.status === 'manager_approved') return this.lang.t('LEAVE.APPROVE.HR_LEVEL');
    return this.lang.t('COMMON.APPROVE');
  }

  approveIcon(r: any): string {
    return r.status === 'pending' ? 'supervisor_account' : 'admin_panel_settings';
  }

  stageLabel(r: any): string {
    if (r.status === 'pending') return this.lang.t('LEAVE.STAGE.AWAITING_MANAGER');
    if (r.status === 'manager_approved') return this.lang.t('LEAVE.STAGE.AWAITING_HR');
    if (r.status === 'approved') return this.lang.t('STATUS.approved');
    if (r.status === 'rejected') {
      return this.lang.t('LEAVE.STAGE.REJECTED_AT', { stage: this.optionalLabel('LEAVE.STAGE.NAME', r.rejected_stage) });
    }
    return this.optionalLabel('STATUS', r.status);
  }

  statusCls(s: string): string {
    const m: Record<string, string> = {
      pending: 'badge-yellow',
      manager_approved: 'badge-blue',
      approved: 'badge-green',
      rejected: 'badge-red',
      cancelled: 'badge-gray',
    };
    return m[s] ?? 'badge-gray';
  }

  statusIcon(s: string): string {
    const m: Record<string, string> = {
      pending: 'pending_actions',
      manager_approved: 'supervisor_account',
      approved: 'check_circle',
      rejected: 'cancel',
      cancelled: 'block',
    };
    return m[s] ?? 'help';
  }

  activityIcon(event: string): string {
    const m: Record<string, string> = {
      submitted: 'send',
      updated: 'edit',
      manager_approved: 'supervisor_account',
      hr_approved: 'admin_panel_settings',
      manager_rejected: 'cancel',
      hr_rejected: 'cancel',
      cancelled: 'block',
    };
    return m[event] ?? 'history';
  }

  activityStatus(a: any): string {
    if (a.from_status && a.to_status) {
      return `${this.statusText(a.from_status)} ${this.lang.isRtl() ? '←' : '→'} ${this.statusText(a.to_status)}`;
    }
    if (a.to_status) return this.statusText(a.to_status);
    return '';
  }

  statusText(status: string): string {
    const m: Record<string, string> = {
      pending: 'LEAVE.STAGE.AWAITING_MANAGER',
      manager_approved: 'LEAVE.STAGE.AWAITING_HR',
    };
    return m[status] ? this.lang.t(m[status]) : this.optionalLabel('STATUS', status);
  }

  /** Translates `<prefix>.<code>` when the key exists, otherwise shows the code as-is. */
  optionalLabel(prefix: string, code: any): string {
    if (code === null || code === undefined || code === '') return '';
    const key = `${prefix}.${String(code).trim().toLowerCase().replace(/[\s-]+/g, '_')}`;
    const label = this.lang.t(key);
    return label !== key ? label : String(code);
  }

  /** Bilingual master-data name (name_ar when Arabic is active). */
  locName(record: any, field = 'name'): string {
    if (!record) return '';
    if (this.lang.current() === 'ar' && record[`${field}_ar`]) return record[`${field}_ar`];
    return record[field] || '';
  }

  /** Employee display name; prefers arabic_name in Arabic when present. */
  employeeName(e: any): string {
    if (!e) return '';
    if (this.lang.current() === 'ar' && e.arabic_name) return e.arabic_name;
    return `${e.first_name || ''} ${e.last_name || ''}`.trim();
  }

  passengerTypeLabel(type: string): string {
    return this.optionalLabel('LEAVE.PASSENGER_TYPE', type);
  }

  relationshipLabel(rel: string): string {
    const label = this.optionalLabel('LEAVE.RELATIONSHIP', rel);
    return label === rel ? String(rel || '').replace(/\b\w/g, c => c.toUpperCase()) : label;
  }

  avatarColor(name: string): string {
    const colors = ['#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#10b981', '#f59e0b', '#ef4444', '#0ea5e9'];
    const idx = (name?.charCodeAt(0) || 0) % colors.length;
    return colors[idx];
  }

  leaveTypeColor(name: string): string {
    const map: any = {
      'Annual Leave': '#10b981', 'Sick Leave': '#ef4444', 'Emergency': '#f59e0b',
      'Maternity': '#ec4899', 'Paternity': '#3b82f6', 'Unpaid': '#6b7280',
    };
    return map[name] || '#6366f1';
  }

  // ── Department Limits Panel ───────────────────────────────────────────
  openLimitsPanel(t: any) {
    this.limitsLeaveType = t;
    this.showLimitsPanel = true;
    this.limitsError = '';
    this.limitsDirty = false;
    this.loadDeptLimits(t.id);
  }

  loadDeptLimits(leaveTypeId: number) {
    this.limitsLoading = true;
    this.http.get<any>('/api/v1/leave/excuse-limits', { params: { leave_type_id: leaveTypeId } }).subscribe({
      next: r => {
        this.deptLimits = r?.limits || [];
        this.limitsLoading = false;
      },
      error: () => this.limitsLoading = false
    });
  }

  toggleDeptLimit(row: any) {
    row.is_limited = !row.is_limited;
    this.limitsDirty = true;
  }

  onLimitHoursChange(row: any) {
    this.limitsDirty = true;
  }

  saveLimits() {
    // Basic validation: limited rows must have a positive hours value
    for (const row of this.deptLimits) {
      if (row.is_limited && (!row.monthly_hours_limit || row.monthly_hours_limit <= 0)) {
        this.limitsError = this.lang.t('LEAVE.LIMITS.ERR_INVALID', { department: row.department_name });
        return;
      }
    }
    this.limitsSaving = true;
    this.limitsError = '';
    this.http.post('/api/v1/leave/excuse-limits/bulk', {
      leave_type_id: this.limitsLeaveType.id,
      limits: this.deptLimits.map(r => ({
        department_id: r.department_id,
        is_limited: r.is_limited,
        monthly_hours_limit: r.is_limited ? r.monthly_hours_limit : null,
      }))
    }).subscribe({
      next: () => {
        this.limitsSaving = false;
        this.limitsDirty = false;
        this.limitsError = '';
      },
      error: err => {
        this.limitsSaving = false;
        this.limitsError = err?.error?.message || this.lang.t('MESSAGES.SAVE_FAILED');
      }
    });
  }

  // ── Department Visibility Panel ───────────────────────────────────────
  openVisibilityPanel(t: any) {
    this.visibilityLeaveType = t;
    this.showVisibilityPanel = true;
    this.visibilityError = '';
    this.visibilityMessage = '';
    this.visibilityDirty = false;
    this.loadDeptVisibility(t.id);
  }

  loadDeptVisibility(leaveTypeId: number) {
    this.visibilityLoading = true;
    this.visibilityMessage = '';
    this.http.get<any>(`/api/v1/leave/types/${leaveTypeId}/visibility`).subscribe({
      next: r => {
        this.deptVisibility = r?.visibility || [];
        this.visibilityLoading = false;
      },
      error: () => this.visibilityLoading = false
    });
  }

  toggleDeptVisibility(row: any) {
    row.is_visible = !row.is_visible;
    this.visibilityMessage = '';
    this.visibilityDirty = true;
  }

  visibleDepartmentCount(): number {
    return this.deptVisibility.filter(r => r.is_visible).length;
  }

  hiddenDepartmentCount(): number {
    return this.deptVisibility.length - this.visibleDepartmentCount();
  }

  saveVisibility() {
    this.visibilitySaving = true;
    this.visibilityError = '';
    this.visibilityMessage = '';
    this.http.post(`/api/v1/leave/types/${this.visibilityLeaveType.id}/visibility`, {
      visibility: this.deptVisibility.map(r => ({
        department_id: r.department_id,
        is_visible: r.is_visible,
      }))
    }).subscribe({
      next: (res: any) => {
        this.visibilitySaving = false;
        this.visibilityDirty = false;
        this.visibilityError = '';
        this.visibilityMessage = res?.message || this.lang.t('LEAVE.VISIBILITY.SAVED');
        this.loadTypes();
      },
      error: err => {
        this.visibilitySaving = false;
        this.visibilityMessage = '';
        this.visibilityError = err?.error?.message || this.lang.t('MESSAGES.SAVE_FAILED');
      }
    });
  }
}
