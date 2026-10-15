import { Component, Input } from '@angular/core';
import { LanguageService } from '../../../core/services/language.service';

/**
 * Shows a master-data name in both languages: the active language first, the other one underneath.
 * Used in settings lists (departments, units, job positions, leave/loan/request types).
 *
 *   <app-bi-name [item]="dept" [strong]="true"></app-bi-name>
 *   <app-bi-name [item]="designation" field="title"></app-bi-name>
 *
 * Reads `<field>` (English) and `<field>_ar` (Arabic). A missing Arabic name shows the English one only.
 */
@Component({
  selector: 'app-bi-name',
  standalone: true,
  template: `
    @if (en || ar) {
      <span class="bi-main" [class.bi-strong]="strong" [attr.lang]="primaryIsAr ? 'ar' : 'en'" [attr.dir]="primaryIsAr ? 'rtl' : 'ltr'">{{ primaryIsAr ? ar : en }}</span>
      @if (en && ar) {
        <span class="bi-alt" [attr.lang]="primaryIsAr ? 'en' : 'ar'" [attr.dir]="primaryIsAr ? 'ltr' : 'rtl'">{{ primaryIsAr ? en : ar }}</span>
      }
    } @else {
      <span class="bi-main">{{ empty }}</span>
    }
  `,
  styles: [`
    :host { display: inline-flex; flex-direction: column; gap: 1px; min-width: 0; vertical-align: middle; }
    .bi-main { color: inherit; }
    .bi-strong { font-weight: 700; }
    .bi-alt { font-size: .86em; color: var(--text3); font-weight: 500; }
    .bi-main, .bi-alt { unicode-bidi: isolate; text-align: start; }
  `],
})
export class BilingualNameComponent {
  @Input() item: any;
  @Input() field = 'name';
  @Input() strong = false;
  @Input() empty = '—';

  constructor(private lang: LanguageService) {}

  get en(): string { return this.text(this.field); }
  get ar(): string { return this.text(`${this.field}_ar`); }
  /** Arabic first when the screen is in Arabic and an Arabic name exists. */
  get primaryIsAr(): boolean { return this.lang.current() === 'ar' && !!this.ar || (!this.en && !!this.ar); }

  private text(key: string): string {
    if (!this.item) return '';
    if (typeof this.item === 'string') return key === this.field ? this.item : '';
    const v = this.item[key];
    return v === null || v === undefined ? '' : String(v).trim();
  }
}
