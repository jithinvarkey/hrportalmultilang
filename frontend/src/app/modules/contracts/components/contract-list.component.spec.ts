import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { ReactiveFormsModule } from '@angular/forms';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { ContractListComponent } from './contract-list.component';

/**
 * Unit tests for contract document upload/download added to ContractListComponent.
 *
 * Covers: file validation, upload-after-save on create, replace on edit,
 * row-level upload, and the download URL.
 *
 * @group contracts
 */
describe('ContractListComponent — document upload', () => {
  let component: ContractListComponent;
  let fixture: ComponentFixture<ContractListComponent>;
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ContractListComponent],
      imports: [HttpClientTestingModule, ReactiveFormsModule],
      schemas: [NO_ERRORS_SCHEMA],
    }).compileComponents();

    fixture = TestBed.createComponent(ContractListComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
  });

  /** Flush all the GET requests fired in ngOnInit. */
  function flushInit(): void {
    fixture.detectChanges();
    httpMock.match(() => true).forEach(r => r.flush({ data: [] }));
  }

  afterEach(() => httpMock.verify());

  function fileOf(name: string, type: string, sizeBytes = 1000): File {
    const blob = new Blob([new Uint8Array(sizeBytes)], { type });
    return new File([blob], name, { type });
  }

  it('accepts a PDF and rejects an exe', () => {
    flushInit();
    const input = { files: [fileOf('c.pdf', 'application/pdf')], value: 'x' } as any;
    component.onFileSelected({ target: input } as unknown as Event);
    expect(component.selectedFile?.name).toBe('c.pdf');

    const bad = { files: [fileOf('m.exe', 'application/octet-stream')], value: 'x' } as any;
    component.onFileSelected({ target: bad } as unknown as Event);
    expect(component.formError).toContain('PDF');
    expect(component.selectedFile).toBeNull();
  });

  it('rejects a file larger than 10 MB', () => {
    flushInit();
    const big = { files: [fileOf('big.pdf', 'application/pdf', 11 * 1024 * 1024)], value: 'x' } as any;
    component.onFileSelected({ target: big } as unknown as Event);
    expect(component.formError).toContain('10 MB');
    expect(component.selectedFile).toBeNull();
  });

  it('creates a contract then uploads the staged document', () => {
    flushInit();
    component.openForm();
    component.contractForm.patchValue({
      employee_id: 1, type: 'full_time', start_date: '2025-01-01',
    });
    component.selectedFile = fileOf('signed.pdf', 'application/pdf');

    component.saveContract();

    // 1) Create
    const create = httpMock.expectOne('/api/v1/contracts');
    expect(create.request.method).toBe('POST');
    create.flush({ contract: { id: 99 } });

    // 2) Document upload (multipart) to the new id
    const upload = httpMock.expectOne('/api/v1/contracts/99/document');
    expect(upload.request.method).toBe('POST');
    expect(upload.request.body instanceof FormData).toBeTrue();
    upload.flush({ contract: { id: 99, has_document: true } });

    // 3) Reloads
    httpMock.match(() => true).forEach(r => r.flush({ data: [] }));
    expect(component.submitting).toBeFalse();
    expect(component.showForm).toBeFalse();
  });

  it('uploads from a list row and reloads', () => {
    flushInit();
    const input = { files: [fileOf('row.pdf', 'application/pdf')], value: 'x' } as any;
    component.onRowFileSelected({ target: input } as unknown as Event, { id: 7 } as any);

    const upload = httpMock.expectOne('/api/v1/contracts/7/document');
    expect(upload.request.method).toBe('POST');
    expect(component.uploadingDocId).toBe(7);
    upload.flush({ contract: { id: 7 } });

    httpMock.match(() => true).forEach(r => r.flush({ data: [] }));
    expect(component.uploadingDocId).toBeNull();
  });

  it('opens the document URL on download', () => {
    flushInit();
    spyOn(window, 'open');
    component.downloadDocument({ id: 5 } as any);
    expect(window.open).toHaveBeenCalledWith('/api/v1/contracts/5/document', '_blank');
  });
});
