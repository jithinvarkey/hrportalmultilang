/**
 * @fileoverview NgRx Effects for the Employee feature.
 *
 * Effects are responsible only for orchestrating side-effects (HTTP, toasts,
 * navigation). All HTTP logic is delegated to {@see EmployeeService}.
 * No direct HttpClient usage is permitted here.
 *
 * @module employees/store/employee.effects
 */

import { Injectable } from '@angular/core';
import { Router } from '@angular/router';
import { Actions, createEffect, ofType } from '@ngrx/effects';
import { ToastrService } from 'ngx-toastr';
import { of } from 'rxjs';
import { catchError, map, switchMap, tap } from 'rxjs/operators';
import { EmployeeService } from '../../../core/services/employee.service';
import { LanguageService } from '../../../core/services/language.service';
import * as EmployeeActions from './employee.actions';

/**
 * Side-effect handlers for the Employee NgRx feature.
 *
 * Each effect listens for a specific action, calls the service,
 * and dispatches a success or failure action in return.
 */
@Injectable()
export class EmployeeEffects {

  constructor(
    private readonly actions$: Actions,
    private readonly employeeService: EmployeeService,
    private readonly toastr: ToastrService,
    private readonly router: Router,
    private readonly lang: LanguageService,
  ) {}

  /**
   * Load the paginated employee list.
   * Dispatches loadEmployeesSuccess or loadEmployeesFailure.
   */
  loadEmployees$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.loadEmployees),
      switchMap(({ params }) =>
        this.employeeService.getAll(params).pipe(
          map((data) => EmployeeActions.loadEmployeesSuccess({ data })),
          catchError((err) =>
            of(EmployeeActions.loadEmployeesFailure({
              error: err?.error?.message ?? this.lang.t('EMPLOYEES.MSG.LOAD_FAILED'),
            }))
          ),
        )
      ),
    )
  );

  /**
   * Load a single employee by ID.
   * Dispatches loadEmployeeSuccess or loadEmployeeFailure.
   */
  loadEmployee$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.loadEmployee),
      switchMap(({ id }) =>
        this.employeeService.getOne(id).pipe(
          map((employee) => EmployeeActions.loadEmployeeSuccess({ employee })),
          catchError((err) =>
            of(EmployeeActions.loadEmployeeFailure({
              error: err?.error?.message ?? this.lang.t('EMPLOYEES.DETAIL.NOT_FOUND'),
            }))
          ),
        )
      ),
    )
  );

  /**
   * Create a new employee.
   * Dispatches createEmployeeSuccess or createEmployeeFailure.
   */
  createEmployee$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.createEmployee),
      switchMap(({ data }) =>
        this.employeeService.create(data).pipe(
          map((res) => EmployeeActions.createEmployeeSuccess({ employee: res.employee })),
          catchError((err) =>
            of(EmployeeActions.createEmployeeFailure({
              error: err?.error?.message ?? this.lang.t('EMPLOYEES.MSG.CREATE_FAILED'),
            }))
          ),
        )
      ),
    )
  );

  /**
   * Show success toast and navigate to the new employee's detail page.
   */
  createEmployeeSuccess$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.createEmployeeSuccess),
      tap(({ employee }) => {
        this.toastr.success(this.lang.t('EMPLOYEES.MSG.CREATED'));
        this.router.navigate(['/employees', employee.id]);
      }),
    ),
    { dispatch: false }
  );

  /** Show error toast when create fails. */
  createEmployeeFailure$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.createEmployeeFailure),
      tap(({ error }) => this.toastr.error(error, this.lang.t('EMPLOYEES.MSG.CREATE_FAILED_TITLE'))),
    ),
    { dispatch: false }
  );

  /**
   * Update an existing employee.
   * Dispatches updateEmployeeSuccess or updateEmployeeFailure.
   */
  updateEmployee$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.updateEmployee),
      switchMap(({ id, data }) =>
        this.employeeService.update(id, data).pipe(
          map((employee) => EmployeeActions.updateEmployeeSuccess({ employee })),
          catchError((err) =>
            of(EmployeeActions.updateEmployeeFailure({
              error: err?.error?.message ?? this.lang.t('EMPLOYEES.MSG.UPDATE_FAILED'),
            }))
          ),
        )
      ),
    )
  );

  /** Show success toast when update succeeds. */
  updateEmployeeSuccess$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.updateEmployeeSuccess),
      tap(() => this.toastr.success(this.lang.t('EMPLOYEES.MSG.UPDATED'))),
    ),
    { dispatch: false }
  );

  /** Show error toast when update fails. */
  updateEmployeeFailure$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.updateEmployeeFailure),
      tap(({ error }) => this.toastr.error(error, this.lang.t('EMPLOYEES.MSG.UPDATE_FAILED_TITLE'))),
    ),
    { dispatch: false }
  );

  /**
   * Terminate (soft-delete) an employee.
   * Dispatches deleteEmployeeSuccess or deleteEmployeeFailure.
   */
  deleteEmployee$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.deleteEmployee),
      switchMap(({ id }) =>
        this.employeeService.delete(id).pipe(
          map(() => EmployeeActions.deleteEmployeeSuccess({ id })),
          catchError((err) =>
            of(EmployeeActions.deleteEmployeeFailure({
              error: err?.error?.message ?? this.lang.t('EMPLOYEES.MSG.TERMINATE_FAILED'),
            }))
          ),
        )
      ),
    )
  );

  /** Show success toast and navigate back to the employee list after termination. */
  deleteEmployeeSuccess$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.deleteEmployeeSuccess),
      tap(() => {
        this.toastr.success(this.lang.t('EMPLOYEES.MSG.TERMINATED'));
        this.router.navigate(['/employees']);
      }),
    ),
    { dispatch: false }
  );

  /** Show error toast when termination fails. */
  deleteEmployeeFailure$ = createEffect(() =>
    this.actions$.pipe(
      ofType(EmployeeActions.deleteEmployeeFailure),
      tap(({ error }) => this.toastr.error(error, this.lang.t('EMPLOYEES.MSG.TERMINATE_FAILED_TITLE'))),
    ),
    { dispatch: false }
  );
}
