import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { HttpClientTestingModule, HttpTestingController } from '@angular/common/http/testing';
import { ReactiveFormsModule } from '@angular/forms';
import { RouterTestingModule } from '@angular/router/testing';
import { ActivatedRoute, Router } from '@angular/router';
import { NO_ERRORS_SCHEMA } from '@angular/core';

import { ResetPasswordComponent } from './reset-password.component';

/**
 * Unit tests for ResetPasswordComponent.
 *
 * @group auth
 */
describe('ResetPasswordComponent', () => {
  let component: ResetPasswordComponent;
  let fixture: ComponentFixture<ResetPasswordComponent>;
  let httpMock: HttpTestingController;

  function configure(token: string | null, email: string | null): void {
    TestBed.configureTestingModule({
      declarations: [ResetPasswordComponent],
      imports: [HttpClientTestingModule, ReactiveFormsModule, RouterTestingModule],
      providers: [
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { queryParamMap: { get: (k: string) => (k === 'token' ? token : email) } } },
        },
      ],
      schemas: [NO_ERRORS_SCHEMA],
    });
    fixture = TestBed.createComponent(ResetPasswordComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  }

  afterEach(() => httpMock.verify());

  it('prefills the email from the query string', () => {
    configure('tok123', 'jane@hrms.com');
    expect(component.form.get('email')?.value).toBe('jane@hrms.com');
  });

  it('shows an error when the token is missing', () => {
    configure(null, 'jane@hrms.com');
    expect(component.errorMsg).toContain('invalid or has expired');
  });

  it('flags mismatched passwords via the cross-field validator', () => {
    configure('tok123', 'jane@hrms.com');
    component.form.patchValue({ password: 'NewPass@123', password_confirmation: 'Other@123' });
    expect(component.form.hasError('mismatch')).toBeTrue();
  });

  it('posts token + new password and redirects on success', fakeAsync(() => {
    configure('tok123', 'jane@hrms.com');
    const router = TestBed.inject(Router);
    const navSpy = spyOn(router, 'navigate');

    component.form.patchValue({ password: 'NewPass@123', password_confirmation: 'NewPass@123' });
    component.onSubmit();

    const req = httpMock.expectOne('/api/v1/auth/reset-password');
    expect(req.request.method).toBe('POST');
    expect(req.request.body.token).toBe('tok123');
    expect(req.request.body.password).toBe('NewPass@123');
    req.flush({ message: 'Password has been reset successfully.' });

    expect(component.done).toBeTrue();
    tick(2500);
    expect(navSpy).toHaveBeenCalledWith(['/auth/login']);
  }));

  it('surfaces a backend error message', () => {
    configure('tok123', 'jane@hrms.com');
    component.form.patchValue({ password: 'NewPass@123', password_confirmation: 'NewPass@123' });
    component.onSubmit();
    httpMock.expectOne('/api/v1/auth/reset-password')
      .flush({ message: 'This token has expired.' }, { status: 422, statusText: 'Unprocessable' });

    expect(component.done).toBeFalse();
    expect(component.errorMsg).toBe('This token has expired.');
  });
});
