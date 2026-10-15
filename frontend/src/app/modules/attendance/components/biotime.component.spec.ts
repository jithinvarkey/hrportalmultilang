import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { BioTimeComponent } from './biotime.component';

/**
 * Unit tests for BioTimeComponent (biometric device management).
 *
 * @group attendance
 */
describe('BioTimeComponent', () => {
  let component: BioTimeComponent;
  let fixture: ComponentFixture<BioTimeComponent>;
  let httpMock: HttpTestingController;

  const API = '/api/v1/biotime/devices';

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [BioTimeComponent],
      imports: [HttpClientTestingModule, FormsModule],
      schemas: [NO_ERRORS_SCHEMA],
    }).compileComponents();

    fixture = TestBed.createComponent(BioTimeComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  function initWith(devices: any[]): void {
    fixture.detectChanges(); // ngOnInit → loadDevices
    httpMock.expectOne(API).flush(devices);
  }

  it('loads devices on init', () => {
    initWith([{ id: 1, name: 'Main', is_active: true }]);
    expect(component.devices.length).toBe(1);
    expect(component.loading).toBeFalse();
  });

  it('blocks save when required fields are missing', () => {
    initWith([]);
    component.openForm();
    component.form.name = '';
    component.saveDevice();
    expect(component.formError).toContain('required');
    httpMock.expectNone(API);
  });

  it('requires a password for a new device', () => {
    initWith([]);
    component.openForm();
    component.form = { name: 'X', ip_address: '1.1.1.1', username: 'admin', password: '' };
    component.saveDevice();
    expect(component.formError).toContain('Password is required');
  });

  it('POSTs a new device and reloads', () => {
    initWith([]);
    component.openForm();
    component.form = { name: 'Gate', protocol: 'http', ip_address: '10.0.0.1', port: 8088, username: 'admin', password: 'pw123456', is_active: true };
    component.saveDevice();

    const post = httpMock.expectOne(API);
    expect(post.request.method).toBe('POST');
    post.flush({ device: { id: 5 } });
    httpMock.expectOne(API).flush([]); // reload
    expect(component.showForm).toBeFalse();
  });

  it('omits a blank password when editing', () => {
    initWith([]);
    component.openForm({ id: 9, name: 'Main', protocol: 'http', ip_address: '1.1.1.1', port: 80, username: 'a', is_active: true });
    component.form.password = '';
    component.saveDevice();

    const put = httpMock.expectOne(`${API}/9`);
    expect(put.request.method).toBe('PUT');
    expect('password' in put.request.body).toBeFalse();
    put.flush({ device: { id: 9 } });
    httpMock.expectOne(API).flush([]);
  });

  it('records a successful test-connection result', () => {
    initWith([{ id: 3, name: 'Main', is_active: true }]);
    component.testConnection({ id: 3 });
    const req = httpMock.expectOne(`${API}/3/test`);
    expect(req.request.method).toBe('POST');
    req.flush({ ok: true, message: 'Authenticated.' });
    expect(component.testResult[3].ok).toBeTrue();
    expect(component.testing[3]).toBeFalse();
  });

  it('syncs a device and reloads', () => {
    initWith([{ id: 2, name: 'Main', is_active: true }]);
    component.syncDevice({ id: 2 });
    const req = httpMock.expectOne(`${API}/2/sync`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Sync complete.', imported: 12 });
    httpMock.expectOne(API).flush([]); // reload
    expect(component.syncing[2]).toBeFalse();
  });

  it('loads stats into the drawer', () => {
    initWith([{ id: 7, name: 'Main', is_active: true }]);
    component.openStats({ id: 7, name: 'Main' });
    expect(component.showStats).toBeTrue();
    httpMock.expectOne(`${API}/7/stats`).flush({ total_punches: 10, today_punches: 2 });
    expect(component.stats.total_punches).toBe(10);
  });

  it('deletes a device after confirmation', () => {
    spyOn(window, 'confirm').and.returnValue(true);
    initWith([{ id: 4, name: 'Main', is_active: true }]);
    component.deleteDevice({ id: 4, name: 'Main' });
    const req = httpMock.expectOne(`${API}/4`);
    expect(req.request.method).toBe('DELETE');
    req.flush({ message: 'Device deleted.' });
    httpMock.expectOne(API).flush([]);
  });
});
