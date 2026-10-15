import { Component, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Router, ActivatedRoute } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  standalone: false,
  selector: 'app-employee-form',
  templateUrl: './employee-form.component.html',
  styleUrls: ['./employee-form.component.scss'],
})
export class EmployeeFormComponent implements OnInit {
  form!: FormGroup;
  isEdit        = false;
  employeeId: any = null;
  saving        = false;
  loadingData   = false;
  departments:   any[] = [];
  units:         any[] = [];
  designations:  any[] = [];
  managers:      any[] = [];
  activeTab     = 'personal';
  errorMsg      = '';

  tabs = [
    { id: 'personal',   label: 'Personal Info',  icon: 'person' },
    { id: 'employment', label: 'Employment',      icon: 'work' },
    { id: 'financial',  label: 'Financial',       icon: 'account_balance_wallet' },
    { id: 'emergency',  label: 'Emergency',       icon: 'emergency' },
  ];

  constructor(
    private fb: FormBuilder,
    private http: HttpClient,
    private router: Router,
    private route: ActivatedRoute,
    private auth: AuthService
  ) {}

  get canManageExtension(): boolean {
    return this.auth.hasAnyRole(['super_admin', 'ceo', 'hr_manager']);
  }

  ngOnInit() {
    this.buildForm();
    this.employeeId = this.route.snapshot.paramMap.get('id');
    this.isEdit = !!this.employeeId && this.employeeId !== 'new';
    this.loadLookups();

    if (this.isEdit) {
      this.loadingData = true;
      this.http.get<any>(`/api/v1/employees/${this.employeeId}`).subscribe({
        next: r => {
          const e = r.employee || r;

          // Designations are department-scoped and loaded async. Load them
          // FIRST so the saved designation's <option> exists before we patch
          // the value — otherwise the select silently renders empty.
          const applyPatch = () => {
            this.form.patchValue({
              prefix: e.prefix, first_name: e.first_name, last_name: e.last_name,
              arabic_name: e.arabic_name, email: e.email, phone: e.phone,
              work_phone: e.work_phone, extension: e.extension,
              // Dates: backend casts to 'date' → ISO timestamps. <input type="date">
              // only accepts YYYY-MM-DD, so strip the time portion.
              dob: this.toDateInput(e.dob),
              gender: e.gender, marital_status: e.marital_status,
              nationality: e.nationality, national_id: e.national_id,
              address: e.address, city: e.city, country: e.country,
              // IDs: option [value] renders as a string but the form value is a
              // number; coerce so Angular's strict-equality match selects it.
              department_id: this.toId(e.department_id),
              unit_id: this.toId(e.unit_id),
              designation_id: this.toId(e.designation_id),
              manager_id: this.toId(e.manager_id),
              employment_type: e.employment_type,
              mode_of_employment: e.mode_of_employment, role: e.role,
              status: e.status,
              hire_date: this.toDateInput(e.hire_date),
              confirmation_date: this.toDateInput(e.confirmation_date),
              termination_date: this.toDateInput(e.termination_date),
              probation_period: e.probation_period, years_of_experience: e.years_of_experience,
              salary: e.salary,
              housing_allowance: e.housing_allowance ?? null,
              transport_allowance: e.transport_allowance ?? null,
              other_allowances: e.other_allowances ?? 0,
              mobile_allowance: e.mobile_allowance ?? 0,
              food_allowance: e.food_allowance ?? 0,
              bank_name: e.bank_name, bank_account: e.bank_account,
              emergency_contact_name: e.emergency_contact_name,
              emergency_contact_phone: e.emergency_contact_phone,
              emergency_contact_relation: e.emergency_contact_relation,
              notes: e.notes,
            });
            this.loadingData = false;
          };

          if (e.department_id) {
            this.loadDesignations(e.department_id, applyPatch);
          } else {
            applyPatch();
          }
        },
        error: () => { this.loadingData = false; this.errorMsg = 'Failed to load employee data.'; }
      });
    }
  }

  buildForm() {
    this.form = this.fb.group({
      // Personal
      prefix:          [''],
      first_name:      ['', [Validators.required, Validators.maxLength(100)]],
      last_name:       ['', [Validators.required, Validators.maxLength(100)]],
      arabic_name:     [''],
      email:           ['', [Validators.required, Validators.email]],
      phone:           [''],
      work_phone:      [''],
      extension:       [''],
      dob:             [''],
      gender:          [''],
      marital_status:  [''],
      nationality:     [''],
      national_id:     [''],
      address:         [''],
      city:            [''],
      country:         [''],
      // Employment
      department_id:      [''],
      unit_id:            [''],
      designation_id:     [''],
      manager_id:         [''],
      employment_type:    ['full_time', Validators.required],
      mode_of_employment: ['direct'],
      role:               ['employee'],
      status:             ['active', Validators.required],
      hire_date:          ['', Validators.required],
      confirmation_date:  [''],
      termination_date:   [''],
      probation_period:   [0],
      years_of_experience:[''],
      // Financial — Salary breakdown
      salary:              ['', [Validators.required, Validators.min(0)]],
      housing_allowance:   [null],   // null = use default 25% of basic
      transport_allowance: [null],   // null = use default 10% of basic
      other_allowances:    [0],
      mobile_allowance:    [0],
      food_allowance:      [0],
      bank_name:           [''],
      bank_account:        [''],
      // Emergency
      emergency_contact_name:     [''],
      emergency_contact_phone:    [''],
      emergency_contact_relation: [''],
      notes:                   [''],
    });
  }

  loadLookups() {
    this.http.get<any>('/api/v1/departments').subscribe(r => this.departments = r?.data || r || []);
    this.http.get<any>('/api/v1/units').subscribe(r => this.units = r?.data || r || []);

    const params: any = {};
    if (this.isEdit) params.employee_id = this.employeeId;
    this.http.get<any>('/api/v1/employees/manager-options', { params }).subscribe({
      next: r => this.managers = r?.managers || [],
      error: () => this.managers = [],
    });
  }

  /**
   * React to a department change. With [ngValue] the form control already holds
   * the new (typed) value when (change) fires, so read it from the control
   * rather than the DOM event target (which would be a string).
   */
  onDeptChange() {
    const id = this.form.get('department_id')?.value;
    this.form.patchValue({ designation_id: '' });
    if (id) this.loadDesignations(id);
    else this.designations = [];
  }

  /**
   * Load department-scoped designations. An optional callback runs after the
   * list is populated, letting the edit flow patch the saved designation_id
   * only once its <option> exists in the DOM.
   *
   * @param deptId Department id to scope designations to.
   * @param done   Optional callback fired after the list is set (success or error).
   */
  loadDesignations(deptId: any, done?: () => void) {
    this.http.get<any>(`/api/v1/designations?department_id=${deptId}`).subscribe({
      next: r => { this.designations = r?.data || r || []; if (done) done(); },
      error: () => { this.designations = []; if (done) done(); },
    });
  }

  /**
   * Normalize a backend date value into the YYYY-MM-DD string required by
   * <input type="date">. Handles ISO timestamps, date-only strings, and null.
   *
   * @param value Raw date value from the API (e.g. "2024-03-15T00:00:00.000000Z").
   * @returns A YYYY-MM-DD string, or '' when the value is empty/invalid.
   */
  toDateInput(value: any): string {
    if (!value) return '';
    // Already date-only — return the first 10 chars verbatim (no TZ shift).
    if (typeof value === 'string') {
      const m = value.match(/^(\d{4}-\d{2}-\d{2})/);
      if (m) return m[1];
    }
    const d = new Date(value);
    if (isNaN(d.getTime())) return '';
    // Build from local parts to avoid an off-by-one day from UTC conversion.
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
  }

  /**
   * Coerce a foreign-key id to a number so it strict-equals the numeric option
   * values bound via [value]="d.id". Empty/null becomes '' (the placeholder).
   *
   * @param value Raw id from the API.
   * @returns A number, or '' when absent.
   */
  toId(value: any): number | '' {
    if (value === null || value === undefined || value === '') return '';
    const n = Number(value);
    return isNaN(n) ? '' : n;
  }

  submit() {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.errorMsg = 'Please fill in all required fields.';
      // Switch to first tab that has an error
      const tabFields: Record<string, string[]> = {
        personal:   ['first_name','last_name','email','phone','dob','gender','marital_status','nationality','national_id','address','city','country'],
        employment: ['department_id','unit_id','designation_id','manager_id','employment_type','status','hire_date','confirmation_date','probation_period'],
        financial:  ['salary','bank_name','bank_account'],
        emergency:  ['emergency_contact_name','emergency_contact_phone','emergency_contact_relation'],
      };
      for (const [tab, fields] of Object.entries(tabFields)) {
        if (fields.some(f => this.form.get(f)?.invalid)) { this.activeTab = tab; break; }
      }
      return;
    }
    this.saving   = true;
    this.errorMsg = '';
    const payload = this.buildPayload();
    const url  = this.isEdit ? `/api/v1/employees/${this.employeeId}` : '/api/v1/employees';
    const req  = this.isEdit ? this.http.put<any>(url, payload) : this.http.post<any>(url, payload);
    req.subscribe({
      next: r => {
        const id = r.employee?.id || this.employeeId;
        this.router.navigate(['/employees', id]);
      },
      error: err => {
        this.saving   = false;
        // Laravel 422 returns { errors: { field: ['message'] } }
        if (err?.status === 422 && err?.error?.errors) {
          const errs = err.error.errors;
          // Patch field errors into Angular form
          Object.keys(errs).forEach(field => {
            const ctrl = this.form.get(field);
            if (ctrl) ctrl.setErrors({ serverError: errs[field][0] });
          });
          this.errorMsg = 'Please correct the highlighted fields.';
        } else {
          this.errorMsg = err?.error?.message || 'Failed to save employee.';
        }
      }
    });
  }

  buildPayload(): any {
    const payload = { ...this.form.getRawValue() };
    if (!this.canManageExtension) delete payload.extension;
    ['dob', 'hire_date', 'confirmation_date', 'termination_date'].forEach(field => {
      payload[field] = this.toDateInput(payload[field]) || null;
    });
    ['department_id', 'unit_id', 'designation_id', 'manager_id'].forEach(field => {
      payload[field] = payload[field] === '' || payload[field] === null ? null : Number(payload[field]);
    });
    ['housing_allowance', 'transport_allowance', 'years_of_experience'].forEach(field => {
      if (payload[field] === '') payload[field] = null;
    });
    return payload;
  }

  tabHasError(tabId: string): boolean {
    const tabFields: Record<string, string[]> = {
      personal:   ['first_name','last_name','email','phone','dob','gender','marital_status','nationality','national_id','address','city','country'],
      employment: ['department_id','unit_id','designation_id','manager_id','employment_type','status','hire_date','confirmation_date','probation_period'],
      financial:  ['salary','bank_name','bank_account'],
      emergency:  ['emergency_contact_name','emergency_contact_phone','emergency_contact_relation'],
    };
    return (tabFields[tabId] || []).some(f => this.form.get(f)?.invalid && this.form.get(f)?.touched);
  }

  get computedHousing(): number {
    const basic = parseFloat(this.form.get('salary')?.value) || 0;
    const override = this.form.get('housing_allowance')?.value;
    return override !== null && override !== '' ? parseFloat(override) || 0 : Math.round(basic * 0.25 * 100) / 100;
  }

  get computedTransport(): number {
    const basic = parseFloat(this.form.get('salary')?.value) || 0;
    const override = this.form.get('transport_allowance')?.value;
    return override !== null && override !== '' ? parseFloat(override) || 0 : Math.round(basic * 0.10 * 100) / 100;
  }

  get computedGross(): number {
    const basic     = parseFloat(this.form.get('salary')?.value) || 0;
    const housing   = this.computedHousing;
    const transport = this.computedTransport;
    const other     = parseFloat(this.form.get('other_allowances')?.value) || 0;
    const mobile    = parseFloat(this.form.get('mobile_allowance')?.value) || 0;
    const food      = parseFloat(this.form.get('food_allowance')?.value) || 0;
    return Math.round((basic + housing + transport + other + mobile + food) * 100) / 100;
  }

  cancel() {
    this.isEdit ? this.router.navigate(['/employees', this.employeeId]) : this.router.navigate(['/employees']);
  }

  f(name: string) { return this.form.get(name); }
  err(name: string) { const c = this.f(name); return c?.invalid && c?.touched ? c.errors : null; }
  serverErr(name: string): string | null {
    return (this.form.get(name)?.errors as any)?.['serverError'] || null;
  }

  prevTab() {
    const i = this.tabs.findIndex(t => t.id === this.activeTab);
    if (i > 0) this.activeTab = this.tabs[i - 1].id;
  }

  nextTab() {
    const i = this.tabs.findIndex(t => t.id === this.activeTab);
    if (i < this.tabs.length - 1) this.activeTab = this.tabs[i + 1].id;
  }
}
