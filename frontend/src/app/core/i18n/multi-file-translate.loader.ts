import { HttpBackend, HttpClient } from '@angular/common/http';
import { TranslateLoader } from '@ngx-translate/core';
import { Observable, forkJoin, of } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { I18N_NAMESPACES } from './i18n.config';

/** Loads every namespace file for a language and deep-merges them into one dictionary. */
export class MultiFileTranslateLoader implements TranslateLoader {
  constructor(private http: HttpClient, private prefix = 'assets/i18n') {}

  getTranslation(lang: string): Observable<any> {
    const requests = I18N_NAMESPACES.map(ns =>
      this.http.get<Record<string, any>>(`${this.prefix}/${lang}/${ns}.json`).pipe(
        catchError(() => of({}))
      )
    );
    return forkJoin(requests).pipe(map(parts => parts.reduce((acc, p) => deepMerge(acc, p || {}), {})));
  }
}

/**
 * Uses HttpBackend so translation files bypass the HTTP interceptors: they are static assets, and the
 * interceptors depend on AuthService -> LanguageService -> TranslateService, which would be circular.
 */
export function multiFileLoaderFactory(backend: HttpBackend): TranslateLoader {
  return new MultiFileTranslateLoader(new HttpClient(backend));
}

function deepMerge(target: any, source: any): any {
  for (const key of Object.keys(source)) {
    const value = source[key];
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      target[key] = deepMerge(target[key] && typeof target[key] === 'object' ? target[key] : {}, value);
    } else {
      target[key] = value;
    }
  }
  return target;
}
