import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';

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
  typeForm: any = { name: '', code: '', days_allowed: 0, is_paid: true, carry_forward: false, max_carry_forward: 10, carry_forward_all: false, requires_document: false, description: '' };
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
  showMyRequestsTab = true;
  userId = '';
  employeeId = '';
  currentUserName = '';
  balanceColumns = ['employee', 'leave_type', 'allocated', 'carry_forward', 'used', 'pending', 'remaining', 'bar'];
  typeColumns = ['name', 'code', 'days', 'paid', 'carry', 'actions'];

  tabs = [
    { id: 'my_requests', label: 'My Requests', icon: 'person_pin' },
    { id: 'requests', label: 'Team Requests', icon: 'event_note' },
    { id: 'calendar', label: 'Calendar', icon: 'calendar_month' },
    { id: 'balances', label: 'Balances', icon: 'account_balance_wallet' },
    { id: 'types', label: 'Leave Types', icon: 'tune' },
    { id: 'holidays', label: 'Holidays', icon: 'star' },
  ];

  statusTabs = [
    { id: 'needs_action', label: 'Needs Action' },
    { id: 'pending', label: 'Awaiting Manager' },
    { id: 'manager_approved', label: 'Awaiting HR' },
    { id: 'approved', label: 'Approved' },
    { id: 'rejected', label: 'Rejected' },
    { id: 'cancelled', label: 'Cancelled' },
    { id: '', label: 'All' },
  ];

  constructor(private http: HttpClient, private auth: AuthService, private route: ActivatedRoute) { }

  ngOnInit() {
    const portalType = this.auth.getPortalType();
    this.showMyRequestsTab = portalType !== 'employee';
    this.tabs = this.tabs.map(tab =>
      tab.id === 'requests'
        ? { ...tab, label: portalType === 'employee' ? 'Requests' : 'Team Requests' }
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
    this.isHR = this.auth.isHRRole();
    this.canManageHolidays = this.auth.hasAnyRole(['super_admin', 'hr_manager', 'hr_staff']);
    this.isMgr = this.auth.isManagerRole() || this.auth.isFinanceManager();
    const user = this.auth.getUser();
    this.userId = user?.id ?? '';
    this.employeeId = user?.employee?.id ?? user?.employee_id ?? null;
    this.currentUserName = (user?.employee?.full_name || user?.name || '').trim().toLowerCase();
    this.typeColumns = this.isHR ? ['name', 'code', 'days', 'paid', 'carry', 'actions'] : ['name', 'code', 'days', 'paid', 'carry'];

    this.http.get<any>('/api/v1/leave/stats').subscribe({
      next: r => {
        this.stats = r;
        this.statItems = [
          { label: 'Pending Requests', value: r.pending_count, icon: 'pending_actions', color: '#f59e0b' },
          { label: 'On Leave Today', value: r.on_leave_today, icon: 'beach_access', color: '#6366f1' },
          { label: 'Approved This Month', value: r.approved_month, icon: 'check_circle', color: '#10b981' },
          { label: 'Cancelled', value: r.cancelled_count, icon: 'cancel', color: '#ef4444' },
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
    if (!confirm(`Approve ${r.total_days} day(s) leave for ${r.employee?.first_name}?`)) return;
    this.setActionLoading(r.id, 'approve');
    this.http.post(`/api/v1/leave/requests/${r.id}/approve`, {})
      .pipe(finalize(() => this.clearActionLoading()))
      .subscribe({
        next: () => { this.load(this.currentPage); this.loadStats(); this.loadMyBalance(); this.loadAnnualBalanceToday(); if (this.showDetail) this.showDetail = false; }
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
        error: err => { this.rejectError = err?.error?.message || 'Rejection failed. Please try again.'; }
      });
  }

  cancel(r: any) {
    if (this.isActionLoading()) return;
    if (!confirm('Cancel this leave request?')) return;
    this.setActionLoading(r.id, 'cancel');
    this.http.delete(`/api/v1/leave/requests/${r.id}`)
      .pipe(finalize(() => this.clearActionLoading()))
      .subscribe({
        next: () => { this.load(this.currentPage); this.loadStats(); this.loadMyBalance(); this.loadAnnualBalanceToday(); if (this.showDetail) this.showDetail = false; }
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
      this.formError = 'All fields are required.'; return;
    }
    if (!isExcuse && !this.form.end_date) {
      this.formError = 'End date is required.'; return;
    }
    const minChars = isExcuse ? 5 : 10;
    if (this.form.reason.length < minChars) {
      this.formError = `Reason must be at least ${minChars} characters.`; return;
    }
    // Check required document
    const lt = this.selectedType;
    if (lt?.requires_document && !this.selectedFile) {
      this.formError = `A supporting document is required for "${lt.name}" leave.`; return;
    }
    if (this.form.requires_ticket && this.ticketOptions?.already_used) {
      this.formError = `Your annual ticket entitlement for ${this.ticketOptions.year} has already been used.`; return;
    }
    if ((this.form.ticket_dependent_ids?.length || 0) > (this.ticketOptions?.max_dependents ?? 0)) {
      this.formError = `A maximum of ${this.ticketOptions.max_dependents} dependents can be selected.`; return;
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
      error: err => { this.submitting = false; this.formError = err?.error?.message || 'Submission failed.'; }
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
      this.typeForm = { name: '', code: '', days_allowed: 0, is_paid: true, carry_forward: false, max_carry_forward: 10, carry_forward_all: false, requires_document: false, description: '', skip_manager_approval: false };
    }
    this.typeError = '';
    this.showTypeForm = true;
  }

  saveType() {
    if (!this.typeForm.name || !this.typeForm.code) { this.typeError = 'Name and code are required.'; return; }
    if (!this.typeForm.carry_forward_all && (this.typeForm.max_carry_forward == null || !Number.isInteger(this.typeForm.max_carry_forward) || this.typeForm.max_carry_forward < 0 || this.typeForm.max_carry_forward > 65535)) {
      this.typeError = 'Enter a whole number from 0 to 65535 for maximum carry forward days.'; return;
    }
    if (this.typeForm.carry_forward_all) this.typeForm.max_carry_forward = 0;
    this.typeSaving = true; this.typeError = '';
    const req = this.typeEditId
      ? this.http.put(`/api/v1/leave/types/${this.typeEditId}`, this.typeForm)
      : this.http.post('/api/v1/leave/types', this.typeForm);
    req.subscribe({
      next: () => { this.typeSaving = false; this.showTypeForm = false; this.loadTypes(); },
      error: err => { this.typeSaving = false; this.typeError = err?.error?.message || 'Save failed.'; }
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
      const dow = new Date(dateStr).getDay();
      const isWeekend = dow === 5 || dow === 6; // Fri=5, Sat=6
      const holiday = this.holidays.find(h => h.date?.slice(0, 10) === dateStr);
      const leaves = this.calendarEvents.filter(e => e.start_date?.slice(0, 10) <= dateStr && e.end_date?.slice(0, 10) >= dateStr);
      const isToday = dateStr === new Date().toISOString().slice(0, 10);
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
    return new Date(year, month - 1, day).toLocaleDateString('en', options);
  }

  closeHolidayForm() {
    this.showHolidayForm = false;
    this.holidayEditId = null;
    this.holidayError = '';
  }

  saveHoliday() {
    if (!this.holidayForm.name || !this.holidayForm.date) {
      this.holidayError = 'Holiday name and date are required.';
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
        this.holidayError = err?.error?.message || 'Holiday save failed.';
      }
    });
  }

  deleteHoliday(id: number) {
    if (!confirm('Delete this holiday?')) return;
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
      this.fileError = 'File must be under 5 MB.'; this.selectedFile = null; return;
    }
    const allowed = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!allowed.includes(file.type)) {
      this.fileError = 'Only PDF, JPG, or PNG files are allowed.'; this.selectedFile = null; return;
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
    const start = new Date(this.form.start_date);
    const end = new Date(this.form.end_date);
    if (end < start) return 0;
    let count = 0;
    const cur = new Date(start);
    const holidayDates = new Set((this.formHolidays || []).map(h => (h.date || '').slice(0, 10)));
    while (cur <= end) {
      const d = cur.getDay();
      const dateStr = cur.toISOString().slice(0, 10);
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
    return `Carry forward included ${included}d: active ${active}d, used ${used}d`;
  }

  selectedTypeBalance(): any {
    if (!this.form.leave_type_id) return null;
    const balances = this.showNewRequest ? this.formBalances : this.myBalances;
    return balances.find(b => b.leave_type_id == this.form.leave_type_id);
  }

  balanceHintLabel(): string {
    if (this.isAnnualLeave && this.balanceAsOfDate()) {
      return `Available until ${this.balanceAsOfDate()}`;
    }

    return 'Available';
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
      error: err => { this.ticketOptionsLoading = false; this.formError = err?.error?.message || 'Could not load ticket entitlement.'; }
    });
  }

  toggleTicketDependent(id: number): void {
    const selected = this.form.ticket_dependent_ids as number[];
    const index = selected.indexOf(id);
    if (index >= 0) selected.splice(index, 1);
    else if (selected.length < (this.ticketOptions?.max_dependents || 0)) selected.push(id);
  }

  get calMonthLabel(): string {
    return new Date(this.calYear, this.calMonth, 1).toLocaleString('en', { month: 'long', year: 'numeric' });
  }

  get calWeekDays() { return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']; }

  get calendarScopeLabel(): string {
    if (this.calendarMeta?.scope === 'all') return 'All approved employee leaves';
    return this.calendarMeta?.department?.name
      ? `${this.calendarMeta.department.name} approved leaves`
      : 'Department approved leaves';
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
    if (r.status === 'pending' && this.isMgr && !this.isHR) {
      return !!this.employeeId && String(r?.employee?.manager_id) === String(this.employeeId);
    }
    if (r.status === 'pending') return this.isMgr;
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

    // HR can always cancel
    if (this.isHR) {
      return true;
    }

    // Employee can cancel only own pending requests
    const isOwner = r.employee_id === this.employeeId;

    return isOwner &&
      ['pending', 'pending_manager'].includes(r.status);
  }

  approveLabel(r: any): string {
    if (r.status === 'pending') return 'Approve (Manager Level)';
    if (r.status === 'manager_approved') return 'Approve (HR Level)';
    return 'Approve';
  }

  approveIcon(r: any): string {
    return r.status === 'pending' ? 'supervisor_account' : 'admin_panel_settings';
  }

  stageLabel(r: any): string {
    if (r.status === 'pending') return 'Awaiting Manager';
    if (r.status === 'manager_approved') return 'Awaiting HR';
    if (r.status === 'approved') return 'Approved';
    if (r.status === 'rejected') return `Rejected (${r.rejected_stage ?? ''})`;
    return r.status;
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
      return `${this.statusText(a.from_status)} -> ${this.statusText(a.to_status)}`;
    }
    if (a.to_status) return this.statusText(a.to_status);
    return '';
  }

  statusText(status: string): string {
    const m: Record<string, string> = {
      pending: 'Awaiting Manager',
      manager_approved: 'Awaiting HR',
      approved: 'Approved',
      rejected: 'Rejected',
      cancelled: 'Cancelled',
    };
    return m[status] ?? status;
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
        this.limitsError = `Please set a valid hour limit for ${row.department_name}.`;
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
        this.limitsError = err?.error?.message || 'Save failed.';
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
        this.visibilityMessage = res?.message || 'Department visibility saved successfully.';
        this.loadTypes();
      },
      error: err => {
        this.visibilitySaving = false;
        this.visibilityMessage = '';
        this.visibilityError = err?.error?.message || 'Save failed.';
      }
    });
  }
}
