import { Pipe, PipeTransform } from '@angular/core';
import { formatDate } from '@angular/common';
import { TranslateService } from '@ngx-translate/core';
import { LanguageService } from '../../core/services/language.service';

/**
 * Status codes are stored in the database as stable English codes (e.g. "manager_approved").
 * Their display text lives in the language files under STATUS.<code>.
 *
 *   {{ leave.status | statusLabel }}            -> STATUS.manager_approved
 *   {{ review.status | statusLabel:'PERFORMANCE' }} -> STATUS_CONTEXT.PERFORMANCE.<code>, falling back to STATUS.<code>
 *
 * Unknown codes fall back to a humanised version of the code, so nothing renders blank.
 */
@Pipe({ name: 'statusLabel', standalone: true, pure: false })
export class StatusLabelPipe implements PipeTransform {
  constructor(private translate: TranslateService) {}

  transform(code: string | null | undefined, context?: string): string {
    if (code === null || code === undefined || code === '') return '';
    const key = String(code).trim().toLowerCase().replace(/[\s-]+/g, '_');
    if (context) {
      const ctxKey = `STATUS_CONTEXT.${context}.${key}`;
      const ctx = this.translate.instant(ctxKey);
      if (ctx !== ctxKey) return ctx;
    }
    const statusKey = `STATUS.${key}`;
    const label = this.translate.instant(statusKey);
    return label !== statusKey ? label : humanize(String(code));
  }
}

/**
 * Picks the Arabic column of a bilingual master-data record when Arabic is active.
 *   {{ leaveType | locName }}               -> name_ar / name
 *   {{ designation | locName:'title' }}     -> title_ar / title
 */
@Pipe({ name: 'locName', standalone: true, pure: false })
export class LocalizedNamePipe implements PipeTransform {
  constructor(private lang: LanguageService) {}

  transform(record: any, field = 'name'): string {
    if (!record) return '';
    if (typeof record === 'string') return record;
    if (this.lang.current() === 'ar') {
      const ar = record[`${field}_ar`];
      if (ar !== null && ar !== undefined && String(ar).trim() !== '') return ar;
    }
    return record[field] ?? '';
  }
}

/** Drop-in replacement for the `date` pipe that follows the active language. */
@Pipe({ name: 'locDate', standalone: true, pure: false })
export class LocalizedDatePipe implements PipeTransform {
  constructor(private lang: LanguageService) {}

  transform(value: any, format = 'mediumDate', timezone?: string): string {
    if (value === null || value === undefined || value === '') return '';
    try { return formatDate(value, format, this.lang.locale(), timezone); } catch { return String(value); }
  }
}

export function humanize(code: string): string {
  return code.replace(/[_-]+/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

export const I18N_PIPES = [StatusLabelPipe, LocalizedNamePipe, LocalizedDatePipe];
