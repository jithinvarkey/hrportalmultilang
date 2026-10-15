import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { ActivatedRoute } from '@angular/router';

@Component({
  standalone: false,
  selector: 'app-separation-list',
  templateUrl: './separation-list.component.html',
  styleUrls: ['./separation-list.component.scss'],
})
export class SeparationListComponent implements OnInit {

  // ── Tabs ────────────────────────────────────────────────────────────────
  activeTab    = 'all';
  activeStatus = '';
  loading      = false;
  submitting   = false;

  // ── Data ─────────────────────────────────────────────────────────────────
  separations:  any[] = [];
  templates:    any[] = [];
  stats:        any   = {};
  statItems:    any[] = [];
  pagination:   any   = null;
  currentPage         = 1;
  isHR = false;
  isHRManager = false;
  isFinanceManager = false;
  isDeptManager = false;
  isSuperAdmin = false;
  isCEO = false;
  isApprover = false;
  currentEmployeeId: any = null;

  // ── Filters ──────────────────────────────────────────────────────────────
  filterSearch = '';
  filterType   = '';

  // ── Panels ───────────────────────────────────────────────────────────────
  showNewForm      = false;
  showDetail       = false;
  showReject       = false;
  showApproveHR    = false;  // HR approval with settlement
  showExitInterview= false;
  showComplete     = false;
  showTemplateForm = false;
  selectedSep: any = null;
  detailTab: 'overview' | 'it' | 'finance' | 'tasks' = 'overview';
  rejectTarget:any = null;
  rejectReason     = '';
  checklistError   = '';
  checklistUpdatingIds = new Set<number>();

  // ── New separation form ──────────────────────────────────────────────────
  employees:   any[] = [];
  form: any = {
    employee_id:'', type:'resignation', reason:'', reason_category:'personal',
    last_working_day:'', notice_waived: false, notice_waived_reason:'', hr_notes:''
  };
  formError     = '';
  completeError = '';
  settlementPreview: any = null;

  // ── HR Approval form ──────────────────────────────────────────────────────
  hrApprovalForm: any = { other_additions: 0, other_deductions: 0, hr_notes: '' };

  // ── Exit interview form ───────────────────────────────────────────────────
  exitForm: any = { date: '', notes: '' };

  // ── Complete form ─────────────────────────────────────────────────────────
  completeForm: any = { settlement_paid: false, settlement_notes: '' };

  // ── Template form ─────────────────────────────────────────────────────────
  templateForm: any = { title:'', category:'hr', description:'', is_required:true, is_active:true, sort_order:0 };
  templateEditId: number | null = null;

  // ── Table columns ─────────────────────────────────────────────────────────
  displayedColumns = ['ref','employee','type','last_working_day','notice','status','actions'];
  templateColumns  = ['title','category','required','actions'];

  allTabs = [
    { id:'all',       label:'All',          icon:'list_alt'     },
    { id:'pending',   label:'Pending',      icon:'hourglass_empty' },
    { id:'offboarding',label:'Offboarding', icon:'checklist'    },
    { id:'completed', label:'Completed',    icon:'task_alt'     },
    { id:'templates', label:'Checklist Setup', icon:'tune'      },
  ];
  tabs = [...this.allTabs];

  statusTabs = [
    { id:'',               label:'All'              },
    { id:'pending_manager',label:'Pending Manager'  },
    { id:'pending_hr',     label:'Pending HR'       },
    { id:'approved',       label:'Approved'         },
    { id:'offboarding',    label:'Offboarding'      },
    { id:'completed',      label:'Completed'        },
    { id:'rejected',       label:'Rejected'         },
    { id:'cancelled',      label:'Cancelled'        },
  ];

  allSeparationTypes = [
    { id:'resignation',      label:'Resignation',       icon:'exit_to_app',     color:'#f59e0b' },
    { id:'termination',      label:'Termination',       icon:'block',           color:'#ef4444' },
    { id:'end_of_contract',  label:'End of Contract',   icon:'event_busy',      color:'#6366f1' },
    { id:'retirement',       label:'Retirement',        icon:'elderly',         color:'#10b981' },
    { id:'abandonment',      label:'Abandonment',       icon:'person_off',      color:'#8b5cf6' },
    { id:'mutual_agreement', label:'Mutual Agreement',  icon:'handshake',       color:'#0ea5e9' },
  ];
  separationTypes = [...this.allSeparationTypes];

  reasonCategories = [
    { id:'personal',          label:'Personal Reasons'     },
    { id:'better_opportunity',label:'Better Opportunity'   },
    { id:'relocation',        label:'Relocation'           },
    { id:'health',            label:'Health Reasons'       },
    { id:'misconduct',        label:'Misconduct'           },
    { id:'performance',       label:'Poor Performance'     },
    { id:'restructuring',     label:'Restructuring'        },
    { id:'contract_end',      label:'Contract Ended'       },
    { id:'other',             label:'Other'                },
  ];

  checklistCategories = ['it','hr','finance','admin','general'];

  constructor(private http: HttpClient, private auth: AuthService, private route: ActivatedRoute) {}

  ngOnInit() {
    const user = this.auth.getUser();
    this.currentEmployeeId = user?.employee?.id || user?.employee_id || null;
    this.isHR = this.auth.isHRRole() || this.auth.isSuperAdmin();
    this.isHRManager = this.auth.isHRManager() || this.auth.isSuperAdmin();
    this.isFinanceManager = this.auth.isFinanceManager();
    this.isDeptManager = this.auth.isDeptManager();
    this.isSuperAdmin = this.auth.isSuperAdmin();
    this.isCEO = this.auth.hasRole('ceo');
    this.isApprover = this.isDeptManager || this.isHRManager || this.isFinanceManager || this.isSuperAdmin || this.isCEO;
    this.tabs = this.isApprover
      ? this.allTabs.filter(t => t.id !== 'templates' || this.canManageOffboarding())
      : this.allTabs.filter(t => t.id === 'all');
    this.separationTypes = this.isHR ? [...this.allSeparationTypes] : this.allSeparationTypes.filter(t => t.id === 'resignation');
    this.loadStats();
    this.load();
    this.loadEmployees();
    if (this.canManageOffboarding()) this.loadTemplates();

    const separationId = Number(this.route.snapshot.queryParamMap.get('separation_id'));
    if (Number.isInteger(separationId) && separationId > 0) {
      this.viewSep({ id: separationId });
    }
  }

  loadStats() {
    this.http.get<any>('/api/v1/separations/stats').subscribe({ next: r => {
      this.stats = r;
      this.statItems = [
        { label:'Pending Manager', value: r.pending_manager,  icon:'manage_accounts', color:'#f59e0b' },
        { label:'Pending HR',      value: r.pending_hr,       icon:'badge',           color:'#6366f1' },
        { label:'Offboarding',     value: r.offboarding,      icon:'checklist',       color:'#3b82f6' },
        { label:'Completed YTD',   value: r.completed_ytd,    icon:'task_alt',        color:'#10b981' },
      ];
    }});
  }

  load(page = 1) {
    this.loading = true; this.currentPage = page;
    const params: any = { per_page: 15, page };
    if (this.activeStatus) params.status = this.activeStatus;
    if (this.filterType)   params.type   = this.filterType;
    if (this.filterSearch) params.search = this.filterSearch;
    if (this.activeTab === 'offboarding') params.status = 'offboarding';
    if (this.activeTab === 'completed')   params.status = 'completed';
    if (this.activeTab === 'pending')     params.status = 'pending_manager,pending_hr,approved';
    this.http.get<any>('/api/v1/separations', { params }).subscribe({
      next: r => { this.separations = r?.data || []; this.pagination = r; this.loading = false; },
      error: () => this.loading = false
    });
  }

  loadEmployees() {
    if (!this.isHR) {
      const user = this.auth.getUser();
      const employee = user?.employee;
      if (employee) {
        this.employees = [employee];
        this.form.employee_id = employee.id;
      }
      return;
    }
    this.http.get<any>('/api/v1/employees?per_page=500&status=active').subscribe({
      next: r => this.employees = r?.data || r?.employees || []
    });
  }

  loadTemplates() {
    this.http.get<any>('/api/v1/separations/templates').subscribe({ next: r => this.templates = r?.templates || [] });
  }

  switchTab(id: string) {
    if (id === 'templates' && !this.isApprover) return;
    this.activeTab = id; this.activeStatus = '';
    if (id !== 'templates') this.load();
  }

  switchStatus(id: string) { this.activeStatus = id; this.load(); }

  // ── View detail ────────────────────────────────────────────────────────
  viewSep(sep: any) {
    this.http.get<any>(`/api/v1/separations/${sep.id}`).subscribe({ next: r => {
      this.selectedSep = r.separation;
      this.ensureAvailableDetailTab();
      this.showDetail  = true;
    }});
  }

  reloadDetail() {
    if (this.selectedSep) {
      this.http.get<any>(`/api/v1/separations/${this.selectedSep.id}`).subscribe({ next: r => {
        this.selectedSep = r.separation;
        this.ensureAvailableDetailTab();
      }});
    }
  }

  private ensureAvailableDetailTab(): void {
    if (this.detailTab === 'it' && !this.selectedSep?.clearance_access?.it) this.detailTab = 'overview';
    if (this.detailTab === 'finance' && !this.selectedSep?.clearance_access?.finance) this.detailTab = 'overview';
    if (this.detailTab === 'tasks' && !this.canViewOffboardingTasks(this.selectedSep)) this.detailTab = 'overview';
  }

  hasClearanceTabs(sep: any): boolean {
    return sep?.type === 'resignation'
      && (sep?.clearance_access?.it || sep?.clearance_access?.finance);
  }

  // ── New separation ──────────────────────────────────────────────────────
  openNew() {
    const d = new Date(); d.setDate(d.getDate() + 30);
    this.form = { employee_id: this.isHR ? '' : this.currentEmployeeId, type:'resignation', reason:'', reason_category:'personal',
      last_working_day: d.toISOString().slice(0,10), notice_waived: false, notice_waived_reason:'', hr_notes:'' };
    this.formError = ''; this.settlementPreview = null;
    this.showNewForm = true;
  }

  onEmployeeOrTypeChange() {
    if (!this.isHR) return;
    if (this.form.employee_id && this.form.last_working_day) {
      this.http.get<any>('/api/v1/separations/settlement-preview', { params: {
        employee_id: this.form.employee_id, type: this.form.type, last_working_day: this.form.last_working_day
      }}).subscribe({ next: r => this.settlementPreview = r });
    }
  }

  submitSeparation() {
    if (!this.isHR) {
      this.form.type = 'resignation';
      this.form.employee_id = this.currentEmployeeId;
      this.form.hr_notes = '';
    }
    if (!this.form.employee_id || !this.form.reason || !this.form.last_working_day) {
      this.formError = 'Employee, reason, and last working day are required.'; return;
    }
    this.submitting = true; this.formError = '';
    this.http.post<any>('/api/v1/separations', this.form).subscribe({
      next: () => {
        this.submitting = false; this.showNewForm = false;
        this.load(); this.loadStats();
      },
      error: err => { this.submitting = false; this.formError = err?.error?.message || 'Failed to submit.'; }
    });
  }

  // ── Approve ────────────────────────────────────────────────────────────
  openApprove(sep: any) {
    if (sep.status === 'pending_hr') {
      this.selectedSep = sep; this.showApproveHR = true;
      this.hrApprovalForm = { other_additions: 0, other_deductions: 0, hr_notes: '' };
    } else {
      this.quickApprove(sep);
    }
  }

  quickApprove(sep: any) {
    this.http.post(`/api/v1/separations/${sep.id}/approve`, {}).subscribe({
      next: () => { this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.reloadDetail(); }
    });
  }

  submitHRApproval() {
    this.http.post(`/api/v1/separations/${this.selectedSep.id}/approve`, this.hrApprovalForm).subscribe({
      next: () => { this.showApproveHR = false; this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.reloadDetail(); }
    });
  }

  startOffboarding(sep: any) {
    this.http.post(`/api/v1/separations/${sep.id}/approve`, {}).subscribe({
      next: () => { this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.reloadDetail(); }
    });
  }

  // ── Reject ─────────────────────────────────────────────────────────────
  openReject(sep: any) { this.rejectTarget = sep; this.rejectReason = ''; this.showReject = true; }

  confirmReject() {
    if (!this.rejectReason.trim()) return;
    this.http.post(`/api/v1/separations/${this.rejectTarget.id}/reject`, { reason: this.rejectReason }).subscribe({
      next: () => { this.showReject = false; this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.showDetail = false; }
    });
  }

  cancel(sep: any) {
    if (!confirm('Cancel this separation request?')) return;
    this.http.post(`/api/v1/separations/${sep.id}/cancel`, {}).subscribe({
      next: () => { this.load(this.currentPage); this.loadStats(); if (this.showDetail) this.showDetail = false; }
    });
  }

  // ── Checklist item ──────────────────────────────────────────────────────
  toggleChecklistItem(item: any, status: string) {
    if (this.checklistUpdatingIds.has(item.id)) return;
    this.checklistError = '';
    this.checklistUpdatingIds.add(item.id);
    const previousItem = { ...item };
    item.status = status;
    this.http.put(`/api/v1/separations/${this.selectedSep.id}/checklist/${item.id}`, { status }).subscribe({
      next: (response: any) => {
        Object.assign(item, response?.item || { status });
        this.checklistUpdatingIds.delete(item.id);
      },
      error: err => {
        Object.assign(item, previousItem);
        this.checklistUpdatingIds.delete(item.id);
        this.checklistError = err?.error?.message || 'Unable to update this offboarding task.';
      }
    });
  }

  isChecklistUpdating(item: any): boolean {
    return this.checklistUpdatingIds.has(item?.id);
  }

  checklistProgress(sep: any): number {
    if (!sep?.checklist_items?.length) return 0;
    const done = sep.checklist_items.filter((i: any) => ['completed','skipped','na'].includes(i.status)).length;
    return Math.round((done / sep.checklist_items.length) * 100);
  }

  pendingChecklistCount(sep: any): number {
    return sep?.checklist_items?.filter((item: any) => item.status === 'pending').length || 0;
  }

  checklistByCategory(items: any[]): any[] {
    if (!items) return [];
    const cats: any = {};
    for (const item of items) {
      if (!cats[item.category]) cats[item.category] = { category: item.category, items: [] };
      cats[item.category].items.push(item);
    }
    return Object.values(cats);
  }

  // ── Exit interview ──────────────────────────────────────────────────────
  openExitInterview(sep: any) {
    this.selectedSep = sep; this.exitForm = { date: new Date().toISOString().slice(0,10), notes: '' };
    this.showExitInterview = true;
  }

  submitExitInterview() {
    this.http.post(`/api/v1/separations/${this.selectedSep.id}/exit-interview`, this.exitForm).subscribe({
      next: () => { this.showExitInterview = false; this.reloadDetail(); }
    });
  }

  // ── Complete ────────────────────────────────────────────────────────────
  openComplete(sep: any) {
    if (!this.canCompleteSeparation() || !this.allChecklistTasksResolved(sep)) return;
    this.selectedSep = sep; this.completeForm = { settlement_paid: false, settlement_notes: '' };
    this.completeError = '';
    this.showComplete = true;
  }

  submitComplete() {
    this.http.post(`/api/v1/separations/${this.selectedSep.id}/complete`, this.completeForm).subscribe({
      next: () => { this.showComplete = false; this.load(this.currentPage); this.loadStats(); this.showDetail = false; },
      error: err => { this.completeError = err?.error?.message || 'Unable to complete this separation.'; }
    });
  }

  // ── Templates ───────────────────────────────────────────────────────────
  openTemplateForm(t?: any) {
    if (t) { this.templateEditId = t.id; this.templateForm = { ...t }; }
    else   { this.templateEditId = null; this.templateForm = { title:'', category:'hr', description:'', is_required:true, is_active:true, sort_order:0 }; }
    this.showTemplateForm = true;
  }

  saveTemplate() {
    if (!this.templateForm.title) return;
    const req = this.templateEditId
      ? this.http.put(`/api/v1/separations/templates/${this.templateEditId}`, this.templateForm)
      : this.http.post('/api/v1/separations/templates', this.templateForm);
    req.subscribe({ next: () => { this.showTemplateForm = false; this.loadTemplates(); }});
  }

  deleteTemplate(id: number) {
    if (!confirm('Delete this template?')) return;
    this.http.delete(`/api/v1/separations/templates/${id}`).subscribe({ next: () => this.loadTemplates() });
  }

  // ── Helpers ─────────────────────────────────────────────────────────────
  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    return Array.from({ length: Math.min(this.pagination.last_page, 8) }, (_, i) => i + 1);
  }

  typeInfo(type: string): any {
    return this.separationTypes.find(t => t.id === type) || { label: type, icon: 'help', color: '#8b949e' };
  }

  statusLabel(s: string): string {
    const map: any = {
      draft:'Draft', submitted:'Submitted', pending_manager:'Pending Manager', pending_hr:'Pending HR',
      approved:'Approved', offboarding:'Offboarding', completed:'Completed', cancelled:'Cancelled', rejected:'Rejected'
    };
    return map[s] || s;
  }

  statusCls(s: string): string {
    const map: any = {
      draft:'badge-gray', submitted:'badge-blue', pending_manager:'badge-yellow', pending_hr:'badge-purple',
      approved:'badge-green', offboarding:'badge-teal', completed:'badge-green-solid',
      cancelled:'badge-gray', rejected:'badge-red'
    };
    return map[s] || 'badge-gray';
  }

  statusIcon(s: string): string {
    const map: any = {
      draft:'draft', submitted:'send', pending_manager:'manage_accounts', pending_hr:'badge',
      approved:'check_circle', offboarding:'checklist', completed:'task_alt',
      cancelled:'block', rejected:'cancel'
    };
    return map[s] || 'help';
  }

  noticeDaysRemaining(sep: any): number {
    if (!sep.last_working_day) return 0;
    return Math.max(0, Math.ceil((new Date(sep.last_working_day).getTime() - Date.now()) / 86400000));
  }

  canApprove(sep: any): boolean {
    if (!sep) return false;
    if (sep.status === 'pending_manager') {
      return this.isDirectManager(sep) || this.isHRManager || this.isSuperAdmin;
    }
    if (sep.status === 'pending_hr') {
      return sep.type === 'termination' || sep.type === 'resignation'
        ? this.isHRManager || this.isSuperAdmin
        : this.isHRManager || this.isFinanceManager || this.isSuperAdmin;
    }
    return false;
  }

  canCancel(sep: any): boolean {
    if (!sep) return false;
    if (sep.type === 'termination') {
      return ['draft', 'pending_hr', 'approved'].includes(sep.status)
        && (this.isHRManager || this.isSuperAdmin);
    }
    if (sep.status === 'approved') {
      return this.canManageOffboarding();
    }
    if (!['draft', 'pending_manager', 'pending_hr'].includes(sep.status)) return false;

    const isOwnRequest = Number(sep.employee_id) === Number(this.currentEmployeeId);
    return isOwnRequest || this.canReject(sep);
  }

  canReject(sep: any): boolean {
    if (!sep) return false;
    if (sep.status === 'pending_manager') {
      return this.isDirectManager(sep) || this.isHRManager || this.isSuperAdmin;
    }
    if (sep.status === 'pending_hr') {
      return sep.type === 'termination' || sep.type === 'resignation'
        ? this.isHRManager || this.isSuperAdmin
        : this.isHRManager || this.isFinanceManager || this.isSuperAdmin;
    }
    return false;
  }

  canManageOffboarding(): boolean {
    return this.isHRManager || this.isFinanceManager || this.isSuperAdmin || this.isCEO;
  }

  canCompleteSeparation(): boolean {
    return this.auth.isHRManager() || this.auth.isSuperAdmin();
  }

  canPrintClearance(sep: any): boolean {
    return sep?.status === 'completed' && (this.auth.isHRManager() || this.auth.isSuperAdmin());
  }

  printClearance(sep: any): void {
    if (!this.canPrintClearance(sep)) return;
    const printWindow = window.open('', '_blank', 'width=1000,height=800');
    if (!printWindow) {
      this.checklistError = 'Please allow pop-ups to print the clearance report.';
      return;
    }

    const groups = this.checklistByCategory(sep.checklist_items || []);
    const departmentSections = groups.map((group: any) => {
      const rows = group.items.map((item: any) => {
        const completedBy = item.completed_by_name
          || (typeof item.completed_by === 'object' ? item.completed_by?.name : null)
          || item.completed_by_user?.name;
        return `<tr>
          <td>${this.escapeHtml(item.title)}</td>
          <td class="status">${this.escapeHtml(this.taskStatusLabel(item.status))}</td>
          <td>${this.escapeHtml(completedBy || '—')}</td>
          <td>${this.escapeHtml(this.printDate(item.completed_at))}</td>
        </tr>`;
      }).join('');
      return `<section>
        <h2>${this.escapeHtml(this.categoryLabel(group.category))} Clearance</h2>
        <table><thead><tr><th>Task</th><th>Status</th><th>Completed By</th><th>Date</th></tr></thead>
        <tbody>${rows}</tbody></table>
        <div class="signoff"><span>Department Manager Signature</span><span>Date</span></div>
      </section>`;
    }).join('');

    const employeeName = `${sep.employee?.first_name || ''} ${sep.employee?.last_name || ''}`.trim();
    printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8">
      <title>Clearance - ${this.escapeHtml(sep.reference)}</title>
      <style>
        @page{size:A4;margin:14mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#172033;margin:0;font-size:12px}
        .header{border-bottom:3px solid #1e3a5f;padding-bottom:12px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:flex-end}
        h1{font-size:21px;color:#1e3a5f;margin:0 0 4px}.sub{color:#64748b}.ref{font-weight:700;color:#1e3a5f}
        .meta{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px}.meta div{border:1px solid #d9e2ec;border-radius:6px;padding:9px}
        .label{display:block;font-size:9px;text-transform:uppercase;color:#64748b;font-weight:700;margin-bottom:3px}
        section{margin:0 0 20px;break-inside:avoid}h2{font-size:14px;color:#1e3a5f;margin:0;padding:8px 10px;background:#edf3f8;border-left:4px solid #2563eb}
        table{width:100%;border-collapse:collapse}th,td{border:1px solid #d9e2ec;padding:7px;text-align:left}th{background:#f8fafc;font-size:10px;text-transform:uppercase;color:#475569}.status{font-weight:700}
        .signoff{display:grid;grid-template-columns:2fr 1fr;gap:30px;margin-top:22px}.signoff span{border-top:1px solid #64748b;padding-top:5px;color:#64748b;font-size:10px}
        .footer{margin-top:25px;padding-top:10px;border-top:1px solid #d9e2ec;color:#64748b;font-size:10px;text-align:center}
        .actions{position:fixed;right:16px;top:16px}@media print{.actions{display:none}}
      </style></head><body>
      <button class="actions" onclick="window.print()">Print</button>
      <div class="header"><div><h1>Employee Clearance Report</h1><div class="sub">Separation &amp; Offboarding</div></div><div class="ref">${this.escapeHtml(sep.reference)}</div></div>
      <div class="meta">
        <div><span class="label">Employee</span>${this.escapeHtml(employeeName)}</div>
        <div><span class="label">Department</span>${this.escapeHtml(sep.employee?.department?.name || '—')}</div>
        <div><span class="label">Designation</span>${this.escapeHtml(sep.employee?.designation?.title || '—')}</div>
        <div><span class="label">Separation Type</span>${this.escapeHtml(this.typeInfo(sep.type).label)}</div>
        <div><span class="label">Last Working Day</span>${this.escapeHtml(this.printDate(sep.last_working_day))}</div>
        <div><span class="label">Completed</span>${this.escapeHtml(this.printDate(sep.updated_at))}</div>
      </div>
      ${departmentSections || '<p>No clearance tasks found.</p>'}
      <div class="signoff"><span>HR Manager Final Approval</span><span>Date</span></div>
      <div class="footer">Generated from HRMS on ${this.escapeHtml(this.printDate(new Date().toISOString()))}</div>
      </body></html>`);
    printWindow.document.close();
    printWindow.focus();
  }

  private taskStatusLabel(status: string): string {
    return ({ completed: 'Completed', skipped: 'Skipped', na: 'N/A', pending: 'Pending' } as any)[status] || status;
  }

  private printDate(value: string | null | undefined): string {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('en-GB', {
      day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Riyadh'
    });
  }

  private escapeHtml(value: any): string {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    } as Record<string, string>)[character]);
  }

  allChecklistTasksResolved(sep: any): boolean {
    const items = sep?.checklist_items || [];
    return items.length > 0 && items.every((item: any) => ['completed', 'skipped', 'na'].includes(item.status));
  }

  canViewOffboardingTasks(sep: any): boolean {
    return ['offboarding', 'completed'].includes(sep?.status)
      && (this.isHR || this.isFinanceManager || this.isDeptManager || this.isSuperAdmin);
  }

  private isDirectManager(sep: any): boolean {
    return this.isDeptManager && Number(sep?.employee?.manager_id) === Number(this.currentEmployeeId);
  }

  avatarColor(name: string): string {
    const colors = ['#3b82f6','#6366f1','#8b5cf6','#ec4899','#10b981','#f59e0b','#ef4444','#0ea5e9'];
    return colors[(name?.charCodeAt(0) || 0) % colors.length];
  }

  categoryLabel(c: string): string {
    return ({ it:'IT', hr:'HR', finance:'Finance', admin:'Admin', general:'General' } as any)[c] || c;
  }

  categoryColor(c: string): string {
    return ({ it:'#3b82f6', hr:'#6366f1', finance:'#10b981', admin:'#f59e0b', general:'#8b949e' } as any)[c] || '#8b949e';
  }

  itemStatusCls(s: string): string {
    return ({ pending:'item-pending', completed:'item-done', skipped:'item-skip', na:'item-na' } as any)[s] || '';
  }

  approvalSteps(sep: any): any[] {
    const isTermination = sep.type === 'termination' || sep.type === 'abandonment';
    const hrFirst = sep.type === 'resignation'
      && (sep.hr_approved_at || (sep.status === 'pending_hr' && !sep.manager_approved_at));
    const steps = [
      { label:'Request', done: true, active: false, by: sep.initiated_by?.name, date: sep.created_at },
    ];
    if (hrFirst) {
      steps.push(
        { label:'HR', done: !!sep.hr_approved_at, active: sep.status === 'pending_hr', by: sep.hr_approver?.name, date: sep.hr_approved_at },
        { label:'Manager', done: !!sep.manager_approved_at, active: sep.status === 'pending_manager', by: sep.manager_approver?.name, date: sep.manager_approved_at }
      );
    }
    if (!isTermination && !hrFirst) {
      steps.push({
        label:'Manager', done: !!sep.manager_approved_at, active: sep.status === 'pending_manager',
        by: sep.manager_approver?.name, date: sep.manager_approved_at
      });
    }
    if (!hrFirst) {
      steps.push({ label:'HR', done: !!sep.hr_approved_at, active: sep.status === 'pending_hr', by: sep.hr_approver?.name, date: sep.hr_approved_at });
    }
    steps.push(
      { label:'Offboarding', done: sep.status === 'offboarding' || sep.status === 'completed', active: sep.status === 'approved', by: '', date: null },
      { label:'Completed', done: sep.status === 'completed', active: false, by: '', date: null }
    );
    return steps;
  }
}
