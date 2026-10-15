import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { environment } from '../../../../environments/environment';
import { LanguageService } from '../../../core/services/language.service';

type DocumentField = {
  field: string;
  title: string;
  type: string;
  required?: boolean;
};

@Component({
  standalone: false,
  selector: 'app-new-hire-onboarding',
  templateUrl: './new-hire-onboarding.component.html',
  styleUrls: ['./new-hire-onboarding.component.scss']
})
export class NewHireOnboardingComponent implements OnInit {
  form!: FormGroup;
  token = '';
  employee: any = null;
  documents: DocumentField[] = [];
  files: Record<string, File | null> = {};
  loading = true;
  saving = false;
  /** API messages, translation keys, or text already translated in code; the template pipes them through translate. */
  error = '';
  success = '';

  constructor(
    private readonly route: ActivatedRoute,
    private readonly http: HttpClient,
    private readonly fb: FormBuilder,
    private readonly lang: LanguageService,
  ) {}

  /** Employee name in the active language (falls back to the English full name). */
  get displayName(): string {
    const e = this.employee || {};
    return (this.lang.isRtl() && e.arabic_name) ? e.arabic_name : e.full_name;
  }

  ngOnInit(): void {
    this.token = this.route.snapshot.paramMap.get('token') || '';
    this.form = this.fb.group({
      phone: ['', [Validators.required, Validators.maxLength(30)]],
      dob: ['', Validators.required],
      gender: ['', Validators.required],
      marital_status: ['', Validators.required],
      address: ['', [Validators.required, Validators.maxLength(255)]],
      city: ['', Validators.maxLength(100)],
      country: ['', Validators.maxLength(100)],
      national_id: ['', [Validators.required, Validators.maxLength(50)]],
      id_expiry_date: [''],
      passport_number: ['', Validators.maxLength(50)],
      passport_expiry_date: [''],
      bank_name: ['', [Validators.required, Validators.maxLength(100)]],
      bank_account: ['', [Validators.required, Validators.maxLength(24), Validators.pattern(/^[SA0-9]+$/i)]],
      emergency_contact_name: ['', [Validators.required, Validators.maxLength(100)]],
      emergency_contact_phone: ['', [Validators.required, Validators.maxLength(30)]],
    });
    this.load();
  }

  load(): void {
    this.loading = true;
    this.http.get<any>(`${environment.apiUrl}/public/onboarding/${this.token}`).subscribe({
      next: res => {
        this.employee = res.employee;
        this.documents = res.documents || [];
        this.form.patchValue(this.employee || {});
        this.loading = false;
      },
      error: err => {
        this.error = err?.error?.message || 'AUTH.ONBOARDING.INVALID_LINK';
        this.loading = false;
      }
    });
  }

  onFileChange(event: Event, field: string): void {
    const input = event.target as HTMLInputElement;
    this.files[field] = input.files?.[0] || null;
  }

  fileName(field: string): string {
    return this.files[field]?.name || '';
  }

  normalizeBankAccount(): void {
    const control = this.form.get('bank_account');
    const value = String(control?.value || '').toUpperCase().replace(/[^SA0-9]/g, '').slice(0, 24);
    if (control && control.value !== value) {
      control.setValue(value, { emitEvent: false });
    }
  }

  private missingRequiredDocuments(): string[] {
    return this.documents
      .filter(doc => doc.required && !this.files[doc.field])
      .map(doc => doc.title);
  }

  submit(): void {
    this.error = '';
    this.success = '';
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error = 'AUTH.ONBOARDING.COMPLETE_REQUIRED';
      return;
    }

    const missingDocuments = this.missingRequiredDocuments();
    if (missingDocuments.length) {
      this.error = this.lang.t('AUTH.ONBOARDING.UPLOAD_MISSING', { docs: missingDocuments.join(this.lang.isRtl() ? '، ' : ', ') });
      return;
    }

    const payload = new FormData();
    Object.entries(this.form.value).forEach(([key, value]) => {
      payload.append(key, value == null ? '' : String(value));
    });
    Object.entries(this.files).forEach(([key, file]) => {
      if (file) payload.append(key, file);
    });

    this.saving = true;
    this.http.post<any>(`${environment.apiUrl}/public/onboarding/${this.token}`, payload).subscribe({
      next: res => {
        this.success = res?.message || 'AUTH.ONBOARDING.SUBMITTED';
        this.saving = false;
      },
      error: err => {
        this.error = err?.error?.message || 'AUTH.ONBOARDING.SUBMIT_FAILED';
        this.saving = false;
      }
    });
  }
}
