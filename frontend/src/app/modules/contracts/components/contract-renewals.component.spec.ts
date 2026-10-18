import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { ReactiveFormsModule } from '@angular/forms';
import { TranslateModule } from '@ngx-translate/core';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { ContractRenewalsComponent } from './contract-renewals.component';
import { AuthService } from '../../../core/services/auth.service';

/**
 * Unit tests for renewal document upload/download added to
 * ContractRenewalsComponent.
 *
 * @group contracts
 */
describe('ContractRenewalsComponent — document upload', () => {
  let component: ContractRenewalsComponent;
  let fixture: ComponentFixture<ContractRenewalsComponent>;
  let httpMock: HttpTestingController;

  const authStub = {
    getUser: () => ({ roles: ['super_admin'] }),
    isHRRole: () => true,
    isCEO: () => false,
    isSuperAdmin: () => true,
    isManagerRole: () => true,
  } as unknown as AuthService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ContractRenewalsComponent],
      imports: [HttpClientTestingModule, ReactiveFormsModule, TranslateModule.forRoot()],
      providers: [{ provide: AuthService, useValue: authStub }],
      schemas: [NO_ERRORS_SCHEMA],
    }).compileComponents();

    fixture = TestBed.createComponent(ContractRenewalsComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  function flushInit(): void {
    fixture.detectChanges();
    httpMock.match(() => true).forEach(r => r.flush({ data: [], meta: null }));
  }

  afterEach(() => httpMock.verify());

  function fileOf(name: string, type: string, sizeBytes = 1000): File {
    const blob = new Blob([new Uint8Array(sizeBytes)], { type });
    return new File([blob], name, { type });
  }

  it('creates a renewal then uploads the staged document', () => {
    flushInit();
    component.createForm.patchValue({ contract_id: 3, proposed_start_date: '2026-01-01' });
    component.selectedFile = fileOf('renewal.pdf', 'application/pdf');

    component.createManual();

    const create = httpMock.expectOne('/api/v1/contracts/renewals');
    expect(create.request.method).toBe('POST');
    create.flush({ renewal: { id: 12 } });

    const upload = httpMock.expectOne('/api/v1/contracts/renewals/12/document');
    expect(upload.request.method).toBe('POST');
    expect(upload.request.body instanceof FormData).toBeTrue();
    upload.flush({ renewal: { id: 12, document: { name: 'renewal.pdf' } } });

    httpMock.match(() => true).forEach(r => r.flush({ data: [], meta: null }));
    expect(component.submitting).toBeFalse();
    expect(component.showCreateForm).toBeFalse();
  });

  it('uploads to an existing renewal from a row action', () => {
    flushInit();
    const input = { files: [fileOf('r.pdf', 'application/pdf')], value: 'x' } as any;
    component.onRowFileSelected({ target: input } as unknown as Event, { id: 8 } as any);

    const upload = httpMock.expectOne('/api/v1/contracts/renewals/8/document');
    expect(upload.request.method).toBe('POST');
    expect(component.uploadingDocId).toBe(8);
    upload.flush({ renewal: { id: 8, document: { name: 'r.pdf' } } });

    httpMock.match(() => true).forEach(r => r.flush({ data: [], meta: null }));
    expect(component.uploadingDocId).toBeNull();
  });

  it('rejects a disallowed file type without an HTTP call', () => {
    flushInit();
    const bad = { files: [fileOf('x.txt', 'text/plain')], value: 'x' } as any;
    component.onRowFileSelected({ target: bad } as unknown as Event, { id: 9 } as any);
    expect(component.errorMsg).toContain('FILE_TYPE');
    httpMock.expectNone('/api/v1/contracts/renewals/9/document');
  });

  it('downloads the document through HttpClient (Bearer auth) as a blob', () => {
    flushInit();
    spyOn(URL, 'createObjectURL').and.returnValue('blob:x');
    spyOn(URL, 'revokeObjectURL');
    component.downloadDocument({ id: 4, reference: 'RNW-4', document: null } as any);
    const req = httpMock.expectOne('/api/v1/contracts/renewals/4/document');
    expect(req.request.method).toBe('GET');
    expect(req.request.responseType).toBe('blob');
    req.flush(new Blob(['x']));
    expect(URL.createObjectURL).toHaveBeenCalled();
  });
});
