import { Component, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators, AbstractControl, ValidationErrors } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';

/**
 * Public "Reset Password" screen reached from the emailed reset link
 * (e.g. /auth/reset-password?token=...&email=...).
 *
 * Submits the token, email, and new password to
 * POST /api/v1/auth/reset-password. On success the user is redirected to login.
 */
@Component({
  standalone: false,
  selector: 'app-reset-password',
  templateUrl: './reset-password.component.html',
  styleUrls: ['./login.component.scss'],
})
export class ResetPasswordComponent implements OnInit {
  form!: FormGroup;
  loading = false;
  done = false;
  errorMsg = '';
  hideNew = true;
  hideConfirm = true;

  private token = '';

  constructor(
    private fb: FormBuilder,
    private http: HttpClient,
    private route: ActivatedRoute,
    private router: Router,
  ) {}

  ngOnInit(): void {
    this.token = this.route.snapshot.queryParamMap.get('token') ?? '';
    const email = this.route.snapshot.queryParamMap.get('email') ?? '';

    this.form = this.fb.group(
      {
        email:                 [email, [Validators.required, Validators.email]],
        password:              ['', [Validators.required, Validators.minLength(8)]],
        password_confirmation: ['', [Validators.required]],
      },
      { validators: ResetPasswordComponent.passwordsMatch },
    );

    if (!this.token) {
      this.errorMsg = 'This reset link is invalid or has expired. Please request a new one.';
    }
  }

  /** Cross-field validator: new password and confirmation must match. */
  static passwordsMatch(group: AbstractControl): ValidationErrors | null {
    const pw = group.get('password')?.value;
    const confirm = group.get('password_confirmation')?.value;
    return pw && confirm && pw !== confirm ? { mismatch: true } : null;
  }

  /** Submit the new password with the token. */
  onSubmit(): void {
    if (!this.token) {
      this.errorMsg = 'This reset link is invalid or has expired. Please request a new one.';
      return;
    }
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.errorMsg = '';

    this.http.post<any>('/api/v1/auth/reset-password', {
      token: this.token,
      ...this.form.value,
    }).subscribe({
      next: () => {
        this.loading = false;
        this.done = true;
        setTimeout(() => this.router.navigate(['/auth/login']), 2500);
      },
      error: (err) => {
        this.loading = false;
        this.errorMsg = err?.error?.message
          ?? err?.error?.errors?.email?.[0]
          ?? 'Could not reset password. The link may have expired.';
      },
    });
  }
}
