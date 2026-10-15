import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { ReactiveFormsModule } from '@angular/forms';
import { RouterTestingModule } from '@angular/router/testing';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { ForgotPasswordComponent } from './forgot-password.component';

/**
 * Unit tests for ForgotPasswordComponent.
 *
 * @group auth
 */
describe('ForgotPasswordComponent', () => {
  let component: ForgotPasswordComponent;
  let fixture: ComponentFixture<ForgotPasswordComponent>;
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [ForgotPasswordComponent],
      imports: [HttpClientTestingModule, ReactiveFormsModule, RouterTestingModule],
      schemas: [NO_ERRORS_SCHEMA],
    }).compileComponents();

    fixture = TestBed.createComponent(ForgotPasswordComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => httpMock.verify());

  it('does not call the API when the email is invalid', () => {
    component.form.setValue({ email: 'bad' });
    component.onSubmit();
    httpMock.expectNone('/api/v1/auth/forgot-password');
  });

  it('posts the email and shows the sent state on success', () => {
    component.form.setValue({ email: 'jane@hrms.com' });
    component.onSubmit();

    const req = httpMock.expectOne('/api/v1/auth/forgot-password');
    expect(req.request.method).toBe('POST');
    expect(req.request.body.email).toBe('jane@hrms.com');
    req.flush({ message: 'ok' });

    expect(component.sent).toBeTrue();
    expect(component.loading).toBeFalse();
  });

  it('still shows the neutral sent state on a server error (no enumeration)', () => {
    component.form.setValue({ email: 'unknown@hrms.com' });
    component.onSubmit();
    httpMock.expectOne('/api/v1/auth/forgot-password')
      .flush({ message: 'error' }, { status: 500, statusText: 'Server Error' });

    expect(component.sent).toBeTrue();
  });

  it('surfaces a 422 validation message instead of the sent state', () => {
    component.form.setValue({ email: 'jane@hrms.com' });
    component.onSubmit();
    httpMock.expectOne('/api/v1/auth/forgot-password')
      .flush({ message: 'Invalid email.' }, { status: 422, statusText: 'Unprocessable' });

    expect(component.sent).toBeFalse();
    expect(component.errorMsg).toBe('Invalid email.');
  });
});
