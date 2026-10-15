import { Component, OnInit, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { PageEvent } from '@angular/material/paginator';

interface Asset {
  id: number;
  name: string;
  asset_code: string;
  brand: string | null;
  model: string | null;
  serial_number: string | null;
  status: 'available' | 'assigned' | 'under_maintenance' | 'disposed' | 'lost';
  condition: 'new' | 'good' | 'fair' | 'poor';
  location: string | null;
  purchase_price: string | null;
  purchase_date: string | null;
  vendor: string | null;
  warranty_expiry: string | null;
  category: { id: number; name: string; icon: string | null } | null;
  custodian: { id: number; name: string; employee_code: string } | null;
  assigned_date: string | null;
  return_date: string | null;
  current_assignment: {
    id: number;
    assigned_date: string | null;
    return_date: string | null;
    condition_at_assign: string | null;
    condition_at_return: string | null;
    notes: string | null;
  } | null;
  has_attachment: boolean;
  attachment_name: string | null;
  description: string | null;
}

/**
 * Full asset management console (HR/Admin).
 * Inventory list, category manager, assign/return, maintenance log.
 */
@Component({
  standalone: false,
  selector: 'app-assets',
  templateUrl: './assets.component.html',
  styleUrls: ['./assets.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AssetsComponent implements OnInit {

  private readonly api = '/api/v1/assets';

  isManager = false;
  canManage = false;
  loading = false;
  assets: Asset[] = [];
  categories: any[] = [];
  employees: any[] = [];
  stats: any = null;
  totalAssets = 0;
  pageIndex = 0;
  pageSize = 15;
  readonly pageSizeOptions = [15, 25, 50, 100];

  // Filters
  filterStatus = '';
  filterCategoryId: number | null = null;
  search = '';

  // Detail drawer
  selected: Asset | null = null;
  selectedDetail: any = null;
  showDetail = false;
  detailLoading = false;
  detailError = '';

  // Asset form
  showForm = false;
  editId: number | null = null;
  submitting = false;
  formError = '';
  form: any = this.blankForm();
  selectedFile: File | null = null;

  // Assign modal
  showAssign = false;
  assignForm: any = { employee_id: null, assigned_date: this.today(), condition_at_assign: 'good', notes: '' };
  assigning = false;

  // Return modal
  showReturn = false;
  returnForm: any = { return_date: this.today(), condition_at_return: 'good', notes: '' };
  returning = false;

  // Maintenance modal
  showMaintenance = false;
  maintenanceForm: any = { type: 'service', title: '', description: '', scheduled_date: '', cost: '', vendor: '', status: 'scheduled' };
  maintenanceSaving = false;

  // Category manager
  showCategories = false;
  catForm: any = { name: '', icon: 'devices' };
  catEditId: number | null = null;

  successMsg = '';
  errorMsg = '';

  readonly conditions = ['new', 'good', 'fair', 'poor'];
  readonly statuses   = ['available', 'assigned', 'under_maintenance', 'disposed', 'lost'];
  readonly maintTypes = ['repair', 'service', 'inspection', 'upgrade'];

  get pageTitle(): string {
    return this.isManager ? 'Asset Management' : 'My Assets';
  }

  get pageSubtitle(): string {
    return this.isManager
      ? 'Track and manage company assets across all employees'
      : 'View the company assets currently assigned to you';
  }

  constructor(
    private readonly http: HttpClient,
    private readonly auth: AuthService,
    private readonly cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    this.isManager = this.auth.hasAssetManagementAccess();
    this.canManage = this.auth.canManageAssets();
    this.loadCategories();
    this.load();
    if (this.isManager) {
      this.loadStats();
      if (this.canManage) this.loadEmployees();
    }
  }

  private blankForm(): any {
    return {
      category_id: null, name: '', asset_code: '', brand: '', model: '',
      serial_number: '', description: '', condition: 'good',
      purchase_price: '', purchase_date: '', vendor: '', warranty_expiry: '', location: '',
    };
  }

  private today(): string {
    return new Date().toISOString().substring(0, 10);
  }

  loadStats(): void {
    this.http.get<any>(`${this.api}/stats`).subscribe({
      next: r => { this.stats = r; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  loadCategories(): void {
    this.http.get<any>(`${this.api}/categories`).subscribe({
      next: r => { this.categories = r?.categories || []; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  loadEmployees(): void {
    this.http.get<any>('/api/v1/employees?per_page=500').subscribe({
      next: r => { this.employees = r?.data || r?.employees || []; this.cdr.markForCheck(); },
      error: () => {},
    });
  }

  load(): void {
    this.loading = true;
    const params: any = {};
    if (this.filterStatus) params.status = this.filterStatus;
    if (this.filterCategoryId) params.category_id = this.filterCategoryId;
    if (this.search.trim()) params.search = this.search.trim();
    params.page = this.pageIndex + 1;
    params.per_page = this.pageSize;

    this.http.get<any>(this.api, { params }).subscribe({
      next: r => {
        this.assets = r?.data || [];
        this.totalAssets = Number(r?.total ?? this.assets.length);
        this.pageIndex = Math.max(0, Number(r?.current_page ?? 1) - 1);
        this.pageSize = Number(r?.per_page ?? this.pageSize);
        this.loading = false;
        this.cdr.markForCheck();
      },
      error: () => { this.loading = false; this.cdr.markForCheck(); },
    });
  }

  onFilterChange(): void {
    this.pageIndex = 0;
    this.load();
  }

  onPageChange(event: PageEvent): void {
    this.pageIndex = event.pageIndex;
    this.pageSize = event.pageSize;
    this.load();
  }

  // ── Detail drawer ─────────────────────────────────────────────────────

  openDetail(a: Asset): void {
    this.selected = a;
    this.showDetail = true;
    this.selectedDetail = a;
    this.detailError = '';
    this.detailLoading = true;
    this.cdr.markForCheck();
    this.http.get<any>(`${this.api}/${a.id}`).subscribe({
      next: r => { this.selectedDetail = r?.asset || r; this.detailLoading = false; this.cdr.markForCheck(); },
      error: err => {
        this.detailLoading = false;
        this.detailError = this.firstError(err) || 'Full asset details could not be loaded.';
        this.cdr.markForCheck();
      },
    });
  }

  closeDetail(): void { this.showDetail = false; this.cdr.markForCheck(); }

  // ── Asset form ────────────────────────────────────────────────────────

  openForm(a?: Asset): void {
    this.formError = '';
    this.selectedFile = null;
    if (a) {
      this.editId = a.id;
      this.form = {
        category_id: a.category?.id ?? null, name: a.name, asset_code: a.asset_code,
        brand: a.brand || '', model: a.model || '', serial_number: a.serial_number || '',
        description: a.description || '', condition: a.condition,
        purchase_price: a.purchase_price || '', purchase_date: a.purchase_date || '',
        vendor: a.vendor || '', warranty_expiry: a.warranty_expiry || '', location: a.location || '',
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
    const f = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (f && f.size > 10 * 1024 * 1024) { this.formError = 'File must be ≤10 MB.'; this.cdr.markForCheck(); return; }
    this.selectedFile = f;
    this.cdr.markForCheck();
  }

  saveAsset(): void {
    if (!this.form.name?.trim() || !this.form.asset_code?.trim()) {
      this.formError = 'Name and asset code are required.';
      this.cdr.markForCheck();
      return;
    }
    this.submitting = true;
    this.formError = '';

    const fd = new FormData();
    Object.entries(this.form).forEach(([k, v]) => { if (v !== null && v !== '') fd.append(k, String(v)); });
    if (this.selectedFile) fd.append('attachment', this.selectedFile);

    const url = this.editId ? `${this.api}/${this.editId}` : this.api;
    this.http.post<any>(url, fd).subscribe({
      next: () => {
        this.submitting = false; this.showForm = false;
        this.toast(this.editId ? 'Asset updated.' : 'Asset created.');
        this.load(); this.loadStats();
      },
      error: err => { this.submitting = false; this.formError = this.firstError(err) || 'Save failed.'; this.cdr.markForCheck(); },
    });
  }

  deleteAsset(a: Asset): void {
    if (!confirm(`Delete asset "${a.name}"?`)) return;
    this.http.delete(`${this.api}/${a.id}`).subscribe({
      next: () => { this.toast('Asset deleted.'); this.load(); this.loadStats(); },
      error: err => this.toast(this.firstError(err) || 'Delete failed.'),
    });
  }

  downloadAttachment(a: Asset): void { window.open(`${this.api}/${a.id}/attachment`, '_blank'); }

  downloadReport(type: 'assets' | 'assignments' | 'maintenance'): void {
    const filenames: Record<string, string> = {
      assets: 'asset_report.csv',
      assignments: 'asset_assignment_report.csv',
      maintenance: 'asset_maintenance_report.csv',
    };

    this.http.get(`${this.api}/reports/${type}`, { responseType: 'blob' }).subscribe({
      next: blob => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filenames[type];
        link.click();
        URL.revokeObjectURL(url);
      },
      error: err => this.toast(this.firstError(err) || 'Report download failed.'),
    });
  }

  // ── Assign ─────────────────────────────────────────────────────────────

  openAssign(a: Asset): void {
    this.selected = a;
    this.assignForm = { employee_id: null, assigned_date: this.today(), condition_at_assign: a.condition, notes: '' };
    this.showAssign = true;
    this.cdr.markForCheck();
  }

  closeAssign(): void { this.showAssign = false; this.cdr.markForCheck(); }

  doAssign(): void {
    if (!this.assignForm.employee_id || !this.assignForm.assigned_date) {
      this.errorMsg = 'Employee and date are required.'; this.cdr.markForCheck(); return;
    }
    this.assigning = true;
    this.http.post<any>(`${this.api}/${this.selected!.id}/assign`, this.assignForm).subscribe({
      next: () => { this.assigning = false; this.showAssign = false; this.toast('Asset assigned.'); this.load(); this.loadStats(); },
      error: err => { this.assigning = false; this.toast(this.firstError(err) || 'Assign failed.'); },
    });
  }

  // ── Return ─────────────────────────────────────────────────────────────

  openReturn(a: Asset): void {
    this.selected = a;
    this.returnForm = { return_date: this.today(), condition_at_return: a.condition, notes: '' };
    this.showReturn = true;
    this.cdr.markForCheck();
  }

  closeReturn(): void { this.showReturn = false; this.cdr.markForCheck(); }

  doReturn(): void {
    this.returning = true;
    this.http.post<any>(`${this.api}/${this.selected!.id}/return`, this.returnForm).subscribe({
      next: () => { this.returning = false; this.showReturn = false; this.toast('Asset returned.'); this.load(); this.loadStats(); },
      error: err => { this.returning = false; this.toast(this.firstError(err) || 'Return failed.'); },
    });
  }

  // ── Maintenance ─────────────────────────────────────────────────────────

  openMaintenance(a: Asset): void {
    this.selected = a;
    this.maintenanceForm = { type: 'service', title: '', description: '', scheduled_date: this.today(), cost: '', vendor: '', status: 'scheduled' };
    this.showMaintenance = true;
    this.cdr.markForCheck();
  }

  closeMaintenance(): void { this.showMaintenance = false; this.cdr.markForCheck(); }

  saveMaintenance(): void {
    if (!this.maintenanceForm.title?.trim()) { this.errorMsg = 'Title is required.'; this.cdr.markForCheck(); return; }
    this.maintenanceSaving = true;
    this.http.post<any>(`${this.api}/${this.selected!.id}/maintenance`, this.maintenanceForm).subscribe({
      next: () => { this.maintenanceSaving = false; this.showMaintenance = false; this.toast('Maintenance logged.'); this.load(); this.loadStats(); },
      error: err => { this.maintenanceSaving = false; this.toast(this.firstError(err) || 'Save failed.'); },
    });
  }

  // ── Categories ──────────────────────────────────────────────────────────

  openCategories(): void { this.showCategories = true; this.catForm = { name: '', icon: 'devices' }; this.catEditId = null; this.cdr.markForCheck(); }
  closeCategories(): void { this.showCategories = false; this.cdr.markForCheck(); }

  editCategory(c: any): void { this.catEditId = c.id; this.catForm = { name: c.name, icon: c.icon || 'devices' }; this.cdr.markForCheck(); }

  saveCategory(): void {
    if (!this.catForm.name?.trim()) return;
    const req = this.catEditId
      ? this.http.put<any>(`${this.api}/categories/${this.catEditId}`, this.catForm)
      : this.http.post<any>(`${this.api}/categories`, this.catForm);
    req.subscribe({ next: () => { this.catEditId = null; this.catForm = { name: '', icon: 'devices' }; this.loadCategories(); this.toast('Category saved.'); }, error: () => {} });
  }

  deleteCategory(c: any): void {
    if (!confirm(`Delete category "${c.name}"?`)) return;
    this.http.delete(`${this.api}/categories/${c.id}`).subscribe({ next: () => { this.loadCategories(); this.toast('Category deleted.'); }, error: () => {} });
  }

  // ── Status helpers ──────────────────────────────────────────────────────

  statusClass(s: string): string {
    return { available: 'st-avail', assigned: 'st-assigned', under_maintenance: 'st-maint', disposed: 'st-disposed', lost: 'st-lost' }[s] || '';
  }

  conditionClass(c: string): string {
    return { new: 'cond-new', good: 'cond-good', fair: 'cond-fair', poor: 'cond-poor' }[c] || '';
  }

  employeeName(id: number): string {
    const e = this.employees.find((x: any) => x.id === id);
    return e ? `${e.first_name} ${e.last_name}` : String(id);
  }

  // ── Utilities ───────────────────────────────────────────────────────────

  private toast(msg: string): void {
    this.successMsg = msg; this.cdr.markForCheck();
    setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3500);
  }

  private firstError(err: any): string {
    if (err?.error?.errors) {
      const v = Object.values(err.error.errors)[0];
      if (Array.isArray(v) && v.length) return v[0] as string;
    }
    return err?.error?.message || '';
  }
}
