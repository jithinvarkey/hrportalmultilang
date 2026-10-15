import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { ReactiveFormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { EmployeeFormComponent } from './employee-form.component';

/**
 * Unit tests for EmployeeFormComponent's EDIT data-load path.
 *
 * Regression coverage for three bugs that made the edit form fail to read data:
 *  1. Date fields arriving as ISO timestamps not populating <input type="date">.
 *  2. Numeric FK ids (department/designation/manager) not matching string options.
 *  3. Designation select empty due to async list loading after patch (race).
 *
 * @group employees
 */
describe('EmployeeFormComponent — edit data load', () => {
  let component: EmployeeFormComponent;
  let fixture: ComponentFixture<EmployeeFormComponent>;
  let httpMock: HttpTestingController;

  function configure(routeId: string | null): void {
    TestBed.configureTestingModule({
      declarations: [EmployeeFormComponent],
      imports: [HttpClientTestingModule, RouterTestingModule, ReactiveFormsModule],
      providers: [
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => routeId } } } },
      ],
      schemas: [NO_ERRORS_SCHEMA],
    });
    fixture = TestBed.createComponent(EmployeeFormComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  }

  afterEach(() => httpMock.verify());

  // ── Pure helper tests (no HTTP) ─────────────────────────────────────────
  describe('toDateInput()', () => {
    beforeEach(() => configure('new'));

    it('extracts YYYY-MM-DD from an ISO timestamp without timezone drift', () => {
      expect(component.toDateInput('2024-03-15T00:00:00.000000Z')).toBe('2024-03-15');
    });
    it('passes through a date-only string', () => {
      expect(component.toDateInput('2024-03-15')).toBe('2024-03-15');
    });
    it('returns empty string for null/empty', () => {
      expect(component.toDateInput(null)).toBe('');
      expect(component.toDateInput('')).toBe('');
    });
  });

  describe('toId()', () => {
    beforeEach(() => configure('new'));

    it('coerces a numeric string to a number', () => {
      expect(component.toId('7')).toBe(7);
    });
    it('keeps a number as a number', () => {
      expect(component.toId(7)).toBe(7);
    });
    it('returns empty string for null/empty/invalid', () => {
      expect(component.toId(null)).toBe('');
      expect(component.toId('')).toBe('');
      expect(component.toId('abc')).toBe('');
    });
  });

  // ── Full edit-load flow ─────────────────────────────────────────────────
  it('loads designations BEFORE patching, then populates dates and FK ids', fakeAsync(() => {
    configure('42');
    fixture.detectChanges(); // ngOnInit

    // Lookups fired by loadLookups()
    httpMock.expectOne('/api/v1/departments').flush({ data: [{ id: 3, name: 'Engineering' }] });
    httpMock.expectOne(req => req.url === '/api/v1/employees/manager-options' && req.params.get('employee_id') === '42').flush({ managers: [] });

    // Employee fetch
    httpMock.expectOne('/api/v1/employees/42').flush({
      employee: {
        id: 42, first_name: 'Sara', last_name: 'Khan', email: 's@x.com',
        dob: '1990-06-01T00:00:00.000000Z',
        hire_date: '2022-01-10T00:00:00.000000Z',
        confirmation_date: '2022-04-10T00:00:00.000000Z',
        termination_date: '2026-01-31T00:00:00.000000Z',
        department_id: 3, designation_id: 9, manager_id: 5,
        employment_type: 'full_time', status: 'active', salary: '12000.00',
        nationality: 'Sri Lankan', national_id: 'IQAMA-123', bank_account: 'SA123',
        address: 'King Fahd Road', notes: 'Existing notes',
        emergency_contact_name: 'Emergency Person',
      },
    });

    // Because department_id is set, designations load BEFORE patch completes.
    const desigReq = httpMock.expectOne('/api/v1/designations?department_id=3');
    desigReq.flush({ data: [{ id: 9, title: 'Senior Engineer' }] });
    tick();

    expect(component.form.get('dob')?.value).toBe('1990-06-01');
    expect(component.form.get('hire_date')?.value).toBe('2022-01-10');
    expect(component.form.get('confirmation_date')?.value).toBe('2022-04-10');
    expect(component.form.get('termination_date')?.value).toBe('2026-01-31');
    expect(component.form.get('department_id')?.value).toBe(3);
    expect(component.form.get('designation_id')?.value).toBe(9); // race fixed
    expect(component.form.get('manager_id')?.value).toBe(5);
    expect(component.form.get('nationality')?.value).toBe('Sri Lankan');
    expect(component.form.get('national_id')?.value).toBe('IQAMA-123');
    expect(component.form.get('bank_account')?.value).toBe('SA123');
    expect(component.form.get('address')?.value).toBe('King Fahd Road');
    expect(component.form.get('notes')?.value).toBe('Existing notes');
    expect(component.form.get('emergency_contact_name')?.value).toBe('Emergency Person');
    expect(component.designations.length).toBe(1);
    expect(component.loadingData).toBeFalse();
  }));

  it('normalizes empty optional dates and foreign keys before update', () => {
    configure('new');
    component.buildForm();
    component.form.patchValue({
      hire_date: '2024-01-15T00:00:00Z',
      dob: '', confirmation_date: '', termination_date: '',
      department_id: '', designation_id: '', manager_id: '',
      housing_allowance: '', transport_allowance: '', years_of_experience: '',
    });

    const payload = component.buildPayload();
    expect(payload.hire_date).toBe('2024-01-15');
    expect(payload.dob).toBeNull();
    expect(payload.confirmation_date).toBeNull();
    expect(payload.termination_date).toBeNull();
    expect(payload.department_id).toBeNull();
    expect(payload.designation_id).toBeNull();
    expect(payload.manager_id).toBeNull();
    expect(payload.housing_allowance).toBeNull();
  });

  it('patches immediately when the employee has no department', fakeAsync(() => {
    configure('43');
    fixture.detectChanges();

    httpMock.expectOne('/api/v1/departments').flush({ data: [] });
    httpMock.expectOne(req => req.url === '/api/v1/employees/manager-options' && req.params.get('employee_id') === '43').flush({ managers: [] });
    httpMock.expectOne('/api/v1/employees/43').flush({
      employee: { id: 43, first_name: 'Tom', last_name: 'Lee', email: 't@x.com', department_id: null },
    });
    tick();

    // No designation request should be made.
    httpMock.expectNone('/api/v1/designations?department_id=null');
    expect(component.form.get('first_name')?.value).toBe('Tom');
    expect(component.loadingData).toBeFalse();
  }));
});
