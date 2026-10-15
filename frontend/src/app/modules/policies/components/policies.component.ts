import { Component, OnInit, ChangeDetectionStrategy, ChangeDetectorRef, ElementRef, HostListener, ViewChild } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';

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
  audience_type?: 'all' | 'departments';
  target_department_ids?: number[];
  is_published: boolean;
  category: { id: number; name: string; icon?: string } | null;
  has_attachment: boolean;
  attachment_name: string | null;
  attachment_mime?: string | null;
  acknowledged?: boolean;
  is_read?: boolean;
  reads_count?: number;
}

type PolicyAction = 'open' | 'report' | 'edit' | 'publish' | 'delete';

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
export class PoliciesComponent implements OnInit {

  @ViewChild('pdfPages') private pdfPagesContainer?: ElementRef<HTMLDivElement>;

  private readonly api = '/api/v1/policies';

  isManager = false;
  loading = false;
  policies: Policy[] = [];
  categories: any[] = [];
  departments: any[] = [];
  filterCategoryId: number | null = null;
  filterDepartmentId: number | null = null;
  search = '';

  // Reader
  selected: Policy | null = null;
  showReader = false;
  acknowledging = false;
  pdfLoading = false;
  pdfError = '';
  pdfPageCount = 0;
  private previewGeneration = 0;

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

  successMsg = '';

  constructor(
    private readonly http: HttpClient,
    private readonly auth: AuthService,
    private readonly cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.isManager = this.auth.hasAnyRole(['super_admin', 'hr_manager', 'hr_staff'])
      || this.auth.can('policies.manage');
    this.loadCategories();
    this.loadDepartments();
    this.load();
  }

  private blankForm(): any {
    return { category_id: null, title: '', content: '', version: '1.0',
             effective_date: '', review_date: '', requires_acknowledgement: true,
             mandatory: false, is_published: true, audience_type: 'all',
             target_department_ids: [] };
  }

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
      next: r => { this.policies = r?.policies || []; this.loading = false; this.cdr.markForCheck(); },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  onFilterChange(): void { this.load(); }

  /** Policies grouped by category name for the accordion view. */
  get grouped(): { name: string; icon?: string; items: Policy[] }[] {
    const map = new Map<string, { name: string; icon?: string; items: Policy[] }>();
    for (const p of this.policies) {
      const key = p.category?.name || 'Uncategorized';
      if (!map.has(key)) map.set(key, { name: key, icon: p.category?.icon, items: [] });
      map.get(key)!.items.push(p);
    }
    return Array.from(map.values());
  }

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
        this.pdfError = `Secure policy preview request failed${status}.`;
        this.cdr.markForCheck();
      },
    });
  }

  private async renderPolicyPdf(blob: Blob, generation: number): Promise<void> {
    try {
      const pdfjs: any = await import('pdfjs-dist/legacy/build/pdf.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = '/assets/pdfjs/pdf.worker.min.mjs?v=5.4.624';
      const pdfDocument = await pdfjs.getDocument({ data: await blob.arrayBuffer() }).promise;
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
        canvas.setAttribute('aria-label', `Policy page ${pageNumber} of ${pdfDocument.numPages}`);
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
      this.pdfError = `The PDF could not be rendered.${detail}`;
      this.cdr.markForCheck();
    }
  }

  private clearPolicyPreview(): void {
    this.previewGeneration++;
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
        this.toast('Policy acknowledged.');
      },
      error: err => { this.acknowledging = false; this.toast(this.firstError(err) || 'Failed.'); },
    });
  }

  downloadAttachment(p: Policy): void {
    window.open(`${this.api}/${p.id}/attachment`, '_blank');
  }

  private blankCategoryForm(): any {
    return { name: '', icon: 'policy', sort_order: 0, audience_type: 'all', target_department_ids: [] };
  }

  canDownloadAttachment(p: Policy): boolean {
    return this.isManager || (p.document_type || '').trim().toLowerCase() === 'form';
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
        this.toast('Attachment preview is unavailable.');
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
        version: p.version, effective_date: p.effective_date ?? '',
        review_date: p.review_date ?? '', mandatory: p.mandatory ?? false,
        requires_acknowledgement: p.requires_acknowledgement, is_published: p.is_published,
        audience_type: p.audience_type || 'all',
        target_department_ids: (p.target_department_ids || []).map(Number),
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
      this.formError = 'File must be 10 MB or smaller.';
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
    if (!this.form.title?.trim()) { this.formError = 'Title is required.'; this.cdr.markForCheck(); return; }
    if (this.form.audience_type === 'departments' && !(this.form.target_department_ids || []).length) {
      this.formError = 'Select at least one department, or choose All departments.';
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
        this.toast(this.editId ? 'Policy updated.' : 'Policy created.');
        this.load();
      },
      error: err => {
        this.submitting = false;
        this.formError = this.firstError(err) || 'Save failed.';
        this.cdr.markForCheck();
      },
    });
  }

  deletePolicy(p: Policy): void {
    if (!confirm(`Delete policy "${p.title}"?`)) return;
    this.http.delete(`${this.api}/${p.id}`).subscribe({
      next: () => { this.toast('Policy deleted.'); this.load(); },
      error: err => this.toast(this.firstError(err) || 'Delete failed.'),
    });
  }

  togglePublish(p: Policy): void {
    const nextPublished = !p.is_published;
    const label = nextPublished ? 'publish' : 'move to draft';
    if (!confirm(`Do you want to ${label} "${p.title}"?`)) return;

    const payload = {
      audience_type: p.audience_type || 'all',
      target_department_ids: p.audience_type === 'departments' ? (p.target_department_ids || []) : [],
      is_published: nextPublished,
    };

    this.http.put<any>(`${this.api}/${p.id}`, payload).subscribe({
      next: () => {
        p.is_published = nextPublished;
        this.toast(nextPublished ? 'Policy published.' : 'Policy moved to draft.');
        this.load();
      },
      error: err => this.toast(this.firstError(err) || 'Publish update failed.'),
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
      this.catError = 'Category name is required.';
      this.cdr.markForCheck();
      return;
    }
    if (this.catForm.audience_type === 'departments' && !(this.catForm.target_department_ids || []).length) {
      this.catError = 'Select at least one department, or choose All departments.';
      this.cdr.markForCheck();
      return;
    }
    this.catError = '';

    const req = this.catEditId
      ? this.http.put<any>(`${this.api}/categories/${this.catEditId}`, this.catForm)
      : this.http.post<any>(`${this.api}/categories`, this.catForm);

    req.subscribe({
      next: () => { this.resetCatForm(); this.loadCategories(); this.toast('Category saved.'); },
      error: err => { this.catError = this.firstError(err) || 'Save failed.'; this.cdr.markForCheck(); },
    });
  }

  deleteCategory(c: any): void {
    if (!confirm(`Delete category "${c.name}"? Policies keep their content but lose this category.`)) return;
    this.http.delete(`${this.api}/categories/${c.id}`).subscribe({
      next: () => { this.loadCategories(); this.load(); this.toast('Category deleted.'); },
      error: err => this.toast(this.firstError(err) || 'Delete failed.'),
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
      error: err => this.toast(this.firstError(err) || 'CSV download failed.'),
    });
  }

  /** Send a reminder to everyone who hasn't acknowledged the current version. */
  remindPending(): void {
    if (!this.reportPolicyId) return;
    this.reminding = true;
    this.http.post<any>(`${this.api}/${this.reportPolicyId}/remind`, {}).subscribe({
      next: r => { this.reminding = false; this.toast(r?.message || 'Reminders sent.'); this.cdr.markForCheck(); },
      error: err => { this.reminding = false; this.toast(this.firstError(err) || 'Failed.'); this.cdr.markForCheck(); },
    });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────

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
