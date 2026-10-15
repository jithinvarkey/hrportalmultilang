import { Component, OnDestroy, OnInit } from '@angular/core';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Store } from '@ngrx/store';
import { Router } from '@angular/router';
import { loginSuccess } from '../store/auth.actions';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  standalone: false,
  selector: 'app-login',
  templateUrl: './login.component.html',
  styleUrls: ['./login.component.scss']
})
export class LoginComponent implements OnInit, OnDestroy {
  form!: FormGroup;
  otpForm!: FormGroup;
  hidePassword = true;
  loading = false;
  errorMsg = '';
  otpStep = false;
  challengeToken = '';
  expiresIn = 0;
  resendCoolingDown = false;
  private countdownId?: number;

  constructor(
    private fb: FormBuilder,
    private store: Store<any>,
    private auth: AuthService,
    private router: Router,
  ) {}

  ngOnInit() {
    this.form = this.fb.group({
      email:    ['', [Validators.required]],
      password: ['', Validators.required]
    });
    this.otpForm = this.fb.group({
      otp: ['', [Validators.required, Validators.pattern(/^\d{6}$/)]],
    });
  }

  onSubmit() {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.errorMsg = '';

    this.auth.login(this.form.value.email, this.form.value.password).subscribe({
      next: (res) => {
        this.loading = false;
        if (res.token && res.user) {
          this.store.dispatch(loginSuccess({ user: res.user, token: res.token }));
          this.router.navigate(['/dashboard']);
          return;
        }

        this.challengeToken = res.challenge_token;
        this.otpStep = true;
        this.startCountdown(res.expires_in ?? 180);
      },
      error: (err) => {
        this.loading = false;
        this.errorMsg = err?.error?.message
          ?? err?.error?.errors?.email?.[0]
          ?? 'Login failed';
      },
    });
  }

  ngOnDestroy(): void {
    if (this.countdownId) {
      window.clearInterval(this.countdownId);
    }
  }

  verifyOtp(): void {
    if (this.otpForm.invalid) {
      this.otpForm.markAllAsTouched();
      return;
    }

    this.loading = true;
    this.errorMsg = '';

    this.auth.verifyLoginOtp(this.challengeToken, this.otpForm.value.otp).subscribe({
      next: (res) => {
        this.loading = false;
        this.store.dispatch(loginSuccess({ user: res.user, token: res.token }));
        this.router.navigate(['/dashboard']);
      },
      error: (err) => {
        this.loading = false;
        this.errorMsg = err?.error?.message
          ?? err?.error?.errors?.otp?.[0]
          ?? 'OTP verification failed';
      },
    });
  }

  resendOtp(): void {
    if (!this.challengeToken || this.resendCoolingDown) {
      return;
    }

    this.resendCoolingDown = true;
    this.errorMsg = '';

    this.auth.resendOtp(this.challengeToken, 'login').subscribe({
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

  backToLogin(): void {
    this.otpStep = false;
    this.challengeToken = '';
    this.otpForm.reset();
    this.errorMsg = '';
    if (this.countdownId) {
      window.clearInterval(this.countdownId);
    }
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
