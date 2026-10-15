import { Component, OnInit, OnDestroy } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  standalone:   false,
  selector:     'app-employee-detail',
  templateUrl:  './employee-detail.component.html',
  styleUrls:    ['./employee-detail.component.scss'],
})
export class EmployeeDetailComponent implements OnInit, OnDestroy {
  loading       = true;
  loadError     = '';
  employeeId: any = null;
  employee: any   = null;
  documents:    any[] = [];
  leaveBalance: any[] = [];
  annualBalanceToday: any = null;
  leaveBalanceLoading = false;
  leaveBalancePeriods: any[] = [];
  selectedLeavePeriod = '';
  selectedLeavePeriodLabel = '';
  selectedLeavePeriodIsCurrent = false;
  onboarding:    any[] = [];
  showTaskForm   = false;
  taskForm:  any = { title:'', category:'hr_documents', description:'', due_date:'', sort_order:0 };
  taskFormError  = '';
  taskSaving     = false;
  editTaskId: number | null = null;
  activeTab     = 'profile';
  dependents: any[] = [];
  dependentForm: any = this.blankDependent();
  dependentEditId: number | null = null;
  dependentSaving = false;
  dependentError = '';
  showDependentForm = false;
  dependentPassportFile: File | null = null;
  dependentIdFile: File | null = null;

  // ── Quick status change ───────────────────────────────────────────────
  statusSaving = false;
  statusMsg    = '';
  statusError  = '';

  /** Available status transitions shown as buttons in the hero section. */
  readonly statusOptions = [
    { value: 'active',     label: 'Active',     color: '#10b981' },
    { value: 'probation',  label: 'Probation',  color: '#f59e0b' },
    { value: 'on_leave',   label: 'On Leave',   color: '#6366f1' },
    { value: 'inactive',   label: 'Inactive',   color: '#8b949e' },
    { value: 'terminated', label: 'Terminated', color: '#ef4444' },
  ];

  // ── Attendance tab state ───────────────────────────────────────────────
  attendanceLogs:    any[]    = [];
  attendanceLoading  = false;
  assetsLoading     = false;
  employeeAssets:   any[]    = [];

  // ── Contracts tab ──────────────────────────────────────────────────────
  contracts:        any[]    = [];
  contractsLoading  = false;
  selectedContract: any      = null;
  showContractDetail = false;
  attendanceMonth    = new Date().getMonth() + 1;
  attendanceYear     = new Date().getFullYear();

  // Upload state
  showUploadForm = false;
  dragOver       = false;
  uploading      = false;
  uploadProgress = 0;
  uploadError    = '';
  uploadData: { title: string; type: string; expiry_date: string; file: File | null } = {
    title: '', type: '', expiry_date: '', file: null
  };

  tabs = [
    { id: 'profile',    label: 'Profile',      icon: 'person' },
    { id: 'documents',  label: 'Documents',    icon: 'folder' },
    { id: 'leave',      label: 'Leave Balance', icon: 'event_available' },
    { id: 'onboarding', label: 'Onboarding',   icon: 'checklist' },
    { id: 'attendance', label: 'Attendance',   icon: 'fingerprint' },
    { id: 'contracts',  label: 'Contracts',    icon: 'description' },
    { id: 'assets',     label: 'Assets',       icon: 'inventory_2' },
  ];

  months = [
    { v: 1, l: 'January' }, { v: 2, l: 'February' }, { v: 3, l: 'March' },
    { v: 4, l: 'April' },   { v: 5, l: 'May' },       { v: 6, l: 'June' },
    { v: 7, l: 'July' },    { v: 8, l: 'August' },    { v: 9, l: 'September' },
    { v: 10, l: 'October'},{ v: 11, l: 'November' }, { v: 12, l: 'December' },
  ];

  years = Array.from({ length: 3 }, (_, i) => new Date().getFullYear() - i);
  isHR = false;
  canViewSalary = false;

  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly route:  ActivatedRoute,
    private readonly router: Router,
    private readonly http:   HttpClient,
    private readonly auth:   AuthService,
  ) {}

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    this.employeeId = id;
    this.isHR = this.auth.isHRRole();
    this.canViewSalary = this.auth.hasAnyRole(['super_admin', 'ceo', 'hr_manager']);
    this.http.get<any>(`/api/v1/employees/${id}`)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: r => {
          this.employee     = r.employee || r;
          this.loading      = false;
          this.leaveBalance = [];
          this.onboarding   = this.employee?.onboarding_tasks || [];
          this.dependents   = this.employee?.dependents || [];
          const ownEmployeeId = this.auth.getUser()?.employee?.id || this.auth.getUser()?.employee_id;
          if (!this.isSaudiEmployee && (this.isHR || Number(ownEmployeeId) === Number(this.employeeId)) && !this.tabs.some(t => t.id === 'dependents')) {
            this.tabs.splice(2, 0, { id: 'dependents', label: 'Dependents', icon: 'family_restroom' });
          }
          this.loadDocuments();
        },
        error: err => {
          this.loading = false;
          if (err?.status === 0) {
            this.loadError = 'Cannot connect to server. Make sure the backend is running on port 8000.';
          } else if (err?.status === 404) {
            this.loadError = 'Employee not found (ID may be invalid).';
          } else {
            this.loadError = err?.error?.message || ('Server error ' + err?.status + '. Check Laravel logs.');
          }
        },
      });
  }

  onTabChange(tabId: string): void {
    this.activeTab = tabId;
    if (tabId === 'attendance' && !this.attendanceLogs.length) {
      this.loadAttendance();
    }
    if (tabId === 'contracts') {
      this.loadContracts();
    }
    if (tabId === 'leave' && !this.leaveBalancePeriods.length) {
      this.loadLeaveBalances();
      this.loadAnnualBalanceToday();
    }
    if (tabId === 'onboarding') {
      this.loadOnboarding();
    }
    if (tabId === 'assets') {
      this.loadEmployeeAssets();
    }
  }

    loadEmployeeAssets(): void {
    this.assetsLoading = true;
    this.http.get<any>(`/api/v1/assets/employee/${this.employeeId}`).subscribe({
      next:  r => { this.employeeAssets = r?.assets || []; this.assetsLoading = false; },
      error: () => { this.assetsLoading = false; },
    });
  }

  get isSaudiEmployee(): boolean {
    return ['saudi', 'saudi arabian', 'saudi arabia'].includes(String(this.employee?.nationality || '').trim().toLowerCase());
  }

  get totalMonthlySalary(): number {
    const basic = this.amount(this.employee?.salary);
    const housing = this.allowanceOrDefault(this.employee?.housing_allowance, basic * 0.25);
    const transport = this.allowanceOrDefault(this.employee?.transport_allowance, basic * 0.10);
    const mobile = this.amount(this.employee?.mobile_allowance);
    const food = this.amount(this.employee?.food_allowance);
    const other = this.amount(this.employee?.other_allowances);

    return Math.round((basic + housing + transport + mobile + food + other) * 100) / 100;
  }

  private allowanceOrDefault(value: unknown, defaultValue: number): number {
    return value === null || value === undefined || value === ''
      ? Math.round(defaultValue * 100) / 100
      : this.amount(value);
  }

  private amount(value: unknown): number {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  private blankDependent(): any {
    return { full_name: '', relationship: 'spouse', date_of_birth: '', nationality: '', id_number: '', id_expiry: '', passport_number: '', passport_expiry: '', is_active: true };
  }

  openDependentForm(dependent?: any): void {
    this.dependentEditId = dependent?.id || null;
    this.dependentForm = dependent ? {
      ...dependent,
      date_of_birth: dependent.date_of_birth?.substring(0, 10) || '',
      id_expiry: dependent.id_expiry?.substring(0, 10) || '',
      passport_expiry: dependent.passport_expiry?.substring(0, 10) || '',
    } : this.blankDependent();
    this.dependentPassportFile = null;
    this.dependentIdFile = null;
    this.dependentError = ''; this.showDependentForm = true;
  }

  saveDependent(): void {
    if (!this.dependentForm.full_name?.trim()) { this.dependentError = 'Dependent name is required.'; return; }
    if (!this.dependentForm.id_number?.trim()) { this.dependentError = 'Iqama / ID number is required.'; return; }
    this.dependentSaving = true; this.dependentError = '';
    const formData = new FormData();
    Object.entries(this.dependentForm).forEach(([key, value]) => {
      if (value !== null && value !== undefined) formData.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    });
    if (this.dependentPassportFile) formData.append('passport_file', this.dependentPassportFile);
    if (this.dependentIdFile) formData.append('id_file', this.dependentIdFile);
    if (this.dependentEditId) formData.append('_method', 'PUT');
    const request = this.dependentEditId
      ? this.http.post<any>(`/api/v1/employees/${this.employeeId}/dependents/${this.dependentEditId}`, formData)
      : this.http.post<any>(`/api/v1/employees/${this.employeeId}/dependents`, formData);
    request.subscribe({
      next: () => { this.dependentSaving = false; this.showDependentForm = false; this.loadDependents(); },
      error: err => {
        this.dependentSaving = false;
        const errors = err?.error?.errors;
        this.dependentError = errors ? String(Object.values(errors)[0]?.[0] || '') : (err?.error?.message || 'Could not save dependent.');
      }
    });
  }

  selectDependentFile(type: 'passport' | 'id', event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] || null;
    if (type === 'passport') this.dependentPassportFile = file;
    else this.dependentIdFile = file;
  }

  downloadDependentDocument(dependent: any, type: 'passport' | 'id'): void {
    this.http.get(`/api/v1/employees/${this.employeeId}/dependents/${dependent.id}/documents/${type}`, {
      responseType: 'blob', observe: 'response',
    }).subscribe({
      next: response => {
        const url = URL.createObjectURL(response.body as Blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = type === 'passport' ? dependent.passport_file_name : dependent.id_file_name;
        link.click();
        URL.revokeObjectURL(url);
      },
      error: () => this.dependentError = 'Could not download the document.',
    });
  }

  loadDependents(): void {
    this.http.get<any>(`/api/v1/employees/${this.employeeId}/dependents`).subscribe({ next: r => this.dependents = r.dependents || [] });
  }

  deleteDependent(dependent: any): void {
    if (!confirm(`Delete ${dependent.full_name}?`)) return;
    this.http.delete(`/api/v1/employees/${this.employeeId}/dependents/${dependent.id}`).subscribe({ next: () => this.loadDependents() });
  }

  // ── Attendance ────────────────────────────────────────────────────────

  loadAttendance(): void {
    this.attendanceLoading = true;
    const params: any = { month: this.attendanceMonth, year: this.attendanceYear };
    this.http.get<any>(`/api/v1/attendance/employee/${this.employeeId}`, { params })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: res => {
          this.attendanceLogs    = res.data ?? res ?? [];
          this.attendanceLoading = false;
        },
        error: () => { this.attendanceLoading = false; },
      });
  }

  onAttendanceFilterChange(): void { this.loadAttendance(); }

  // ── Contracts ─────────────────────────────────────────────────────────

  loadContracts(): void {
    this.contractsLoading = true;
    this.http.get<any>(`/api/v1/employees/${this.employeeId}/contracts`)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next:  r => { this.contracts = r.contracts ?? []; this.contractsLoading = false; },
        error: () => { this.contractsLoading = false; },
      });
  }

  viewContract(c: any): void {
    this.selectedContract  = c;
    this.showContractDetail = true;
  }

  contractTypeLabel(type: string): string {
    const m: Record<string,string> = {
      full_time: 'Full Time', part_time: 'Part Time', contract: 'Contract',
      intern: 'Intern', probation: 'Probation', fixed_term: 'Fixed Term', unlimited: 'Unlimited',
    };
    return m[type] ?? type;
  }

  contractStatusClass(status: string): string {
    const m: Record<string,string> = {
      active: 'badge-green', draft: 'badge-gray',
      expired: 'badge-yellow', terminated: 'badge-red', cancelled: 'badge-gray',
    };
    return m[status] ?? 'badge-gray';
  }

  formatDate(d: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  formatSalary(s: number | null, cur = 'SAR'): string {
    if (!s) return '—';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(s);
  }

  formatMins(mins: number | null): string {
    if (!mins) return '—';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
  }

  attendanceStatusClass(status: string): string {
    const map: Record<string, string> = {
      present:  'badge-green',
      absent:   'badge-red',
      late:     'badge-yellow',
      half_day: 'badge-orange',
    };
    return map[status] ?? 'badge-gray';
  }

  // ── Documents ─────────────────────────────────────────────────────────

  loadDocuments(): void {
    this.http.get<any>(`/api/v1/employees/${this.employeeId}/documents`)
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: d => this.documents = d?.documents || [], error: () => {} });
  }

  edit(): void { this.router.navigate(['/employees', this.employee?.id, 'edit']); }
  back(): void { this.router.navigate(['/employees']); }

  initial(n?: string): string {
    return n?.split(' ').map((w: string) => w[0]).join('').toUpperCase().slice(0, 2) || '?';
  }
  avCol(n?: string): string {
    const c = ['#3b82f6','#10b981','#f59e0b','#ef4444','#6366f1','#0ea5e9','#f97316','#a78bfa'];
    return c[(n?.charCodeAt(0) ?? 0) % c.length];
  }
  statusCls(s: string): string {
    return ({ active:'badge-green', on_leave:'badge-yellow', probation:'badge-blue',
              inactive:'badge-gray', terminated:'badge-red' } as any)[s] || 'badge-gray';
  }
  taskCls(s: string): string {
    return ({ completed:'badge-green', in_progress:'badge-blue', pending:'badge-yellow',
              overdue:'badge-red' } as any)[s] || 'badge-gray';
  }
  pctLeave(alloc: any): number {
    if (!alloc?.allocated_days) return 0;
    return Math.min(100, Math.round((alloc.used_days / alloc.allocated_days) * 100));
  }

  // ── Carry forward (HR manager / system admin, active employees, current period) ──
  canSetCarryForward = false;
  showCarryForwardModal = false;
  cfLoading = false;
  cfSaving = false;
  cfError = '';
  cfNotice = '';
  cfPeriodLabel = '';
  cfPeriodStart = '';
  cfCurrentValue: number | null = null;
  cfValue: number | null = null;
  cfOverridden = false;
  cfOverriddenBy = '';
  cfOverriddenAt = '';

  /** Button visibility. The server re-checks both conditions before it writes anything. */
  get canEditCarryForward(): boolean {
    return this.employee?.status === 'active'
      && this.auth.hasAnyRole(['super_admin', 'ceo', 'hr_manager']);
  }

  openCarryForwardModal(): void {
    this.showCarryForwardModal = true;
    this.cfLoading = true;
    this.cfError = '';
    this.cfNotice = '';
    this.cfValue = null;
    this.cfCurrentValue = null;
    this.cfOverridden = false;
    this.cfOverriddenBy = '';
    this.cfOverriddenAt = '';

    // No period_start, so the API answers for the employee's current contract period.
    this.http.get<any>(`/api/v1/employees/${this.employeeId}/leave-balances`).subscribe({
      next: r => {
        const annual = (r?.balances || []).find((b: any) => this.isAnnualLeaveBalance(b));
        this.canSetCarryForward = !!r?.can_set_carry_forward;
        this.cfPeriodLabel = r?.selected_period?.label || '';
        this.cfPeriodStart = r?.selected_period?.start_date || '';

        if (!annual) {
          this.cfNotice = 'This employee has no annual leave allocation for the current contract period yet.';
        } else {
          this.cfCurrentValue = Number(annual.carried_forward_days || 0);
          this.cfValue = this.cfCurrentValue;
          this.cfOverridden = !!annual.carry_forward_overridden;
          this.cfOverriddenBy = annual.carry_forward_overridden_by_name || '';
          this.cfOverriddenAt = annual.carry_forward_overridden_at || '';
        }

        if (!this.canSetCarryForward) {
          this.cfNotice = 'Only an HR manager or system admin can change this, and only for active employees.';
        }
        this.cfLoading = false;
      },
      error: err => {
        this.cfError = err?.error?.message || 'Could not load the current carry forward value.';
        this.cfLoading = false;
      }
    });
  }

  closeCarryForwardModal(): void {
    if (this.cfSaving) return;
    this.showCarryForwardModal = false;
  }

  saveCarryForward(clear = false): void {
    if (this.cfSaving || !this.canSetCarryForward || !this.cfPeriodStart) return;
    if (!clear && (this.cfValue === null || isNaN(Number(this.cfValue))
        || Number(this.cfValue) < 0 || Number(this.cfValue) > 999.9)) {
      this.cfError = 'Enter a number of days from 0 to 999.9.';
      return;
    }
    if (clear && !confirm('Discard the value set by hand and go back to the calculated carry forward?')) return;

    this.cfSaving = true;
    this.cfError = '';
    this.http.put<any>(`/api/v1/employees/${this.employeeId}/leave-balances/carry-forward`, {
      period_start: this.cfPeriodStart,
      carried_forward_days: clear ? null : Number(this.cfValue),
    }).subscribe({
      next: () => {
        this.cfSaving = false;
        this.showCarryForwardModal = false;
        this.loadAnnualBalanceToday();
        if (this.activeTab === 'leave') this.loadLeaveBalances(this.selectedLeavePeriod);
      },
      error: err => {
        this.cfError = err?.error?.message
          || err?.error?.errors?.carried_forward_days?.[0]
          || 'Could not update carry forward.';
        this.cfSaving = false;
      }
    });
  }

  isAnnualLeaveBalance(balance: any): boolean {
    const name = String(balance?.leave_type?.name || '').toLowerCase();
    const code = String(balance?.leave_type?.code || '').toLowerCase();
    return balance?.leave_type?.is_annual === true || code === 'al' || name.includes('annual');
  }

  annualLeavePeriodLabel(balance: any): string {
    const start = balance?.accrual_year_start ? new Date(`${balance.accrual_year_start}T00:00:00`) : null;
    const startYear = start && !Number.isNaN(start.getTime())
      ? start.getFullYear()
      : Number(balance?.year);

    return Number.isInteger(startYear) && startYear > 0
      ? `${startYear}-${startYear + 1}`
      : '—';
  }

  loadAnnualBalanceToday(): void {
    if (!this.employeeId) return;
    this.http.get<any>(`/api/v1/leave/balance/${this.employeeId}`, {
      params: { as_of: this.dateInputValue(new Date()) },
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: r => {
          const balances = r?.balances || [];
          this.annualBalanceToday = balances.find((b: any) => this.isAnnualLeaveBalance(b)) || null;
          this.syncCurrentAnnualBalance();
        },
        error: () => {
          this.annualBalanceToday = null;
        },
      });
  }

  loadLeaveBalances(periodStart = ''): void {
    if (!this.employeeId) return;
    this.leaveBalanceLoading = true;
    const params: any = {};
    const selected = periodStart || this.selectedLeavePeriod;
    if (selected) params.period_start = selected;

    this.http.get<any>(`/api/v1/employees/${this.employeeId}/leave-balances`, { params })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: r => {
          this.leaveBalance = r?.balances || [];
          this.leaveBalancePeriods = r?.periods || [];
          this.canSetCarryForward = !!r?.can_set_carry_forward;
          const fallbackPeriod = this.leaveBalancePeriods[0] || null;
          this.selectedLeavePeriod = r?.selected_period?.start_date || selected || fallbackPeriod?.start_date || '';
          this.selectedLeavePeriodLabel = r?.selected_period?.label || fallbackPeriod?.label || '';
          this.selectedLeavePeriodIsCurrent = Boolean(r?.selected_period?.is_current ?? fallbackPeriod?.is_current);
          this.syncCurrentAnnualBalance();
          this.leaveBalanceLoading = false;
        },
        error: () => {
          this.leaveBalance = [];
          this.leaveBalancePeriods = [];
          this.canSetCarryForward = false;
          this.selectedLeavePeriod = '';
          this.selectedLeavePeriodLabel = '';
          this.selectedLeavePeriodIsCurrent = false;
          this.leaveBalanceLoading = false;
        },
      });
  }

  private syncCurrentAnnualBalance(): void {
    if (!this.selectedLeavePeriodIsCurrent || !this.annualBalanceToday) return;

    this.leaveBalance = this.leaveBalance.map(balance =>
      this.isAnnualLeaveBalance(balance)
        ? { ...balance, ...this.annualBalanceToday, leave_type: balance.leave_type || this.annualBalanceToday.leave_type }
        : balance
    );
  }

  private dateInputValue(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }
  fmtSize(bytes: number): string {
    if (!bytes) return '—';
    if (bytes < 1024)        return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  onFileSelect(e: Event): void {
    const input = e.target as HTMLInputElement;
    if (input.files?.[0]) this.uploadData.file = input.files[0];
  }
  onDrop(e: DragEvent): void {
    e.preventDefault();
    this.dragOver = false;
    const file = e.dataTransfer?.files?.[0];
    if (file) this.uploadData.file = file;
  }

  submitUpload(): void {
    if (!this.uploadData.title) { this.uploadError = 'Document title is required.'; return; }
    if (!this.uploadData.type)  { this.uploadError = 'Document type is required.'; return; }
    if (!this.uploadData.file)  { this.uploadError = 'Please select a file to upload.'; return; }
    if (this.uploadData.file.size > 10 * 1024 * 1024) { this.uploadError = 'File must be under 10MB.'; return; }

    this.uploading      = true;
    this.uploadProgress = 0;
    this.uploadError    = '';

    const fd = new FormData();
    fd.append('title', this.uploadData.title);
    fd.append('type',  this.uploadData.type);
    fd.append('file',  this.uploadData.file);
    if (this.uploadData.expiry_date) fd.append('expiry_date', this.uploadData.expiry_date);

    const ivl = setInterval(() => {
      if (this.uploadProgress < 85) this.uploadProgress += 15;
    }, 200);

    this.http.post<any>(`/api/v1/employees/${this.employee.id}/documents`, fd)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: r => {
          clearInterval(ivl);
          this.uploadProgress = 100;
          setTimeout(() => {
            this.documents.unshift(r.document);
            this.uploading      = false;
            this.uploadProgress = 0;
            this.showUploadForm = false;
            this.uploadData     = { title: '', type: '', expiry_date: '', file: null };
          }, 400);
        },
        error: err => {
          clearInterval(ivl);
          this.uploading      = false;
          this.uploadProgress = 0;
          this.uploadError    = err?.error?.message || 'Upload failed. Please try again.';
        },
      });
  }

  deleteDoc(docId: number): void {
    if (!confirm('Delete this document? This cannot be undone.')) return;
    this.http.delete(`/api/v1/employees/${this.employee.id}/documents/${docId}`)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { this.documents = this.documents.filter(d => d.id !== docId); },
        error: () => {},
      });
  }

  approveDoc(doc: any): void {
    if (doc.verifying) return;
    doc.verifying = true;
    this.http.post<any>(`/api/v1/employees/${this.employee.id}/documents/${doc.id}/approve`, {})
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: response => {
          const index = this.documents.findIndex(item => item.id === doc.id);
          if (index >= 0) this.documents[index] = response.document;
        },
        error: err => {
          doc.verifying = false;
          this.uploadError = err?.error?.message || 'Could not approve the document.';
        },
      });
  }

  downloadDoc(doc: any): void {
    if (!this.employee?.id || !doc?.id) return;
    this.uploadError = '';
    this.http.get(`/api/v1/employees/${this.employee.id}/documents/${doc.id}/download`, {
      responseType: 'blob',
      observe: 'response',
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: response => {
          const blob = response.body as Blob;
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = doc.file_name || doc.title || 'employee-document';
          link.click();
          URL.revokeObjectURL(url);
        },
        error: err => {
          this.uploadError = err?.error?.message || 'Could not download document.';
        },
      });
  }

  docIcon(type: string): string {
    return ({ contract:'description', id:'badge', certificate:'workspace_premium',
              visa:'flight', passport:'import_contacts', medical:'medical_information',
              other:'attach_file' } as any)[type] || 'attach_file';
  }
  docIconBg(type: string): string {
    return ({ contract:'rgba(59,130,246,.1)', id:'rgba(167,139,250,.1)',
              certificate:'rgba(16,185,129,.1)', visa:'rgba(14,165,233,.1)',
              passport:'rgba(249,115,22,.1)', medical:'rgba(239,68,68,.1)',
              other:'rgba(139,148,158,.1)' } as any)[type] || 'rgba(139,148,158,.1)';
  }
  docIconColor(type: string): string {
    return ({ contract:'#3b82f6', id:'#a78bfa', certificate:'#10b981',
              visa:'#0ea5e9', passport:'#f97316', medical:'#ef4444',
              other:'#8b949e' } as any)[type] || '#8b949e';
  }

  isExpired(d: string): boolean     { return !!d && new Date(d) < new Date(); }
  isExpiringSoon(d: string): boolean {
    const diff = new Date(d).getTime() - Date.now();
    return diff > 0 && diff < 30 * 86400000;
  }

  /**
   * PATCH the employee's status via PUT /api/v1/employees/:id
   * Only sends the status field — all other fields are unchanged.
   */
  changeStatus(newStatus: string): void {
    if (!this.employee?.id) return;
    this.statusSaving = true;
    this.statusMsg    = '';
    this.statusError  = '';

    this.http.put<any>(`/api/v1/employees/${this.employee.id}`, { status: newStatus })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (res) => {
          this.employee.status = res.employee?.status ?? newStatus;
          this.statusMsg       = `Status updated to "${newStatus.replace('_', ' ')}"`;
          this.statusSaving    = false;
          // Reload stats strip on list if we navigate back
          setTimeout(() => { this.statusMsg = ''; }, 4000);
        },
        error: (err) => {
          this.statusError  = err?.error?.message ?? 'Failed to update status.';
          this.statusSaving = false;
          setTimeout(() => { this.statusError = ''; }, 5000);
        },
      });
  }

  // ── Onboarding tasks ─────────────────────────────────────────────────

  loadOnboarding(): void {
    this.http.get<any>(`/api/v1/onboarding/${this.employeeId}/tasks`)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: r => { this.onboarding = r.tasks ?? []; },
        error: () => {},
      });
  }

  openTaskForm(task?: any): void {
    this.editTaskId  = task?.id ?? null;
    this.taskFormError = '';
    if (task) {
      this.taskForm = {
        title:       task.title,
        category:    task.category,
        description: task.description ?? '',
        due_date:    task.due_date ?? '',
        sort_order:  task.sort_order ?? 0,
      };
    } else {
      this.taskForm = { title:'', category:'hr_documents', description:'', due_date:'', sort_order:0 };
    }
    this.showTaskForm = true;
  }

  saveTask(): void {
    if (!this.taskForm.title || !this.taskForm.category) {
      this.taskFormError = 'Title and category are required.'; return;
    }
    this.taskSaving = true; this.taskFormError = '';
    const req = this.editTaskId
      ? this.http.put<any>(`/api/v1/onboarding/tasks/${this.editTaskId}`, this.taskForm)
      : this.http.post<any>(`/api/v1/onboarding/${this.employeeId}/tasks`, this.taskForm);
    req.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.taskSaving = false; this.showTaskForm = false; this.loadOnboarding(); },
      error: (e: any) => { this.taskSaving = false; this.taskFormError = e?.error?.message ?? 'Save failed.'; },
    });
  }

  completeTask(taskId: number): void {
    this.http.post(`/api/v1/onboarding/tasks/${taskId}/complete`, {})
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => this.loadOnboarding(),
        error: () => {},
      });
  }

  updateTaskStatus(taskId: number, status: string): void {
    this.http.put(`/api/v1/onboarding/tasks/${taskId}`, { status })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => this.loadOnboarding(),
        error: () => {},
      });
  }

  deleteTask(taskId: number): void {
    if (!confirm('Delete this task?')) return;
    this.http.delete(`/api/v1/onboarding/tasks/${taskId}`)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => this.loadOnboarding(),
        error: () => {},
      });
  }

  onboardingProgress(): number {
    if (!this.onboarding.length) return 0;
    const done = this.onboarding.filter(t => t.status === 'completed').length;
    return Math.round((done / this.onboarding.length) * 100);
  }

  completedTaskCount(): number {
    return this.onboarding.filter(t => t.status === 'completed').length;
  }

  taskStatusColor(s: string): string {
    return ({pending:'var(--text3)',in_progress:'var(--accent)',completed:'var(--success)',skipped:'var(--text3)'} as any)[s] ?? 'var(--text3)';
  }

  displayText(value: any, fallback = '-'): string {
    if (value === null || value === undefined) return fallback;
    const text = String(value).trim();
    if (!text || this.isBrokenPlaceholder(text)) return fallback;
    return text;
  }

  displayLabel(value: any): string {
    const text = this.displayText(value);
    return text === '-' ? text : text.replace(/_/g, ' ');
  }

  addressText(): string {
    const parts = [this.employee?.address, this.employee?.city, this.employee?.country]
      .map(v => this.displayText(v, ''))
      .filter(Boolean);
    return parts.length ? parts.join(', ') : '-';
  }

  maskedBankAccount(): string {
    const account = this.displayText(this.employee?.bank_account, '');
    if (!account) return '-';
    if (this.isHR) return account;
    const tail = account.slice(-4);
    return tail ? `**** **** ${tail}` : '**** ****';
  }

  taskTitle(task: any, index: number): string {
    const title = this.displayText(task?.title, '');
    if (title) return title;

    const fallbackTitles = [
      'Provide company laptop and accessories',
      'Create email and system accounts',
      'Prepare ID badge and access card',
      'Set up workstation and desk allocation',
      'Sign employment contract',
      'Collect required personal documents',
      'Register bank and payroll details',
      'Complete mandatory compliance training',
      'Introduce to team and department',
      'Set up buddy or mentor',
      '30-day probation check-in',
      '90-day probation review',
    ];

    return fallbackTitles[index] ?? 'Onboarding task';
  }

  taskDescription(task: any): string {
    return this.displayText(task?.description, '');
  }

  isOD(date: string | null): boolean {
    if (!date) return false;
    const due = new Date(date);
    if (Number.isNaN(due.getTime())) return false;
    due.setHours(23, 59, 59, 999);
    return due.getTime() < Date.now();
  }

  private isBrokenPlaceholder(text: string): boolean {
    return ['â€”', 'Ã¢â‚¬â€', 'Ã¢â‚¬â€�', 'Â·', 'Ã‚Â·', 'â—â—â—â—'].includes(text)
      || /^[ÂÃâ€�â€”“—\s]+$/.test(text);
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
