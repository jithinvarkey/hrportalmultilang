import { Component, OnInit, OnDestroy, ChangeDetectionStrategy, ChangeDetectorRef, ElementRef, HostListener, ViewChild } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

interface Policy {
  id: number;
  title: string;
  content: string | null;
  version: string;
  document_type?: string | null;
  effective_date: string | null;
  review_date?: string | null;
  requires_acknowledgement: boolean;
  mandatory?: boolean;
  audience_type?: 'all' | 'departments' | 'employees';
  target_department_ids?: number[];
  target_employee_ids?: number[];
  is_published: boolean;
  status?: string | null;
  /** 'qms' when the document was sent from the Quality Management System */
  source?: string | null;
  document_number?: string | null;
  owner_department?: string | null;
  expiry_date?: string | null;
  /** A QMS version waiting for HR to publish (the live version stays on the policy) */
  pending_version?: { id: number; version: string; change_summary?: string | null; received_at?: string | null; has_attachment?: boolean } | null;
  category: { id: number; name: string; icon?: string } | null;
  has_attachment: boolean;
  attachment_name: string | null;
  attachment_mime?: string | null;
  acknowledged?: boolean;
  is_read?: boolean;
  reads_count?: number;
}

type PolicyAction = 'open' | 'report' | 'edit' | 'publish' | 'delete' | 'history';

/**
 * HR Policies library. All employees view and acknowledge; HR/Admin manage
 * policies and categories, and can see who has acknowledged each policy.
 */
@Component({
  standalone:      false,
  selector:        'app-policies',
  templateUrl:     './policies.component.html',
  styleUrls:       ['./policies.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PoliciesComponent implements OnInit, OnDestroy {

  @ViewChild('pdfPages') private pdfPagesContainer?: ElementRef<HTMLDivElement>;

  private readonly api = '/api/v1/policies';

  isManager = false;
  loading = false;
  policies: Policy[] = [];
  categories: any[] = [];
  departments: any[] = [];
  employees: any[] = [];
  employeeSearch = '';
  onlyAwaiting = false;
  filterCategoryId: number | null = null;
  filterDepartmentId: number | null = null;
  search = '';
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

  // Reader
  selected: Policy | null = null;
  showReader = false;
  acknowledging = false;
  pdfLoading = false;
  pdfError = '';
  pdfPageCount = 0;
  private previewGeneration = 0;
  /** pdf.js loading task of the open preview; destroyed on close so the document and its worker data are freed. */
  private pdfLoadingTask: any = null;

  // Form (HR)
  showForm = false;
  editId: number | null = null;
  submitting = false;
  formError = '';
  form: any = this.blankForm();
  selectedFile: File | null = null;

  showCategories = false;
  catEditId: number | null = null;
  catForm: any = this.blankCategoryForm();
  catError = '';

  // Acknowledgement report (HR)
  showReport = false;
  report: any = null;
  reportLoading = false;
  reportPolicyId: number | null = null;
  reminding = false;

  // QMS documents: publish panel + version history (HR)
  showQms = false;
  qmsPolicy: Policy | null = null;
  qmsForm: any = {};
  qmsSearch = '';
  qmsVersions: any[] = [];
  qmsVersionsLoading = false;
  publishing = false;
  publishError = '';

  successMsg = '';

  constructor(
    private readonly http: HttpClient,
    private readonly auth: AuthService,
    private readonly cdr: ChangeDetectorRef,
    private readonly lang: LanguageService,
  ) {}

  ngOnInit(): void {
    this.isManager = this.auth.hasAnyRole(['super_admin', 'hr_manager', 'hr_staff'])
      || this.auth.can('policies.manage');
    this.loadCategories();
    this.loadDepartments();
    this.loadEmployees();
    this.load();
  }

  private blankForm(): any {
    return { category_id: null, title: '', content: '', version: '1.0',
             effective_date: '', review_date: '', requires_acknowledgement: true,
             mandatory: false, is_published: true, audience_type: 'all',
             target_department_ids: [], target_employee_ids: [] };
  }

  loadEmployees(): void {
    if (!this.isManager) return;
    this.http.get<any>('/api/v1/employees', { params: { per_page: 1000, status: 'active' } }).subscribe({
      next: r => { this.employees = Array.isArray(r) ? r : (r?.data || []); this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  employeeLabel(e: any): string {
    return e.full_name || `${e.first_name || ''} ${e.last_name || ''}`.trim() || e.email || `#${e.id}`;
  }

  /** Employees matching a search box, selected ones first */
  filteredEmployees(selected: number[], term: string): any[] {
    const q = (term || '').trim().toLowerCase();
    const list = q
      ? this.employees.filter(e => `${this.employeeLabel(e)} ${e.email || ''} ${e.employee_code || ''}`.toLowerCase().includes(q))
      : this.employees;
    return [...list].sort((a, b) => Number(selected.includes(b.id)) - Number(selected.includes(a.id))).slice(0, 200);
  }

  employeeNames(ids: number[]): string {
    return (ids || []).map(id => this.employees.find(e => e.id === id)).filter(Boolean).map(e => this.employeeLabel(e)).join(', ');
  }

  toggleIn(arr: number[], id: number): void {
    const n = Number(id);
    const i = arr.indexOf(n);
    if (i >= 0) arr.splice(i, 1); else arr.push(n);
    this.cdr.markForCheck();
  }

  /** QMS document waiting for HR (first version, or a new version of a live one) */
  isAwaiting(p: Policy): boolean {
    return p.source === 'qms' && (!!p.pending_version || (!p.is_published && p.status === 'pending_publication'));
  }

  get awaitingCount(): number { return this.policies.filter(p => this.isAwaiting(p)).length; }

  awaitingVersion(p: Policy): string { return p.pending_version?.version || p.version; }

  loadCategories(): void {
    this.http.get<any>(`${this.api}/categories`).subscribe({
      next: r => { this.categories = r?.categories || []; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  loadDepartments(): void {
    if (!this.isManager) return;
    this.http.get<any>('/api/v1/departments').subscribe({
      next: r => { this.departments = Array.isArray(r) ? r : (r?.data || []); this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  load(): void {
    this.loading = true;
    const params: any = {};
    if (this.filterCategoryId) params.category_id = this.filterCategoryId;
    if (this.filterDepartmentId) params.department_id = this.filterDepartmentId;
    if (this.search.trim()) params.search = this.search.trim();

    this.http.get<any>(this.api, { params }).subscribe({
      next: r => { this.setPolicies(r?.policies || []); this.loading = false; this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  onFilterChange(): void {
    if (this.searchTimer) { clearTimeout(this.searchTimer); this.searchTimer = null; }
    this.load();
  }

  /** Search as you type, once typing pauses (Enter still searches immediately). */
  onSearchInput(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => { this.searchTimer = null; this.load(); }, 400);
  }

  /**
   * Policies grouped by category name for the accordion view (category null = uncategorized; label translated in
   * the template). Built once per load — not as a getter — so typing in the page does not rebuild every row.
   */
  grouped: { name: string; category: Policy['category']; icon?: string; items: Policy[] }[] = [];

  private setPolicies(list: Policy[]): void {
    this.policies = list;
    const map = new Map<string, { name: string; category: Policy['category']; icon?: string; items: Policy[] }>();
    for (const p of list) {
      const key = p.category?.name || '';
      if (!map.has(key)) map.set(key, { name: key, category: p.category?.name ? p.category : null, icon: p.category?.icon, items: [] });
      map.get(key)!.items.push(p);
    }
    this.grouped = Array.from(map.values());
  }

  trackGroup = (_: number, g: { name: string }) => g.name;
  trackPolicy = (_: number, p: Policy) => p.id;
  trackEmployee = (_: number, e: any) => e.id;

  get pendingAckCount(): number {
    return this.policies.filter(p => p.requires_acknowledgement && !p.acknowledged).length;
  }

  // ── Reader ──────────────────────────────────────────────────────────────

  openReader(p: Policy): void {
    this.selected = p;
    this.clearPolicyPreview();
    this.showReader = true;
    this.cdr.markForCheck();
    // Fetch full content (list may omit large content for brevity in future).
    this.http.get<any>(`${this.api}/${p.id}`).subscribe({
      next: r => {
        this.selected = r?.policy || p;
        const inList = this.policies.find(item => item.id === p.id);
        if (inList) {
          inList.is_read = true;
          inList.reads_count = this.selected?.reads_count ?? inList.reads_count;
        }
        if (!this.isManager && this.selected?.has_attachment && this.selected.attachment_mime === 'application/pdf') {
          this.loadPolicyPreview(this.selected);
        }
        this.cdr.markForCheck();
      },
      error: () => {},
    });
  }

  closeReader(): void {
    this.showReader = false;
    this.selected = null;
    this.clearPolicyPreview();
    this.cdr.markForCheck();
  }

  private loadPolicyPreview(policy: Policy): void {
    const generation = ++this.previewGeneration;
    this.pdfLoading = true;
    this.pdfError = '';
    this.cdr.markForCheck();
    this.http.get(`${this.api}/${policy.id}/attachment`, {
      params: { inline: '1' },
      responseType: 'blob',
    }).subscribe({
      next: blob => this.renderPolicyPdf(blob, generation),
      error: err => {
        this.pdfLoading = false;
        const status = err?.status ? ` (HTTP ${err.status})` : '';
        this.pdfError = this.lang.t('POLICIES.MSG.PREVIEW_FAILED', { status });
        this.cdr.markForCheck();
      },
    });
  }

  private async renderPolicyPdf(blob: Blob, generation: number): Promise<void> {
    try {
      const pdfjs: any = await import('pdfjs-dist/legacy/build/pdf.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = '/assets/pdfjs/pdf.worker.min.mjs?v=5.4.624';
      const data = await blob.arrayBuffer();
      if (generation !== this.previewGeneration) return;
      const loadingTask = pdfjs.getDocument({ data });
      this.pdfLoadingTask = loadingTask;
      const pdfDocument = await loadingTask.promise;
      if (generation !== this.previewGeneration) return;

      this.pdfPageCount = pdfDocument.numPages;
      this.pdfLoading = false;
      this.cdr.detectChanges();
      const container = this.pdfPagesContainer?.nativeElement;
      if (!container) return;
      container.replaceChildren();

      for (let pageNumber = 1; pageNumber <= pdfDocument.numPages; pageNumber++) {
        if (generation !== this.previewGeneration) return;
        const page = await pdfDocument.getPage(pageNumber);
        const natural = page.getViewport({ scale: 1 });
        const targetWidth = Math.max(280, Math.min(container.clientWidth - 32, 1150));
        const viewport = page.getViewport({ scale: targetWidth / natural.width });
        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { alpha: false });
        if (!context) throw new Error('Canvas rendering is unavailable.');

        canvas.width = Math.floor(viewport.width * outputScale);
        canvas.height = Math.floor(viewport.height * outputScale);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;
        canvas.style.display = 'block';
        canvas.style.maxWidth = '100%';
        canvas.style.margin = '0 auto 20px';
        canvas.style.background = '#fff';
        canvas.style.boxShadow = '0 2px 12px rgba(0,0,0,.22)';
        canvas.setAttribute('aria-label', this.lang.t('POLICIES.READER.PAGE_LABEL', { page: pageNumber, total: pdfDocument.numPages }));
        container.appendChild(canvas);

        await page.render({
          canvas: null,
          canvasContext: context,
          viewport,
          transform: outputScale === 1 ? undefined : [outputScale, 0, 0, outputScale, 0, 0],
        }).promise;
      }
    } catch (error) {
      if (generation !== this.previewGeneration) return;
      console.error('Policy PDF preview failed.', error);
      this.pdfLoading = false;
      const detail = error instanceof Error && error.message
        ? ` ${error.message}`
        : '';
      this.pdfError = this.lang.t('POLICIES.MSG.RENDER_FAILED', { detail });
      this.cdr.markForCheck();
    }
  }

  private clearPolicyPreview(): void {
    this.previewGeneration++;
    if (this.pdfLoadingTask) {
      this.pdfLoadingTask.destroy?.().catch?.(() => {});
      this.pdfLoadingTask = null;
    }
    this.pdfLoading = false;
    this.pdfError = '';
    this.pdfPageCount = 0;
    this.pdfPagesContainer?.nativeElement.replaceChildren();
  }

  blockPolicyActions(event: Event): void {
    if (this.isManager) return;
    event.preventDefault();
    event.stopPropagation();
  }

  @HostListener('document:keydown', ['$event'])
  blockPolicyShortcuts(event: KeyboardEvent): void {
    if (!this.showReader || this.isManager || !(event.ctrlKey || event.metaKey)) return;
    if (['s', 'p'].includes(event.key.toLowerCase())) {
      event.preventDefault();
      event.stopPropagation();
    }
  }

  acknowledge(): void {
    if (!this.selected) return;
    this.acknowledging = true;
    this.http.post<any>(`${this.api}/${this.selected.id}/acknowledge`, {}).subscribe({
      next: () => {
        this.acknowledging = false;
        if (this.selected) this.selected.acknowledged = true;
        // reflect in the list
        const inList = this.policies.find(p => p.id === this.selected?.id);
        if (inList) inList.acknowledged = true;
        this.toast(this.lang.t('POLICIES.MSG.ACKNOWLEDGED'));
      },
      error: err => { this.acknowledging = false; this.toast(this.firstError(err) || this.lang.t('POLICIES.MSG.FAILED')); },
    });
  }

  downloadAttachment(p: Policy): void {
    // The API authenticates with a Bearer header, which window.open() cannot send.
    this.http.get(`${this.api}/${p.id}/attachment`, { responseType: 'blob' }).subscribe({
      next: blob => this.saveBlob(blob, p.attachment_name || `policy-${p.id}.pdf`),
      error: () => this.toast(this.lang.t('POLICIES.MSG.PREVIEW_UNAVAILABLE')),
    });
  }

  ngOnDestroy(): void {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.clearPolicyPreview();
  }

  private blankCategoryForm(): any {
    return { name: '', icon: 'policy', sort_order: 0, audience_type: 'all', target_department_ids: [] };
  }

  canDownloadAttachment(p: Policy): boolean {
    return this.isManager || (p.document_type || '').trim().toLowerCase() === 'form'
      || (p.source === 'qms' && !!p.attachment_mime && p.attachment_mime !== 'application/pdf');
  }

  viewAttachment(p: Policy): void {
    const previewWindow = window.open('', '_blank');
    this.http.get(`${this.api}/${p.id}/attachment`, {
      params: { inline: '1' },
      responseType: 'blob',
    }).subscribe({
      next: blob => {
        const previewUrl = URL.createObjectURL(blob);
        if (previewWindow) {
          previewWindow.location.href = previewUrl;
        } else {
          window.open(previewUrl, '_blank');
        }
      },
      error: () => {
        previewWindow?.close();
        this.toast(this.lang.t('POLICIES.MSG.PREVIEW_UNAVAILABLE'));
      },
    });
  }

  canPreviewAttachment(p: Policy): boolean {
    const mime = (p.attachment_mime || '').toLowerCase();
    if (mime === 'application/pdf' || ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'].includes(mime)) {
      return true;
    }

    return /\.(pdf|png|jpe?g|gif|webp|bmp|avif)$/i.test(p.attachment_name || '');
  }

  onPolicyAction(event: Event, action: PolicyAction, policy: Policy): void {
    event.preventDefault();
    event.stopPropagation();

    if (action === 'open') {
      this.openReader(policy);
      return;
    }

    if (action === 'report') {
      this.openReport(policy);
      return;
    }

    if (action === 'edit') {
      this.openForm(policy);
      return;
    }

    if (action === 'publish') {
      this.togglePublish(policy);
      return;
    }

    if (action === 'history') {
      this.openQms(policy);
      return;
    }

    this.deletePolicy(policy);
  }

  // ── Form (HR) ───────────────────────────────────────────────────────────

  openForm(p?: Policy): void {
    this.formError = '';
    this.selectedFile = null;
    if (p) {
      this.editId = p.id;
      this.form = {
        category_id: p.category?.id ?? null, title: p.title, content: p.content || '',
        // Date casts arrive as UTC ISO strings (previous day 21:00Z in Asia/Riyadh); the inputs need yyyy-MM-dd.
        version: p.version, effective_date: this.dateInputValue(p.effective_date),
        review_date: this.dateInputValue(p.review_date), mandatory: p.mandatory ?? false,
        requires_acknowledgement: p.requires_acknowledgement, is_published: p.is_published,
        audience_type: p.audience_type || 'all',
        target_department_ids: (p.target_department_ids || []).map(Number),
        target_employee_ids: (p.target_employee_ids || []).map(Number),
      };
    } else {
      this.editId = null;
      this.form = this.blankForm();
    }
    this.showForm = true;
    this.cdr.markForCheck();
  }

  closeForm(): void { this.showForm = false; this.cdr.markForCheck(); }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (file && file.size > 10 * 1024 * 1024) {
      this.formError = this.lang.t('POLICIES.MSG.FILE_TOO_LARGE');
      input.value = ''; this.cdr.markForCheck(); return;
    }
    this.selectedFile = file;
    this.cdr.markForCheck();
  }

  toggleDept(id: number): void {
    const arr = (this.form.target_department_ids || []) as number[];
    const numericId = Number(id);
    this.form.target_department_ids = arr.includes(numericId)
      ? arr.filter(x => x !== numericId)
      : [...arr, numericId];
    this.cdr.markForCheck();
  }

  toggleCategoryDept(id: number): void {
    const arr = (this.catForm.target_department_ids || []) as number[];
    const numericId = Number(id);
    this.catForm.target_department_ids = arr.includes(numericId)
      ? arr.filter(x => x !== numericId)
      : [...arr, numericId];
    this.cdr.markForCheck();
  }

  savePolicy(): void {
    if (!this.form.title?.trim()) { this.formError = this.lang.t('POLICIES.MSG.TITLE_REQUIRED'); this.cdr.markForCheck(); return; }
    if (this.form.audience_type === 'departments' && !(this.form.target_department_ids || []).length) {
      this.formError = this.lang.t('POLICIES.MSG.SELECT_DEPARTMENT');
      this.cdr.markForCheck();
      return;
    }
    if (this.form.audience_type === 'employees' && !(this.form.target_employee_ids || []).length) {
      this.formError = this.lang.t('POLICIES.MSG.SELECT_EMPLOYEE');
      this.cdr.markForCheck();
      return;
    }
    this.submitting = true;
    this.formError = '';

    const fd = new FormData();
    fd.append('title', this.form.title);
    fd.append('content', this.form.content || '');
    fd.append('version', this.form.version || '1.0');
    fd.append('requires_acknowledgement', this.form.requires_acknowledgement ? '1' : '0');
    fd.append('mandatory', this.form.mandatory ? '1' : '0');
    fd.append('is_published', this.form.is_published ? '1' : '0');
    fd.append('audience_type', this.form.audience_type || 'all');
    if (this.form.audience_type === 'departments') {
      for (const id of this.form.target_department_ids || []) {
        fd.append('target_department_ids[]', String(id));
      }
    }
    if (this.form.audience_type === 'employees') {
      for (const id of this.form.target_employee_ids || []) {
        fd.append('target_employee_ids[]', String(id));
      }
    }
    if (this.form.category_id) fd.append('category_id', String(this.form.category_id));
    if (this.form.effective_date) fd.append('effective_date', this.form.effective_date);
    if (this.form.review_date) fd.append('review_date', this.form.review_date);
    if (this.selectedFile) fd.append('attachment', this.selectedFile);
    if (this.editId) fd.append('_method', 'PUT');

    const url = this.editId ? `${this.api}/${this.editId}` : this.api;
    this.http.post<any>(url, fd).subscribe({
      next: () => {
        this.submitting = false;
        this.showForm = false;
        this.toast(this.lang.t(this.editId ? 'POLICIES.MSG.UPDATED' : 'POLICIES.MSG.CREATED'));
        this.load();
      },
      error: err => {
        this.submitting = false;
        this.formError = this.firstError(err) || this.lang.t('MESSAGES.SAVE_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  deletePolicy(p: Policy): void {
    if (!confirm(this.lang.t('POLICIES.MSG.CONFIRM_DELETE', { title: p.title }))) return;
    this.http.delete(`${this.api}/${p.id}`).subscribe({
      next: () => { this.toast(this.lang.t('POLICIES.MSG.DELETED')); this.load(); },
      error: err => this.toast(this.firstError(err) || this.lang.t('MESSAGES.DELETE_FAILED')),
    });
  }

  togglePublish(p: Policy): void {
    // QMS documents waiting for HR are published through the QMS panel (audience + version)
    if (this.isAwaiting(p)) { this.openQms(p); return; }
    const nextPublished = !p.is_published;
    const confirmKey = nextPublished ? 'POLICIES.MSG.CONFIRM_PUBLISH' : 'POLICIES.MSG.CONFIRM_DRAFT';
    if (!confirm(this.lang.t(confirmKey, { title: p.title }))) return;

    const payload = {
      audience_type: p.audience_type || 'all',
      target_department_ids: p.audience_type === 'departments' ? (p.target_department_ids || []) : [],
      target_employee_ids: p.audience_type === 'employees' ? (p.target_employee_ids || []) : [],
      is_published: nextPublished,
    };

    this.http.put<any>(`${this.api}/${p.id}`, payload).subscribe({
      next: () => {
        p.is_published = nextPublished;
        this.toast(this.lang.t(nextPublished ? 'POLICIES.MSG.PUBLISHED' : 'POLICIES.MSG.MOVED_TO_DRAFT'));
        this.load();
      },
      error: err => this.toast(this.firstError(err) || this.lang.t('POLICIES.MSG.PUBLISH_FAILED')),
    });
  }

  // ── QMS documents (HR): publish waiting version, version history ──────

  openQms(p: Policy): void {
    this.qmsPolicy = p;
    this.publishError = '';
    this.qmsSearch = '';
    this.qmsForm = {
      audience_type: p.audience_type || 'all',
      target_department_ids: (p.target_department_ids || []).map(Number),
      target_employee_ids: (p.target_employee_ids || []).map(Number),
      requires_acknowledgement: p.requires_acknowledgement ?? true,
      mandatory: p.mandatory ?? false,
    };
    this.showQms = true;
    this.loadVersions(p.id);
    this.cdr.markForCheck();
  }

  closeQms(): void { this.showQms = false; this.qmsPolicy = null; this.cdr.markForCheck(); }

  loadVersions(id: number): void {
    this.qmsVersions = [];
    this.qmsVersionsLoading = true;
    this.http.get<any>(`${this.api}/${id}/versions`).subscribe({
      next: r => { this.qmsVersions = r?.versions || []; this.qmsVersionsLoading = false; this.cdr.markForCheck(); },
      error: () => { this.qmsVersionsLoading = false; this.cdr.markForCheck(); },
    });
  }

  publishQms(): void {
    const p = this.qmsPolicy;
    if (!p) return;
    const f = this.qmsForm;
    if (f.audience_type === 'departments' && !f.target_department_ids.length) { this.publishError = this.lang.t('POLICIES.MSG.SELECT_DEPARTMENT'); this.cdr.markForCheck(); return; }
    if (f.audience_type === 'employees' && !f.target_employee_ids.length) { this.publishError = this.lang.t('POLICIES.MSG.SELECT_EMPLOYEE'); this.cdr.markForCheck(); return; }
    if (!confirm(this.lang.t('POLICIES.QMS.CONFIRM_PUBLISH', { title: p.title, version: this.awaitingVersion(p) }))) return;

    this.publishing = true;
    this.publishError = '';
    this.http.post<any>(`${this.api}/${p.id}/publish`, {
      audience_type: f.audience_type,
      target_department_ids: f.audience_type === 'departments' ? f.target_department_ids : [],
      target_employee_ids: f.audience_type === 'employees' ? f.target_employee_ids : [],
      requires_acknowledgement: f.requires_acknowledgement,
      mandatory: f.mandatory,
    }).subscribe({
      next: r => {
        this.publishing = false;
        this.showQms = false;
        this.toast(r?.message || this.lang.t('POLICIES.MSG.PUBLISHED'));
        this.load();
      },
      error: err => {
        this.publishing = false;
        this.publishError = this.firstError(err) || this.lang.t('POLICIES.MSG.PUBLISH_FAILED');
        this.cdr.markForCheck();
      },
    });
  }

  /** Download / view the file of a specific version (e.g. to review a waiting version) */
  downloadVersion(v: any): void {
    if (!this.qmsPolicy) return;
    this.http.get(`${this.api}/${this.qmsPolicy.id}/versions/${v.id}/attachment`, { responseType: 'blob' }).subscribe({
      next: blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = v.attachment_name || `v${v.version}`;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      },
      error: () => this.toast(this.lang.t('POLICIES.QMS.FILE_FAILED')),
    });
  }

  // ── Acknowledgement report (HR) ─────────────────────────────────────────

  openCategories(): void {
    this.showCategories = true;
    this.resetCatForm();
    this.catError = '';
    this.cdr.markForCheck();
  }

  closeCategories(): void {
    this.showCategories = false;
    this.cdr.markForCheck();
  }

  resetCatForm(): void {
    this.catEditId = null;
    this.catForm = this.blankCategoryForm();
  }

  editCategory(c: any): void {
    this.catEditId = c.id;
    this.catForm = {
      name: c.name,
      icon: c.icon || 'policy',
      sort_order: c.sort_order || 0,
      audience_type: c.audience_type || 'all',
      target_department_ids: (c.target_department_ids || []).map(Number),
    };
    this.cdr.markForCheck();
  }

  saveCategory(): void {
    if (!this.catForm.name?.trim()) {
      this.catError = this.lang.t('POLICIES.MSG.CATEGORY_NAME_REQUIRED');
      this.cdr.markForCheck();
      return;
    }
    if (this.catForm.audience_type === 'departments' && !(this.catForm.target_department_ids || []).length) {
      this.catError = this.lang.t('POLICIES.MSG.SELECT_DEPARTMENT');
      this.cdr.markForCheck();
      return;
    }
    this.catError = '';

    const req = this.catEditId
      ? this.http.put<any>(`${this.api}/categories/${this.catEditId}`, this.catForm)
      : this.http.post<any>(`${this.api}/categories`, this.catForm);

    req.subscribe({
      next: () => { this.resetCatForm(); this.loadCategories(); this.toast(this.lang.t('POLICIES.MSG.CATEGORY_SAVED')); },
      error: err => { this.catError = this.firstError(err) || this.lang.t('MESSAGES.SAVE_FAILED'); this.cdr.markForCheck(); },
    });
  }

  deleteCategory(c: any): void {
    if (!confirm(this.lang.t('POLICIES.MSG.CONFIRM_DELETE_CATEGORY', { name: c.name }))) return;
    this.http.delete(`${this.api}/categories/${c.id}`).subscribe({
      next: () => { this.loadCategories(); this.load(); this.toast(this.lang.t('POLICIES.MSG.CATEGORY_DELETED')); },
      error: err => this.toast(this.firstError(err) || this.lang.t('MESSAGES.DELETE_FAILED')),
    });
  }

  openReport(p: Policy): void {
    this.reportPolicyId = p.id;
    this.showReport = true;
    this.report = null;
    this.reportLoading = true;
    this.cdr.markForCheck();
    this.http.get<any>(`${this.api}/${p.id}/acknowledgements`).subscribe({
      next: r => { this.report = r; this.reportLoading = false; this.cdr.markForCheck(); },
      error: () => { this.reportLoading = false; this.cdr.markForCheck(); },
    });
  }

  closeReport(): void { this.showReport = false; this.report = null; this.cdr.markForCheck(); }

  /** Download the acknowledgement report as CSV. */
  exportReport(): void {
    if (!this.reportPolicyId) return;
    const filename = `policy-${this.reportPolicyId}-acknowledgements.csv`;
    this.http.get(`${this.api}/${this.reportPolicyId}/acknowledgements/export`, {
      responseType: 'blob',
    }).subscribe({
      next: blob => this.saveBlob(blob, filename),
      error: err => this.toast(this.firstError(err) || this.lang.t('POLICIES.MSG.CSV_FAILED')),
    });
  }

  /** Send a reminder to everyone who hasn't acknowledged the current version. */
  remindPending(): void {
    if (!this.reportPolicyId) return;
    this.reminding = true;
    this.http.post<any>(`${this.api}/${this.reportPolicyId}/remind`, {}).subscribe({
      next: r => { this.reminding = false; this.toast(r?.message || this.lang.t('POLICIES.MSG.REMINDERS_SENT')); this.cdr.markForCheck(); },
      error: err => { this.reminding = false; this.toast(this.firstError(err) || this.lang.t('POLICIES.MSG.FAILED')); this.cdr.markForCheck(); },
    });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────

  /** Legacy document types are free text; known ones are translated, anything else is shown as stored. */
  documentTypeLabel(type: string | null | undefined): string {
    const raw = (type || '').trim();
    if (!raw) return '';
    const key = `POLICIES.DOCUMENT_TYPE.${raw.toLowerCase()}`;
    const label = this.lang.t(key);
    return label !== key ? label : raw;
  }


  private dateInputValue(value: string | null | undefined): string {
    if (!value) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value.substring(0, 10);
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  private toast(msg: string): void {
    this.successMsg = msg; this.cdr.markForCheck();
    setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3500);
  }

  private saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  private firstError(err: any): string {
    if (err?.error?.errors) {
      const first = Object.values(err.error.errors)[0];
      if (Array.isArray(first) && first.length) return first[0] as string;
    }
    return err?.error?.message || '';
  }
}
