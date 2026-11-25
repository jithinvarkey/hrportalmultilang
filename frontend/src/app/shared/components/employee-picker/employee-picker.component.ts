import {
  ChangeDetectionStrategy, ChangeDetectorRef, Component, ElementRef, Input, OnChanges, ViewChild, forwardRef,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { MatIconModule } from '@angular/material/icon';
import { TranslateModule } from '@ngx-translate/core';

/**
 * Searchable employee combo box: a text input with a chevron that opens a list
 * starting with "All Employees", then each employee with their code on the right.
 * Works with both reactive forms (formControlName) and ngModel. The value is the
 * employee id, or '' for "All Employees".
 *
 * The parent passes the employees the user may pick (already scoped by role or
 * department); the picker only narrows that list by the search term.
 */
@Component({
  selector: 'app-employee-picker',
  standalone: true,
  imports: [CommonModule, MatIconModule, TranslateModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => EmployeePickerComponent), multi: true }],
  template: `
    <div class="emp-combo">
      <input #input class="emp-combo-input" type="text"
             [placeholder]="placeholder | translate" autocomplete="off"
             [attr.aria-label]="placeholder | translate"
             [disabled]="disabled"
             [value]="open ? term : selectedLabel"
             (focus)="openList()"
             (input)="onSearch($any($event.target).value)"
             (blur)="closeList()"
             (keydown.escape)="$any($event.target).blur()">
      <mat-icon class="emp-combo-caret">expand_more</mat-icon>
      <div class="emp-combo-list" *ngIf="open">
        <div class="emp-combo-opt" [class.active]="!value"
             (mousedown)="$event.preventDefault(); select(null)">
          {{ allLabel | translate }}
        </div>
        <div class="emp-combo-opt" *ngFor="let e of options; trackBy: trackById"
             [class.active]="'' + value === '' + e.id"
             (mousedown)="$event.preventDefault(); select(e)">
          {{ e.first_name }} {{ e.last_name }}
          <span class="emp-combo-code" dir="ltr" *ngIf="e.employee_code">{{ e.employee_code }}</span>
        </div>
        <div class="emp-combo-empty" *ngIf="!options.length">{{ emptyLabel | translate }}</div>
      </div>
    </div>
  `,
  styles: [`
    :host { display:block; }
    .emp-combo { position:relative; min-width:240px; }
    .emp-combo-input {
      width:100%; box-sizing:border-box; padding:7px 30px 7px 11px; padding-inline:11px 30px;
      background:var(--surface2); border:1px solid var(--border2); border-radius:8px;
      color:var(--text); font-size:13px; font-family:inherit; outline:none;
    }
    .emp-combo-input:focus { border-color:var(--accent); }
    :host(.compact) .emp-combo { min-width:220px; }
    :host(.compact) .emp-combo-input { font-size:12px; border-radius:9px; }
    .emp-combo-caret {
      position:absolute; inset-inline-end:6px; top:50%; transform:translateY(-50%);
      font-size:18px; width:18px; height:18px; color:var(--text3); pointer-events:none;
    }
    .emp-combo-list {
      position:absolute; top:calc(100% + 4px); inset-inline:0; z-index:20;
      max-height:260px; overflow-y:auto;
      background:var(--surface); border:1px solid var(--border2); border-radius:8px;
      box-shadow:0 8px 24px rgba(0,0,0,.12);
    }
    .emp-combo-opt {
      display:flex; justify-content:space-between; gap:8px;
      padding:7px 11px; font-size:13px; color:var(--text); cursor:pointer;
    }
    .emp-combo-opt:hover { background:var(--surface2); }
    .emp-combo-opt.active { color:var(--accent); font-weight:600; }
    .emp-combo-code  { font-size:11px; color:var(--text3); }
    .emp-combo-empty { padding:8px 11px; font-size:12px; color:var(--text3); }
    @media (max-width: 1024px) { .emp-combo { min-width:200px; } }
    @media (max-width: 768px)  {
      .emp-combo { min-width:0; width:100%; }
      .emp-combo-input { min-height:38px; }
    }
  `],
})
export class EmployeePickerComponent implements ControlValueAccessor, OnChanges {
  /** Employees the user may pick from (already role/department scoped). */
  @Input() employees: any[] = [];
  /** Translation keys; add class="compact" on the host for the smaller toolbar look. */
  @Input() placeholder = 'ATTENDANCE.FILTER.SEARCH_EMPLOYEE';
  @Input() allLabel    = 'ATTENDANCE.FILTER.ALL_EMPLOYEES';
  @Input() emptyLabel  = 'ATTENDANCE.FILTER.NO_EMPLOYEES';

  @ViewChild('input') input?: ElementRef<HTMLInputElement>;

  value: any = '';
  term = '';
  open = false;
  disabled = false;
  options: any[] = [];

  private onChange: (v: any) => void = () => {};
  private onTouched: () => void = () => {};

  constructor(private cdr: ChangeDetectorRef) {}

  ngOnChanges(): void { this.refresh(); }

  get selectedLabel(): string {
    if (!this.value) return '';
    const e = (this.employees || []).find(x => String(x.id) === String(this.value));
    return e ? `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim() : '';
  }

  openList(): void {
    this.term = '';
    this.open = true;
    this.refresh();
  }

  onSearch(value: string): void {
    this.term = value;
    this.refresh();
  }

  closeList(): void {
    this.open = false;
    this.term = '';
    this.onTouched();
  }

  select(e: any | null): void {
    this.value = e ? e.id : '';
    this.onChange(this.value);
    this.closeList();
    this.input?.nativeElement.blur();
    this.cdr.markForCheck();
  }

  trackById = (_: number, e: any) => e.id;

  private refresh(): void {
    const term = this.term.trim().toLowerCase();
    this.options = (this.employees || []).filter(e => !term
      || `${e.first_name ?? ''} ${e.last_name ?? ''} ${e.employee_code ?? ''}`.toLowerCase().includes(term));
  }

  // ── ControlValueAccessor ────────────────────────────────────────────────
  writeValue(v: any): void { this.value = v ?? ''; this.cdr.markForCheck(); }
  registerOnChange(fn: (v: any) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(d: boolean): void { this.disabled = d; this.cdr.markForCheck(); }
}
