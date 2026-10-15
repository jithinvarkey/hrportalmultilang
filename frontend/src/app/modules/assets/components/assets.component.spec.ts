import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { AssetsComponent } from './assets.component';
import { AuthService } from '../../../core/services/auth.service';

/**
 * Unit tests for AssetsComponent.
 * @group assets
 */
describe('AssetsComponent', () => {
  let component: AssetsComponent;
  let fixture: ComponentFixture<AssetsComponent>;
  let httpMock: HttpTestingController;

  const API = '/api/v1/assets';

  function setup(isManager: boolean): void {
    const authStub = {
      hasAssetManagementAccess: () => isManager,
      canManageAssets: () => isManager,
    } as unknown as AuthService;
    TestBed.configureTestingModule({
      declarations: [AssetsComponent],
      imports: [HttpClientTestingModule, FormsModule],
      providers: [{ provide: AuthService, useValue: authStub }],
      schemas: [NO_ERRORS_SCHEMA],
    });
    fixture = TestBed.createComponent(AssetsComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  }

  afterEach(() => httpMock.verify());

  function flushInit(isManager = false): void {
    fixture.detectChanges();
    httpMock.expectOne(`${API}/categories`).flush({ categories: [] });
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
    if (isManager) {
      httpMock.expectOne(`${API}/stats`).flush({ total: 0, available: 0, assigned: 0, under_maintenance: 0, disposed: 0, lost: 0 });
      httpMock.expectOne(r => r.url.includes('/api/v1/employees')).flush({ data: [] });
    }
  }

  it('loads categories and assets on init', () => {
    setup(false);
    flushInit();
    expect(component.assets.length).toBe(0);
    expect(component.loading).toBeFalse();
  });

  it('requests the selected asset page and keeps pagination totals', () => {
    setup(false);
    fixture.detectChanges();
    httpMock.expectOne(`${API}/categories`).flush({ categories: [] });
    httpMock.expectOne(r => r.url === API).flush({ data: [{ id: 1 }], current_page: 1, per_page: 15, total: 113 });

    expect(component.totalAssets).toBe(113);
    component.onPageChange({ pageIndex: 1, pageSize: 15, length: 113, previousPageIndex: 0 });

    const pageRequest = httpMock.expectOne(r => r.url === API && r.params.get('page') === '2');
    expect(pageRequest.request.params.get('per_page')).toBe('15');
    pageRequest.flush({ data: [], current_page: 2, per_page: 15, total: 113 });
    expect(component.pageIndex).toBe(1);
  });

  it('managers see stats and employee list', () => {
    setup(true);
    flushInit(true);
    expect(component.stats.total).toBe(0);
    expect(component.isManager).toBeTrue();
  });

  it('blocks save when name or code is missing', () => {
    setup(true);
    flushInit(true);
    component.openForm();
    component.form.name = '';
    component.saveAsset();
    expect(component.formError).toContain('required');
    httpMock.expectNone(API);
  });

  it('posts FormData on create and reloads', () => {
    setup(true);
    flushInit(true);
    component.openForm();
    component.form.name = 'Monitor';
    component.form.asset_code = 'IT-001';
    component.saveAsset();

    const req = httpMock.expectOne(API);
    expect(req.request.method).toBe('POST');
    expect(req.request.body instanceof FormData).toBeTrue();
    req.flush({ asset: { id: 1, name: 'Monitor' } });
    // reload + stats
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
    httpMock.expectOne(`${API}/stats`).flush({ total: 1 });
    expect(component.showForm).toBeFalse();
  });

  it('sends assign request and reloads', () => {
    setup(true);
    flushInit(true);
    const asset = { id: 3, name: 'Laptop', status: 'available', condition: 'good' } as any;
    component.openAssign(asset);
    component.assignForm.employee_id = 1;
    component.doAssign();

    const req = httpMock.expectOne(`${API}/3/assign`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Asset assigned.' });
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
    httpMock.expectOne(`${API}/stats`).flush({ total: 1 });
    expect(component.showAssign).toBeFalse();
  });

  it('sends return request and reloads', () => {
    setup(true);
    flushInit(true);
    const asset = { id: 5, name: 'Monitor', status: 'assigned', condition: 'good' } as any;
    component.openReturn(asset);
    component.doReturn();

    const req = httpMock.expectOne(`${API}/5/return`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Asset returned.' });
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
    httpMock.expectOne(`${API}/stats`).flush({ total: 1 });
    expect(component.showReturn).toBeFalse();
  });

  it('logs a maintenance event', () => {
    setup(true);
    flushInit(true);
    const asset = { id: 8, name: 'PC', status: 'available' } as any;
    component.openMaintenance(asset);
    component.maintenanceForm.title = 'Annual service';
    component.saveMaintenance();

    const req = httpMock.expectOne(`${API}/8/maintenance`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Maintenance logged.' });
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
    httpMock.expectOne(`${API}/stats`).flush({ total: 1 });
    expect(component.showMaintenance).toBeFalse();
  });

  it('opens detail drawer and fetches full asset', () => {
    setup(false);
    flushInit();
    const a = { id: 2, name: 'Laptop' } as any;
    component.openDetail(a);
    const req = httpMock.expectOne(`${API}/2`);
    req.flush({ asset: { id: 2, name: 'Laptop', status: 'available', assignments: [], maintenance: [] } });
    expect(component.showDetail).toBeTrue();
    expect(component.selectedDetail.name).toBe('Laptop');
  });

  it('rejects oversized attachment', () => {
    setup(true);
    flushInit(true);
    component.openForm();
    const bigFile = new File([new Uint8Array(11 * 1024 * 1024)], 'big.pdf');
    const event = { target: { files: [bigFile], value: '' } } as any;
    component.onFileSelected(event);
    expect(component.formError).toContain('10 MB');
    expect(component.selectedFile).toBeNull();
  });
});
