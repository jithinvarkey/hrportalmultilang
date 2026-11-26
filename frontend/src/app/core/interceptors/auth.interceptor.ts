import { Injectable } from '@angular/core';
import { HttpRequest, HttpHandler, HttpEvent, HttpInterceptor, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { AuthService } from '../services/auth.service';

/** Read (and cleared) by the login page to explain why the user was signed out. */
export const SIGNOUT_REASON_KEY = 'hrms_signout_reason';

@Injectable()
export class AuthInterceptor implements HttpInterceptor {
  constructor(private auth: AuthService, private router: Router) {}

  private isSameOrigin(url: string): boolean {
    if (!/^[a-z][a-z0-9+.-]*:|^\/\//i.test(url)) {
      return true; // relative URL such as /api/v1/...
    }
    try {
      return new URL(url).origin === window.location.origin;
    } catch {
      return false;
    }
  }
  intercept(req: HttpRequest<any>, next: HttpHandler): Observable<HttpEvent<any>> {
    const token = this.auth.getToken();
    // Send the token only to this site's own API, never to another origin.
    if (token && this.isSameOrigin(req.url)) {
      req = req.clone({ setHeaders: { Authorization: `Bearer ${token}` } });
    }
    return next.handle(req).pipe(
      catchError((err: unknown) => {
        // The stored token was revoked or is no longer valid (e.g. password changed on another device,
        // account deactivated): drop the dead session and send the user to the login page instead of
        // leaving every page failing. Only once — later 401s find no token and pass straight through.
        if (err instanceof HttpErrorResponse && err.status === 401 && token && this.auth.getToken() === token) {
          this.auth.clearSession();
          // Show the server's reason on the login page (e.g. "account no longer active"), but not the
          // generic message of an expired/unknown token.
          const reason = err.error?.message;
          if (reason && reason !== 'Unauthenticated.') {
            try { sessionStorage.setItem(SIGNOUT_REASON_KEY, reason); } catch { /* storage unavailable */ }
          }
          if (!this.router.url.startsWith('/auth/')) this.router.navigate(['/auth/login']);
        }
        return throwError(() => err);
      })
    );
  }
}
