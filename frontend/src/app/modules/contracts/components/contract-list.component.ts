import {
  Component, OnInit, OnDestroy,
  ChangeDetectionStrategy, ChangeDetectorRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import { FormControl } from '@angular/forms';
import { formatDate as ngFormatDate } from '@angular/common';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

export interface Contract {
  id:           number;
  reference:    string;
  type:         string;
  status:       string;
  start_date:   string;
  end_date:     string | null;
  salary:       number | null;
  currency:     string;
  position:     string | null;
  terms:        string | null;
  pdf_path:     string | null;
  pdf_url:      string | null;
  has_document: boolean;
  is_expired:   boolean;
  approved_at:  string | null;
  created_at:   string;
  employee:     { id: number; full_name: string; code: string; avatar_url?: string } | null;
  department:   { id: number; name: string } | null;
  created_by:   { id: number; name: string } | null;
  approved_by:  { id: number; name: string } | null;
}

export interface ContractStats {
  total:         number;
  active:        number;
  draft:         number;
  expiring_soon: number;
  expired:       number;
  terminated:    number;
}

@Component({
  standalone:      false,
  selector:        'app-contract-list',
  templateUrl:     './contract-list.component.html',
  styleUrls:       ['./contract-list.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContractListComponent implements OnInit, OnDestroy {

  // ── Data ──────────────────────────────────────────────────────────────
  contracts:   Contract[]     = [];
  employees:   any[]          = [];
  departments: any[]          = [];
  stats:       ContractStats | null = null;
  pagination:  any            = null;
  currentPage  = 1;

  pendingRenewals = 0;

  // ── State ─────────────────────────────────────────────────────────────
  loading        = false;
  statsLoading   = true;
  submitting     = false;
  savingStatus   = false;
  formError      = '';
  successMsg     = '';
  errorMsg       = '';

  // ── View ──────────────────────────────────────────────────────────────
  showForm       = false;
  editId: number | null = null;
  selectedContract: Contract | null = null;
  showDetail     = false;

  // ── Document upload ───────────────────────────────────────────────────
  /** File chosen in the contract form, uploaded after the contract is saved. */
  selectedFile: File | null = null;
  /** Per-row id currently uploading a document (drives a spinner in the table). */
  uploadingDocId: number | null = null;

  // ── Filters ───────────────────────────────────────────────────────────
  searchControl  = new FormControl('');
  statusFilter   = 'all';
  typeFilter     = '';
  expiringFilter = false;

  // ── Stat tiles ────────────────────────────────────────────────────────
  statTiles: Array<{ labelKey: string; value: number; color: string; icon: string; status: string }> = [];

  // ── Options ───────────────────────────────────────────────────────────
  readonly contractTypes = [
    { value: 'full_time',   labelKey: 'CONTRACTS.TYPES.full_time' },
    { value: 'part_time',   labelKey: 'CONTRACTS.TYPES.part_time' },
    { value: 'contract',    labelKey: 'CONTRACTS.TYPES.contract' },
    { value: 'intern',      labelKey: 'CONTRACTS.TYPES.intern' },
    { value: 'probation',   labelKey: 'CONTRACTS.TYPES.probation' },
    { value: 'fixed_term',  labelKey: 'CONTRACTS.TYPES.fixed_term' },
    { value: 'unlimited',   labelKey: 'CONTRACTS.TYPES.unlimited' },
  ];

  readonly statusOptions = [
    { value: 'draft',       color: '#8b949e' },
    { value: 'active',      color: '#10b981' },
    { value: 'expired',     color: '#f59e0b' },
    { value: 'terminated',  color: '#ef4444' },
    { value: 'cancelled',   color: '#6b7280' },
  ];

  readonly displayedColumns = [
    'employee', 'reference', 'type', 'position', 'dates', 'salary', 'status', 'actions',
  ];

  contractForm!: FormGroup;

  isHR = false;

  private readonly api      = '/api/v1/contracts';
  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly http: HttpClient,
    private readonly fb:   FormBuilder,
    private readonly cdr:  ChangeDetectorRef,
    private auth: AuthService,
    private readonly lang: LanguageService,
  ) {}

  ngOnInit(): void {
    this.buildForm();
    this.loadStats();
    this.loadContracts();
    this.loadEmployees();
    this.loadDepartments();
    this.loadPendingRenewals();

    this.searchControl.valueChanges.pipe(
      debounceTime(400),
      distinctUntilChanged(),
      takeUntil(this.destroy$),
    ).subscribe(() => this.loadContracts(1));
  }

  // ── Form ──────────────────────────────────────────────────────────────

  private buildForm(): void {
     this.isHR = this.auth.isHRRole();
    this.contractForm = this.fb.group({
      employee_id:   ['', Validators.required],
      type:          ['full_time', Validators.required],
      status:        ['draft'],
      start_date:    ['', Validators.required],
      end_date:      [null],
      salary:        [null],
      currency:      ['SAR'],
      position:      [''],
      department_id: [''],
      terms:         [''],
    });
  }

  private ensureCanManageContracts(): boolean {
    if (this.isHR) return true;
    this.errorMsg = this.lang.t('CONTRACTS.MSG.NO_PERMISSION');
    this.showForm = false;
    this.cdr.markForCheck();
    return false;
  }

  openForm(contract?: Contract): void {
    if (!this.ensureCanManageContracts()) return;

    this.formError = '';
    this.editId    = contract?.id ?? null;
    this.selectedFile = null;

    if (contract) {
      this.contractForm.patchValue({
        employee_id:   contract.employee?.id ?? '',
        type:          contract.type,
        status:        contract.status,
        start_date:    contract.start_date,
        end_date:      contract.end_date ?? null,
        salary:        contract.salary,
        currency:      contract.currency,
        position:      contract.position ?? '',
        department_id: contract.department?.id ?? '',
        terms:         contract.terms ?? '',
      });
    } else {
      this.contractForm.reset({ type: 'full_time', status: 'draft', currency: 'SAR' });
    }

    this.showForm  = true;
    this.showDetail = false;
    this.cdr.markForCheck();
  }

  closeForm(): void { this.showForm = false; this.cdr.markForCheck(); }

  /**
   * Validate the selected file: PDF/DOC/DOCX, ≤ 10 MB. Returns an error string
   * or null when valid.
   */
  private validateFile(file: File): string | null {
    const allowed = [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    const okExt = /\.(pdf|doc|docx)$/i.test(file.name);
    if (!allowed.includes(file.type) && !okExt) return this.lang.t('CONTRACTS.MSG.FILE_TYPE');
    if (file.size > 10 * 1024 * 1024) return this.lang.t('CONTRACTS.MSG.FILE_SIZE');
    return null;
  }

  /** Handle file selection in the contract form. */
  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file  = input.files?.[0] ?? null;
    if (file) {
      const err = this.validateFile(file);
      if (err) { this.formError = err; input.value = ''; this.cdr.markForCheck(); return; }
    }
    this.selectedFile = file;
    this.cdr.markForCheck();
  }

  /** Clear the staged file before save. */
  clearSelectedFile(): void { this.selectedFile = null; this.cdr.markForCheck(); }

  saveContract(): void {
    if (!this.ensureCanManageContracts()) return;

    if (this.contractForm.invalid) {
      this.contractForm.markAllAsTouched();
      this.formError = this.lang.t('MESSAGES.FILL_REQUIRED');
      return;
    }

    this.submitting = true;
    this.formError  = '';

    const body = this.contractForm.value;
    const req   = this.editId
      ? this.http.put<any>(`${this.api}/${this.editId}`, body)
      : this.http.post<any>(this.api, body);

    req.pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        const contractId = this.editId ?? res?.contract?.id;
        // If a document was attached, upload it against the saved contract.
        if (this.selectedFile && contractId) {
          this.uploadDocumentFor(contractId, this.selectedFile, () => this.finishSave());
        } else {
          this.finishSave();
        }
      },
      error: (err) => {
        this.formError  = err?.error?.message ?? this.lang.t('CONTRACTS.MSG.SAVE_FAILED');
        this.submitting = false;
        this.cdr.markForCheck();
      },
    });
  }

  /** Common post-save cleanup + refresh. */
  private finishSave(): void {
    this.submitting   = false;
    this.showForm     = false;
    this.successMsg   = this.lang.t(this.editId ? 'CONTRACTS.MSG.UPDATED' : 'CONTRACTS.MSG.CREATED');
    this.selectedFile = null;
    this.loadContracts();
    this.loadStats();
    setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3500);
    this.cdr.markForCheck();
  }

  /**
   * Upload a document to a contract via multipart/form-data.
   *
   * @param contractId Target contract id.
   * @param file       The file to upload.
   * @param done       Optional success callback.
   */
  private uploadDocumentFor(contractId: number, file: File, done?: () => void): void {
    const fd = new FormData();
    fd.append('document', file);
    this.http.post<any>(`${this.api}/${contractId}/document`, fd)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { if (done) done(); },
        error: (err) => {
          this.formError  = err?.error?.message ?? this.lang.t('CONTRACTS.MSG.UPLOAD_AFTER_SAVE_FAILED');
          this.submitting = false;
          this.cdr.markForCheck();
        },
      });
  }

  /** Upload a document directly from the contract list row. */
  onRowFileSelected(event: Event, contract: Contract): void {
    if (!this.ensureCanManageContracts()) return;

    const input = event.target as HTMLInputElement;
    const file  = input.files?.[0];
    if (!file) return;
    const err = this.validateFile(file);
    if (err) { this.errorMsg = err; input.value = ''; this.cdr.markForCheck(); return; }

    this.uploadingDocId = contract.id;
    this.cdr.markForCheck();
    const fd = new FormData();
    fd.append('document', file);
    this.http.post<any>(`${this.api}/${contract.id}/document`, fd)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.uploadingDocId = null;
          this.successMsg = this.lang.t('CONTRACTS.MSG.DOCUMENT_UPLOADED');
          this.loadContracts();
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
          this.cdr.markForCheck();
        },
        error: (e) => {
          this.uploadingDocId = null;
          this.errorMsg = e?.error?.message ?? this.lang.t('CONTRACTS.MSG.UPLOAD_FAILED');
          this.cdr.markForCheck();
        },
      });
    input.value = '';
  }

  /** Open the contract document in a new tab (download). */
  downloadDocument(contract: Contract): void {
    window.open(`${this.api}/${contract.id}/document`, '_blank');
  }

  // ── Actions ───────────────────────────────────────────────────────────

  approve(contract: Contract): void {
    if (!this.ensureCanManageContracts()) return;

    if (!confirm(this.lang.t('CONTRACTS.MSG.CONFIRM_APPROVE', { ref: contract.reference }))) return;
    this.http.post<any>(`${this.api}/${contract.id}/approve`, {})
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.successMsg = this.lang.t('CONTRACTS.MSG.APPROVED'); this.loadContracts(); this.loadStats(); this.cdr.markForCheck(); },
        error: (err) => { this.errorMsg = err?.error?.message ?? this.lang.t('CONTRACTS.MSG.APPROVE_FAILED'); this.cdr.markForCheck(); },
      });
  }

  changeStatus(contract: Contract, status: string): void {
    if (!this.ensureCanManageContracts()) return;

    this.http.put<any>(`${this.api}/${contract.id}`, { status })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r) => {
          contract.status = r.contract?.status ?? status;
          this.successMsg = this.lang.t('CONTRACTS.MSG.STATUS_UPDATED', { status: this.lang.t('STATUS.' + status) });
          this.loadStats();
          setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3000);
          this.cdr.markForCheck();
        },
        error: () => {},
      });
  }

  deleteContract(id: number): void {
    if (!this.ensureCanManageContracts()) return;

    if (!confirm(this.lang.t('CONTRACTS.MSG.CONFIRM_DELETE'))) return;
    this.http.delete(`${this.api}/${id}`).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.loadContracts(); this.loadStats(); this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  viewDetail(contract: Contract): void {
    this.selectedContract = contract;
    this.showDetail       = true;
    this.showForm         = false;
    this.cdr.markForCheck();
  }

  // ── Data loading ──────────────────────────────────────────────────────

  loadContracts(page = 1): void {
    this.loading     = true;
    this.currentPage = page;

    const params: any = { page, per_page: 15 };
    if (this.searchControl.value)  params.search       = this.searchControl.value;
    if (this.statusFilter !== 'all') params.status     = this.statusFilter;
    if (this.typeFilter)             params.type        = this.typeFilter;
    if (this.expiringFilter)         params.expiring_soon = '1';

    this.http.get<any>(this.api, { params }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => {
        this.contracts  = r.data ?? [];
        this.pagination = r.meta ?? null;
        this.loading    = false;
        this.cdr.markForCheck();
      },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  downloadActiveEmployeeContractsReport(): void {
    const params: any = {};
    if (this.searchControl.value) params.search = this.searchControl.value;
    if (this.typeFilter) params.type = this.typeFilter;

    this.http.get(`${this.api}/active-employee-contracts-report`, {
      params,
      responseType: 'blob',
      observe: 'response',
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: response => this.downloadBlob(
        response,
        `active-employee-contract-details-report-${new Date().toISOString().slice(0, 10)}.xlsx`
      ),
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

  loadStats(): void {
    this.statsLoading = true;
    this.http.get<ContractStats>(`${this.api}/stats`).pipe(takeUntil(this.destroy$)).subscribe({
      next: (s) => {
        this.stats = s;
        this.statTiles = [
          { labelKey: 'CONTRACTS.STATS.TOTAL',         value: s.total,         color: '#3b82f6', icon: 'description',  status: 'all' },
          { labelKey: 'CONTRACTS.STATS.ACTIVE',        value: s.active,        color: '#10b981', icon: 'check_circle', status: 'active' },
          { labelKey: 'CONTRACTS.STATS.DRAFT',         value: s.draft,         color: '#8b949e', icon: 'drafts',       status: 'draft' },
          { labelKey: 'CONTRACTS.STATS.EXPIRING_SOON', value: s.expiring_soon, color: '#f59e0b', icon: 'schedule',     status: 'expiring' },
          { labelKey: 'CONTRACTS.STATS.EXPIRED',       value: s.expired,       color: '#ef4444', icon: 'event_busy',   status: 'expired' },
          { labelKey: 'CONTRACTS.STATS.TERMINATED',    value: s.terminated,    color: '#6b7280', icon: 'cancel',       status: 'terminated' },
        ];
        this.statsLoading = false;
        this.cdr.markForCheck();
      },
      error: () => { this.statsLoading = false; this.cdr.markForCheck(); },
    });
  }

  loadEmployees(): void {
    this.http.get<any>('/api/v1/employees?per_page=500&status=active')
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r) => { this.employees = r?.data ?? []; this.cdr.markForCheck(); },
        error: () => {},
      });
  }

  loadDepartments(): void {
    this.http.get<any>('/api/v1/departments')
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r) => { this.departments = r?.data ?? r ?? []; this.cdr.markForCheck(); },
        error: () => {},
      });
  }

  // ── Filter helpers ────────────────────────────────────────────────────

  filterByTile(tile: typeof this.statTiles[0]): void {
    if (tile.status === 'expiring') {
      this.expiringFilter = true;
      this.statusFilter   = 'all';
    } else {
      this.expiringFilter = false;
      this.statusFilter   = tile.status;
    }
    this.loadContracts(1);
  }

  clearFilters(): void {
    this.searchControl.setValue('');
    this.statusFilter   = 'all';
    this.typeFilter     = '';
    this.expiringFilter = false;
    this.loadContracts(1);
  }

  // ── Display helpers ───────────────────────────────────────────────────

  statusClass(status: string): string {
    return ({
      active:     'badge-green',
      draft:      'badge-gray',
      expired:    'badge-yellow',
      terminated: 'badge-red',
      cancelled:  'badge-gray',
    } as any)[status] ?? 'badge-gray';
  }

  avatarColor(name?: string | null): string {
    const palette = ['#3b82f6','#10b981','#f59e0b','#ef4444','#6366f1','#0ea5e9','#f97316','#a78bfa'];
    return palette[(name?.charCodeAt(0) ?? 0) % palette.length];
  }

  initial(name?: string | null): string { return name?.charAt(0)?.toUpperCase() ?? '?'; }

  formatDate(d: string | null): string {
    if (!d) return '—';
    try { return ngFormatDate(d, 'dd MMM yyyy', this.lang.locale()); } catch { return d; }
  }

  formatSalary(s: number | null, currency = 'SAR'): string {
    if (!s) return '—';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency, maximumFractionDigits: 0 }).format(s);
  }

  typeLabel(type: string): string {
    const t = this.contractTypes.find(o => o.value === type);
    return t ? this.lang.t(t.labelKey) : type;
  }

  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    return Array.from({ length: Math.min(this.pagination.last_page, 8) }, (_, i) => i + 1);
  }

  get f() { return this.contractForm.controls; }

  loadPendingRenewals(): void {
    this.http.get<any>('/api/v1/contracts/renewals/stats')
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (s) => {
          this.pendingRenewals = (s.pending ?? 0) + (s.manager_approved ?? 0) + (s.hr_approved ?? 0);
          this.cdr.markForCheck();
        },
        error: () => {},
      });
  }

  
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
