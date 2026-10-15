import { Component, OnInit } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { environment } from '../../../../environments/environment';

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
  error = '';
  success = '';

  constructor(
    private readonly route: ActivatedRoute,
    private readonly http: HttpClient,
    private readonly fb: FormBuilder,
  ) {}

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
        this.error = err?.error?.message || 'This onboarding link is invalid or expired.';
        this.loading = false;
      }
    });
  }

  onFileChange(event: Event, field: string): void {
    const input = event.target as HTMLInputElement;
    this.files[field] = input.files?.[0] || null;
  }

  fileName(field: string): string {
    return this.files[field]?.name || 'No file selected';
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
      this.error = 'Please complete the required fields.';
      return;
    }

    const missingDocuments = this.missingRequiredDocuments();
    if (missingDocuments.length) {
      this.error = `Please upload: ${missingDocuments.join(', ')}.`;
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
        this.success = res?.message || 'Onboarding details submitted successfully.';
        this.saving = false;
      },
      error: err => {
        this.error = err?.error?.message || 'Unable to submit onboarding details.';
        this.saving = false;
      }
    });
  }
}
