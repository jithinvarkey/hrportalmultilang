import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { RouterTestingModule } from '@angular/router/testing';
import { FormsModule } from '@angular/forms';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { AdminComponent } from './admin.component';
import { AuthService } from '../../../core/services/auth.service';

/**
 * Unit tests for the Department & Designation management features added to
 * AdminComponent (Admin → Departments / Designations tabs).
 *
 * Covers: tab switching triggers loads, list hydration from both array and
 * `{data:[]}` envelopes, create vs update routing, client-side validation,
 * and salary-range formatting.
 *
 * @group admin
 */
describe('AdminComponent — Departments & Designations', () => {
  let component: AdminComponent;
  let fixture: ComponentFixture<AdminComponent>;
  let httpMock: HttpTestingController;

  const authStub = { hasRole: () => true, hasAnyRole: () => true } as unknown as AuthService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [AdminComponent],
      imports: [HttpClientTestingModule, RouterTestingModule, FormsModule],
      providers: [{ provide: AuthService, useValue: authStub }],
      schemas: [NO_ERRORS_SCHEMA], // ignore <mat-*> template elements
    }).compileComponents();

    fixture = TestBed.createComponent(AdminComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  /** Flush the four ngOnInit calls so each test starts from a clean slate. */
  function flushInit(): void {
    fixture.detectChanges(); // triggers ngOnInit
    httpMock.match(() => true).forEach(r => r.flush({}));
  }

  afterEach(() => httpMock.verify());

  it('exposes Departments and Designations tabs', () => {
    expect(component.tabs.map(t => t.id)).toContain('departments');
    expect(component.tabs.map(t => t.id)).toContain('designations');
  });

  it('loads departments when switching to the departments tab', () => {
    flushInit();
    component.switchTab('departments');
    const req = httpMock.expectOne('/api/v1/departments');
    expect(req.request.method).toBe('GET');
    req.flush([{ id: 1, name: 'HR', code: 'HR', is_active: true }]);
    expect(component.departments.length).toBe(1);
    expect(component.departments[0].name).toBe('HR');
  });

  it('hydrates designations from a {data:[]} envelope', () => {
    flushInit();
    component.loadDesignations();
    const req = httpMock.expectOne('/api/v1/designations');
    req.flush({ data: [{ id: 9, title: 'Engineer' }] });
    expect(component.designations.length).toBe(1);
    expect(component.designations[0].title).toBe('Engineer');
  });

  it('POSTs a new department on create', () => {
    flushInit();
    component.openDeptForm();
    component.deptForm.name = 'Finance';
    component.deptForm.code = 'FIN';
    component.saveDept();

    const req = httpMock.expectOne('/api/v1/departments');
    expect(req.request.method).toBe('POST');
    expect(req.request.body.name).toBe('Finance');
    req.flush({ department: { id: 2, name: 'Finance', code: 'FIN' } });

    httpMock.expectOne('/api/v1/departments').flush([]); // reload after save
    expect(component.showDeptForm).toBeFalse();
  });

  it('PUTs when editing an existing department', () => {
    flushInit();
    component.openDeptForm({ id: 5, name: 'IT', code: 'IT', is_active: true });
    component.deptForm.name = 'Information Technology';
    component.saveDept();

    const req = httpMock.expectOne('/api/v1/departments/5');
    expect(req.request.method).toBe('PUT');
    req.flush({ department: { id: 5, name: 'Information Technology' } });
    httpMock.expectOne('/api/v1/departments').flush([]);
  });

  it('blocks department save without name/code (no HTTP call)', () => {
    flushInit();
    component.openDeptForm();
    component.deptForm.name = '';
    component.saveDept();
    expect(component.deptError).toBeTruthy();
    httpMock.expectNone('/api/v1/departments');
  });

  it('rejects a designation whose max salary is below min', () => {
    flushInit();
    component.openDesigForm();
    component.desigForm.title = 'Bad';
    component.desigForm.min_salary = 10000;
    component.desigForm.max_salary = 5000;
    component.saveDesig();
    expect(component.desigError).toContain('Max salary');
    httpMock.expectNone('/api/v1/designations');
  });

  it('formats salary ranges for display', () => {
    expect(component.salaryRange({ min_salary: null, max_salary: null })).toBe('—');
    expect(component.salaryRange({ min_salary: 8000, max_salary: 14000 }))
      .toBe('8,000 – 14,000');
  });

  it('deletes a department after confirmation', () => {
    spyOn(window, 'confirm').and.returnValue(true);
    flushInit();
    component.deleteDept({ id: 3, name: 'Temp' });
    const req = httpMock.expectOne('/api/v1/departments/3');
    expect(req.request.method).toBe('DELETE');
    req.flush({ message: 'Department deleted' });
    httpMock.expectOne('/api/v1/departments').flush([]);
  });

  it('does not delete when confirmation is declined', () => {
    spyOn(window, 'confirm').and.returnValue(false);
    flushInit();
    component.deleteDesig({ id: 4, title: 'X' });
    httpMock.expectNone('/api/v1/designations/4');
  });
});
