import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { PoliciesComponent } from './policies.component';
import { AuthService } from '../../../core/services/auth.service';

/**
 * Unit tests for PoliciesComponent.
 * @group policies
 */
describe('PoliciesComponent', () => {
  let component: PoliciesComponent;
  let fixture: ComponentFixture<PoliciesComponent>;
  let httpMock: HttpTestingController;
  let managerMode = false;

  const API = '/api/v1/policies';

  function setup(isManager: boolean): void {
    managerMode = isManager;
    const authStub = { hasAnyRole: () => isManager } as unknown as AuthService;
    TestBed.configureTestingModule({
      declarations: [PoliciesComponent],
      imports: [HttpClientTestingModule, FormsModule],
      providers: [{ provide: AuthService, useValue: authStub }],
      schemas: [NO_ERRORS_SCHEMA],
    });
    fixture = TestBed.createComponent(PoliciesComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  }

  afterEach(() => httpMock.verify());

  function flushInit(policies: any[] = []): void {
    fixture.detectChanges();
    httpMock.expectOne(`${API}/categories`).flush({ categories: [] });
    if (managerMode) {
      httpMock.expectOne('/api/v1/departments').flush({ data: [] });
    }
    httpMock.expectOne(r => r.url === API).flush({ policies });
  }

  it('groups policies by category', () => {
    setup(false);
    flushInit([
      { id: 1, title: 'A', category: { id: 1, name: 'Leave' }, requires_acknowledgement: true, acknowledged: true, version: '1.0', is_published: true, has_attachment: false },
      { id: 2, title: 'B', category: { id: 1, name: 'Leave' }, requires_acknowledgement: true, acknowledged: false, version: '1.0', is_published: true, has_attachment: false },
      { id: 3, title: 'C', category: null, requires_acknowledgement: false, acknowledged: false, version: '1.0', is_published: true, has_attachment: false },
    ]);
    const groups = component.grouped;
    expect(groups.length).toBe(2);
    expect(groups.find(g => g.name === 'Leave')!.items.length).toBe(2);
    expect(groups.find(g => g.name === 'Uncategorized')!.items.length).toBe(1);
  });

  it('counts pending acknowledgements', () => {
    setup(false);
    flushInit([
      { id: 1, title: 'A', category: null, requires_acknowledgement: true, acknowledged: false, version: '1', is_published: true, has_attachment: false },
      { id: 2, title: 'B', category: null, requires_acknowledgement: true, acknowledged: true, version: '1', is_published: true, has_attachment: false },
      { id: 3, title: 'C', category: null, requires_acknowledgement: false, acknowledged: false, version: '1', is_published: true, has_attachment: false },
    ]);
    expect(component.pendingAckCount).toBe(1);
  });

  it('opens previewable policy attachments inline', () => {
    setup(true);
    flushInit();
    spyOn(window, 'open').and.returnValue(null);
    spyOn(URL, 'createObjectURL').and.returnValue('blob:policy-preview');
    const policy = { id: 5, attachment_name: 'policy.pdf', attachment_mime: 'application/pdf' } as any;
    expect(component.canPreviewAttachment(policy)).toBeTrue();
    component.viewAttachment(policy);
    const request = httpMock.expectOne(r => r.url === `${API}/5/attachment` && r.params.get('inline') === '1');
    request.flush(new Blob(['pdf'], { type: 'application/pdf' }));
    expect(window.open).toHaveBeenCalledWith('blob:policy-preview', '_blank');
  });

  it('acknowledges a policy and flips the flag', () => {
    setup(false);
    flushInit([{ id: 9, title: 'X', category: null, requires_acknowledgement: true, acknowledged: false, version: '1', is_published: true, has_attachment: false }]);

    component.openReader(component.policies[0]);
    httpMock.expectOne(`${API}/9`).flush({ policy: component.policies[0] });

    component.acknowledge();
    const req = httpMock.expectOne(`${API}/9/acknowledge`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Policy acknowledged.' });

    expect(component.selected!.acknowledged).toBeTrue();
    expect(component.policies[0].acknowledged).toBeTrue();
  });

  it('loads the acknowledgement report for HR', () => {
    setup(true);
    flushInit([{ id: 4, title: 'R', category: null, requires_acknowledgement: true, acknowledged: false, version: '1', is_published: true, has_attachment: false }]);
    component.openReport(component.policies[0]);
    const req = httpMock.expectOne(`${API}/4/acknowledgements`);
    req.flush({ policy: { id: 4, title: 'R', version: '1' }, acknowledged: [], pending: [], ack_count: 0, pending_count: 3, by_department: [] });
    expect(component.report.pending_count).toBe(3);
    expect(component.reportPolicyId).toBe(4);
  });

  it('routes manager policy action buttons to report, edit, publish, and delete', () => {
    setup(true);
    flushInit([{ id: 4, title: 'R', category: null, requires_acknowledgement: true, acknowledged: false, version: '1', is_published: true, has_attachment: false }]);
    const makeEvent = () => jasmine.createSpyObj<Event>('event', ['preventDefault', 'stopPropagation']);
    const policy = component.policies[0];

    const reportEvent = makeEvent();
    component.onPolicyAction(reportEvent, 'report', policy);
    httpMock.expectOne(`${API}/4/acknowledgements`).flush({ policy: { id: 4, title: 'R', version: '1' }, acknowledged: [], pending: [], ack_count: 0, pending_count: 0, by_department: [] });
    expect(component.showReport).toBeTrue();

    const editEvent = makeEvent();
    component.onPolicyAction(editEvent, 'edit', policy);
    expect(component.showForm).toBeTrue();
    expect(component.editId).toBe(4);

    spyOn(window, 'confirm').and.returnValue(true);
    const publishEvent = makeEvent();
    component.onPolicyAction(publishEvent, 'publish', policy);
    const publishReq = httpMock.expectOne(`${API}/4`);
    expect(publishReq.request.method).toBe('PUT');
    expect(publishReq.request.body.is_published).toBeFalse();
    publishReq.flush({ policy: { id: 4 } });
    httpMock.expectOne(r => r.url === API).flush({ policies: [] });

    const deleteEvent = makeEvent();
    component.onPolicyAction(deleteEvent, 'delete', policy);
    const req = httpMock.expectOne(`${API}/4`);
    expect(req.request.method).toBe('DELETE');
    req.flush({});
    httpMock.expectOne(r => r.url === API).flush({ policies: [] });

    expect(reportEvent.preventDefault).toHaveBeenCalled();
    expect(deleteEvent.stopPropagation).toHaveBeenCalled();
  });

  it('sends a reminder to pending employees', () => {
    setup(true);
    flushInit([{ id: 4, title: 'R', category: null, requires_acknowledgement: true, acknowledged: false, version: '1', is_published: true, has_attachment: false }]);
    component.openReport(component.policies[0]);
    httpMock.expectOne(`${API}/4/acknowledgements`).flush({ policy: { id: 4, title: 'R', version: '1' }, acknowledged: [], pending: [], ack_count: 0, pending_count: 2, by_department: [] });
    component.remindPending();
    const req = httpMock.expectOne(`${API}/4/remind`);
    expect(req.request.method).toBe('POST');
    req.flush({ message: 'Reminder sent to 2 employees.', count: 2 });
    expect(component.reminding).toBeFalse();
  });

  it('posts multipart with _method=PUT when editing a policy', () => {
    setup(true);
    flushInit();
    component.openForm({ id: 8, title: 'P', content: 'c', category: null, version: '1.0',
      effective_date: null, requires_acknowledgement: true, is_published: true, has_attachment: false, attachment_name: null } as any);
    component.savePolicy();
    const req = httpMock.expectOne(`${API}/8`);
    expect((req.request.body as FormData).get('_method')).toBe('PUT');
    req.flush({ policy: { id: 8 } });
    httpMock.expectOne(r => r.url === API).flush({ policies: [] });
  });
});
