import {
  Component, OnInit, OnDestroy,
  ChangeDetectionStrategy, ChangeDetectorRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { GeolocationService } from '../../../core/services/geolocation.service';
import { LanguageService } from '../../../core/services/language.service';

export interface AttendanceLocation {
  id:            number;
  name:          string;
  latitude:      number;
  longitude:     number;
  radius_meters: number;
  is_active:     boolean;
}

/** HR list of the work sites employees may punch from on their phone. */
@Component({
  standalone:      false,
  selector:        'app-attendance-locations',
  templateUrl:     './attendance-locations.component.html',
  styleUrls:       ['./attendance-locations.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AttendanceLocationsComponent implements OnInit, OnDestroy {

  locations: AttendanceLocation[] = [];
  form!: FormGroup;
  editingId: number | null = null;
  showForm  = false;
  saving    = false;
  locating  = false;
  errorMsg  = '';

  private readonly api      = '/api/v1/attendance/locations';
  private readonly destroy$ = new Subject<void>();

  constructor(
    private readonly http: HttpClient,
    private readonly fb:   FormBuilder,
    private readonly geo:  GeolocationService,
    private readonly cdr:  ChangeDetectorRef,
    private readonly lang: LanguageService,
  ) {}

  ngOnInit(): void {
    this.form = this.fb.group({
      name:          ['', [Validators.required, Validators.maxLength(100)]],
      latitude:      [null, [Validators.required, Validators.min(-90), Validators.max(90)]],
      longitude:     [null, [Validators.required, Validators.min(-180), Validators.max(180)]],
      radius_meters: [500, [Validators.required, Validators.min(50), Validators.max(5000)]],
      is_active:     [true],
    });
    this.load();
  }

  load(): void {
    this.http.get<any>(this.api).pipe(takeUntil(this.destroy$)).subscribe({
      next:  (r) => { this.locations = r.data ?? []; this.cdr.markForCheck(); },
      error: (err) => this.fail(err),
    });
  }

  openForm(location: AttendanceLocation | null = null): void {
    this.errorMsg  = '';
    this.editingId = location?.id ?? null;
    this.form.reset(location ?? { name: '', latitude: null, longitude: null, radius_meters: 500, is_active: true });
    this.showForm  = true;
  }

  closeForm(): void {
    this.showForm  = false;
    this.editingId = null;
  }

  /** HR can stand at the site and capture its coordinates instead of typing them. */
  useCurrentPosition(): void {
    this.locating = true;
    this.errorMsg = '';
    this.geo.current().pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ latitude, longitude }) => {
        this.form.patchValue({ latitude, longitude });
        this.locating = false;
        this.cdr.markForCheck();
      },
      error: (key: string) => {
        this.errorMsg = this.lang.t(key);
        this.locating = false;
        this.cdr.markForCheck();
      },
    });
  }

  save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    this.saving   = true;
    this.errorMsg = '';
    const request = this.editingId
      ? this.http.put<any>(`${this.api}/${this.editingId}`, this.form.value)
      : this.http.post<any>(this.api, this.form.value);

    request.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.closeForm();
        this.load();
      },
      error: (err) => { this.saving = false; this.fail(err); },
    });
  }

  remove(location: AttendanceLocation): void {
    if (!confirm(this.lang.t('ATTENDANCE.LOCATIONS.CONFIRM_DELETE', { name: location.name }))) return;
    this.http.delete<any>(`${this.api}/${location.id}`).pipe(takeUntil(this.destroy$)).subscribe({
      next:  () => this.load(),
      error: (err) => this.fail(err),
    });
  }

  private fail(err: any): void {
    this.errorMsg = err?.error?.message ?? this.lang.t('ATTENDANCE.LOCATIONS.SAVE_FAILED');
    this.cdr.markForCheck();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }
}
