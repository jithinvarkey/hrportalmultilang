import {
  Component, OnInit, OnDestroy,
  ChangeDetectionStrategy, ChangeDetectorRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Subject, interval } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { AuthService } from '../../../core/services/auth.service';
import { ActivatedRoute, Router } from '@angular/router';

export interface AttendanceLog {
  id:            number;
  employee_id:   number;
  date:          string;
  check_in:      string | null;
  check_out:     string | null;
  total_minutes: number | null;
  status:        string;
  source:        string;
  has_active_leave?: boolean | number | null;
  notes?:        string;
}

export interface AttendanceSettings {
  work_start:          string;   // HH:MM
  late_after_minutes:  number;
  half_day_hours:      number;
  full_day_hours:      number;
  grace_minutes:       number;
  weekend_days:        number[];
}

@Component({
  standalone:      false,
  selector:        'app-attendance',
  templateUrl:     './attendance.component.html',
  styleUrls:       ['./attendance.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AttendanceComponent implements OnInit, OnDestroy {

  // ── State ─────────────────────────────────────────────────────────────
  todayLog:    AttendanceLog | null = null;
  reportRows:  any[] = [];
  reportPagination: any = null;
  reportPage = 1;
  reportPerPage = 25;
  departments: any[] = [];

  loading       = true;
  reportLoading = false;
  checkingIn    = false;
  checkingOut   = false;
  errorMsg      = '';
  successMsg    = '';
  clock         = '';

  // ── Views ─────────────────────────────────────────────────────────────
  isHR             = false;
  isAdmin   = false;
  isMgr = false;
  currentEmployeeId: number | null = null;
  activeTab: 'log' | 'mine' | 'manual' | 'settings' = 'log';
  showSettings     = false;
  showManualEntry  = false;
  editingRow: any  = null;   // the row being inline-edited
  savingEdit       = false;
  savingManual     = false;
  settingsSaving   = false;
  settingsSaved    = false;

  // ── Settings ──────────────────────────────────────────────────────────
  settings: AttendanceSettings = {
    work_start:         '08:00',
    late_after_minutes: 15,
    half_day_hours:     4,
    full_day_hours:     8,
    grace_minutes:      5,
    weekend_days:       [5, 6],
  };
  settingsForm!: FormGroup;

  // ── Filters ───────────────────────────────────────────────────────────
  filterForm!:   FormGroup;
  manualForm!:   FormGroup;
  editForm!:     FormGroup;

  readonly statusOptions = [
    { value: 'present',  label: 'Present',  color: '#10b981' },
    { value: 'late',     label: 'Late',     color: '#f59e0b' },
    { value: 'absent',   label: 'Absent',   color: '#ef4444' },
    { value: 'half_day', label: 'Half Day', color: '#fb923c' },
    { value: 'on_leave', label: 'On Leave', color: '#6366f1' },
    { value: 'holiday',  label: 'Holiday',  color: '#8b949e' },
  ];

  readonly weekDays = [
    { value: 0, label: 'Sun' }, { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' }, { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' }, { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
  ];

  private readonly api      = '/api/v1/attendance';
  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly http:  HttpClient,
    private readonly fb:    FormBuilder,
    private readonly auth:  AuthService,
    private readonly cdr:   ChangeDetectorRef,
    private readonly route: ActivatedRoute,
    private readonly router: Router,
  ) {}

  ngOnInit(): void {
    // Multi-strategy role check — guards against Collection serialisation issues
    // from older login tokens where roles may be {"0":"super_admin"} instead of ["super_admin"]
    const user = this.auth.getUser();
    this.currentEmployeeId = Number(user?.employee?.id ?? user?.employee_id) || null;
const toArr = (v: any): string[] => !v ? [] : Array.isArray(v) ? v : Object.values(v);
const roleValues = toArr(user?.roles);
const permValues = toArr(user?.permissions);
const rawUser = JSON.stringify(user ?? {});
this.isMgr = ['department_manager'].some((r:string) => roleValues.includes(r) || rawUser.includes(r));
this.isHR = this.auth.isHRRole();

this.isAdmin = this.auth.isAdminRole();

    this.filterForm = this.fb.group({
      date_from:     [this.firstOfMonth()],
      date_to:       [this.todayStr()],
      department_id: [''],
      status:        [''],
      employee_id:   [''],
    });
    this.filterForm.get('department_id')!.valueChanges
      .pipe(takeUntil(this.destroy$))
      .subscribe(deptId => this.onFilterDepartmentChange(deptId));

    this.settingsForm = this.fb.group({
      work_start:         ['08:00', Validators.required],
      late_after_minutes: [15,  [Validators.required, Validators.min(0), Validators.max(120)]],
      half_day_hours:     [4,   [Validators.required, Validators.min(1), Validators.max(12)]],
      full_day_hours:     [8,   [Validators.required, Validators.min(4), Validators.max(24)]],
      grace_minutes:      [5,   [Validators.required, Validators.min(0), Validators.max(60)]],
      weekend_days:       [[5, 6]],
    });

    this.manualForm = this.fb.group({
      employee_id: ['', Validators.required],
      date:        [this.todayStr(), Validators.required],
      check_in:    [''],
      check_out:   [''],
      status:      ['present', Validators.required],
      notes:       [''],
    });

    this.editForm = this.fb.group({
      check_in:  [''],
      check_out: [''],
      status:    ['', Validators.required],
      notes:     [''],
    });

    // Open specific tab via query param (e.g. ?tab=manual from dashboard)
    this.route.queryParams.pipe(takeUntil(this.destroy$)).subscribe(p => {
      if (p['tab'] && ['log','mine','manual','settings'].includes(p['tab'])) {
        this.activeTab = p['tab'] as any;
        this.cdr.markForCheck();
      }
    });

    this.loadToday();
    this.loadReport();
    this.loadSettings();
    if (this.isHR) {
      this.loadDepartments();
    }
    this.loadEmployees(); // needed for manual form and HR employee filter

    interval(1000).pipe(takeUntil(this.destroy$)).subscribe(() => {
      this.clock = new Date().toLocaleTimeString('en-GB', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        timeZone: 'Asia/Riyadh',
      });
      this.cdr.markForCheck();
    });
  }

  // ── Today ──────────────────────────────────────────────────────────────

  loadToday(): void {
    this.loading = true;
    this.http.get<any>(`${this.api}/today`).pipe(takeUntil(this.destroy$)).subscribe({
      next:  (r) => { this.todayLog = r.log ?? null; this.loading = false; this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  checkIn(): void {
    this.checkingIn = true; this.clearMessages();
    this.http.post<any>(`${this.api}/checkin`, {}).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => {
        this.todayLog   = r.log;
        this.successMsg = `Checked in at ${r.log?.check_in}`;
        this.checkingIn = false;
        this.loadReport(); this.cdr.markForCheck();
      },
      error: (err) => {
        if (err?.error?.log) this.todayLog = err.error.log;
        this.errorMsg   = err?.error?.message ?? 'Check-in failed.';
        this.checkingIn = false; this.cdr.markForCheck();
      },
    });
  }

  checkOut(): void {
    this.checkingOut = true; this.clearMessages();
    this.http.post<any>(`${this.api}/checkout`, {}).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => {
        this.todayLog    = r.log;
        this.successMsg  = `Checked out at ${r.log?.check_out} · ${this.fmt(r.log?.total_minutes)}`;
        this.checkingOut = false;
        this.loadReport(); this.cdr.markForCheck();
      },
      error: (err) => {
        if (err?.error?.log) this.todayLog = err.error.log;
        this.errorMsg    = err?.error?.message ?? 'Check-out failed.';
        this.checkingOut = false; this.cdr.markForCheck();
      },
    });
  }

  // ── Report ─────────────────────────────────────────────────────────────

  loadReport(page = this.reportPage): void {
    this.reportLoading = true;
    this.reportPage = page;
    const mineScope = this.isMgr && !this.isHR && this.activeTab === 'mine';
    const params = this.clean({
      ...this.filterForm.value,
      ...(mineScope ? { employee_id: '' } : {}),
      scope: mineScope ? 'mine' : 'team',
      page,
      per_page: this.reportPerPage,
    });
    this.http.get<any>(`${this.api}/report`, { params })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next:  (r) => {
          this.reportRows = r.data ?? [];
          this.reportPagination = r;
          this.reportPage = r.current_page ?? page;
          this.reportLoading = false;
          this.cdr.markForCheck();
        },
        error: () => { this.reportLoading = false; this.cdr.markForCheck(); },
      });
  }

  applyFilters(): void { this.loadReport(1); }

  switchTab(tab: 'log' | 'mine' | 'manual' | 'settings'): void {
    this.activeTab = tab;
    this.editingRow = null;
    if (tab === 'log' || tab === 'mine') {
      this.loadReport(1);
    }
    this.cdr.markForCheck();
  }

  changeReportPage(page: number): void {
    const lastPage = this.reportPagination?.last_page ?? 1;
    if (page < 1 || page > lastPage || page === this.reportPage || this.reportLoading) return;
    this.editingRow = null;
    this.loadReport(page);
  }

  changeReportPageSize(): void {
    this.loadReport(1);
  }

  get reportPages(): number[] {
    const lastPage = this.reportPagination?.last_page ?? 1;
    const start = Math.max(1, Math.min(this.reportPage - 2, lastPage - 4));
    const end = Math.min(lastPage, start + 4);
    return Array.from({ length: Math.max(0, end - start + 1) }, (_, index) => start + index);
  }

  allEmployees: any[] = [];

  loadEmployees(): void {
    // Direct managers (non-HR) can only filter by the employees who report to them
    const url = this.isMgr && !this.isHR
      ? `${this.api}/direct-reports`
      : '/api/v1/employees?per_page=500';
    this.http.get<any>(url).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => {
        this.allEmployees = r?.data || [];
        this.refreshEmployeeOptions();
        this.cdr.markForCheck();
      },
    });
  }

  // ── Employee filter (department-aware, searchable) ─────────────────────
  filterEmployeeOptions: any[] = [];
  empSearch     = '';
  empSearchOpen = false;

  get selectedEmployeeLabel(): string {
    const id = this.filterForm?.value.employee_id;
    if (!id) return '';
    const e = this.allEmployees.find(x => String(x.id) === String(id));
    return e ? `${e.first_name} ${e.last_name}` : '';
  }

  refreshEmployeeOptions(deptId = this.filterForm?.value.department_id): void {
    const term   = this.empSearch.trim().toLowerCase();
    this.filterEmployeeOptions = this.allEmployees.filter(e => {
      if (!['active', 'probation'].includes(e.status)) return false;
      if (deptId && String(e.department_id) !== String(deptId)) return false;
      if (!term) return true;
      return `${e.first_name ?? ''} ${e.last_name ?? ''} ${e.employee_code ?? ''}`
        .toLowerCase().includes(term);
    });
  }

  openEmpSearch(): void {
    this.empSearch = '';
    this.empSearchOpen = true;
    this.refreshEmployeeOptions();
  }

  onEmpSearch(value: string): void {
    this.empSearch = value;
    this.refreshEmployeeOptions();
  }

  closeEmpSearch(): void {
    this.empSearchOpen = false;
    this.empSearch = '';
  }

  selectFilterEmployee(e: any | null): void {
    this.filterForm.patchValue({ employee_id: e ? e.id : '' });
    this.closeEmpSearch();
    (document.activeElement as HTMLElement | null)?.blur();
  }

  private onFilterDepartmentChange(deptId: any): void {
    this.refreshEmployeeOptions(deptId);
    // Clear the selected employee if they don't belong to the new department
    const empId = this.filterForm.value.employee_id;
    if (empId && !this.filterEmployeeOptions.some(e => String(e.id) === String(empId))) {
      this.filterForm.patchValue({ employee_id: '' }, { emitEvent: false });
    }
    this.cdr.markForCheck();
  }

  loadDepartments(): void {
    this.http.get<any>('/api/v1/departments').pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => { this.departments = r?.data ?? r ?? []; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  // ── Edit record ────────────────────────────────────────────────────────

  openEdit(row: any): void {
    this.editingRow = row;
    this.editForm.patchValue({
      check_in:  this.timeForInput(row.check_in),
      check_out: this.timeForInput(row.check_out),
      status:    row.status    ?? 'present',
      notes:     row.notes     ?? '',
    });
    this.cdr.markForCheck();
  }

  cancelEdit(): void { this.editingRow = null; this.cdr.markForCheck(); }

  saveEdit(): void {
    if (this.editForm.invalid || !this.editingRow) return;
    this.savingEdit = true;
    this.cdr.markForCheck();

    const payload = {
      ...this.editForm.value,
      check_in: this.timeForApi(this.editForm.value.check_in),
      check_out: this.timeForApi(this.editForm.value.check_out),
    };

    this.http.put<any>(`${this.api}/${this.editingRow.id}`, payload)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r) => {
          // Update row in place
          const idx = this.reportRows.findIndex(x => x.id === this.editingRow.id);
          if (idx > -1) this.reportRows[idx] = { ...this.reportRows[idx], ...r.log };
          this.editingRow = null;
          this.savingEdit = false;
          this.successMsg = 'Record updated.';
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
          this.cdr.markForCheck();
        },
        error: (err) => {
          this.errorMsg   = err?.error?.message ?? this.firstValidationError(err) ?? 'Update failed.';
          this.savingEdit = false; this.cdr.markForCheck();
        },
      });
  }

  // ── Manual entry ───────────────────────────────────────────────────────

  submitManual(): void {
    if (this.manualForm.invalid) return;
    this.savingManual = true;
    this.http.post<any>(`${this.api}/manual`, this.manualForm.value)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.showManualEntry = false;
          this.savingManual    = false;
          this.successMsg      = 'Manual entry saved.';
          this.loadReport();
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
          this.cdr.markForCheck();
        },
        error: (err) => {
          this.errorMsg    = err?.error?.message ?? 'Manual entry failed.';
          this.savingManual = false; this.cdr.markForCheck();
        },
      });
  }

  // ── Settings ───────────────────────────────────────────────────────────

  loadSettings(): void {
    this.http.get<AttendanceSettings>(`${this.api}/settings`)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (s) => {
          this.settings = s;
          this.settingsForm.patchValue(s);
          this.cdr.markForCheck();
        },
        error: () => {},
      });
  }

  saveSettings(): void {
    if (this.settingsForm.invalid) return;
    this.settingsSaving = true;
    this.http.post<any>(`${this.api}/settings`, this.settingsForm.value)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r) => {
          this.settings    = r.settings;
          this.settingsSaving = false;
          this.settingsSaved  = true;
          this.loadReport();
          setTimeout(() => { this.settingsSaved = false; this.cdr.markForCheck(); }, 3000);
          this.cdr.markForCheck();
        },
        error: () => { this.settingsSaving = false; this.cdr.markForCheck(); },
      });
  }

  toggleWeekend(day: number): void {
    const curr: number[] = this.settingsForm.value.weekend_days ?? [];
    const updated = curr.includes(day) ? curr.filter(d => d !== day) : [...curr, day];
    this.settingsForm.patchValue({ weekend_days: updated });
  }

  isWeekend(day: number): boolean {
    return (this.settingsForm.value.weekend_days ?? []).includes(day);
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  get canCheckIn():  boolean { return !this.todayLog?.check_in; }
  get canCheckOut(): boolean { return !!this.todayLog?.check_in && !this.todayLog?.check_out; }

  fmt(mins: number | null | undefined): string {
    if (!mins) return '—';
    const h = Math.floor(mins / 60), m = mins % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
  }

  statusColor(status: string): string {
    const map: any = {
      present:'#10b981', late:'#f59e0b', absent:'#ef4444',
      half_day:'#fb923c', on_leave:'#6366f1', holiday:'#8b949e',
    };
    return map[status] || '#6b7280';
  }

  statusClass(s: string): string {
    return ({ present: 'badge-green', late: 'badge-yellow', absent: 'badge-red',
              half_day: 'badge-orange', on_leave: 'badge-purple', holiday: 'badge-gray' } as any)[s] ?? 'badge-gray';
  }

  /** Determine if a check-in time would be considered late given current settings. */
  isLate(checkIn: string | null): boolean {
    if (!checkIn) return false;
    const [sh, sm] = this.settings.work_start.split(':').map(Number);
    const threshold = sh * 60 + sm + this.settings.late_after_minutes;
    const [ch, cm] = checkIn.split(':').map(Number);
    return ch * 60 + cm > threshold;
  }

  todayStr(): string {
    // Use Asia/Riyadh date to match server timezone
    const d = new Date();
    const riyadh = new Date(d.toLocaleString('en-US', { timeZone: 'Asia/Riyadh' }));
    return `${riyadh.getFullYear()}-${String(riyadh.getMonth() + 1).padStart(2, '0')}-${String(riyadh.getDate()).padStart(2, '0')}`;
  }

  /** Employees can request leave from their own attendance exception only. */
  canApplyForLeave(row: AttendanceLog): boolean {
    const isOwnRecord = this.currentEmployeeId !== null
      && Number(row.employee_id) === this.currentEmployeeId;
    const hasShortRecordedDay = row.total_minutes !== null
      && row.total_minutes < 8 * 60;
    const hasMissingCheckOut = Boolean(row.check_in) && !row.check_out;

    return isOwnRecord
      && !Boolean(row.has_active_leave)
      && (this.isLate(row.check_in) || hasShortRecordedDay || hasMissingCheckOut);
  }

  applyForLeave(row: AttendanceLog): void {
    if (!this.canApplyForLeave(row)) return;
    this.router.navigate(['/leave/my'], {
      queryParams: { apply_date: this.displayDate(row.date) },
    });
  }

  displayDate(value: string | null | undefined): string {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
      return value.split('T')[0] || value;
    }

    return new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Riyadh',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(parsed);
  }

  private timeForInput(value: string | null | undefined): string {
    const normalized = this.timeForApi(value);
    return normalized ?? '';
  }

  private timeForApi(value: string | null | undefined): string | null {
    if (!value) return null;
    const raw = String(value).trim();
    if (!raw) return null;

    const direct = raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
    if (direct) {
      return `${direct[1].padStart(2, '0')}:${direct[2]}:${direct[3] ?? '00'}`;
    }

    const meridiem = raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AP]M)$/i);
    if (meridiem) {
      let hour = Number(meridiem[1]);
      const suffix = meridiem[4].toUpperCase();
      if (suffix === 'PM' && hour < 12) hour += 12;
      if (suffix === 'AM' && hour === 12) hour = 0;
      return `${String(hour).padStart(2, '0')}:${meridiem[2]}:${meridiem[3] ?? '00'}`;
    }

    return raw;
  }

  private firstValidationError(err: any): string | null {
    const errors = err?.error?.errors;
    if (!errors) return null;
    const first = Object.values(errors)[0] as any;
    return Array.isArray(first) ? first[0] : String(first || '');
  }

  private firstOfMonth(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
  }

  private clean(obj: Record<string, any>): Record<string, string> {
    return Object.fromEntries(
      Object.entries(obj).filter(([, v]) => v !== null && v !== undefined && v !== '')
    ) as Record<string, string>;
  }

  private clearMessages(): void { this.errorMsg = ''; this.successMsg = ''; }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
