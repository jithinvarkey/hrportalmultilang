import { Component, OnInit, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  standalone: false,
  selector: 'app-profile',
  templateUrl: './profile.component.html',
  styleUrls: ['./profile.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileComponent implements OnInit {

  // ── Data ─────────────────────────────────────────────────────────────
  user: any     = null;
  employee: any = null;
  loading       = true;
  activeTab     = 'info';

  // ── Edit form ─────────────────────────────────────────────────────────
  editForm: any      = {};
  editSaving         = false;
  editSuccess        = '';
  editError          = '';

  // ── Password form ─────────────────────────────────────────────────────
  pwForm             = { current_password: '', password: '', password_confirmation: '' };
  pwSaving           = false;
  pwSuccess          = '';
  pwError            = '';
  showCurrentPw      = false;
  showNewPw          = false;
  showConfirmPw      = false;

  // ── Avatar ────────────────────────────────────────────────────────────
  avatarUploading    = false;
  avatarError        = '';

  dependents: any[] = [];
  dependentsLoading = false;
  dependentSaving = false;
  dependentError = '';
  showDependentForm = false;
  dependentEditId: number | null = null;
  dependentPassportFile: File | null = null;
  dependentIdFile: File | null = null;
  dependentForm: any = this.blankDependent();

  documents: any[] = [];
  documentsLoading = false;
  documentUploading = false;
  documentError = '';
  documentSuccess = '';
  documentFile: File | null = null;
  documentForm: any = this.blankDocument();

  readonly tabs = [
    { id: 'info',     label: 'My Info',        icon: 'person'   },
    { id: 'dependents', label: 'Dependents',   icon: 'family_restroom' },
    { id: 'documents', label: 'Documents',     icon: 'folder' },
    { id: 'edit',     label: 'Edit Profile',   icon: 'edit'     },
    { id: 'password', label: 'Change Password', icon: 'lock'    },
  ];

  readonly documentTypes = [
    { key: 'id_iqama', label: 'ID / Iqama', type: 'id', defaultTitle: 'ID / Iqama' },
    { key: 'passport', label: 'Passport', type: 'passport', defaultTitle: 'Passport' },
    { key: 'hdf', label: 'HDF', type: 'medical', defaultTitle: 'HDF' },
    { key: 'signed_offer_letter', label: 'Signed Offer Letter', type: 'contract', defaultTitle: 'Signed Offer Letter' },
    { key: 'certificate', label: 'Certificate', type: 'certificate', defaultTitle: 'Certificate' },
    { key: 'experience_letter', label: 'Experience Letter', type: 'certificate', defaultTitle: 'Experience Letter' },
    { key: 'bank_details', label: 'Bank Details', type: 'other', defaultTitle: 'Bank Details' },
    { key: 'national_address', label: 'National Address', type: 'other', defaultTitle: 'National Address' },
    { key: 'other', label: 'Other', type: 'other', defaultTitle: '' },
  ];

  constructor(
    private http: HttpClient,
    private auth: AuthService,
    private cdr: ChangeDetectorRef,
  ) {}

  ngOnInit(): void {
    // Immediately populate from the current tab session while API loads
    const stored = this.auth.getUser();
    if (stored) { this.user = stored; }

    this.http.get<any>('/api/v1/profile').subscribe({
      next: r => {
        this.user     = r.user     || this.user;
        this.employee = r.employee || null;
        this.loading  = false;
        this.initEditForm();
        if (this.employee?.id) this.loadDependents();
        if (this.employee?.id) this.loadDocuments();
        this.cdr.markForCheck();
      },
      error: () => {
        this.loading = false;
        this.initEditForm();
        this.cdr.markForCheck();
      },
    });
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  initial(n?: string): string {
    return (n || '?').split(' ').map((w: string) => w[0]).join('').toUpperCase().slice(0, 2);
  }

  roleBadge(role: string): string {
    const labels: Record<string, string> = {
      super_admin:        'Super Admin',
      ceo:                'CEO',
      hr_manager:         'HR Manager',
      hr_staff:           'HR Staff',
      finance_manager:    'Finance Manager',
      department_manager: 'Department Manager',
      employee:           'Employee',
    };
    return labels[role] || role;
  }

  avatarUrl(): string | null {
    return this.employee?.avatar_url || null;
  }

  displayText(value: any, fallback = '-'): string {
    if (value === null || value === undefined) return fallback;
    const text = String(value).trim();
    return text ? text : fallback;
  }

  switchTab(tabId: string): void {
    this.activeTab = tabId;
    if (tabId === 'dependents' && this.employee?.id && !this.dependents.length) {
      this.loadDependents();
    }
    if (tabId === 'documents' && this.employee?.id && !this.documents.length) {
      this.loadDocuments();
    }
  }

  // ── Edit Profile ──────────────────────────────────────────────────────

  private initEditForm(): void {
    this.editForm = {
      name:        this.user?.name        || '',
      phone:       this.employee?.phone   || '',
      arabic_name: this.employee?.arabic_name || '',
      address:     this.employee?.address || '',
      city:        this.employee?.city    || '',
      country:     this.employee?.country || '',
    };
  }

  saveProfile(): void {
    this.editSaving = true;
    this.editSuccess = '';
    this.editError   = '';

    this.http.put<any>('/api/v1/profile', this.editForm).subscribe({
      next: r => {
        this.editSaving  = false;
        this.editSuccess = 'Profile updated successfully.';
        this.user        = { ...this.user, name: this.editForm.name };
        // Refresh stored user
        if (r.user) sessionStorage.setItem('hrms_user', JSON.stringify({ ...this.auth.getUser(), name: r.user.name }));
        this.cdr.markForCheck();
      },
      error: e => {
        this.editSaving = false;
        this.editError  = e?.error?.message || 'Failed to update profile.';
        this.cdr.markForCheck();
      },
    });
  }

  // ── Change Password ───────────────────────────────────────────────────

  changePassword(): void {
    this.pwSuccess = '';
    this.pwError   = '';

    if (this.pwForm.password !== this.pwForm.password_confirmation) {
      this.pwError = 'New password and confirmation do not match.';
      this.cdr.markForCheck();
      return;
    }
    if (this.pwForm.password.length < 8) {
      this.pwError = 'Password must be at least 8 characters.';
      this.cdr.markForCheck();
      return;
    }

    this.pwSaving = true;

    this.http.put<any>('/api/v1/profile/password', this.pwForm).subscribe({
      next: () => {
        this.pwSaving  = false;
        this.pwSuccess = 'Password changed successfully.';
        this.pwForm    = { current_password: '', password: '', password_confirmation: '' };
        this.cdr.markForCheck();
      },
      error: e => {
        this.pwSaving = false;
        const errs    = e?.error?.errors;
        this.pwError  = errs
          ? Object.values(errs).flat().join(' ')
          : (e?.error?.message || 'Failed to change password.');
        this.cdr.markForCheck();
      },
    });
  }

  // ── Avatar Upload ─────────────────────────────────────────────────────

  onAvatarChange(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowed.includes(file.type)) {
      this.avatarError = 'Only JPG, PNG, GIF or WEBP images are allowed.';
      this.cdr.markForCheck();
      return;
    }
    if (file.size > 2 * 1024 * 1024) {
      this.avatarError = 'Image must be smaller than 2 MB.';
      this.cdr.markForCheck();
      return;
    }

    const fd = new FormData();
    fd.append('avatar', file);

    this.avatarUploading = true;
    this.avatarError     = '';

    this.http.post<any>('/api/v1/profile/avatar', fd).subscribe({
      next: r => {
        this.avatarUploading = false;
        if (this.employee) this.employee = { ...this.employee, avatar_url: r.avatar_url };
        this.cdr.markForCheck();
      },
      error: e => {
        this.avatarUploading = false;
        this.avatarError     = e?.error?.message || 'Failed to upload avatar.';
        this.cdr.markForCheck();
      },
    });
  }

  // Dependents
  private blankDependent(): any {
    return {
      full_name: '',
      relationship: 'spouse',
      date_of_birth: '',
      nationality: '',
      id_number: '',
      id_expiry: '',
      passport_number: '',
      passport_expiry: '',
      is_active: true,
    };
  }

  private blankDocument(): any {
    return {
      document_key: 'id_iqama',
      title: 'ID / Iqama',
      expiry_date: '',
    };
  }

  loadDependents(): void {
    if (!this.employee?.id) return;
    this.dependentsLoading = true;
    this.http.get<any>(`/api/v1/employees/${this.employee.id}/dependents`).subscribe({
      next: r => {
        this.dependents = r?.dependents || [];
        this.dependentsLoading = false;
        this.cdr.markForCheck();
      },
      error: e => {
        this.dependentsLoading = false;
        this.dependentError = e?.error?.message || 'Could not load dependents.';
        this.cdr.markForCheck();
      },
    });
  }

  openDependentForm(dependent?: any): void {
    this.dependentEditId = dependent?.id || null;
    this.dependentForm = dependent ? {
      ...dependent,
      date_of_birth: this.toDateInput(dependent.date_of_birth),
      id_expiry: this.toDateInput(dependent.id_expiry),
      passport_expiry: this.toDateInput(dependent.passport_expiry),
      is_active: dependent.is_active ?? true,
    } : this.blankDependent();
    this.dependentPassportFile = null;
    this.dependentIdFile = null;
    this.dependentError = '';
    this.showDependentForm = true;
  }

  cancelDependentForm(): void {
    this.showDependentForm = false;
    this.dependentEditId = null;
    this.dependentForm = this.blankDependent();
    this.dependentPassportFile = null;
    this.dependentIdFile = null;
    this.dependentError = '';
  }

  selectDependentFile(type: 'passport' | 'id', event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] || null;
    if (!file) {
      if (type === 'passport') this.dependentPassportFile = null;
      else this.dependentIdFile = null;
      return;
    }

    const allowed = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!allowed.includes(file.type)) {
      this.dependentError = 'Dependent documents must be PDF, JPG, or PNG.';
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      this.dependentError = 'Dependent documents must be 5 MB or smaller.';
      return;
    }

    this.dependentError = '';
    if (type === 'passport') this.dependentPassportFile = file;
    else this.dependentIdFile = file;
  }

  saveDependent(): void {
    if (!this.employee?.id) {
      this.dependentError = 'No employee record linked to this profile.';
      return;
    }
    if (!this.dependentForm.full_name?.trim()) {
      this.dependentError = 'Dependent full name is required.';
      return;
    }
    if (!this.dependentForm.id_number?.trim()) {
      this.dependentError = 'Iqama / ID number is required.';
      return;
    }

    this.dependentSaving = true;
    this.dependentError = '';

    const fd = new FormData();
    Object.entries(this.dependentForm).forEach(([key, value]) => {
      if (value === null || value === undefined || value === '') return;
      fd.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    });
    if (this.dependentPassportFile) fd.append('passport_file', this.dependentPassportFile);
    if (this.dependentIdFile) fd.append('id_file', this.dependentIdFile);

    let request = this.http.post<any>(`/api/v1/employees/${this.employee.id}/dependents`, fd);
    if (this.dependentEditId) {
      fd.append('_method', 'PUT');
      request = this.http.post<any>(`/api/v1/employees/${this.employee.id}/dependents/${this.dependentEditId}`, fd);
    }

    request.subscribe({
      next: () => {
        this.dependentSaving = false;
        this.cancelDependentForm();
        this.loadDependents();
      },
      error: e => {
        this.dependentSaving = false;
        const errors = e?.error?.errors;
        this.dependentError = errors
          ? String(Object.values(errors).flat().join(' '))
          : (e?.error?.message || 'Could not save dependent.');
        this.cdr.markForCheck();
      },
    });
  }

  deleteDependent(dependent: any): void {
    if (!this.employee?.id || !confirm(`Delete ${dependent.full_name}?`)) return;
    this.http.delete(`/api/v1/employees/${this.employee.id}/dependents/${dependent.id}`).subscribe({
      next: () => this.loadDependents(),
      error: e => {
        this.dependentError = e?.error?.message || 'Could not delete dependent.';
        this.cdr.markForCheck();
      },
    });
  }

  downloadDependentDocument(dependent: any, type: 'passport' | 'id'): void {
    if (!this.employee?.id) return;
    this.http.get(`/api/v1/employees/${this.employee.id}/dependents/${dependent.id}/documents/${type}`, {
      responseType: 'blob',
      observe: 'response',
    }).subscribe({
      next: response => {
        const url = URL.createObjectURL(response.body as Blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = type === 'passport'
          ? (dependent.passport_file_name || 'passport-document')
          : (dependent.id_file_name || 'id-document');
        link.click();
        URL.revokeObjectURL(url);
      },
      error: e => {
        this.dependentError = e?.error?.message || 'Could not download the document.';
        this.cdr.markForCheck();
      },
    });
  }

  private toDateInput(value: string | null): string {
    if (!value) return '';
    return String(value).slice(0, 10);
  }

  // Employee documents
  onDocumentTypeChange(): void {
    const selected = this.documentTypes.find(type => type.key === this.documentForm.document_key);
    if (selected?.defaultTitle) this.documentForm.title = selected.defaultTitle;
  }

  selectDocumentFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] || null;
    this.documentError = '';
    if (!file) {
      this.documentFile = null;
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      this.documentError = 'Document must be 10 MB or smaller.';
      this.documentFile = null;
      return;
    }

    this.documentFile = file;
  }

  loadDocuments(): void {
    if (!this.employee?.id) return;
    this.documentsLoading = true;
    this.http.get<any>(`/api/v1/employees/${this.employee.id}/documents`).subscribe({
      next: r => {
        this.documents = r?.documents || [];
        this.documentsLoading = false;
        this.cdr.markForCheck();
      },
      error: e => {
        this.documentsLoading = false;
        this.documentError = e?.error?.message || 'Could not load documents.';
        this.cdr.markForCheck();
      },
    });
  }

  uploadDocument(): void {
    if (!this.employee?.id) {
      this.documentError = 'No employee record linked to this profile.';
      return;
    }
    if (!this.documentForm.title?.trim()) {
      this.documentError = 'Document title is required.';
      return;
    }
    if (!this.documentFile) {
      this.documentError = 'Please select a document file.';
      return;
    }

    const selected = this.documentTypes.find(type => type.key === this.documentForm.document_key) || this.documentTypes[0];
    const fd = new FormData();
    fd.append('title', this.documentForm.title);
    fd.append('type', selected.type);
    fd.append('file', this.documentFile);
    if (this.documentForm.expiry_date) fd.append('expiry_date', this.documentForm.expiry_date);

    this.documentUploading = true;
    this.documentError = '';
    this.documentSuccess = '';

    this.http.post<any>(`/api/v1/employees/${this.employee.id}/documents`, fd).subscribe({
      next: () => {
        this.documentUploading = false;
        this.documentSuccess = 'Document uploaded successfully.';
        this.documentForm = this.blankDocument();
        this.documentFile = null;
        this.loadDocuments();
        this.cdr.markForCheck();
      },
      error: e => {
        this.documentUploading = false;
        const errors = e?.error?.errors;
        this.documentError = errors
          ? String(Object.values(errors).flat().join(' '))
          : (e?.error?.message || 'Could not upload document.');
        this.cdr.markForCheck();
      },
    });
  }

  downloadDocument(doc: any): void {
    if (!this.employee?.id) return;
    this.http.get(`/api/v1/employees/${this.employee.id}/documents/${doc.id}/download`, {
      responseType: 'blob',
      observe: 'response',
    }).subscribe({
      next: response => {
        const url = URL.createObjectURL(response.body as Blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = doc.file_name || doc.title || 'employee-document';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: e => {
        this.documentError = e?.error?.message || 'Could not download document.';
        this.cdr.markForCheck();
      },
    });
  }

  deleteDocument(doc: any): void {
    if (!this.employee?.id || !confirm(`Delete ${doc.title}?`)) return;
    this.http.delete(`/api/v1/employees/${this.employee.id}/documents/${doc.id}`).subscribe({
      next: () => this.loadDocuments(),
      error: e => {
        this.documentError = e?.error?.message || 'Could not delete document.';
        this.cdr.markForCheck();
      },
    });
  }

  documentTypeLabel(type: string): string {
    const labels: any = {
      id: 'ID / Iqama',
      passport: 'Passport',
      medical: 'Medical / HDF',
      contract: 'Contract / Offer Letter',
      certificate: 'Certificate',
      visa: 'Visa',
      other: 'Other',
    };
    return labels[type] || type;
  }

  formatFileSize(bytes: number | null): string {
    if (!bytes) return '-';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }

  formatEmploymentType(type: string): string {

  const map: any = {
    full_time: 'Full Time',
    part_time: 'Part Time',
    contract: 'Contract',
    intern: 'Intern'
  };

  return map[type] || type;
}
}
