import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';
import { LanguageService } from '../../../core/services/language.service';

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

  /** `label` holds a translation key, rendered with the translate pipe. */
  tabs = [
    { id:'overview',     label:'ADMIN.TABS.OVERVIEW',      icon:'dashboard'          },
    { id:'users',        label:'ADMIN.TABS.USERS',         icon:'people'             },
    { id:'login-history', label:'ADMIN.TABS.LOGIN_HISTORY', icon:'manage_search'     },
    { id:'roles',        label:'ADMIN.TABS.ROLES',         icon:'security'           },
    { id:'permissions',  label:'ADMIN.TABS.PERMISSIONS',   icon:'lock'               },
    { id:'units',        label:'ADMIN.TABS.UNITS',         icon:'business'           },
    { id:'departments',  label:'ADMIN.TABS.DEPARTMENTS',   icon:'corporate_fare'     },
    { id:'designations', label:'ADMIN.TABS.DESIGNATIONS',  icon:'badge'              },
    { id:'migration',    label:'ADMIN.TABS.MIGRATION',     icon:'upload_file'        },
    { id:'settings',     label:'ADMIN.TABS.SETTINGS',      icon:'settings'           },
  ];

  /** Labels below are translation keys (ADMIN.MIGRATION.*), rendered with the translate pipe. */
  migrationCategories = [
    { id: '', label: 'ADMIN.MIGRATION.CATEGORIES.ALL' },
    { id: 1, label: 'ADMIN.MIGRATION.CATEGORIES.C1' },
    { id: 2, label: 'ADMIN.MIGRATION.CATEGORIES.C2' },
    { id: 3, label: 'ADMIN.MIGRATION.CATEGORIES.C3' },
    { id: 4, label: 'ADMIN.MIGRATION.CATEGORIES.C4' },
    { id: 5, label: 'ADMIN.MIGRATION.CATEGORIES.C5' },
    { id: 6, label: 'ADMIN.MIGRATION.CATEGORIES.C6' },
    { id: 7, label: 'ADMIN.MIGRATION.CATEGORIES.C7' },
    { id: 8, label: 'ADMIN.MIGRATION.CATEGORIES.C8' },
    { id: 9, label: 'ADMIN.MIGRATION.CATEGORIES.C9' },
    { id: 10, label: 'ADMIN.MIGRATION.CATEGORIES.C10' },
    { id: 11, label: 'ADMIN.MIGRATION.CATEGORIES.C11' },
    { id: 12, label: 'ADMIN.MIGRATION.CATEGORIES.C12' },
    { id: 13, label: 'ADMIN.MIGRATION.CATEGORIES.C13' },
    { id: 14, label: 'ADMIN.MIGRATION.CATEGORIES.C14' },
    { id: 15, label: 'ADMIN.MIGRATION.CATEGORIES.C15' },
    { id: 16, label: 'ADMIN.MIGRATION.CATEGORIES.C16' },
  ];
  migrationScopes = [
    { id: 'all', label: 'ADMIN.MIGRATION.SCOPES.all' },
    { id: 'departments', label: 'ADMIN.MIGRATION.SCOPES.departments' },
    { id: 'job_positions', label: 'ADMIN.MIGRATION.SCOPES.job_positions' },
    { id: 'employees', label: 'ADMIN.MIGRATION.SCOPES.employees' },
    { id: 'employee_managers', label: 'ADMIN.MIGRATION.SCOPES.employee_managers' },
    { id: 'leave_records', label: 'ADMIN.MIGRATION.SCOPES.leave_records' },
    { id: 'loan_records', label: 'ADMIN.MIGRATION.SCOPES.loan_records' },
    { id: 'documents', label: 'ADMIN.MIGRATION.SCOPES.documents' },
  ];
  migrationOrder = [
    'ADMIN.MIGRATION.STEPS.DEPARTMENTS', 'ADMIN.MIGRATION.STEPS.JOB_POSITIONS', 'ADMIN.MIGRATION.STEPS.EMPLOYEES',
    'ADMIN.MIGRATION.STEPS.EMPLOYEE_MANAGERS', 'ADMIN.MIGRATION.STEPS.LEAVE_RECORDS', 'ADMIN.MIGRATION.STEPS.LOAN_RECORDS',
    'ADMIN.MIGRATION.STEPS.DOCUMENTS',
  ];
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
  unitColumns = ['name','name_ar','code','legacy','counts','status','actions'];
  showUnitForm = false;
  unitForm: any = { name:'', name_ar:'', code:'', legacy_unitid:'', description:'', is_active:true };
  unitEditId: number | null = null;
  unitError = '';

  departments: any[]   = [];
  deptColumns          = ['name','name_ar','code','manager','parent','headcount','status','actions'];
  showDeptForm         = false;
  deptForm: any        = { name:'', name_ar:'', code:'', description:'', parent_id:'', manager_id:'', headcount_budget:'', is_active:true };
  deptEditId: number | null = null;
  deptError            = '';

  // ── Designations ──────────────────────────────────────────────────────
  designations: any[]  = [];
  desigColumns         = ['title','title_ar','level','department','salary','status','actions'];
  showDesigForm        = false;
  desigForm: any       = { title:'', title_ar:'', level:'', department_id:'', min_salary:'', max_salary:'', is_active:true };
  desigEditId: number | null = null;
  desigError           = '';

  /** Designation seniority levels — mirrors backend validation enum. */
  desigLevels = ['junior','mid','senior','lead','manager','director','executive','management','staff'];

  /** Colour/icon per role. Display names come from ROLES.<role> in common.json (see roleLabel). */
  roleInfo: any = {
    super_admin:        { color:'#ef4444', icon:'shield'             },
    ceo:                { color:'#7c3aed', icon:'business_center'    },
    hr_manager:         { color:'#6366f1', icon:'manage_accounts'    },
    hr_staff:           { color:'#8b5cf6', icon:'badge'              },
    finance_manager:    { color:'#10b981', icon:'account_balance'    },
    department_manager: { color:'#f59e0b', icon:'supervisor_account' },
    employee:           { color:'#3b82f6', icon:'person'             },
  };

  permModules = [
    { key:'dashboard',    label:'ADMIN.MODULES.dashboard',    icon:'dashboard'          },
    { key:'employees',    label:'ADMIN.MODULES.employees',    icon:'people'             },
    { key:'payroll',      label:'ADMIN.MODULES.payroll',      icon:'payments'           },
    { key:'leave',        label:'ADMIN.MODULES.leave',        icon:'event_available'    },
    { key:'loans',        label:'ADMIN.MODULES.loans',        icon:'account_balance'    },
    { key:'separations',  label:'ADMIN.MODULES.separations',  icon:'exit_to_app'        },
    { key:'requests',     label:'ADMIN.MODULES.requests',     icon:'inbox'              },
    { key:'recruitment',  label:'ADMIN.MODULES.recruitment',  icon:'work'               },
    { key:'performance',  label:'ADMIN.MODULES.performance',  icon:'leaderboard'        },
    { key:'attendance',   label:'ADMIN.MODULES.attendance',   icon:'fingerprint'         },
    { key:'contracts',    label:'ADMIN.MODULES.contracts',    icon:'description'         },
    { key:'orgchart',     label:'ADMIN.MODULES.orgchart',     icon:'account_tree'        },
    { key:'admin',        label:'ADMIN.MODULES.admin',        icon:'admin_panel_settings'},
  ];

  constructor(private http: HttpClient, public auth: AuthService, private lang: LanguageService) {}

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
        this.adminError = this.adminErrorMessage(err, 'login_history');
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
      this.migrationError = this.lang.t('ADMIN.MSG.CHOOSE_MIGRATION_FILE');
      return;
    }
    if (this.migrationScope === 'documents' && !this.migrationSourceFolder.trim()) {
      this.migrationError = this.lang.t('ADMIN.MSG.ENTER_SOURCE_FOLDER');
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
        this.migrationMessage = r.message || this.lang.t(dryRun ? 'ADMIN.MSG.MIGRATION_VALIDATED' : 'ADMIN.MSG.MIGRATION_COMPLETED');
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
          ? this.lang.t('ADMIN.MSG.MIGRATION_TIMEOUT')
          : this.firstError(err)
          || err?.error?.message
          || err?.message
          || this.lang.t('ADMIN.MSG.MIGRATION_FAILED');
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
          this.carryForwardError = this.lang.t('ADMIN.MSG.NO_ANNUAL_TYPE');
        }
        this.carryForwardLoading = false;
      },
      error: err => {
        this.carryForwardError = this.firstError(err) || this.lang.t('ADMIN.MSG.CARRY_LOAD_FAILED');
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
      this.carryForwardError = this.lang.t('ADMIN.MSG.CARRY_INVALID');
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
        this.carryForwardMessage = this.lang.t('ADMIN.MSG.CARRY_SAVED');
        this.carryForwardSaving = false;
      },
      error: err => {
        this.carryForwardError = this.firstError(err) || this.lang.t('ADMIN.MSG.CARRY_SAVE_FAILED');
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
        this.loanSettingsError = this.firstError(err) || this.lang.t('ADMIN.MSG.LOAN_LOAD_FAILED');
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
        this.loanSettingsError = this.firstError(err) || this.lang.t('ADMIN.MSG.LOAN_SAVE_FAILED');
        this.settingsSaving = false;
      }
    });
  }

  loadHdfTemplate() {
    this.http.get<any>('/api/v1/admin/settings/hdf-template').subscribe({
      next: r => this.hdfTemplate = r?.template || { exists: false, name: null, size: null },
      error: err => this.hdfError = this.firstError(err) || this.lang.t('ADMIN.MSG.HDF_LOAD_FAILED')
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
      this.hdfError = this.lang.t('ADMIN.MSG.HDF_CHOOSE');
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
        this.hdfError = this.firstError(err) || this.lang.t('ADMIN.MSG.HDF_UPLOAD_FAILED');
        this.hdfUploading = false;
      }
    });
  }

  loadAnnualTicketSettings() {
    this.http.get<any>('/api/v1/admin/settings/annual-tickets').subscribe({
      next: r => this.annualTicketSettings = r.settings,
      error: err => this.ticketSettingsError = this.firstError(err) || this.lang.t('ADMIN.MSG.TICKET_LOAD_FAILED')
    });
  }

  saveAnnualTicketSettings() {
    this.ticketSettingsSaving = true; this.ticketSettingsMessage = ''; this.ticketSettingsError = '';
    this.http.put<any>('/api/v1/admin/settings/annual-tickets', this.annualTicketSettings).subscribe({
      next: r => { this.annualTicketSettings = r.settings; this.ticketSettingsMessage = r.message; this.ticketSettingsSaving = false; },
      error: err => { this.ticketSettingsError = this.firstError(err) || this.lang.t('ADMIN.MSG.TICKET_SAVE_FAILED'); this.ticketSettingsSaving = false; }
    });
  }

  loadMonthlyLeaveReminderSettings() {
    this.http.get<any>('/api/v1/admin/settings/monthly-leave-reminder').subscribe({
      next: r => this.monthlyLeaveReminderSettings = { ...this.monthlyLeaveReminderSettings, ...r.settings },
      error: err => this.leaveReminderError = this.firstError(err) || this.lang.t('ADMIN.MSG.REMINDER_LOAD_FAILED')
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
        this.leaveReminderError = this.firstError(err) || this.lang.t('ADMIN.MSG.REMINDER_SAVE_FAILED');
        this.leaveReminderSaving = false;
      }
    });
  }

  loadUnifonicSettings() {
    this.http.get<any>('/api/v1/admin/settings/unifonic').subscribe({
      next: r => this.unifonicSettings = { ...this.unifonicSettings, ...r.settings },
      error: err => this.unifonicError = this.firstError(err) || this.lang.t('ADMIN.MSG.SMS_LOAD_FAILED')
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
        this.unifonicError = this.firstError(err) || this.lang.t('ADMIN.MSG.SMS_SAVE_FAILED');
        this.unifonicSaving = false;
      }
    });
  }

  loadEmailSettings() {
    this.http.get<any>('/api/v1/admin/settings/email').subscribe({
      next: r => this.emailSettings = { ...this.emailSettings, ...r.settings },
      error: err => this.emailSettingsError = this.firstError(err) || this.lang.t('ADMIN.MSG.EMAIL_LOAD_FAILED')
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
        this.emailSettingsError = this.firstError(err) || this.lang.t('ADMIN.MSG.EMAIL_SAVE_FAILED');
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
    if (!this.userForm.name || !this.userForm.email) { this.formError = this.lang.t('ADMIN.MSG.NAME_EMAIL_REQUIRED'); return; }
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
      error: err => { this.submitting = false; this.formError = err?.error?.message || this.lang.t('ADMIN.MSG.FAILED'); }
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
      error: err => this.adminError = this.firstError(err) || this.lang.t('ADMIN.MSG.OTP_UPDATE_FAILED')
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
    const key = `ADMIN.LOGIN.EVENTS.${event}`;
    const label = event ? this.lang.t(key) : '';
    if (label && label !== key) return label;
    return (event || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  loginActor(activity: any): string {
    return activity?.user?.name || activity?.login_identifier || this.lang.t('ADMIN.LOGIN.UNKNOWN_USER');
  }

  loginEmployee(activity: any): string {
    const employee = activity?.user?.employee;
    if (!employee) return '';
    return `${employee.first_name || ''} ${employee.last_name || ''}`.trim() || employee.employee_code || '';
  }

  roleData(roleName: string): any {
    return this.roleInfo[roleName] || { color: '#8b949e', icon: 'person' };
  }

  /** Translated role name (ROLES.<role>); custom roles fall back to the given fallback or the raw name. */
  roleLabel(roleName: string, fallback?: string): string {
    if (!roleName) return fallback || '';
    const key = `ROLES.${roleName}`;
    const label = this.lang.t(key);
    return label && label !== key ? label : (fallback || roleName);
  }

  /** Translated designation level (ADMIN.LEVELS.<level>), falling back to the raw code. */
  levelLabel(level: string): string {
    if (!level) return '';
    const key = `ADMIN.LEVELS.${level}`;
    const label = this.lang.t(key);
    return label && label !== key ? label : level;
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
        name_ar: unit.name_ar || '',
        code: unit.code,
        legacy_unitid: unit.legacy_unitid || '',
        description: unit.description || '',
        is_active: unit.is_active ?? true,
      };
    } else {
      this.unitEditId = null;
      this.unitForm = { name:'', name_ar:'', code:'', legacy_unitid:'', description:'', is_active:true };
    }
    this.unitError = ''; this.showUnitForm = true;
  }

  saveUnit() {
    if (!this.unitForm.name?.trim() || !this.unitForm.code?.trim()) {
      this.unitError = this.lang.t('ADMIN.MSG.NAME_CODE_REQUIRED'); return;
    }
    this.submitting = true; this.unitError = '';
    const payload = {
      name: this.unitForm.name.trim(),
      name_ar: this.unitForm.name_ar?.trim() || null,
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
      error: err => { this.submitting = false; this.unitError = this.firstError(err) || this.lang.t('ADMIN.MSG.UNIT_SAVE_FAILED'); }
    });
  }

  deleteUnit(unit: any) {
    if (!confirm(this.lang.t('ADMIN.MSG.UNIT_CONFIRM_DELETE', { name: unit.name }))) return;
    this.http.delete(`/api/v1/units/${unit.id}`).subscribe({
      next: () => this.loadUnits(),
      error: err => alert(this.firstError(err) || this.lang.t('ADMIN.MSG.UNIT_DELETE_FAILED'))
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
        name: dept.name, name_ar: dept.name_ar || '', code: dept.code, description: dept.description || '',
        parent_id: dept.parent_id || '', manager_id: dept.manager_id || '',
        headcount_budget: dept.headcount_budget ?? '', is_active: dept.is_active ?? true,
      };
    } else {
      this.deptEditId = null;
      this.deptForm = { name:'', name_ar:'', code:'', description:'', parent_id:'', manager_id:'', headcount_budget:'', is_active:true };
    }
    this.deptError = ''; this.showDeptForm = true;
  }

  /** Persist a department (create or update). */
  saveDept() {
    if (!this.deptForm.name?.trim() || !this.deptForm.code?.trim()) {
      this.deptError = this.lang.t('ADMIN.MSG.NAME_CODE_REQUIRED'); return;
    }
    this.submitting = true; this.deptError = '';
    const payload: any = {
      name: this.deptForm.name.trim(),
      name_ar: this.deptForm.name_ar?.trim() || null,
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
      error: err => { this.submitting = false; this.deptError = this.firstError(err) || this.lang.t('ADMIN.MSG.DEPT_SAVE_FAILED'); }
    });
  }

  /** Soft-delete a department after confirmation. */
  deleteDept(dept: any) {
    if (!confirm(this.lang.t('ADMIN.MSG.DEPT_CONFIRM_DELETE', { name: dept.name }))) return;
    this.http.delete(`/api/v1/departments/${dept.id}`).subscribe({
      next: () => this.loadDepartments(),
      error: err => alert(this.firstError(err) || this.lang.t('ADMIN.MSG.DEPT_DELETE_FAILED'))
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
        title: desig.title, title_ar: desig.title_ar || '', level: desig.level || '',
        department_id: desig.department_id || '',
        min_salary: desig.min_salary ?? '', max_salary: desig.max_salary ?? '',
        is_active: desig.is_active ?? true,
      };
    } else {
      this.desigEditId = null;
      this.desigForm = { title:'', title_ar:'', level:'', department_id:'', min_salary:'', max_salary:'', is_active:true };
    }
    this.desigError = ''; this.showDesigForm = true;
  }

  /** Persist a designation (create or update). */
  saveDesig() {
    if (!this.desigForm.title?.trim()) { this.desigError = this.lang.t('ADMIN.MSG.TITLE_REQUIRED'); return; }
    const min = this.desigForm.min_salary === '' ? null : Number(this.desigForm.min_salary);
    const max = this.desigForm.max_salary === '' ? null : Number(this.desigForm.max_salary);
    if (min !== null && max !== null && max < min) {
      this.desigError = this.lang.t('ADMIN.MSG.SALARY_RANGE_INVALID'); return;
    }
    this.submitting = true; this.desigError = '';
    const payload: any = {
      title: this.desigForm.title.trim(),
      title_ar: this.desigForm.title_ar?.trim() || null,
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
      error: err => { this.submitting = false; this.desigError = this.firstError(err) || this.lang.t('ADMIN.MSG.DESIG_SAVE_FAILED'); }
    });
  }

  /** Delete a designation after confirmation. */
  deleteDesig(desig: any) {
    if (!confirm(this.lang.t('ADMIN.MSG.DESIG_CONFIRM_DELETE', { name: desig.title }))) return;
    this.http.delete(`/api/v1/designations/${desig.id}`).subscribe({
      next: () => this.loadDesignations(),
      error: err => alert(this.firstError(err) || this.lang.t('ADMIN.MSG.DESIG_DELETE_FAILED'))
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
    if (err?.status === 401) return this.lang.t('ADMIN.MSG.ERR_401');
    if (err?.status === 403) return this.lang.t('ADMIN.MSG.ERR_403');
    const detail = err?.error?.message || err?.message || this.lang.t('ADMIN.MSG.ERR_CHECK_API');
    const sectionKey = `ADMIN.SECTIONS.${section}`;
    const sectionLabel = this.lang.t(sectionKey);
    return this.lang.t('ADMIN.MSG.ERR_LOAD_SECTION', { section: sectionLabel !== sectionKey ? sectionLabel : section, detail });
  }

  unlinkEmployee(userId: number) {
    // handled via employee update endpoint
  }
}
