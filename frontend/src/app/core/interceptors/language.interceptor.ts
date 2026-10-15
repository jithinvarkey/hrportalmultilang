import { Injectable } from '@angular/core';
import { HttpEvent, HttpHandler, HttpInterceptor, HttpRequest } from '@angular/common/http';
import { Observable } from 'rxjs';
import { DEFAULT_LANG } from '../i18n/i18n.config';

/**
 * Tells the API which language to answer in (validation errors, messages).
 * Reads <html lang>, which LanguageService keeps current, instead of injecting LanguageService:
 * the translation loader itself uses HttpClient, so injecting it here would be circular.
 */
@Injectable()
export class LanguageInterceptor implements HttpInterceptor {
  intercept(req: HttpRequest<any>, next: HttpHandler): Observable<HttpEvent<any>> {
    if (req.url.includes('/api/')) {
      const lang = document.documentElement.getAttribute('lang') || DEFAULT_LANG;
      req = req.clone({ setHeaders: { 'Accept-Language': lang } });
    }
    return next.handle(req);
  }
}
