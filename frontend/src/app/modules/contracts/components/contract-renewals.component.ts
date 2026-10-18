import {
  Component, OnInit, OnDestroy,
  ChangeDetectionStrategy, ChangeDetectorRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { FormControl } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { formatDate as ngFormatDate } from '@angular/common';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

export interface RenewalRequest {
  id:                  number;
  reference:           string;
  status:              string;
  current_stage:       string;
  progress:            number;
  auto_generated:      boolean;
  notes:               string | null;
  proposed_start_date: string;
  proposed_end_date:   string | null;
  proposed_salary:     number | null;
  proposed_type:       string | null;
  created_at:          string;
  notified_at:         string | null;
  document:            { name: string; mime: string; size: number; url: string } | null;
  employee:            { id: number; full_name: string; code: string; department: string | null } | null;
  contract:            { id: number; reference: string; end_date: string | null; type: string; salary: number | null } | null;
  approvals: {
    manager: { approved: boolean; approved_by: string | null; approved_at: string | null; notes: string | null };
    hr:      { approved: boolean; approved_by: string | null; approved_at: string | null; notes: string | null };
    ceo:     { approved: boolean; approved_by: string | null; approved_at: string | null; notes: string | null };
  };
  rejection: { stage: string; reason: string; by: string | null; at: string | null } | null;
  new_contract_id: number | null;
}

@Component({
  standalone:      false,
  selector:        'app-contract-renewals',
  templateUrl:     './contract-renewals.component.html',
  styleUrls:       ['./contract-renewals.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContractRenewalsComponent implements OnInit, OnDestroy {

  renewals:    RenewalRequest[] = [];
  stats:       any              = {};
  pagination:  any              = null;
  currentPage  = 1;

  loading      = false;
  statsLoading = true;
  submitting   = false;
  actionLoading: number | null = null;

  successMsg = '';
  errorMsg   = '';

  statusFilter = '';
  searchControl = new FormControl('');

  selectedRenewal: RenewalRequest | null = null;
  showDetail       = false;
  showCreateForm   = false;
  showActionDialog = false;
  actionType: 'approve' | 'reject' = 'approve';
  actionNotes = '';
  actionRejectionReason = '';

  contracts: any[] = [];   // for manual creation dropdown
  createForm!: FormGroup;

  // ── Document upload ───────────────────────────────────────────────────
  /** File staged in the create form, uploaded after the renewal is created. */
  selectedFile: File | null = null;
  /** Renewal id currently uploading a document from a row/detail action. */
  uploadingDocId: number | null = null;

  isHR     = false;
  isCEO    = false;
  isMgr    = false;

  readonly statTiles = [
    { labelKey: 'CONTRACTS.RENEWALS.STATS.TOTAL',            key: 'total',            color: '#3b82f6', icon: 'description',      status: '' },
    { labelKey: 'CONTRACTS.RENEWALS.STATS.AWAITING_MANAGER', key: 'pending',          color: '#f59e0b', icon: 'person',            status: 'pending' },
    { labelKey: 'CONTRACTS.RENEWALS.STATS.AWAITING_HR',      key: 'manager_approved', color: '#6366f1', icon: 'manage_accounts',   status: 'manager_approved' },
    { labelKey: 'CONTRACTS.RENEWALS.STATS.AWAITING_CEO',     key: 'hr_approved',      color: '#0ea5e9', icon: 'shield',            status: 'hr_approved' },
    { labelKey: 'CONTRACTS.RENEWALS.STATS.FULLY_APPROVED',   key: 'approved',         color: '#10b981', icon: 'check_circle',      status: 'approved' },
    { labelKey: 'CONTRACTS.RENEWALS.STATS.REJECTED',         key: 'rejected',         color: '#ef4444', icon: 'cancel',            status: 'rejected' },
  ];

  readonly stages = [
    { key: 'manager', labelKey: 'CONTRACTS.RENEWALS.STAGES.MANAGER', icon: 'person',          color: '#f59e0b' },
    { key: 'hr',      labelKey: 'CONTRACTS.RENEWALS.STAGES.HR',      icon: 'manage_accounts', color: '#6366f1' },
    { key: 'ceo',     labelKey: 'CONTRACTS.RENEWALS.STAGES.CEO',     icon: 'shield',          color: '#10b981' },
  ];

  private readonly api      = '/api/v1/contracts/renewals';
  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly http: HttpClient,
    private readonly fb:   FormBuilder,
    private readonly auth: AuthService,
    private readonly cdr:  ChangeDetectorRef,
    private readonly lang: LanguageService,
  ) {}

  ngOnInit(): void {
    this.isHR  = this.auth.isHRRole();
    this.isCEO = this.auth.isCEO() || this.auth.isSuperAdmin();
    this.isMgr = this.auth.isManagerRole();

    this.createForm = this.fb.group({
      contract_id:         ['', Validators.required],
      proposed_start_date: ['', Validators.required],
      proposed_end_date:   [null],
      proposed_salary:     [null],
      proposed_type:       [''],
      notes:               [''],
    });

    this.loadStats();
    this.loadRenewals();
    this.loadContracts();
  }

  loadRenewals(page = 1): void {
    this.loading     = true;
    this.currentPage = page;
    const params: any = { page, per_page: 15 };
    if (this.statusFilter)         params.status = this.statusFilter;
    if (this.searchControl.value)  params.search = this.searchControl.value;

    this.http.get<any>(this.api, { params }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (r) => {
        this.renewals   = r.data ?? [];
        this.pagination = r.meta ?? (r.last_page ? r : null);
        this.loading    = false;
        this.cdr.markForCheck();
      },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  loadStats(): void {
    this.http.get<any>(`${this.api}/stats`).pipe(takeUntil(this.destroy$)).subscribe({
      next: (s) => { this.stats = s; this.statsLoading = false; this.cdr.markForCheck(); },
      error: () => { this.statsLoading = false; this.cdr.markForCheck(); },
    });
  }

  loadContracts(): void {
    this.http.get<any>('/api/v1/contracts?status=active&per_page=200')
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (r) => { this.contracts = r?.data ?? []; this.cdr.markForCheck(); },
        error: () => {},
      });
  }

  // ── Actions ───────────────────────────────────────────────────────────

  openApprove(renewal: RenewalRequest): void {
    this.selectedRenewal  = renewal;
    this.actionType       = 'approve';
    this.actionNotes      = '';
    this.errorMsg         = '';
    this.showActionDialog = true;
    this.cdr.markForCheck();
  }

  openReject(renewal: RenewalRequest): void {
    this.selectedRenewal        = renewal;
    this.actionType             = 'reject';
    this.actionRejectionReason  = '';
    this.errorMsg               = '';
    this.showActionDialog       = true;
    this.cdr.markForCheck();
  }

  submitAction(): void {
    if (!this.selectedRenewal) return;
    const id  = this.selectedRenewal.id;

    if (this.actionType === 'approve') {
      this.actionLoading = id;
      this.http.post<any>(`${this.api}/${id}/approve`, { notes: this.actionNotes })
        .pipe(takeUntil(this.destroy$)).subscribe({
          next: (r) => {
            this.successMsg       = r.message;
            this.showActionDialog = false;
            this.actionLoading    = null;
            this.loadRenewals(this.currentPage);
            this.loadStats();
            if (this.showDetail && this.selectedRenewal?.id === id) {
              this.selectedRenewal = r.renewal;
            }
            setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 4000);
            this.cdr.markForCheck();
          },
          error: (err) => {
            this.errorMsg      = err?.error?.message ?? this.lang.t('CONTRACTS.RENEWALS.MSG.APPROVAL_FAILED');
            this.actionLoading = null;
            this.cdr.markForCheck();
          },
        });
    } else {
      if (!this.actionRejectionReason.trim()) {
        this.errorMsg = this.lang.t('CONTRACTS.RENEWALS.MSG.ENTER_REASON');
        this.cdr.markForCheck();
        return;
      }
      this.actionLoading = id;
      this.http.post<any>(`${this.api}/${id}/reject`, { reason: this.actionRejectionReason })
        .pipe(takeUntil(this.destroy$)).subscribe({
          next: (r) => {
            this.successMsg       = r.message;
            this.showActionDialog = false;
            this.actionLoading    = null;
            this.loadRenewals(this.currentPage);
            this.loadStats();
            setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 4000);
            this.cdr.markForCheck();
          },
          error: (err) => {
            this.errorMsg      = err?.error?.message ?? this.lang.t('CONTRACTS.RENEWALS.MSG.REJECTION_FAILED');
            this.actionLoading = null;
            this.cdr.markForCheck();
          },
        });
    }
  }

  /**
   * Validate a chosen file: PDF/DOC/DOCX, ≤ 10 MB.
   *
   * @param file The selected file.
   * @returns An error message, or null when valid.
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

  /** Handle file selection in the create form. */
  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file  = input.files?.[0] ?? null;
    if (file) {
      const err = this.validateFile(file);
      if (err) { this.errorMsg = err; input.value = ''; this.cdr.markForCheck(); return; }
    }
    this.selectedFile = file;
    this.cdr.markForCheck();
  }

  /** Clear the staged file. */
  clearSelectedFile(): void { this.selectedFile = null; this.cdr.markForCheck(); }

  createManual(): void {
    if (this.createForm.invalid) { this.createForm.markAllAsTouched(); return; }
    this.submitting = true;
    this.http.post<any>(this.api, this.createForm.value).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        const renewalId = res?.renewal?.id;
        if (this.selectedFile && renewalId) {
          this.uploadDocumentFor(renewalId, this.selectedFile, () => this.finishCreate());
        } else {
          this.finishCreate();
        }
      },
      error: (err) => {
        this.errorMsg   = err?.error?.message ?? this.lang.t('CONTRACTS.RENEWALS.MSG.CREATE_FAILED');
        this.submitting = false;
        this.cdr.markForCheck();
      },
    });
  }

  /** Common post-create cleanup + refresh. */
  private finishCreate(): void {
    this.submitting     = false;
    this.showCreateForm = false;
    this.selectedFile   = null;
    this.successMsg     = this.lang.t('CONTRACTS.RENEWALS.MSG.CREATED');
    this.createForm.reset();
    this.loadRenewals();
    this.loadStats();
    setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3500);
    this.cdr.markForCheck();
  }

  /**
   * Upload a document to a renewal request via multipart/form-data.
   *
   * @param renewalId Target renewal id.
   * @param file      File to upload.
   * @param done      Optional success callback.
   */
  private uploadDocumentFor(renewalId: number, file: File, done?: () => void): void {
    const fd = new FormData();
    fd.append('document', file);
    this.http.post<any>(`${this.api}/${renewalId}/document`, fd)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => { if (done) done(); },
        error: (err) => {
          this.errorMsg   = err?.error?.message ?? this.lang.t('CONTRACTS.RENEWALS.MSG.UPLOAD_AFTER_CREATE_FAILED');
          this.submitting = false;
          this.cdr.markForCheck();
        },
      });
  }

  /** Upload a document to an existing renewal from a row/detail action. */
  onRowFileSelected(event: Event, renewal: RenewalRequest): void {
    const input = event.target as HTMLInputElement;
    const file  = input.files?.[0];
    if (!file) return;
    const err = this.validateFile(file);
    if (err) { this.errorMsg = err; input.value = ''; this.cdr.markForCheck(); return; }

    this.uploadingDocId = renewal.id;
    this.cdr.markForCheck();
    const fd = new FormData();
    fd.append('document', file);
    this.http.post<any>(`${this.api}/${renewal.id}/document`, fd)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (r) => {
          this.uploadingDocId = null;
          this.successMsg = this.lang.t('CONTRACTS.MSG.DOCUMENT_UPLOADED');
          if (this.showDetail && this.selectedRenewal?.id === renewal.id) {
            this.selectedRenewal = r.renewal;
          }
          this.loadRenewals(this.currentPage);
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

  /** Open the renewal document in a new tab (download). */
  downloadDocument(renewal: RenewalRequest): void {
    // The API authenticates with a Bearer header, which window.open() cannot send.
    this.http.get(`${this.api}/${renewal.id}/document`, { responseType: 'blob', observe: 'response' })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: response => {
          const disposition = response.headers.get('content-disposition') || '';
          const match = disposition.match(/filename="?([^"]+)"?/i);
          const url = URL.createObjectURL(response.body as Blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = match?.[1] || renewal.document?.name || renewal.reference;
          link.click();
          URL.revokeObjectURL(url);
        },
        error: () => { this.errorMsg = this.lang.t('CONTRACTS.MSG.DOWNLOAD_FAILED'); this.cdr.markForCheck(); },
      });
  }

  viewDetail(r: RenewalRequest): void {
    this.selectedRenewal = r;
    this.showDetail      = true;
    this.cdr.markForCheck();
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  canApprove(r: RenewalRequest): boolean {
    if (r.status === 'pending')          return this.isMgr;
    if (r.status === 'manager_approved') return this.isHR;
    if (r.status === 'hr_approved')      return this.isCEO;
    return false;
  }

  canReject(r: RenewalRequest): boolean {
    return this.canApprove(r);
  }

  stageLabel(status: string): string {
    const key = `CONTRACTS.RENEWALS.STAGE_LABEL.${status}`;
    const label = this.lang.t(key);
    return label !== key ? label : status;
  }

  /** Approval stage code from the API (manager / hr / ceo) as display text. */
  stageName(stage: string | null | undefined): string {
    if (!stage) return '';
    const key = `CONTRACTS.RENEWALS.STAGES.${String(stage).toUpperCase()}`;
    const label = this.lang.t(key);
    return label !== key ? label : stage;
  }

  statusClass(status: string): string {
    return ({
      pending:          'badge-yellow',
      manager_approved: 'badge-purple',
      hr_approved:      'badge-blue',
      approved:         'badge-green',
      rejected:         'badge-red',
      cancelled:        'badge-gray',
    } as any)[status] ?? 'badge-gray';
  }

  formatDate(d: string | null): string {
    if (!d) return '—';
    try { return ngFormatDate(d, 'dd MMM yyyy', this.lang.locale()); } catch { return d; }
  }

  formatDateTime(d: string | null): string {
    if (!d) return '—';
    try { return ngFormatDate(d, 'dd MMM yyyy, HH:mm', this.lang.locale()); } catch { return d; }
  }

  formatSalary(s: number | null): string {
    if (!s) return '—';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'SAR', maximumFractionDigits: 0 }).format(s);
  }

  avatarColor(name?: string | null): string {
    const p = ['#3b82f6','#10b981','#f59e0b','#ef4444','#6366f1','#0ea5e9','#f97316','#a78bfa'];
    return p[(name?.charCodeAt(0) ?? 0) % p.length];
  }

  initial(name?: string | null): string { return name?.charAt(0)?.toUpperCase() ?? '?'; }

  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    return Array.from({ length: Math.min(this.pagination.last_page, 8) }, (_, i) => i + 1);
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
