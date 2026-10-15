import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { AnnouncementsComponent } from './announcements.component';
import { AuthService } from '../../../core/services/auth.service';

/**
 * Unit tests for AnnouncementsComponent.
 * @group announcements
 */
describe('AnnouncementsComponent', () => {
  let component: AnnouncementsComponent;
  let fixture: ComponentFixture<AnnouncementsComponent>;
  let httpMock: HttpTestingController;

  const API = '/api/v1/announcements';

  function setup(isManager: boolean): void {
    const authStub = { hasAnyRole: () => isManager } as unknown as AuthService;
    TestBed.configureTestingModule({
      declarations: [AnnouncementsComponent],
      imports: [HttpClientTestingModule, FormsModule],
      providers: [{ provide: AuthService, useValue: authStub }],
      schemas: [NO_ERRORS_SCHEMA],
    });
    fixture = TestBed.createComponent(AnnouncementsComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  }

  afterEach(() => httpMock.verify());

  function flushInit(isManager = false): void {
    fixture.detectChanges();
    httpMock.expectOne(`${API}/categories`).flush({ categories: [{ id: 1, name: 'General' }] });
    if (isManager) {
      httpMock.expectOne('/api/v1/departments').flush([{ id: 1, name: 'Eng' }]);
    }
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
  }

  it('loads categories and announcements on init', () => {
    setup(false);
    flushInit();
    expect(component.categories.length).toBe(1);
    expect(component.announcements.length).toBe(0);
  });

  it('blocks save with no title/body', () => {
    setup(true);
    flushInit(true);
    component.openForm();
    component.form.title = '';
    component.saveAnnouncement();
    expect(component.formError).toContain('required');
    httpMock.expectNone(API);
  });

  it('posts multipart FormData when creating', () => {
    setup(true);
    flushInit(true);
    component.openForm();
    component.form.title = 'Hello';
    component.form.body = 'World';
    component.saveAnnouncement();

    const req = httpMock.expectOne(API);
    expect(req.request.method).toBe('POST');
    expect(req.request.body instanceof FormData).toBeTrue();
    req.flush({ announcement: { id: 1 } });
    // reload
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
    expect(component.showForm).toBeFalse();
  });

  it('adds _method=PUT when editing', () => {
    setup(true);
    flushInit(true);
    component.openForm({ id: 7, title: 'T', body: 'B', priority: 'normal', is_pinned: false,
      is_published: true, published_at: null, expires_at: null, category: null, creator: null,
      has_attachment: false, attachment_name: null, created_at: '' } as any);
    component.saveAnnouncement();

    const req = httpMock.expectOne(`${API}/7`);
    expect(req.request.method).toBe('POST'); // multipart PUT via _method
    expect((req.request.body as FormData).get('_method')).toBe('PUT');
    req.flush({ announcement: { id: 7 } });
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
  });

  it('opens the attachment URL on download', () => {
    setup(false);
    flushInit();
    spyOn(window, 'open');
    component.downloadAttachment({ id: 5 } as any);
    expect(window.open).toHaveBeenCalledWith(`${API}/5/attachment`, '_blank');
  });

  it('opens previewable attachments inline', () => {
    setup(false);
    flushInit();
    spyOn(window, 'open').and.returnValue(null);
    spyOn(URL, 'createObjectURL').and.returnValue('blob:announcement-preview');
    const announcement = { id: 5, attachment_name: 'notice.pdf', attachment_mime: 'application/pdf' } as any;
    expect(component.canPreviewAttachment(announcement)).toBeTrue();
    component.viewAttachment(announcement);
    const request = httpMock.expectOne(r => r.url === `${API}/5/attachment` && r.params.get('inline') === '1');
    request.flush(new Blob(['pdf'], { type: 'application/pdf' }));
    expect(window.open).toHaveBeenCalledWith('blob:announcement-preview', '_blank');
  });

  it('rejects an oversized attachment', () => {
    setup(true);
    flushInit(true);
    const big = { files: [new File([new Uint8Array(11 * 1024 * 1024)], 'big.pdf')], value: 'x' } as any;
    component.onFileSelected({ target: big } as unknown as Event);
    expect(component.formError).toContain('10 MB');
    expect(component.selectedFile).toBeNull();
  });

  it('toggles a reaction and updates the count', () => {
    setup(false);
    flushInit();
    const a: any = { id: 3, reactions_count: 0 };
    component.toggleReaction(a);
    const req = httpMock.expectOne(`${API}/3/react`);
    expect(req.request.method).toBe('POST');
    req.flush({ reacted: true, count: 1 });
    expect(a.reactions_count).toBe(1);
  });

  it('marks an announcement as read once', () => {
    setup(false);
    flushInit();
    const a: any = { id: 4, is_read: false, reads_count: 0 };
    component.markRead(a);
    httpMock.expectOne(`${API}/4/read`).flush({ message: 'ok' });
    expect(a.is_read).toBeTrue();
    // Already read → no second call.
    component.markRead(a);
    httpMock.expectNone(`${API}/4/read`);
  });

  it('loads engagement stats for HR', () => {
    setup(true);
    flushInit(true);
    component.openStats({ id: 6 } as any);
    const req = httpMock.expectOne(`${API}/6/stats`);
    req.flush({ announcement: { id: 6, title: 'X' }, reach: 10, read_count: 4, read_rate: 40, readers: [], unread: [] });
    expect(component.stats.read_rate).toBe(40);
  });

  it('sends department targeting in the payload', () => {
    setup(true);
    flushInit(true);
    component.openForm();
    component.form.title = 'T';
    component.form.body = 'B';
    component.form.audience_type = 'departments';
    component.form.target_department_ids = [2, 5];
    component.saveAnnouncement();
    const req = httpMock.expectOne(API);
    expect((req.request.body as FormData).get('audience_type')).toBe('departments');
    expect((req.request.body as FormData).get('target_department_ids')).toBe('[2,5]');
    req.flush({ announcement: { id: 1 } });
    httpMock.expectOne(r => r.url === API).flush({ data: [] });
  });
});
