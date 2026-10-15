import { Component, OnDestroy, OnInit } from '@angular/core';
import { AbstractControl, FormBuilder, FormGroup, ValidationErrors, Validators } from '@angular/forms';
import { AuthService } from '../../../core/services/auth.service';

/**
 * Public "Forgot Password" screen.
 *
 * Collects the account email and asks the backend to email a password-reset
 * link (POST /api/v1/auth/forgot-password). To avoid leaking which emails are
 * registered, the success state is shown regardless of whether the address
 * exists — matching Laravel's password-broker behaviour.
 */
@Component({
  standalone: false,
  selector: 'app-forgot-password',
  templateUrl: './forgot-password.component.html',
  styleUrls: ['./login.component.scss'],
})
export class ForgotPasswordComponent implements OnInit, OnDestroy {
  form!: FormGroup;
  resetForm!: FormGroup;
  loading = false;
  sent = false;
  resetComplete = false;
  errorMsg = '';
  otpStep = false;
  challengeToken = '';
  expiresIn = 0;
  resendCoolingDown = false;
  hideNew = true;
  hideConfirm = true;
  private countdownId?: number;

  constructor(private fb: FormBuilder, private auth: AuthService) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      email: ['', [Validators.required, Validators.email]],
    });
    this.resetForm = this.fb.group(
      {
        otp: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
        password: ['', [Validators.required, Validators.minLength(8)]],
        password_confirmation: ['', [Validators.required]],
      },
      { validators: ForgotPasswordComponent.passwordsMatch },
    );
  }

  /** Submit the email and request a reset link. */
  onSubmit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.errorMsg = '';

    this.auth.forgotPassword(this.form.value.email).subscribe({
      next: (res) => {
        this.loading = false;
        if (res?.challenge_token) {
          this.challengeToken = res.challenge_token;
          this.otpStep = true;
          this.startCountdown(res.expires_in ?? 180);
        } else {
          this.sent = true;
          this.resetComplete = false;
        }
      },
      error: (err) => {
        this.loading = false;
        if (err?.status === 422) {
          this.errorMsg = err?.error?.message ?? 'Please enter a valid email address.';
        } else {
          this.sent = true;
        }
      },
    });
  }

  ngOnDestroy(): void {
    if (this.countdownId) {
      window.clearInterval(this.countdownId);
    }
  }

  onResetSubmit(): void {
    if (this.resetForm.invalid) {
      this.resetForm.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.errorMsg = '';

    this.auth.resetPasswordWithOtp(
      this.challengeToken,
      this.resetForm.value.otp,
      this.resetForm.value.password,
      this.resetForm.value.password_confirmation,
    ).subscribe({
      next: () => {
        this.loading = false;
        this.sent = true;
        this.resetComplete = true;
        this.otpStep = false;
      },
      error: (err) => {
        this.loading = false;
        this.errorMsg = err?.error?.message
          ?? err?.error?.errors?.otp?.[0]
          ?? err?.error?.errors?.password?.[0]
          ?? 'Could not reset password.';
      },
    });
  }

  resendOtp(): void {
    if (!this.challengeToken || this.resendCoolingDown) {
      return;
    }

    this.resendCoolingDown = true;
    this.errorMsg = '';

    this.auth.resendOtp(this.challengeToken, 'password_reset').subscribe({
      next: (res) => {
        this.startCountdown(res.expires_in ?? 180);
        window.setTimeout(() => this.resendCoolingDown = false, 30000);
      },
      error: (err) => {
        this.resendCoolingDown = false;
        this.errorMsg = err?.error?.message
          ?? err?.error?.errors?.otp?.[0]
          ?? 'Could not resend OTP';
      },
    });
  }

  static passwordsMatch(group: AbstractControl): ValidationErrors | null {
    const pw = group.get('password')?.value;
    const confirm = group.get('password_confirmation')?.value;
    return pw && confirm && pw !== confirm ? { mismatch: true } : null;
  }

  get expiryLabel(): string {
    const minutes = Math.floor(this.expiresIn / 60).toString().padStart(2, '0');
    const seconds = (this.expiresIn % 60).toString().padStart(2, '0');
    return `${minutes}:${seconds}`;
  }

  private startCountdown(seconds: number): void {
    this.expiresIn = seconds;
    if (this.countdownId) {
      window.clearInterval(this.countdownId);
    }
    this.countdownId = window.setInterval(() => {
      this.expiresIn = Math.max(0, this.expiresIn - 1);
      if (this.expiresIn === 0 && this.countdownId) {
        window.clearInterval(this.countdownId);
      }
    }, 1000);
  }
}
