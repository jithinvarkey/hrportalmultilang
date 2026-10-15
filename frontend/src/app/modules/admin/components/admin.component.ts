import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  standalone: false,
  selector: 'app-admin',
  templateUrl: './admin.component.html',
  styleUrls: ['./admin.component.scss'],
})
export class AdminComponent implements OnInit {

  activeTab = 'overview';
  loading   = false;
  submitting = false;
  adminError = '';

  // ── Data ─────────────────────────────────────────────────────────────
  overview: any    = {};
  users: any[]     = [];
  roles: any[]     = [];
  permissions: any = {};
  pagination: any  = null;
  currentPage      = 1;
  employees: any[] = [];

  // ── Filters ──────────────────────────────────────────────────────────
  filterRole   = '';
  filterSearch = '';

  loginActivities: any[] = [];
  loginActivityPagination: any = null;
  loginActivityPage = 1;
  loginActivityFilters: any = { search:'', event:'', status:'', from:'', to:'' };
  loginActivityEvents = ['login_success','login_failed','otp_challenge','otp_success','otp_failed','logout'];

  // ── Panels ───────────────────────────────────────────────────────────
  showUserForm   = false;
  showRoleEditor = false;
  showUserDetail = false;
  selectedUser: any = null;
  selectedRole: any = null;
  formError = '';

  // ── User form ────────────────────────────────────────────────────────
  userForm: any = { name:'', email:'', password:'', role:'employee', employee_id:'' };
  userEditId: number | null = null;

  // ── Role editor (permissions) ─────────────────────────────────────────
  editablePerms: Set<string> = new Set();

  // ── Table columns ─────────────────────────────────────────────────────
  userColumns  = ['user','role','employee','otp','actions'];
  roleColumns  = ['role','users','description','actions'];

  tabs = [
    { id:'overview',     label:'Overview',     icon:'dashboard'          },
    { id:'users',        label:'Users',        icon:'people'             },
    { id:'login-history', label:'Login History', icon:'manage_search'     },
    { id:'roles',        label:'Roles',        icon:'security'           },
    { id:'permissions',  label:'Permissions',  icon:'lock'               },
    { id:'units',        label:'Units',        icon:'business'           },
    { id:'departments',  label:'Departments',  icon:'corporate_fare'     },
    { id:'designations', label:'Job Positions', icon:'badge'             },
    { id:'migration',    label:'Data Migration', icon:'upload_file'       },
    { id:'settings',     label:'Settings',     icon:'settings'           },
  ];

  migrationCategories = [
    { id: '', label: 'All Categories' },
    { id: 1, label: 'IT and Cyber Security' },
    { id: 2, label: 'HR' },
    { id: 3, label: 'Cyber security' },
    { id: 4, label: 'Circulars' },
    { id: 5, label: 'Quality and Development' },
    { id: 6, label: 'Sales and Marketing' },
    { id: 7, label: 'Technical' },
    { id: 8, label: 'Finance' },
    { id: 9, label: 'Operation' },
    { id: 10, label: 'Compliance' },
    { id: 11, label: 'Shahin' },
    { id: 12, label: 'Business Services' },
    { id: 13, label: 'Operation Enrollment' },
    { id: 14, label: 'Operation Medical approval' },
    { id: 15, label: 'Executive Management' },
    { id: 16, label: 'Violations and Penalties' },
  ];
  migrationScopes = [
    { id: 'all', label: 'All Modules' },
    { id: 'departments', label: 'Departments' },
    { id: 'job_positions', label: 'Job Positions' },
    { id: 'employees', label: 'Employees' },
    { id: 'employee_managers', label: 'Employee Managers' },
    { id: 'leave_records', label: 'Leave Records' },
    { id: 'loan_records', label: 'Loan Records' },
    { id: 'documents', label: 'Policies & Documents' },
  ];
  migrationOrder = ['Departments', 'Job Positions', 'Employees', 'Employee Managers', 'Leave Records', 'Loan Records', 'Documents'];
  migrationScope = 'all';
  migrationCategoryId: number | '' = '';
  migrationSourceFolder = '';
  migrationFile: File | null = null;
  migrationSummary: any = null;
  migrationRunning = false;
  migrationMessage = '';
  migrationError = '';

  carryForwardSettings = { all: false, maximum: 10 as number | null };
  carryForwardTypeId: number | null = null;
  carryForwardLoading = false;
  carryForwardSaving = false;
  carryForwardMessage = '';
  carryForwardError = '';


  loanSettings = { approval_levels: 2 };
  annualTicketSettings = { saudi_employee_tickets: 1, non_saudi_employee_tickets: 1, non_saudi_max_dependents: 3 };
  monthlyLeaveReminderSettings = {
    enabled: true,
    day: 25,
    subject: 'Reminder: Submit current month leave entries',
    body: 'Dear {{first_name}},\n\nPlease make sure all leave requests for {{month_name}} {{year}} are submitted in HRMS before payroll processing. Any missing or unsubmitted leave entries may be deducted from salary as per company policy.\n\nRegards,\nHuman Resources',
  };
  unifonicSettings = {
    enabled: false,
    api_url: 'https://el.cloud.unifonic.com/rest/SMS/messages',
    app_sid: '',
    sender: '',
  };
  emailSettings = { reply_to_email: '' };
  ticketSettingsSaving = false;
  leaveReminderSaving = false;
  unifonicSaving = false;
  emailSettingsSaving = false;
  settingsLoading = false;
  settingsSaving = false;
  settingsMessage = '';
  settingsError = '';
  loanSettingsMessage = '';
  loanSettingsError = '';
  ticketSettingsMessage = '';
  ticketSettingsError = '';
  leaveReminderMessage = '';
  leaveReminderError = '';
  unifonicMessage = '';
  unifonicError = '';
  emailSettingsMessage = '';
  emailSettingsError = '';
  hdfTemplate: any = { exists: false, name: null, size: null };
  hdfFile: File | null = null;
  hdfUploading = false;
  hdfMessage = '';
  hdfError = '';

  // ── Departments ───────────────────────────────────────────────────────
  units: any[] = [];
  unitColumns = ['name','code','legacy','counts','status','actions'];
  showUnitForm = false;
  unitForm: any = { name:'', code:'', legacy_unitid:'', description:'', is_active:true };
  unitEditId: number | null = null;
  unitError = '';

  departments: any[]   = [];
  deptColumns          = ['name','code','manager','parent','headcount','status','actions'];
  showDeptForm         = false;
  deptForm: any        = { name:'', code:'', description:'', parent_id:'', manager_id:'', headcount_budget:'', is_active:true };
  deptEditId: number | null = null;
  deptError            = '';

  // ── Designations ──────────────────────────────────────────────────────
  designations: any[]  = [];
  desigColumns         = ['title','level','department','salary','status','actions'];
  showDesigForm        = false;
  desigForm: any       = { title:'', level:'', department_id:'', min_salary:'', max_salary:'', is_active:true };
  desigEditId: number | null = null;
  desigError           = '';

  /** Designation seniority levels — mirrors backend validation enum. */
  desigLevels = ['junior','mid','senior','lead','manager','director','executive','management','staff'];

  roleInfo: any = {
    super_admin:        { label:'Super Admin',        color:'#ef4444', icon:'shield'             },
    ceo:                { label:'CEO',                color:'#7c3aed', icon:'business_center'    },
    hr_manager:         { label:'HR Manager',         color:'#6366f1', icon:'manage_accounts'    },
    hr_staff:           { label:'HR Staff',           color:'#8b5cf6', icon:'badge'              },
    finance_manager:    { label:'Finance Manager',    color:'#10b981', icon:'account_balance'    },
    department_manager: { label:'Dept. Manager',      color:'#f59e0b', icon:'supervisor_account' },
    employee:           { label:'Employee',           color:'#3b82f6', icon:'person'             },
  };

  permModules = [
    { key:'dashboard',    label:'Dashboard',    icon:'dashboard'          },
    { key:'employees',    label:'Employees',    icon:'people'             },
    { key:'payroll',      label:'Payroll',      icon:'payments'           },
    { key:'leave',        label:'Leave',        icon:'event_available'    },
    { key:'loans',        label:'Loans',        icon:'account_balance'    },
    { key:'separations',  label:'Separations',  icon:'exit_to_app'        },
    { key:'requests',     label:'Requests',     icon:'inbox'              },
    { key:'recruitment',  label:'Recruitment',  icon:'work'               },
    { key:'performance',  label:'Performance',  icon:'leaderboard'        },
    { key:'attendance',   label:'Attendance',   icon:'fingerprint'         },
    { key:'contracts',    label:'Contracts',    icon:'description'         },
    { key:'orgchart',     label:'Org Chart',    icon:'account_tree'        },
    { key:'admin',        label:'Admin',        icon:'admin_panel_settings'},
  ];

  constructor(private http: HttpClient, public auth: AuthService) {}

  ngOnInit() {
    this.refreshAdminData();
  }

  loadOverview() {
    this.http.get<any>('/api/v1/admin/overview').subscribe({
      next: r => {
        this.adminError = '';
        this.overview = {
          attention: [],         // ensure *ngFor never sees undefined
          users_by_role: [],
          attendance_today: {},
          ...r,
        };
      },
      error: err => {
        this.adminError = this.adminErrorMessage(err, 'overview');
        console.error('[Admin] overview failed:', err?.status, err?.error?.message ?? err?.message);
      },
    });
  }

  loadUsers(page = 1) {
    this.loading = true; this.currentPage = page;
    const params: any = { page, per_page: 20 };
    if (this.filterRole)   params.role   = this.filterRole;
    if (this.filterSearch) params.search = this.filterSearch;
    this.http.get<any>('/api/v1/admin/users', { params }).subscribe({
      next: r => { this.adminError = ''; this.users = r?.data || []; this.pagination = r; this.loading = false; },
      error: err => { this.adminError = this.adminErrorMessage(err, 'users'); this.loading = false; }
    });
  }

  loadLoginActivities(page = 1) {
    this.loading = true;
    this.loginActivityPage = page;
    const params: any = { page, per_page: 20 };
    Object.entries(this.loginActivityFilters).forEach(([key, value]) => {
      if (value) params[key] = value;
    });

    this.http.get<any>('/api/v1/admin/login-activities', { params }).subscribe({
      next: r => {
        this.adminError = '';
        this.loginActivities = r?.data || [];
        this.loginActivityPagination = r;
        this.loading = false;
      },
      error: err => {
        this.adminError = this.adminErrorMessage(err, 'login history');
        this.loading = false;
      }
    });
  }

  loadRoles() {
    this.http.get<any>('/api/v1/admin/roles').subscribe({
      next: r => {
        this.adminError = '';
        this.roles = r?.roles || [];
        console.log('[Admin] roles loaded:', this.roles.length, this.roles);
      },
      error: err => {
        this.adminError = this.adminErrorMessage(err, 'roles');
        console.error('[Admin] roles error:', err?.status, err?.error);
      },
    });
  }

  loadPermissions() {
    this.http.get<any>('/api/v1/admin/permissions').subscribe({
      next: r => {
        this.adminError = '';
        this.permissions = r?.permissions || {};
        console.log('[Admin] permissions loaded:', Object.keys(this.permissions));
      },
      error: err => {
        this.adminError = this.adminErrorMessage(err, 'permissions');
        console.error('[Admin] permissions error:', err?.status, err?.error);
      },
    });
  }

  loadEmployees() {
    this.http.get<any>('/api/v1/employees?per_page=500').subscribe({ next: r => this.employees = r?.data || [] });
  }

  switchTab(id: string) {
    this.activeTab = id;
    if (id === 'overview')     { this.loadOverview(); this.loadRoles(); }
    if (id === 'users')        { this.loadUsers(); this.loadRoles(); }
    if (id === 'login-history') this.loadLoginActivities();
    if (id === 'roles')        { this.loadRoles(); this.loadPermissions(); }
    if (id === 'permissions')  { this.loadPermissions(); this.loadRoles(); this.loadOverview(); }
    if (id === 'units')        this.loadUnits();
    if (id === 'departments')  this.loadDepartments();
    if (id === 'designations') { this.loadDesignations(); this.loadDepartments(); }
    if (id === 'settings')     { this.loadCarryForwardSettings(); this.loadLoanSettings(); this.loadAnnualTicketSettings(); this.loadMonthlyLeaveReminderSettings(); this.loadUnifonicSettings(); this.loadEmailSettings(); this.loadHdfTemplate(); }
  }

  onMigrationFile(event: Event) {
    const input = event.target as HTMLInputElement;
    this.migrationFile = input.files?.[0] || null;
    this.migrationSummary = null;
    this.migrationMessage = '';
    this.migrationError = '';
  }

  runMigration(dryRun: boolean) {
    if (!this.migrationFile) {
      this.migrationError = 'Please choose a CSV or Excel file.';
      return;
    }
    if (this.migrationScope === 'documents' && !this.migrationSourceFolder.trim()) {
      this.migrationError = 'Please enter the source document folder.';
      return;
    }
    const payload = new FormData();
    payload.append('file', this.migrationFile);
    payload.append('scope', this.migrationScope);
    if ((this.migrationScope === 'documents' || this.migrationScope === 'all') && this.migrationCategoryId !== '') {
      payload.append('legacy_category_id', String(this.migrationCategoryId));
    }
    payload.append('dry_run', dryRun ? '1' : '0');
    if (this.migrationSourceFolder.trim()) payload.append('source_folder', this.migrationSourceFolder.trim());
    this.migrationRunning = true;
    this.migrationMessage = '';
    this.migrationError = '';
    this.http.post<any>('/api/v1/admin/legacy-migration/import', payload).subscribe({
      next: r => {
        this.migrationSummary = r.summary;
        this.migrationMessage = r.message || (dryRun ? 'Migration file validated.' : 'Migration completed.');
        this.migrationRunning = false;
        if (!dryRun) {
          this.loadDepartments();
          this.loadDesignations();
          this.loadEmployees();
          this.loadOverview();
        }
      },
      error: err => {
        this.migrationSummary = err?.error?.summary || null;
        this.migrationError = err?.status === 502
          ? 'Server timed out while importing. The file may still be too large for the live web timeout; try Validate Only first, split the CSV into smaller files, or increase the server proxy/PHP timeout.'
          : this.firstError(err)
          || err?.error?.message
          || err?.message
          || 'Migration failed. Please check the file and try again.';
        this.migrationRunning = false;
      }
    });
  }

  loadCarryForwardSettings() {
    this.carryForwardLoading = true;
    this.carryForwardTypeId = null;
    this.carryForwardError = '';
    this.carryForwardMessage = '';
    this.http.get<any>('/api/v1/leave/types').subscribe({
      next: r => {
        const types: any[] = r?.types || [];
        const type = types.find(t => String(t.code).toUpperCase() === 'AL')
          || types.find(t => t.is_annual)
          || types.find(t => String(t.name).toLowerCase().includes('annual'));
        if (type) {
          this.carryForwardTypeId = type.id;
          this.carryForwardSettings = {
            all: !!type.carry_forward_all,
            maximum: type.carry_forward ? Number(type.max_carry_forward ?? 10) : 0,
          };
        } else {
          this.carryForwardError = 'Create an Annual Leave type in Leave Management before setting this rule.';
        }
        this.carryForwardLoading = false;
      },
      error: err => {
        this.carryForwardError = this.firstError(err) || 'Failed to load carryforward settings.';
        this.carryForwardLoading = false;
      }
    });
  }

  saveCarryForwardSettings() {
    if (!this.carryForwardTypeId || this.carryForwardSaving) return;
    this.carryForwardMessage = '';
    this.carryForwardError = '';
    const maximum = this.carryForwardSettings.maximum;
    if (!this.carryForwardSettings.all &&
        (maximum == null || !Number.isInteger(maximum) || maximum < 0 || maximum > 65535)) {
      this.carryForwardError = 'Enter a whole number from 0 to 65535.';
      return;
    }
    this.carryForwardSaving = true;
    this.http.put<any>(`/api/v1/leave/types/${this.carryForwardTypeId}`, {
      carry_forward: true,
      carry_forward_all: this.carryForwardSettings.all,
      max_carry_forward: this.carryForwardSettings.all ? 0 : maximum,
    }).subscribe({
      next: r => {
        this.carryForwardSettings.all = !!r.type.carry_forward_all;
        this.carryForwardSettings.maximum = Number(r.type.max_carry_forward);
        this.carryForwardMessage = 'Annual leave carryforward rule saved.';
        this.carryForwardSaving = false;
      },
      error: err => {
        this.carryForwardError = this.firstError(err) || 'Failed to save carryforward settings.';
        this.carryForwardSaving = false;
      }
    });
  }

  loadLoanSettings() {
    this.settingsLoading = true;
    this.loanSettingsError = '';
    this.http.get<any>('/api/v1/admin/settings/loans').subscribe({
      next: r => {
        this.loanSettings.approval_levels = r?.settings?.approval_levels === 3 ? 3 : 2;
        this.settingsLoading = false;
      },
      error: err => {
        this.loanSettingsError = this.firstError(err) || 'Failed to load loan settings.';
        this.settingsLoading = false;
      }
    });
  }

  saveLoanSettings() {
    this.settingsSaving = true;
    this.loanSettingsMessage = '';
    this.loanSettingsError = '';
    this.http.put<any>('/api/v1/admin/settings/loans', this.loanSettings).subscribe({
      next: r => {
        this.loanSettings.approval_levels = r.settings.approval_levels;
        this.loanSettingsMessage = r.message;
        this.settingsSaving = false;
      },
      error: err => {
        this.loanSettingsError = this.firstError(err) || 'Failed to save loan settings.';
        this.settingsSaving = false;
      }
    });
  }

  loadHdfTemplate() {
    this.http.get<any>('/api/v1/admin/settings/hdf-template').subscribe({
      next: r => this.hdfTemplate = r?.template || { exists: false, name: null, size: null },
      error: err => this.hdfError = this.firstError(err) || 'Failed to load HDF template settings.'
    });
  }

  onHdfFile(event: Event) {
    const input = event.target as HTMLInputElement;
    this.hdfFile = input.files?.[0] || null;
    this.hdfMessage = '';
    this.hdfError = '';
  }

  uploadHdfTemplate() {
    if (!this.hdfFile) {
      this.hdfError = 'Please choose an HDF file.';
      return;
    }

    const payload = new FormData();
    payload.append('file', this.hdfFile);
    this.hdfUploading = true;
    this.hdfMessage = '';
    this.hdfError = '';
    this.http.post<any>('/api/v1/admin/settings/hdf-template', payload).subscribe({
      next: r => {
        this.hdfTemplate = r.template;
        this.hdfFile = null;
        this.hdfUploading = false;
        this.hdfMessage = r.message;
      },
      error: err => {
        this.hdfError = this.firstError(err) || 'Failed to upload the HDF template.';
        this.hdfUploading = false;
      }
    });
  }

  loadAnnualTicketSettings() {
    this.http.get<any>('/api/v1/admin/settings/annual-tickets').subscribe({
      next: r => this.annualTicketSettings = r.settings,
      error: err => this.ticketSettingsError = this.firstError(err) || 'Failed to load annual ticket settings.'
    });
  }

  saveAnnualTicketSettings() {
    this.ticketSettingsSaving = true; this.ticketSettingsMessage = ''; this.ticketSettingsError = '';
    this.http.put<any>('/api/v1/admin/settings/annual-tickets', this.annualTicketSettings).subscribe({
      next: r => { this.annualTicketSettings = r.settings; this.ticketSettingsMessage = r.message; this.ticketSettingsSaving = false; },
      error: err => { this.ticketSettingsError = this.firstError(err) || 'Failed to save annual ticket settings.'; this.ticketSettingsSaving = false; }
    });
  }

  loadMonthlyLeaveReminderSettings() {
    this.http.get<any>('/api/v1/admin/settings/monthly-leave-reminder').subscribe({
      next: r => this.monthlyLeaveReminderSettings = { ...this.monthlyLeaveReminderSettings, ...r.settings },
      error: err => this.leaveReminderError = this.firstError(err) || 'Failed to load monthly leave reminder settings.'
    });
  }

  saveMonthlyLeaveReminderSettings() {
    this.leaveReminderSaving = true; this.leaveReminderMessage = ''; this.leaveReminderError = '';
    this.http.put<any>('/api/v1/admin/settings/monthly-leave-reminder', this.monthlyLeaveReminderSettings).subscribe({
      next: r => {
        this.monthlyLeaveReminderSettings = { ...this.monthlyLeaveReminderSettings, ...r.settings };
        this.leaveReminderMessage = r.message;
        this.leaveReminderSaving = false;
      },
      error: err => {
        this.leaveReminderError = this.firstError(err) || 'Failed to save monthly leave reminder settings.';
        this.leaveReminderSaving = false;
      }
    });
  }

  loadUnifonicSettings() {
    this.http.get<any>('/api/v1/admin/settings/unifonic').subscribe({
      next: r => this.unifonicSettings = { ...this.unifonicSettings, ...r.settings },
      error: err => this.unifonicError = this.firstError(err) || 'Failed to load Unifonic settings.'
    });
  }

  saveUnifonicSettings() {
    this.unifonicSaving = true; this.unifonicMessage = ''; this.unifonicError = '';
    this.http.put<any>('/api/v1/admin/settings/unifonic', this.unifonicSettings).subscribe({
      next: r => {
        this.unifonicSettings = { ...this.unifonicSettings, ...r.settings };
        this.unifonicMessage = r.message;
        this.unifonicSaving = false;
      },
      error: err => {
        this.unifonicError = this.firstError(err) || 'Failed to save Unifonic settings.';
        this.unifonicSaving = false;
      }
    });
  }

  loadEmailSettings() {
    this.http.get<any>('/api/v1/admin/settings/email').subscribe({
      next: r => this.emailSettings = { ...this.emailSettings, ...r.settings },
      error: err => this.emailSettingsError = this.firstError(err) || 'Failed to load email settings.'
    });
  }

  saveEmailSettings() {
    this.emailSettingsSaving = true; this.emailSettingsMessage = ''; this.emailSettingsError = '';
    this.http.put<any>('/api/v1/admin/settings/email', this.emailSettings).subscribe({
      next: r => {
        this.emailSettings = { ...this.emailSettings, ...r.settings };
        this.emailSettingsMessage = r.message;
        this.emailSettingsSaving = false;
      },
      error: err => {
        this.emailSettingsError = this.firstError(err) || 'Failed to save email settings.';
        this.emailSettingsSaving = false;
      }
    });
  }

  refreshAdminData() {
    this.loadOverview();
    this.loadRoles();
    this.loadPermissions();
    this.loadUsers();
    this.loadEmployees();
  }

  // ── User CRUD ──────────────────────────────────────────────────────
  openUserForm(user?: any) {
    if (user) {
      this.userEditId = user.id;
      this.userForm = { name: user.name, email: user.email, password: '', role: user.roles?.[0]?.name || 'employee', employee_id: user.employee?.id || '', otp_exempt: !!user.otp_exempt };
    } else {
      this.userEditId = null;
      this.userForm = { name:'', email:'', password:'', role:'employee', employee_id:'', otp_exempt:false };
    }
    this.formError = ''; this.showUserForm = true;
  }

  viewUser(user: any) {
    this.http.get<any>(`/api/v1/admin/users/${user.id}`).subscribe({ next: r => {
      this.selectedUser = r.user; this.showUserDetail = true;
    }});
  }

  saveUser() {
    if (!this.userForm.name || !this.userForm.email) { this.formError = 'Name and email required.'; return; }
    this.submitting = true; this.formError = '';
    const req = this.userEditId
      ? this.http.put<any>(`/api/v1/admin/users/${this.userEditId}`, this.userForm)
      : this.http.post<any>('/api/v1/admin/users', this.userForm);
    req.subscribe({
      next: r => {
        // Also assign role if editing
        if (this.userEditId) {
          this.http.post(`/api/v1/admin/users/${this.userEditId}/assign-role`, { role: this.userForm.role }).subscribe();
        }
        this.submitting = false; this.showUserForm = false;
        this.loadUsers(this.currentPage); this.loadOverview();
      },
      error: err => { this.submitting = false; this.formError = err?.error?.message || 'Failed.'; }
    });
  }

  quickAssignRole(userId: number, role: string) {
    this.http.post(`/api/v1/admin/users/${userId}/assign-role`, { role }).subscribe({
      next: () => this.loadUsers(this.currentPage)
    });
  }

  toggleOtpExemption(user: any) {
    const current = this.isOtpExempt(user);
    this.http.put<any>(`/api/v1/admin/users/${user.id}/otp-exemption`, { otp_exempt: !current }).subscribe({
      next: r => {
        this.settingsMessage = r.message;
        this.loadUsers(this.currentPage);
      },
      error: err => this.adminError = this.firstError(err) || 'Failed to update OTP exemption.'
    });
  }

  isOtpExempt(user: any): boolean {
    return !!user?.otp_exempt || this.roleName(user?.roles?.[0]) === 'super_admin';
  }

  // ── Role permissions editor ────────────────────────────────────────
  openRoleEditor(role: any) {
    if (role.name === 'super_admin') return;
    this.selectedRole = role;
    this.editablePerms = new Set(role.permissions);
    this.showRoleEditor = true;
  }

  togglePerm(perm: string) {
    if (this.editablePerms.has(perm)) this.editablePerms.delete(perm);
    else this.editablePerms.add(perm);
  }

  hasPerm(perm: string): boolean { return this.editablePerms.has(perm); }

  saveRolePermissions() {
    if (!this.selectedRole) return;
    this.http.put(`/api/v1/admin/roles/${this.selectedRole.id}/permissions`, {
      permissions: Array.from(this.editablePerms)
    }).subscribe({ next: () => { this.showRoleEditor = false; this.loadRoles(); }});
  }

  // ── Permission matrix helpers ──────────────────────────────────────
  modulePerms(moduleKey: string): string[] {
    const raw: any[] = this.permissions[moduleKey] || [];
    // Backend may return [{name:'employees.view'}] or ['employees.view'] — handle both
    return raw.map((p: any) => typeof p === 'string' ? p : p.name);
  }

  roleHasPerm(role: any, perm: string): boolean {
    const perms: any[] = role.permissions ?? [];
    // permissions may be string[] or object[] (with .name)
    return perms.some((p: any) => (typeof p === 'string' ? p : p.name) === perm);
  }

  permLabel(perm: string): string {
    return perm.split('.')[1]?.replace(/_/g,' ') || perm;
  }

  // ── Helpers ────────────────────────────────────────────────────────
  get pages(): number[] {
    if (!this.pagination?.last_page) return [];
    return Array.from({ length: Math.min(this.pagination.last_page, 8) }, (_, i) => i + 1);
  }

  get loginActivityPages(): number[] {
    if (!this.loginActivityPagination?.last_page) return [];
    return Array.from({ length: Math.min(this.loginActivityPagination.last_page, 8) }, (_, i) => i + 1);
  }

  clearLoginActivityFilters() {
    this.loginActivityFilters = { search:'', event:'', status:'', from:'', to:'' };
    this.loadLoginActivities();
  }

  loginEventLabel(event: string): string {
    return (event || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  loginActor(activity: any): string {
    return activity?.user?.name || activity?.login_identifier || 'Unknown user';
  }

  loginEmployee(activity: any): string {
    const employee = activity?.user?.employee;
    if (!employee) return '';
    return `${employee.first_name || ''} ${employee.last_name || ''}`.trim() || employee.employee_code || '';
  }

  roleData(roleName: string): any {
    return this.roleInfo[roleName] || { label: roleName, color: '#8b949e', icon: 'person' };
  }

  /** Safely extract the role name string from either a string or a Spatie role object. */
  roleName(role: any): string {
    if (!role) return '';
    return typeof role === 'string' ? role : (role.name ?? '');
  }

  avatarColor(name: string): string {
    const colors = ['#3b82f6','#6366f1','#8b5cf6','#ec4899','#10b981','#f59e0b','#ef4444','#0ea5e9'];
    return colors[(name?.charCodeAt(0) || 0) % colors.length];
  }

  overviewRoles(): any[] { return this.overview?.users_by_role || []; }


  roleHasModule(role: any, moduleKey: string): boolean {
    return (role.permissions || []).some((p: any) => {
      const name = typeof p === 'string' ? p : p.name;
      return name?.startsWith(moduleKey + '.');
    });
  }

  moduleColor(role: any, moduleKey: string): string {
    return this.roleHasModule(role, moduleKey) ? this.roleData(role.name).color : 'var(--text3)';
  }

  moduleBorder(role: any, moduleKey: string, alpha: string = '60'): string {
    return this.roleHasModule(role, moduleKey) ? this.roleData(role.name).color + alpha : 'transparent';
  }

  // ── Department CRUD ─────────────────────────────────────────────────
  loadUnits() {
    this.loading = true;
    this.http.get<any>('/api/v1/units').subscribe({
      next: r => { this.units = Array.isArray(r) ? r : (r?.data || []); this.loading = false; },
      error: err => { this.loading = false; console.error('[Admin] units error:', err?.status, err?.error); }
    });
  }

  openUnitForm(unit?: any) {
    if (unit) {
      this.unitEditId = unit.id;
      this.unitForm = {
        name: unit.name,
        code: unit.code,
        legacy_unitid: unit.legacy_unitid || '',
        description: unit.description || '',
        is_active: unit.is_active ?? true,
      };
    } else {
      this.unitEditId = null;
      this.unitForm = { name:'', code:'', legacy_unitid:'', description:'', is_active:true };
    }
    this.unitError = ''; this.showUnitForm = true;
  }

  saveUnit() {
    if (!this.unitForm.name?.trim() || !this.unitForm.code?.trim()) {
      this.unitError = 'Name and code are required.'; return;
    }
    this.submitting = true; this.unitError = '';
    const payload = {
      name: this.unitForm.name.trim(),
      code: this.unitForm.code.trim(),
      legacy_unitid: this.unitForm.legacy_unitid?.trim() || null,
      description: this.unitForm.description?.trim() || null,
      is_active: !!this.unitForm.is_active,
    };
    const req = this.unitEditId
      ? this.http.put<any>(`/api/v1/units/${this.unitEditId}`, payload)
      : this.http.post<any>('/api/v1/units', payload);
    req.subscribe({
      next: () => { this.submitting = false; this.showUnitForm = false; this.loadUnits(); },
      error: err => { this.submitting = false; this.unitError = this.firstError(err) || 'Failed to save unit.'; }
    });
  }

  deleteUnit(unit: any) {
    if (!confirm(`Delete unit "${unit.name}"?`)) return;
    this.http.delete(`/api/v1/units/${unit.id}`).subscribe({
      next: () => this.loadUnits(),
      error: err => alert(this.firstError(err) || 'Failed to delete unit.')
    });
  }

  /** Fetch all departments (with manager + parent eager-loaded). */
  loadDepartments() {
    this.loading = true;
    this.http.get<any>('/api/v1/departments').subscribe({
      next: r => { this.departments = Array.isArray(r) ? r : (r?.data || []); this.loading = false; },
      error: err => { this.loading = false; console.error('[Admin] departments error:', err?.status, err?.error); }
    });
  }

  /** Open the department modal in create or edit mode. */
  openDeptForm(dept?: any) {
    if (dept) {
      this.deptEditId = dept.id;
      this.deptForm = {
        name: dept.name, code: dept.code, description: dept.description || '',
        parent_id: dept.parent_id || '', manager_id: dept.manager_id || '',
        headcount_budget: dept.headcount_budget ?? '', is_active: dept.is_active ?? true,
      };
    } else {
      this.deptEditId = null;
      this.deptForm = { name:'', code:'', description:'', parent_id:'', manager_id:'', headcount_budget:'', is_active:true };
    }
    this.deptError = ''; this.showDeptForm = true;
  }

  /** Persist a department (create or update). */
  saveDept() {
    if (!this.deptForm.name?.trim() || !this.deptForm.code?.trim()) {
      this.deptError = 'Name and code are required.'; return;
    }
    this.submitting = true; this.deptError = '';
    const payload: any = {
      name: this.deptForm.name.trim(),
      code: this.deptForm.code.trim(),
      description: this.deptForm.description?.trim() || null,
      parent_id: this.deptForm.parent_id || null,
      manager_id: this.deptForm.manager_id || null,
      headcount_budget: this.deptForm.headcount_budget === '' ? null : Number(this.deptForm.headcount_budget),
      is_active: !!this.deptForm.is_active,
    };
    const req = this.deptEditId
      ? this.http.put<any>(`/api/v1/departments/${this.deptEditId}`, payload)
      : this.http.post<any>('/api/v1/departments', payload);
    req.subscribe({
      next: () => { this.submitting = false; this.showDeptForm = false; this.loadDepartments(); },
      error: err => { this.submitting = false; this.deptError = this.firstError(err) || 'Failed to save department.'; }
    });
  }

  /** Soft-delete a department after confirmation. */
  deleteDept(dept: any) {
    if (!confirm(`Delete department "${dept.name}"? This cannot be undone from the UI.`)) return;
    this.http.delete(`/api/v1/departments/${dept.id}`).subscribe({
      next: () => this.loadDepartments(),
      error: err => alert(this.firstError(err) || 'Failed to delete department.')
    });
  }

  // ── Designation CRUD ────────────────────────────────────────────────
  /** Fetch all designations (with department eager-loaded). */
  loadDesignations() {
    this.loading = true;
    this.http.get<any>('/api/v1/designations').subscribe({
      next: r => { this.designations = Array.isArray(r) ? r : (r?.data || []); this.loading = false; },
      error: err => { this.loading = false; console.error('[Admin] designations error:', err?.status, err?.error); }
    });
  }

  /** Open the designation modal in create or edit mode. */
  openDesigForm(desig?: any) {
    if (desig) {
      this.desigEditId = desig.id;
      this.desigForm = {
        title: desig.title, level: desig.level || '',
        department_id: desig.department_id || '',
        min_salary: desig.min_salary ?? '', max_salary: desig.max_salary ?? '',
        is_active: desig.is_active ?? true,
      };
    } else {
      this.desigEditId = null;
      this.desigForm = { title:'', level:'', department_id:'', min_salary:'', max_salary:'', is_active:true };
    }
    this.desigError = ''; this.showDesigForm = true;
  }

  /** Persist a designation (create or update). */
  saveDesig() {
    if (!this.desigForm.title?.trim()) { this.desigError = 'Title is required.'; return; }
    const min = this.desigForm.min_salary === '' ? null : Number(this.desigForm.min_salary);
    const max = this.desigForm.max_salary === '' ? null : Number(this.desigForm.max_salary);
    if (min !== null && max !== null && max < min) {
      this.desigError = 'Max salary must be greater than or equal to min salary.'; return;
    }
    this.submitting = true; this.desigError = '';
    const payload: any = {
      title: this.desigForm.title.trim(),
      level: this.desigForm.level || null,
      department_id: this.desigForm.department_id || null,
      min_salary: min, max_salary: max,
      is_active: !!this.desigForm.is_active,
    };
    const req = this.desigEditId
      ? this.http.put<any>(`/api/v1/designations/${this.desigEditId}`, payload)
      : this.http.post<any>('/api/v1/designations', payload);
    req.subscribe({
      next: () => { this.submitting = false; this.showDesigForm = false; this.loadDesignations(); },
      error: err => { this.submitting = false; this.desigError = this.firstError(err) || 'Failed to save designation.'; }
    });
  }

  /** Delete a designation after confirmation. */
  deleteDesig(desig: any) {
    if (!confirm(`Delete designation "${desig.title}"?`)) return;
    this.http.delete(`/api/v1/designations/${desig.id}`).subscribe({
      next: () => this.loadDesignations(),
      error: err => alert(this.firstError(err) || 'Failed to delete designation.')
    });
  }

  /** Resolve an employee's display name from the loaded employees list. */
  employeeName(id: any): string {
    const e = this.employees.find(x => x.id === id || x.id === Number(id));
    return e ? `${e.first_name} ${e.last_name}` : '—';
  }

  /** Format a salary range for table display. */
  salaryRange(d: any): string {
    if (d.min_salary == null && d.max_salary == null) return '—';
    const f = (v: any) => v == null ? '—' : Number(v).toLocaleString();
    return `${f(d.min_salary)} – ${f(d.max_salary)}`;
  }

  /** Extract the first Laravel validation error message from an HTTP error. */
  private firstError(err: any): string {
    if (err?.error?.errors) {
      const first = Object.values(err.error.errors)[0];
      if (Array.isArray(first) && first.length) return first[0] as string;
    }
    return err?.error?.message || '';
  }

  private adminErrorMessage(err: any, section: string): string {
    if (err?.status === 401) return 'Admin data could not load because your session is not authenticated. Please log in again.';
    if (err?.status === 403) return 'Admin data could not load because this user does not have admin permission.';
    const detail = err?.error?.message || err?.message || 'Please check the API server and try again.';
    return `Failed to load admin ${section}: ${detail}`;
  }

  unlinkEmployee(userId: number) {
    // handled via employee update endpoint
  }
}
