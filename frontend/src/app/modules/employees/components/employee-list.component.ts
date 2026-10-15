/**
 * @fileoverview Employee list component.
 *
 * Displays a paginated, filterable table of employees with a summary
 * stat strip at the top. All list state is read from the NgRx store;
 * the stat strip is loaded via a direct HTTP call.
 *
 * @module employees/components/employee-list.component
 */

import {
  Component,
  OnInit,
  OnDestroy,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
} from '@angular/core';
import { AuthService } from '../../../core/services/auth.service';
import { ActivatedRoute, Router } from '@angular/router';
import { HttpClient } from '@angular/common/http';
import { Store } from '@ngrx/store';
import { Observable, Subject } from 'rxjs';
import {
  takeUntil,
  debounceTime,
  distinctUntilChanged,
} from 'rxjs/operators';
import { FormControl } from '@angular/forms';
import {
  Employee,
  EmployeeStatus,
  EmploymentType,
} from '../../../shared/models/employee.model';
import { Pagination } from '../store/employee.reducer';
import * as EmployeeActions from '../store/employee.actions';
import { selectAll } from '../store/employee.reducer';
import { DepartmentRef } from '../../../shared/models/employee.model';

/** The slice of global state that contains the employee feature state. */
interface AppState {
  employees: {
    ids:              number[];
    entities:         Record<number, Employee>;
    loading:          boolean;
    pagination:       Pagination | null;
    filters:          Record<string, unknown>;
    error:            string | null;
  };
}

/** Summary counts displayed in the stat strip. */
interface EmpStats {
  total:          number;
  active:         number;
  probation:      number;
  on_leave:       number;
  terminated:     number;
  new_this_month: number;
}

/**
 * Renders the employee list with a stats strip, search, filters,
 * and action buttons.
 *
 * @example
 * <app-employee-list></app-employee-list>
 */
@Component({
  standalone:      false,
  selector:        'app-employee-list',
  templateUrl:     './employee-list.component.html',
  styleUrls:       ['./employee-list.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EmployeeListComponent implements OnInit, OnDestroy {

  /** Stream of employee arrays from the NgRx entity store. */
  employees$:  Observable<Employee[]>;

  /** True while an HTTP request is in flight. */
  loading$:    Observable<boolean>;

  /** Current pagination metadata. */
  pagination$: Observable<Pagination | null>;

  /** Filter controls */
  searchControl  = new FormControl<string>('');
  statusFilter   = new FormControl<EmployeeStatus | ''>('');
  deptFilter     = new FormControl<number | ''>('');
  unitFilter     = new FormControl<number | ''>('');
  typeFilter     = new FormControl<EmploymentType | ''>('');

  /** Department list for the filter dropdown. */
  departments: DepartmentRef[] = [];
  units: any[] = [];

  /** Workforce summary for the stat strip. */
  stats: EmpStats | null = null;
  statsLoading = true;

  /** Stat strip tile definitions (built after stats load). */
  statTiles: Array<{
    label:  string;
    value:  number;
    color:  string;
    icon:   string;
    status: string;        // value to set on statusFilter when clicked
  }> = [];

  /** Columns shown in the Material table. */
  readonly displayedColumns: string[] = [
    'avatar', 'employee_code', 'full_name', 'department',
    'unit', 'nationality',
    'employment_type', 'status', 'actions',
  ];
  isHR = false;
  canFilterEmployees = false;
  fromDashboard = false;

  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly store:  Store<AppState>,
    private readonly router: Router,
    private readonly route: ActivatedRoute,
    private readonly http:   HttpClient,
    private readonly cdr:    ChangeDetectorRef,
    private auth: AuthService
  ) {
    this.employees$  = this.store.select((s) => selectAll(s.employees));
    this.loading$    = this.store.select((s) => s.employees.loading);
    this.pagination$ = this.store.select((s) => s.employees.pagination);
  }

  /** @inheritdoc */
  ngOnInit(): void {
    this.canFilterEmployees = this.auth.getRoles().some(role =>
      ['super_admin', 'hr_manager', 'hr_staff'].includes(role));
    this.fromDashboard = this.route.snapshot.queryParamMap.get('dashboard_scope') === '1';
    this.loadEmployees();
    this.loadStats();
    if (this.canFilterEmployees) {
      this.loadDepartments();
      this.loadUnits();
    }
    this.isHR = this.auth.isHRRole();

    // Re-fetch on search input after debounce
    this.searchControl.valueChanges.pipe(
      debounceTime(400),
      distinctUntilChanged(),
      takeUntil(this.destroy$),
    ).subscribe(() => this.loadEmployees());
  }

  // ── Stats strip ────────────────────────────────────────────────────────

  /** Fetch workforce summary from the dedicated stats endpoint. */
  loadStats(): void {
    this.statsLoading = true;
    this.http.get<EmpStats>('/api/v1/employees/stats', {
      params: this.fromDashboard ? { dashboard_scope: '1' } : {},
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (s) => {
          this.stats = s;
          this.buildTiles(s);
          this.statsLoading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.statsLoading = false;
          this.cdr.markForCheck();
        },
      });
  }

  /** Load department list for filter dropdown. */
  loadDepartments(): void {
    this.http.get<any>('/api/v1/departments')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (r) => {
          this.departments = r?.data ?? r ?? [];
          this.cdr.markForCheck();
        },
        error: () => {},
      });
  }

  loadUnits(): void {
    this.http.get<any>('/api/v1/units')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (r) => {
          this.units = r?.data ?? r ?? [];
          this.cdr.markForCheck();
        },
        error: () => {},
      });
  }

  private buildTiles(s: EmpStats): void {
    this.statTiles = [
      { label: 'Total',          value: s.total,          color: '#3b82f6', icon: 'people',          status: '' },
      { label: 'Active',         value: s.active,          color: '#10b981', icon: 'how_to_reg',      status: 'active' },
      { label: 'Probation',      value: s.probation,       color: '#f59e0b', icon: 'hourglass_top',   status: 'probation' },
      { label: 'New This Month', value: s.new_this_month,  color: '#0ea5e9', icon: 'person_add_alt_1', status: '' },
    ];
  }

  /**
   * Click a stat tile to filter the table by that status.
   * Clicking "Total" or "New This Month" (no status filter) clears the filter.
   */
  filterByStatus(tile: typeof this.statTiles[0]): void {
    if (!this.canFilterEmployees) return;
    this.statusFilter.setValue(tile.status as EmployeeStatus | '');
    this.loadEmployees();
  }

  // ── Table ──────────────────────────────────────────────────────────────

  /**
   * Dispatch a load action with the current filter state.
   *
   * @param page  Page number (1-based); defaults to 1
   */
  loadEmployees(page = 1): void {
    this.store.dispatch(EmployeeActions.loadEmployees({
      params: {
        search:          this.searchControl.value  ?? '',
        status:          this.statusFilter.value   ?? '',
        department_id:   this.deptFilter.value     ?? '',
        unit_id:         this.unitFilter.value     ?? '',
        employment_type: this.typeFilter.value      ?? '',
        page,
        per_page:        15,
        dashboard_scope: this.fromDashboard,
      },
    }));
  }

  /** Reset all filters and reload the first page. */
  clearFilters(): void {
    this.searchControl.setValue('');
    this.statusFilter.setValue('');
    this.deptFilter.setValue('');
    this.unitFilter.setValue('');
    this.typeFilter.setValue('');
    this.loadEmployees();
  }

  downloadEmployeeDetailsReport(): void {
    const params: any = {
      search: this.searchControl.value ?? '',
      status: this.statusFilter.value ?? '',
      department_id: this.deptFilter.value ?? '',
      unit_id: this.unitFilter.value ?? '',
      employment_type: this.typeFilter.value ?? '',
    };

    this.http.get('/api/v1/employees/details-report', {
      params,
      responseType: 'blob',
      observe: 'response',
    }).subscribe({
      next: response => this.downloadBlob(
        response,
        `employee-details-report-${new Date().toISOString().slice(0, 10)}.xlsx`
      ),
    });
  }

  /** Navigate to the employee detail page. */
  viewEmployee(id: number): void {
    this.router.navigate(['/employees', id]);
  }

  /** Navigate to the employee edit form. */
  editEmployee(id: number): void {
    this.router.navigate(['/employees', id, 'edit']);
  }

  /** Navigate to the new-employee form. */
  addEmployee(): void {
    this.router.navigate(['/employees', 'new']);
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

  /**
   * Confirm and dispatch a terminate action.
   *
   * @param id  Employee primary key
   */
  terminate(id: number): void {
    if (confirm('Terminate this employee? This action cannot be undone.')) {
      this.store.dispatch(EmployeeActions.deleteEmployee({ id }));
    }
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  statusClass(status: EmployeeStatus | string): string {
    const map: Record<string, string> = {
      active:     'badge-green',
      on_leave:   'badge-yellow',
      probation:  'badge-blue',
      inactive:   'badge-gray',
      terminated: 'badge-red',
    };
    return map[status] ?? 'badge-gray';
  }

  typeClass(type: EmploymentType | string): string {
    const map: Record<string, string> = {
      full_time: 'badge-blue',
      part_time: 'badge-yellow',
      contract:  'badge-orange',
      intern:    'badge-purple',
    };
    return map[type] ?? 'badge-gray';
  }

  initial(name?: string | null): string {
    return name?.charAt(0)?.toUpperCase() ?? '?';
  }

  avatarColor(name?: string | null): string {
    const palette = [
      '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
      '#6366f1', '#0ea5e9', '#f97316', '#a78bfa',
    ];
    return palette[(name?.charCodeAt(0) ?? 0) % palette.length];
  }

  /**
   * Quickly toggle an employee's status directly from the list row.
   * Stops row click propagation so it doesn't navigate to the detail page.
   *
   * @param employee  The employee row object (mutated in place on success)
   * @param status    New status value
   * @param event     MouseEvent — used to stop propagation
   */
  quickStatus(employee: any, status: string, event: MouseEvent): void {
    event.stopPropagation();
    if (!confirm(`Set employee "${employee.full_name}" to ${status.replace('_', ' ')}?`)) return;

    this.http.put<any>(`/api/v1/employees/${employee.id}`, { status })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (res) => {
          this.loadEmployees();
          // Reload stats strip to reflect the change
          this.loadStats();
        },
        error: () => {},
      });
  }

  /** @inheritdoc */
  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}
