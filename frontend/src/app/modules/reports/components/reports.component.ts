import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { LanguageService } from '../../../core/services/language.service';

interface ReportDef {
  id:          string;
  key:         string;    // REPORTS.DEFS.<key>.LABEL / .DESC
  icon:        string;
  color:       string;
  filters:     string[];  // which filter fields apply
  columns:     string[];  // preview column keys
  headers:     string[];  // header translation keys (REPORTS.COL.<header>)
}

@Component({
  standalone: false,
  selector: 'app-reports',
  templateUrl: './reports.component.html',
  styleUrls: ['./reports.component.scss'],
})
export class ReportsComponent implements OnInit {

  // ── State ──────────────────────────────────────────────────────────────
  activeReport: ReportDef | null = null;
  previewData:  any[]  = [];
  previewTotal  = 0;
  loading       = false;
  downloading   = false;
  previewLoaded = false;
  error         = '';

  // ── Filters ────────────────────────────────────────────────────────────
  filters: any = {
    month:          this.currentMonth(),
    year:           new Date().getFullYear(),
    from:           '',
    to:             '',
    department_id:  '',
    status:         '',
    leave_type_id:  '',
    annual_only:    true,
  };

  // ── Lookups ────────────────────────────────────────────────────────────
  departments: any[] = [];
  leaveTypes:  any[] = [];

  readonly YEARS = Array.from({length: 5}, (_, i) => new Date().getFullYear() - i);

  readonly REPORTS: ReportDef[] = [
    {
      id:          'employees',
      key:         'EMPLOYEES',
      icon:        'people',
      color:       '#3b82f6',
      filters:     ['department', 'status', 'from', 'to'],
      columns:     ['code','name','department','designation','hire_date','nationality','status','salary'],
      headers:     ['CODE','NAME','DEPARTMENT','DESIGNATION','HIRE_DATE','NATIONALITY','STATUS','BASIC_SALARY'],
    },
    {
      id:          'payroll',
      key:         'PAYROLL',
      icon:        'payments',
      color:       '#10b981',
      filters:     ['month', 'department'],
      columns:     ['code','name','department','basic','housing','transport','gross','gosi','deductions','net'],
      headers:     ['CODE','NAME','DEPT','BASIC','HOUSING','TRANSPORT','GROSS','GOSI','DEDUCTIONS','NET'],
    },
    {
      id:          'leave-balance',
      key:         'LEAVE_BALANCE',
      icon:        'event_available',
      color:       '#6366f1',
      filters:     ['year', 'department'],
      columns:     ['code','name','department','entitlement','used','pending','remaining'],
      headers:     ['CODE','NAME','DEPARTMENT','ENTITLEMENT','USED','PENDING','REMAINING'],
    },
    {
      id:          'leave-requests',
      key:         'LEAVE_REQUESTS',
      icon:        'event_note',
      color:       '#f59e0b',
      filters:     ['from', 'to', 'department', 'status', 'leave_type'],
      columns:     ['code','name','department','leave_type','from','to','days','status'],
      headers:     ['CODE','NAME','DEPT','TYPE','FROM','TO','DAYS','STATUS'],
    },
    {
      id:          'attendance',
      key:         'ATTENDANCE',
      icon:        'fingerprint',
      color:       '#0ea5e9',
      filters:     ['month', 'from', 'to', 'department', 'status'],
      columns:     ['code','name','department','date','check_in','check_out','hours','status'],
      headers:     ['CODE','NAME','DEPT','DATE','IN','OUT','HOURS','STATUS'],
    },
    {
      id:          'loans',
      key:         'LOANS',
      icon:        'account_balance',
      color:       '#f97316',
      filters:     ['department', 'status', 'from', 'to'],
      columns:     ['code','name','department','loan_type','amount','outstanding','installment','installments','status'],
      headers:     ['CODE','NAME','DEPT','TYPE','AMOUNT','OUTSTANDING','INSTALLMENT','PROGRESS','STATUS'],
    },
  ];

  readonly EMP_STATUSES    = ['active','inactive','probation','on_leave','terminated'];
  readonly LEAVE_STATUSES  = ['pending','approved','rejected','cancelled'];
  readonly ATT_STATUSES    = ['present','absent','late','holiday','off'];
  readonly LOAN_STATUSES   = ['pending','active','settled','rejected'];

  constructor(private http: HttpClient, private lang: LanguageService) {}

  ngOnInit() {
    this.loadDepts();
    this.loadLeaveTypes();
    // Select first report by default
    this.selectReport(this.REPORTS[0]);
  }

  // ── Report selection ───────────────────────────────────────────────────

  selectReport(r: ReportDef) {
    if (this.activeReport?.id === r.id) return;
    this.activeReport  = r;
    this.previewData   = [];
    this.previewLoaded = false;
    this.error         = '';
  }

  hasFilter(f: string): boolean {
    return this.activeReport?.filters.includes(f) ?? false;
  }

  // ── Preview ────────────────────────────────────────────────────────────

  runPreview() {
    if (!this.activeReport) return;
    this.loading       = true;
    this.previewLoaded = false;
    this.error         = '';

    this.http.get<any>(`/api/v1/reports/${this.activeReport.id}`, { params: this.buildParams() }).subscribe({
      next: r => {
        this.previewData   = r.data || [];
        this.previewTotal  = r.total || this.previewData.length;
        this.loading       = false;
        this.previewLoaded = true;
      },
      error: err => {
        this.loading = false;
        this.error   = err?.error?.message || this.lang.t('REPORTS.LOAD_FAILED');
      },
    });
  }

  // ── Downloads ──────────────────────────────────────────────────────────

  download(format: 'csv' | 'pdf' | 'xlsx') {
    if (!this.activeReport) return;
    this.downloading = true;
    const params = this.buildParams();
    const qs     = Object.entries(params).filter(([,v]) => v !== '' && v !== null).map(([k,v]) => `${k}=${encodeURIComponent(v as string)}`).join('&');
    const isMonthlyAttendanceXlsx = format === 'xlsx' && this.activeReport.id === 'attendance';
    const url = isMonthlyAttendanceXlsx
      ? `/api/v1/attendance/monthly-report.xlsx?${qs}`
      : `/api/v1/reports/download/${this.activeReport.id}/${format}?${qs}`;

    // Use anchor + token for authenticated file download
    const token = sessionStorage.getItem('hrms_token') || localStorage.getItem('hrms_token');
    this.http.get(url, { responseType: 'blob', headers: { Authorization: `Bearer ${token}` } }).subscribe({
      next: blob => {
        const ext  = format === 'pdf' ? 'pdf' : (format === 'xlsx' ? 'xlsx' : 'csv');
        const name = `${this.activeReport!.id}_${this.yyyymmdd()}.${ext}`;
        const a    = document.createElement('a');
        a.href     = URL.createObjectURL(blob);
        a.download = name;
        a.click();
        URL.revokeObjectURL(a.href);
        this.downloading = false;
      },
      error: () => { this.downloading = false; this.error = this.lang.t('REPORTS.DOWNLOAD_FAILED'); },
    });
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  private buildParams(): any {
    const p: any = {};
    const hasRange = !!(this.filters.from && this.filters.to);
    if (this.hasFilter('month')      && this.filters.month && !(this.activeReport?.id === 'attendance' && hasRange)) p.month = this.filters.month;
    if (this.hasFilter('year')       && this.filters.year)          p.year           = this.filters.year;
    if (this.hasFilter('from')       && this.filters.from)          p.from           = this.filters.from;
    if (this.hasFilter('to')         && this.filters.to)            p.to             = this.filters.to;
    if (this.hasFilter('department') && this.filters.department_id) p.department_id  = this.filters.department_id;
    if (this.hasFilter('status')     && this.filters.status)        p.status         = this.filters.status;
    if (this.hasFilter('leave_type') && this.filters.leave_type_id) p.leave_type_id  = this.filters.leave_type_id;
    if (this.activeReport?.id === 'leave-balance')                  p.annual_only    = 1;
    return p;
  }

  private loadDepts() {
    this.http.get<any>('/api/v1/departments').subscribe({
      next: r => this.departments = Array.isArray(r) ? r : (r?.data ?? []),
    });
  }

  private loadLeaveTypes() {
    this.http.get<any>('/api/v1/leave/types').subscribe({
      next: r => this.leaveTypes = r?.types || r || [],
    });
  }

  private currentMonth(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}`;
  }

  private yyyymmdd(): string {
    return new Date().toISOString().slice(0,10).replace(/-/g,'');
  }

  statusCls(s: string): string {
    const m: any = {
      active:'badge-green', approved:'badge-green', present:'badge-green', settled:'badge-green',
      pending:'badge-yellow', late:'badge-yellow', on_leave:'badge-yellow', probation:'badge-yellow',
      rejected:'badge-red', absent:'badge-red', terminated:'badge-red',
      cancelled:'badge-gray', inactive:'badge-gray', draft:'badge-gray', off:'badge-gray',
    };
    return m[s?.toLowerCase()] ?? 'badge-gray';
  }

  get currentYear() { return new Date().getFullYear(); }

  /** Display label for a status code: STATUS.<code>, report-specific extras, or a humanised fallback. */
  statusText(code: any): string {
    if (code === null || code === undefined || code === '') return '';
    const norm = String(code).trim().toLowerCase().replace(/[\s-]+/g, '_');
    for (const key of [`STATUS.${norm}`, `REPORTS.STATUS_EXTRA.${norm}`]) {
      const label = this.lang.t(key);
      if (label !== key) return label;
    }
    return String(code).replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  getCellValue(row: any, col: string): any {
    const keys = Object.keys(row);
    // Try direct key match first
    if (row[col] !== undefined) return row[col];
    // Try index-based fallback using column position in report definition
    const idx = this.activeReport?.columns.indexOf(col) ?? -1;
    return idx > -1 ? Object.values(row)[idx] : '—';
  }
}
