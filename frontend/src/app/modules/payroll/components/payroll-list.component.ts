import { Component, OnInit, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { formatDate } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslateService } from '@ngx-translate/core';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

@Component({
  standalone: false,
  selector: 'app-payroll-list',
  templateUrl: './payroll-list.component.html',
  styleUrls: ['./payroll-list.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PayrollListComponent implements OnInit {
  loading       = true;
  payrolls: any[] = [];
  payslips: any[] = [];
  pagination: any = null;

  // Modal flags
  showRunForm    = false;
  showDetail     = false;
  showReject     = false;
  showEditSlip   = false;
  showSlipDetail = false;
  showGenerateSlip = false;

  selectedPayroll: any  = null;
  selectedSlip: any     = null;
  rejectReason          = '';
  running               = false;
  detailLoading         = false;
  editSaving            = false;
  recalculating         = false;
  removingPayslipId: number | null = null;
  downloading           = false;
  slipIndex             = 0;
  runError              = '';
  editError             = '';
  isHR                  = false;
  /** HR managers or the Finance Manager: approve / reject / mark paid. Running and editing stay HR-only (isHR). */
  canApprovePayroll     = false;
  markingPaid           = false;
  stats: any            = {};
  filterStatus          = '';
  slipSearch            = '';
  showMarkPaid          = false;
  employees: any[]      = [];
  generateForm          = { employee_id: '', month: '' };
  employeeSearch        = '';
  employeeDropdownOpen  = false;
  generateError         = '';
  generatingSlip        = false;

  displayedColumns = ['employee', 'basic', 'housing', 'transport', 'other_earn', 'gross', 'gosi', 'unpaid_leave', 'loan', 'other_ded', 'net', 'days', 'actions'];
  /** label = translation key; money values are formatted at render time so the currency label follows the language. */
  statItems: { label: string; value: any; money: boolean; icon: string; color: string }[] = [];

  runForm = { month: '', period_start: '', period_end: '' };

  // Edit form — manual override
  editForm: any = {};

  constructor(
    private http: HttpClient,
    private auth: AuthService,
    private cdr:  ChangeDetectorRef,
    private lang: LanguageService,
    translate:    TranslateService,
  ) {
    // OnPush: re-render labels, dates and amounts when the language changes.
    translate.onLangChange.pipe(takeUntilDestroyed()).subscribe(() => this.cdr.markForCheck());
  }

  ngOnInit() {
    this.isHR = this.auth.isHRRole();
    this.canApprovePayroll = this.isHR || this.auth.isFinanceManager();
    this.setDefaultPeriod();
    this.loadStats();
    this.load();
  }

  loadStats() {
    this.http.get<any>('/api/v1/payroll/stats').subscribe({
      next: s => { this.stats = s; this.cdr.markForCheck(); },
      error: () => {}
    });
  }

  setDefaultPeriod() {
    const now = new Date();
    const y = now.getFullYear();
    const m = now.getMonth();
    this.runForm.month = `${y}-${String(m + 1).padStart(2, '0')}`;
    this.onRunMonthChange();
  }

  /** Keep the period on the selected "YYYY-MM" month (built locally: toISOString() is UTC and gives the previous day in Riyadh). */
  onRunMonthChange() {
    const [y, m] = String(this.runForm.month || '').split('-').map(Number);
    if (!y || !m) return;
    const pad = (n: number) => String(n).padStart(2, '0');
    const lastDay = new Date(y, m, 0).getDate();
    this.runForm.period_start = `${y}-${pad(m)}-01`;
    this.runForm.period_end   = `${y}-${pad(m)}-${pad(lastDay)}`;
  }

  load(page = 1) {
    this.loading = true;
    this.http.get<any>('/api/v1/payroll', { params: { page, per_page: 12 } }).subscribe({
      next: r => { this.payrolls = r?.data || []; this.pagination = r; this.loading = false; this.buildStats(); this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); }
    });
  }

  buildStats() {
    const latest = this.payrolls[0];
    this.statItems = [
      { label: 'PAYROLL.STATS.LATEST_NET',       value: latest ? latest.total_net   : null, money: true,  icon: 'payments',        color: '#10b981' },
      { label: 'PAYROLL.STATS.LATEST_GROSS',     value: latest ? latest.total_gross : null, money: true,  icon: 'account_balance', color: '#3b82f6' },
      { label: 'PAYROLL.STATS.PENDING_APPROVAL', value: String(this.payrolls.filter((p: any) => p.status === 'pending_approval').length), money: false, icon: 'pending_actions', color: '#f59e0b' },
      { label: 'PAYROLL.STATS.TOTAL_RUNS',       value: String(this.pagination?.total || this.payrolls.length), money: false, icon: 'receipt_long', color: '#6366f1' },
    ];
  }

  // ── Run Payroll ────────────────────────────────────────────────────────────
  runPayroll() {
    if (!this.runForm.month || !this.runForm.period_start || !this.runForm.period_end) {
      this.runError = this.lang.t('PAYROLL.MSG.ALL_FIELDS_REQUIRED'); return;
    }
    this.running = true; this.runError = '';
    this.http.post<any>('/api/v1/payroll/run', this.runForm).subscribe({
      next: () => { this.running = false; this.showRunForm = false; this.load(); this.cdr.markForCheck(); },
      error: err => {
        this.running  = false;
        const msg     = err?.error?.message || this.lang.t('PAYROLL.MSG.RUN_FAILED');
        this.runError = msg;
        this.cdr.markForCheck();
      }
    });
  }

  openGeneratePayslip() {
    if (!this.isHR) return;
    this.generateError = '';
    this.employeeSearch = '';
    this.employeeDropdownOpen = false;
    this.generateForm = { employee_id: '', month: this.runForm.month };
    this.showGenerateSlip = true;
    if (!this.employees.length) {
      this.http.get<any>('/api/v1/payroll/employee-options').subscribe({
        next: r => { this.employees = r?.employees || []; this.cdr.markForCheck(); },
        error: () => { this.generateError = this.lang.t('PAYROLL.MSG.LOAD_EMPLOYEES_FAILED'); this.cdr.markForCheck(); }
      });
    }
  }

  generatePayslip() {
    if (!this.generateForm.employee_id || !this.generateForm.month) {
      this.generateError = this.lang.t('PAYROLL.MSG.SELECT_EMPLOYEE_MONTH');
      return;
    }
    this.generatingSlip = true;
    this.generateError = '';
    const printWindow = this.openPrintWindow();
    this.http.get<any>('/api/v1/payroll/payslip/generate', { params: this.generateForm }).subscribe({
      next: r => {
        this.generatingSlip = false;
        this.showGenerateSlip = false;
        this.downloadPayslip(r.payslip, printWindow);
        this.cdr.markForCheck();
      },
      error: err => {
        printWindow?.close();
        this.generatingSlip = false;
        this.generateError = err?.error?.message || this.lang.t('PAYROLL.MSG.PAYSLIP_FAILED');
        this.cdr.markForCheck();
      }
    });
  }

  get filteredEmployeeOptions(): any[] {
    if (this.generateForm.employee_id) {
      return this.employees.filter(employee => String(employee.id) === String(this.generateForm.employee_id));
    }
    const query = this.employeeSearch.trim().toLowerCase();
    if (!query) return this.employees;

    return this.employees.filter(employee =>
      employee.employee_code?.toLowerCase().includes(query) ||
      employee.first_name?.toLowerCase().includes(query) ||
      employee.last_name?.toLowerCase().includes(query) ||
      `${employee.first_name || ''} ${employee.last_name || ''}`.toLowerCase().includes(query)
    );
  }

  selectEmployee(employee: any) {
    this.generateForm.employee_id = String(employee.id);
    this.employeeSearch = `${employee.employee_code} - ${employee.first_name} ${employee.last_name}`;
    this.employeeDropdownOpen = false;
    this.generateError = '';
  }

  onEmployeeSearchChange() {
    this.generateForm.employee_id = '';
    this.employeeDropdownOpen = true;
  }

  clearEmployeeSelection() {
    this.employeeSearch = '';
    this.generateForm.employee_id = '';
    this.employeeDropdownOpen = true;
  }

  closeEmployeeDropdown() {
    setTimeout(() => { this.employeeDropdownOpen = false; this.cdr.markForCheck(); }, 150);
  }

  private escapeHtml(value: any): string {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    } as any)[character]);
  }

  private formatPayPeriod(month: string): string {
    if (!month) return '';
    const [year, monthNumber] = month.split('-').map(Number);
    if (!year || !monthNumber) return month;
    // Angular formatDate keeps the Gregorian calendar and Western digits for ar-SA as well.
    return formatDate(new Date(year, monthNumber - 1, 1), 'MMMM y', this.lang.locale());
  }

  /** Payroll period ("2025-03") as a localized month name, e.g. "March 2025" / "مارس 2025". */
  periodLabel(month: string): string {
    if (!month || !/^\d{4}-\d{2}/.test(month)) return month ?? '';
    return this.formatPayPeriod(month.slice(0, 7));
  }

  /** Employee display name — Arabic name when Arabic is active and one is recorded. */
  empName(e: any): string {
    if (!e) return '';
    if (this.lang.current() === 'ar' && e.arabic_name) return e.arabic_name;
    return `${e.first_name || ''} ${e.last_name || ''}`.trim();
  }

  private amountInWords(value: number): string {
    return this.lang.current() === 'ar' ? this.amountInWordsAr(value) : this.amountInWordsEn(value);
  }

  /** Arabic tafqeet: "فقط ألف ومائتان وخمسون ريالاً سعودياً لا غير". */
  private amountInWordsAr(value: number): string {
    const ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة',
      'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    const tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    const hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    const underThousand = (n: number): string => {
      const parts: string[] = [];
      if (n >= 100) { parts.push(hundreds[Math.floor(n / 100)]); n %= 100; }
      if (n >= 20) {
        const u = n % 10;
        parts.push(u ? `${ones[u]} و${tens[Math.floor(n / 10)]}` : tens[Math.floor(n / 10)]);
      } else if (n > 0) {
        parts.push(ones[n]);
      }
      return parts.join(' و');
    };
    const scales = [
      { size: 1_000_000_000, one: 'مليار',  two: 'ملياران', plural: 'مليارات' },
      { size: 1_000_000,     one: 'مليون',  two: 'مليونان', plural: 'ملايين' },
      { size: 1_000,         one: 'ألف',    two: 'ألفان',   plural: 'آلاف' },
    ];
    let remaining = Math.floor(Math.max(0, value));
    if (!remaining) return 'صفر ريال سعودي';
    const groups: string[] = [];
    for (const sc of scales) {
      const count = Math.floor(remaining / sc.size);
      if (!count) continue;
      remaining %= sc.size;
      if (count === 1)      groups.push(sc.one);
      else if (count === 2) groups.push(sc.two);
      else if (count % 100 >= 3 && count % 100 <= 10) groups.push(`${underThousand(count)} ${sc.plural}`);
      else                  groups.push(`${underThousand(count)} ${sc.one}`);
    }
    if (remaining) groups.push(underThousand(remaining));
    return `فقط ${groups.join(' و')} ريال سعودي لا غير`;
  }

  private amountInWordsEn(value: number): string {
    const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    const underThousand = (number: number): string => {
      const parts: string[] = [];
      if (number >= 100) { parts.push(`${ones[Math.floor(number / 100)]} Hundred`); number %= 100; }
      if (number >= 20) { parts.push(tens[Math.floor(number / 10)]); number %= 10; }
      if (number > 0) parts.push(ones[number]);
      return parts.join(' ');
    };
    let remaining = Math.floor(Math.max(0, value));
    if (!remaining) return 'Zero Saudi Riyals Only';
    const words: string[] = [];
    for (const group of [{ size: 1_000_000_000, name: 'Billion' }, { size: 1_000_000, name: 'Million' }, { size: 1_000, name: 'Thousand' }, { size: 1, name: '' }]) {
      const amount = Math.floor(remaining / group.size);
      if (amount) { words.push(underThousand(amount), group.name); remaining %= group.size; }
    }
    return `${words.filter(Boolean).join(' ')} Saudi Riyals Only`;
  }

  private buildPayslipHtml(response: any): string {
    const s = response.payslip;
    const emp = s.employee;
    const money = (value: any) => this.fmtSAR(Number(value || 0));
    const net = Number(s.net_salary || 0);
    const t = (key: string, params?: Record<string, any>) => this.escapeHtml(this.lang.t(`PAYROLL.PRINT.${key}`, params));
    const isRtl = this.lang.isRtl();
    const designation = emp?.designation ? (isRtl && emp.designation.title_ar ? emp.designation.title_ar : emp.designation.title) : '';
    const department  = emp?.department  ? (isRtl && emp.department.name_ar   ? emp.department.name_ar   : emp.department.name)   : '';
    return `<!doctype html><html lang="${this.lang.current()}" dir="${this.lang.dir()}"><head><meta charset="utf-8"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap"><title>${t('DOC_TITLE', { month: s.payroll?.month ?? '' })}</title><style>
      @page{size:A4 portrait;margin:13mm 12mm 8mm}*{box-sizing:border-box}body{margin:0;font-family:Arial,'IBM Plex Sans Arabic',Tahoma,sans-serif;color:#111;font-size:14px}.num{direction:ltr;unicode-bidi:isolate}.sheet{width:100%;min-height:270mm;position:relative;padding-bottom:24mm}
      .header{text-align:center;margin:12mm 0 17mm}.header h1{margin:0 0 7px;font-size:25px;font-weight:500}.company{font-size:20px}.employee-grid{display:grid;grid-template-columns:1fr 1fr;column-gap:36mm;margin-bottom:17mm}.detail-row{display:grid;grid-template-columns:43mm 1fr;line-height:1.65}.detail-value:before{content:': '}
      table{width:100%;border-collapse:collapse;table-layout:fixed}.pay-grid{border:1px solid #111}.pay-grid th{background:#d2d2d2;border:1px solid #111;padding:7px 8px;font-size:17px;font-weight:500;text-align:center}.pay-grid td{padding:6px 8px;vertical-align:top}.pay-grid td:nth-child(1),.pay-grid th:nth-child(1){width:32%;border-inline-end:1px solid #111}.pay-grid td:nth-child(2),.pay-grid th:nth-child(2){width:18%;border-inline-end:1px solid #111;text-align:end}.pay-grid td:nth-child(3),.pay-grid th:nth-child(3){width:32%;border-inline-end:1px solid #111}.pay-grid td:nth-child(4),.pay-grid th:nth-child(4){width:18%;text-align:end}.totals td{padding-top:16px;font-weight:600}.net td{padding-top:2px;padding-bottom:8px;font-weight:700}
      .amount-words{text-align:center;margin-top:18mm;line-height:1.5}.amount-words strong{display:block;font-size:17px}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:70mm;margin:18mm 23mm 0;text-align:center}.signature-line{border-bottom:1px solid #111;height:25mm;margin-bottom:7px}.footer-note{position:absolute;bottom:0;left:0;right:0;text-align:center;font-size:13px}.print-action{position:fixed;inset-inline-end:18px;top:18px;border:0;border-radius:7px;padding:9px 14px;background:#2563eb;color:#fff;cursor:pointer}@media print{.print-action{display:none}}
    </style></head><body><button class="print-action" onclick="window.print()">${t('PRINT_BTN')}</button><main class="sheet">
      <header class="header"><h1>${t('TITLE')}</h1><div class="company">${this.escapeHtml(response.company_name || 'HRMS')}</div></header>
      <section class="employee-grid"><div><div class="detail-row"><span>${t('DATE_OF_JOINING')}</span><span class="detail-value num">${this.escapeHtml(emp?.hire_date || '—')}</span></div><div class="detail-row"><span>${t('PAY_PERIOD')}</span><span class="detail-value">${this.escapeHtml(this.formatPayPeriod(s.payroll?.month))}</span></div><div class="detail-row"><span>${t('WORKED_DAYS')}</span><span class="detail-value">${this.escapeHtml(s.working_days)}</span></div></div>
      <div><div class="detail-row"><span>${t('EMPLOYEE_NAME')}</span><span class="detail-value">${this.escapeHtml(this.empName(emp))}</span></div><div class="detail-row"><span>${this.escapeHtml(this.lang.t('COMMON.DESIGNATION'))}</span><span class="detail-value">${this.escapeHtml(designation || '—')}</span></div><div class="detail-row"><span>${this.escapeHtml(this.lang.t('COMMON.DEPARTMENT'))}</span><span class="detail-value">${this.escapeHtml(department || '—')}</span></div></div></section>
      <table class="pay-grid"><thead><tr><th>${t('EARNINGS')}</th><th>${this.escapeHtml(this.lang.t('COMMON.AMOUNT'))}</th><th>${t('DEDUCTIONS')}</th><th>${this.escapeHtml(this.lang.t('COMMON.AMOUNT'))}</th></tr></thead><tbody>
      <tr><td>${t('BASIC_PAY')}</td><td>${money(s.basic_salary)}</td><td>${t('GOSI_EMPLOYEE')}</td><td>${money(s.gosi_employee)}</td></tr><tr><td>${t('HOUSING_ALLOWANCE')}</td><td>${money(s.housing_allowance)}</td><td>${t('UNPAID_LEAVE_DAYS', { days: Number(s.unpaid_leave_days || 0).toFixed(1) })}</td><td>${money(s.leave_deduction)}</td></tr><tr><td>${t('TRANSPORT_ALLOWANCE')}</td><td>${money(s.transport_allowance)}</td><td>${t('LOAN_INSTALLMENTS')}</td><td>${money(s.loan_deduction)}</td></tr><tr><td>${t('OTHER_EARNINGS')}</td><td>${money(s.other_allowances)}</td><td>${t('OTHER_DEDUCTIONS')}</td><td>${money(s.other_deductions)}</td></tr><tr class="totals"><td>${t('TOTAL_EARNINGS')}</td><td>${money(s.gross_salary)}</td><td>${t('TOTAL_DEDUCTIONS')}</td><td>${money(s.total_deductions)}</td></tr><tr class="net"><td></td><td></td><td>${t('NET_PAY')}</td><td>${money(net)}</td></tr></tbody></table>
      <div class="amount-words"><strong>${money(net)}</strong>${this.escapeHtml(this.amountInWords(net))}</div><section class="signatures"><div><div class="signature-line"></div>${t('EMPLOYER_SIGNATURE')}</div><div><div class="signature-line"></div>${t('EMPLOYEE_SIGNATURE')}</div></section><div class="footer-note">${t('SYSTEM_GENERATED')}</div>
    </main></body></html>`;
  }

  // ── View Payslips ──────────────────────────────────────────────────────────
  viewDetail(p: any) {
    this.selectedPayroll = p;
    this.showDetail      = true;
    this.detailLoading   = true;
    this.payslips        = [];
    this.http.get<any>(`/api/v1/payroll/${p.id}/payslips`, { params: { per_page: 1000 } }).subscribe({
      next: r => { this.payslips = r?.data || r || []; this.detailLoading = false; this.cdr.markForCheck(); },
      error: () => { this.detailLoading = false; this.cdr.markForCheck(); }
    });
  }

  // ── View single payslip breakdown (drawer) ───────────────────────────────
  viewSlip(ps: any) {
    this.slipIndex      = this.payslips.findIndex(p => p.id === ps.id);
    this.selectedSlip   = ps;
    this.showSlipDetail = true;
  }

  navigateSlip(dir: number) {
    const next = this.slipIndex + dir;
    if (next < 0 || next >= this.payslips.length) return;
    this.slipIndex    = next;
    this.selectedSlip = this.payslips[next];
  }

  /** Opened synchronously from the click handler; a window opened after an HTTP round-trip is popup-blocked (null). */
  private openPrintWindow(): Window | null {
    return window.open('', '_blank');
  }

  downloadPayslip(ps: any, printWindow: Window | null = this.openPrintWindow()) {
    this.downloading = true;
    this.http.get<any>(`/api/v1/payroll/payslip/${ps.id}/download`).subscribe({
      next: r => {
        this.downloading = false;
        this.cdr.markForCheck();
        const w = printWindow && !printWindow.closed ? printWindow : window.open('', '_blank');
        if (!w) { alert(this.lang.t('PAYROLL.MSG.POPUP_BLOCKED')); return; }
        w.document.open();
        w.document.write(this.buildPayslipHtml(r));
        w.document.close();
      },
      error: err => {
        printWindow?.close();
        this.downloading = false;
        alert(err?.error?.message || this.lang.t('PAYROLL.MSG.PAYSLIP_FAILED'));
        this.cdr.markForCheck();
      }
    });
  }

  // ── Edit payslip ──────────────────────────────────────────────────────────
  openEdit(ps: any) {
    this.selectedSlip = ps;
    this.editForm = {
      basic_salary:        ps.basic_salary        || 0,
      housing_allowance:   ps.housing_allowance   || 0,
      transport_allowance: ps.transport_allowance || 0,
      other_allowances:    ps.other_allowances    || 0,
      gosi_employee:       ps.gosi_employee       || 0,
      leave_deduction:     ps.leave_deduction     || 0,
      loan_deduction:      ps.loan_deduction      || 0,
      other_deductions:    ps.other_deductions    || 0,
      unpaid_leave_days:   ps.unpaid_leave_days   || 0,
      absent_days:         ps.absent_days         || 0,
    };
    this.editError    = '';
    this.showEditSlip = true;
  }

  saveEdit() {
    this.editSaving = true;
    this.editError  = '';
    this.http.put<any>(
      `/api/v1/payroll/${this.selectedPayroll.id}/payslips/${this.selectedSlip.id}`,
      this.editForm
    ).subscribe({
      next: r => {
        // Replace the array reference so MatTable and OnPush detect the edited row.
        const idx = this.payslips.findIndex(p => p.id === this.selectedSlip.id);
        const updatedSlip = idx > -1
          ? { ...this.payslips[idx], ...r.payslip }
          : r.payslip;

        if (idx > -1) {
          this.payslips = this.payslips.map((p, i) => i === idx ? updatedSlip : p);
        }

        this.selectedSlip = updatedSlip;
        if (r.payroll) {
          this.selectedPayroll = { ...this.selectedPayroll, ...r.payroll };
        }
        this.editSaving    = false;
        this.showEditSlip  = false;
        this.load();
        this.cdr.markForCheck();
      },
      error: err => { this.editSaving = false; this.editError = err?.error?.message || this.lang.t('MESSAGES.SAVE_FAILED'); this.cdr.markForCheck(); }
    });
  }

  removeProbationPayslip(ps: any) {
    if (ps?.employee?.status !== 'probation' || !this.canEdit(this.selectedPayroll)) return;

    const employeeName = this.empName(ps.employee);
    if (!confirm(this.lang.t('PAYROLL.MSG.CONFIRM_REMOVE', { name: employeeName }))) return;

    this.removingPayslipId = ps.id;
    this.http.delete<any>(`/api/v1/payroll/${this.selectedPayroll.id}/payslips/${ps.id}`).subscribe({
      next: r => {
        this.payslips = this.payslips.filter(p => p.id !== ps.id);
        this.selectedPayroll = { ...this.selectedPayroll, ...r.payroll };
        this.removingPayslipId = null;
        this.load();
        this.loadStats();
        this.cdr.markForCheck();
      },
      error: err => {
        this.removingPayslipId = null;
        alert(err?.error?.message || this.lang.t('PAYROLL.MSG.REMOVE_FAILED'));
        this.cdr.markForCheck();
      }
    });
  }

  // ── Approve / Reject ──────────────────────────────────────────────────────
  approve(p: any) {
    if (!confirm(this.lang.t('PAYROLL.MSG.CONFIRM_APPROVE', { month: this.periodLabel(p.month) }))) return;
    this.http.post(`/api/v1/payroll/${p.id}/approve`, {}).subscribe({
      next: () => { this.showDetail = false; this.load(); this.loadStats(); this.cdr.markForCheck(); },
      error: err => { alert(err?.error?.message || this.lang.t('MESSAGES.GENERIC_ERROR')); this.cdr.markForCheck(); }
    });
  }

  openReject(p: any) {
    this.selectedPayroll = p;
    this.rejectReason    = '';
    this.showReject      = true;
  }

  confirmReject() {
    if (!this.rejectReason.trim()) return;
    this.http.post(`/api/v1/payroll/${this.selectedPayroll.id}/reject`, { reason: this.rejectReason }).subscribe({
      next: () => { this.showReject = false; this.showDetail = false; this.load(); this.loadStats(); this.cdr.markForCheck(); },
      error: err => { alert(err?.error?.message || this.lang.t('MESSAGES.GENERIC_ERROR')); this.cdr.markForCheck(); }
    });
  }

  reopen(p: any) {
    if (!confirm(this.lang.t('PAYROLL.MSG.CONFIRM_REOPEN', { month: this.periodLabel(p.month) }))) return;
    this.http.post<any>(`/api/v1/payroll/${p.id}/reopen`, {}).subscribe({
      next: r => {
        this.showDetail = false;
        this.load();
        this.cdr.markForCheck();
      },
      error: err => alert(err?.error?.message || this.lang.t('PAYROLL.MSG.REOPEN_FAILED'))
    });
  }

  recalculate(p: any) {
    if (!confirm(this.lang.t('PAYROLL.MSG.CONFIRM_REGENERATE', { month: this.periodLabel(p.month) }))) return;
    this.recalculating = true;
    this.http.post<any>(`/api/v1/payroll/${p.id}/recalculate`, {}).subscribe({
      next: r => {
        this.recalculating = false;
        this.viewDetail(p);
        this.load();
        this.cdr.markForCheck();
      },
      error: err => {
        this.recalculating = false;
        this.cdr.markForCheck();
        alert(err?.error?.message || this.lang.t('PAYROLL.MSG.REGENERATE_FAILED'));
      }
    });
  }

  markPaid(p: any) {
    if (!confirm(this.lang.t('PAYROLL.MSG.CONFIRM_MARK_PAID', { month: this.periodLabel(p.month) }))) return;
    this.markingPaid = true;
    this.http.post<any>(`/api/v1/payroll/${p.id}/mark-paid`, {}).subscribe({
      next: () => {
        this.markingPaid  = false;
        this.showDetail   = false;
        this.showMarkPaid = false;
        this.load();
        this.loadStats();
        this.cdr.markForCheck();
      },
      error: err => {
        this.markingPaid = false;
        this.cdr.markForCheck();
        alert(err?.error?.message ?? this.lang.t('PAYROLL.MSG.MARK_PAID_FAILED'));
      }
    });
  }

  exportBank(p: any) {
    this.http.get(`/api/v1/payroll/${p.id}/export`, { responseType: 'blob' }).subscribe({
      next: blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = `bank_transfer_${p.month}.csv`; a.click();
        window.URL.revokeObjectURL(url);
      },
      error: () => alert(this.lang.t('MESSAGES.GENERIC_ERROR'))
    });
  }

  // ── Computed helpers ──────────────────────────────────────────────────────
  editNetPreview(): number {
    const e = this.editForm;
    const earn = (+e.basic_salary||0) + (+e.housing_allowance||0) + (+e.transport_allowance||0) + (+e.other_allowances||0);
    // Same formula as PayrollController::updatePayslip — the leave/loan deductions are editable too.
    return Math.max(0, earn - this.editDeductionPreview());
  }

  editDeductionPreview(): number {
    return (+this.editForm.gosi_employee||0) + (+this.editForm.other_deductions||0)
      + (+this.editForm.leave_deduction||0) + (+this.editForm.loan_deduction||0);
  }

  editGrossPreview(): number {
    const e = this.editForm;
    return (+e.basic_salary||0) + (+e.housing_allowance||0) + (+e.transport_allowance||0) + (+e.other_allowances||0);
  }

  get filteredPayslips(): any[] {
    if (!this.slipSearch) return this.payslips;
    const q = this.slipSearch.toLowerCase();
    return this.payslips.filter(p =>
      p.employee?.first_name?.toLowerCase().includes(q) ||
      p.employee?.last_name?.toLowerCase().includes(q)  ||
      p.employee?.employee_code?.toLowerCase().includes(q)
    );
  }

  payrollTotalNet()   { return this.payslips.reduce((s, p) => s + (+p.net_salary   || 0), 0); }
  payrollTotalGross() { return this.payslips.reduce((s, p) => s + (+p.gross_salary || 0), 0); }
  payrollTotalDed()   { return this.payslips.reduce((s, p) => s + (+p.total_deductions || 0), 0); }

  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    return Array.from({ length: Math.min(this.pagination.last_page, 10) }, (_, i) => i + 1);
  }

  fmtSAR(v: any) {
    if (v === null || v === undefined || v === '') return '—';
    // Western digits in both languages; only the currency label is translated.
    return this.lang.t('COMMON.SAR') + ' ' + Number(v).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  canEdit(payroll: any) { return this.isHR && ['draft', 'pending_approval'].includes(payroll?.status); }

  statusCls(s: string) {
    return ({ draft:'badge-gray', pending_approval:'badge-yellow', approved:'badge-blue', paid:'badge-green', rejected:'badge-red' } as any)[s] || 'badge-gray';
  }

}
