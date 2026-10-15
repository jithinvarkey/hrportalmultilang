import { Component, OnInit, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { HttpClient } from '@angular/common/http';

/**
 * BioTime / ZKTeco biometric device management.
 *
 * Lists configured devices, supports add/edit/delete, tests the connection,
 * triggers manual sync, and shows per-device punch statistics. Talks to the
 * backend BioTime API at /api/v1/biotime/devices.
 */
@Component({
  standalone:      false,
  selector:        'app-biotime',
  templateUrl:     './biotime.component.html',
  styleUrls:       ['./biotime.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BioTimeComponent implements OnInit {

  private readonly api = '/api/v1/biotime/devices';

  devices: any[] = [];
  loading = false;

  // Per-device transient UI state keyed by device id.
  testing: Record<number, boolean> = {};
  syncing: Record<number, boolean> = {};
  testResult: Record<number, { ok: boolean; message: string }> = {};

  // Stats drawer
  statsDevice: any = null;
  stats: any = null;
  showStats = false;

  // Add / edit modal
  showForm = false;
  editId: number | null = null;
  submitting = false;
  formError = '';
  form: any = this.blankForm();

  // Toast
  successMsg = '';

showSyncModal = false;
syncDeviceObj: any = null;

syncForm = {
  type: 'today', // today | date | range
  date: '',
  start_date: '',
  end_date: '',
  employee_code: ''
};




  constructor(private readonly http: HttpClient, private readonly cdr: ChangeDetectorRef) {}

  ngOnInit(): void {
    this.loadDevices();
  }

  /** A fresh device form with sensible ZKTeco/BioTime defaults. */
  private blankForm(): any {
    return {
      name: '', protocol: 'http', ip_address: '', port: 8088,api_path:'',
      username: '', password: '', timeout_seconds: 30, is_active: true,
    };
  }

  /** Load all configured devices. */
  loadDevices(): void {
    this.loading = true;
    this.http.get<any>(this.api).subscribe({
      next: r => {
        this.devices = Array.isArray(r) ? r : (r?.data || []);
        this.loading = false;
        this.cdr.markForCheck();
      },
      error: () => {
        this.loading = false;
        this.cdr.markForCheck();
      },
    });
  }

  // ── Add / edit ──────────────────────────────────────────────────────────

  openForm(device?: any): void {
    this.formError = '';
    if (device) {
      this.editId = device.id;
      this.form = {
        name: device.name, protocol: device.protocol, ip_address: device.ip_address,
        port: device.port, username: device.username, password: '',api_path:device.api_path,
        timeout_seconds: device.timeout_seconds ?? 30, is_active: device.is_active ?? true,
      };
    } else {
      this.editId = null;
      this.form = this.blankForm();
    }
    this.showForm = true;
    this.cdr.markForCheck();
  }

  closeForm(): void { this.showForm = false; this.cdr.markForCheck(); }

  saveDevice(): void {
    if (!this.form.name?.trim() || !this.form.ip_address?.trim() || !this.form.username?.trim()) {
      this.formError = 'Name, IP address, and username are required.';
      this.cdr.markForCheck();
      return;
    }
    if (!this.editId && !this.form.password) {
      this.formError = 'Password is required for a new device.';
      this.cdr.markForCheck();
      return;
    }

    if (this.form.ip_address?.includes('http://') ||
    this.form.ip_address?.includes('https://')) {

  this.formError =
    'Please enter only IP Address or Host. Do not enter the full URL.';
  return;
}

    this.submitting = true;
    this.formError = '';

    // On edit, omit a blank password so the backend keeps the existing one.
    const payload: any = { ...this.form };
    if (this.editId && !payload.password) delete payload.password;

    const req = this.editId
      ? this.http.put<any>(`${this.api}/${this.editId}`, payload)
      : this.http.post<any>(this.api, payload);

    req.subscribe({
      next: () => {
        this.submitting = false;
        this.showForm = false;
        this.toast(this.editId ? 'Device updated.' : 'Device added.');
        this.loadDevices();
      },
      error: err => {
        this.submitting = false;
        this.formError = this.firstError(err) || 'Failed to save device.';
        this.cdr.markForCheck();
      },
    });
  }

  deleteDevice(device: any): void {
    if (!confirm(`Delete device "${device.name}"? Its punch logs will be removed too.`)) return;
    this.http.delete(`${this.api}/${device.id}`).subscribe({
      next: () => { this.toast('Device deleted.'); this.loadDevices(); },
      error: err => { this.toast(this.firstError(err) || 'Delete failed.'); },
    });
  }

  // ── Test connection ─────────────────────────────────────────────────────

  testConnection(device: any): void {
    this.testing[device.id] = true;
    delete this.testResult[device.id];
    this.cdr.markForCheck();

    this.http.post<any>(`${this.api}/${device.id}/test`, {}).subscribe({
      next: r => {
        this.testing[device.id] = false;
        this.testResult[device.id] = { ok: !!r?.ok, message: r?.message || (r?.ok ? 'Connected.' : 'Failed.') };
        this.cdr.markForCheck();
      },
      error: err => {
        this.testing[device.id] = false;
        this.testResult[device.id] = { ok: false, message: this.firstError(err) || 'Connection failed.' };
        this.cdr.markForCheck();
      },
    });
  }

  // ── Sync ────────────────────────────────────────────────────────────────


  syncDevice(): void {

  if (!this.syncDeviceObj) {
    return;
  }

  const deviceId = this.syncDeviceObj.id;

  if (this.syncing[deviceId]) {
    return;
  }

  this.syncing[deviceId] = true;
  this.cdr.markForCheck();

  const payload: any = {
    sync_type: this.syncForm.type
  };

  if (this.syncForm.type === 'date') {
    payload.date = this.syncForm.date;
  }

  if (this.syncForm.type === 'range') {
    payload.start_date = this.syncForm.start_date;
    payload.end_date = this.syncForm.end_date;
  }

  if (this.syncForm.employee_code) {
    payload.employee_code = this.syncForm.employee_code;
  }

  this.http.post<any>(
    `${this.api}/${deviceId}/sync`,
    payload
  ).subscribe({
    next: r => {

      this.syncing[deviceId] = false;
      this.showSyncModal = false;

      const skipped = Number(r?.skipped_duplicates ?? 0);
      const parts = [
        `Fetched ${Number(r?.fetched ?? 0)}`,
        `new ${Number(r?.new_raw ?? r?.new ?? 0)}`,
        `processed ${Number(r?.processed ?? 0)}`,
      ];
      if (skipped > 0) parts.push(`skipped duplicates ${skipped}`);

      this.toast(r?.message || `Sync completed: ${parts.join(', ')}.`);

      this.loadDevices();
      this.cdr.markForCheck();
    },
    error: err => {

      this.syncing[deviceId] = false;

      this.toast(
        this.firstError(err) ||
        'Sync failed.'
      );

      this.cdr.markForCheck();
    }
  });
}

  // ── Stats ───────────────────────────────────────────────────────────────

  openStats(device: any): void {
    this.statsDevice = device;
    this.stats = null;
    this.showStats = true;
    this.cdr.markForCheck();

    this.http.get<any>(`${this.api}/${device.id}/stats`).subscribe({
      next: r => { this.stats = r; this.cdr.markForCheck(); },
      error: () => { this.stats = { error: true }; this.cdr.markForCheck(); },
    });
  }

  closeStats(): void { this.showStats = false; this.cdr.markForCheck(); }

  // ── Helpers ─────────────────────────────────────────────────────────────

  private toast(msg: string): void {
    this.successMsg = msg;
    this.cdr.markForCheck();
    setTimeout(() => { this.successMsg = ''; this.cdr.markForCheck(); }, 3500);
  }

  private firstError(err: any): string {
    if (err?.error?.errors) {
      const first = Object.values(err.error.errors)[0];
      if (Array.isArray(first) && first.length) return first[0] as string;
    }
    return err?.error?.message || '';
  }

openSyncModal(device: any): void {

  this.syncDeviceObj = device;

  this.syncForm = {
    type: 'today',
    date: '',
    start_date: '',
    end_date: '',
    employee_code: ''
  };

  this.showSyncModal = true;
  this.cdr.markForCheck();
}

closeSyncModal(): void {
  this.showSyncModal = false;
  this.cdr.markForCheck();
}


}
