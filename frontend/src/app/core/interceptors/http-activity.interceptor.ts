import { Injectable } from '@angular/core';
import { HttpEvent, HttpHandler, HttpInterceptor, HttpRequest } from '@angular/common/http';
import { Observable, finalize } from 'rxjs';
import { HttpActivityService } from '../services/http-activity.service';

@Injectable()
export class HttpActivityInterceptor implements HttpInterceptor {
  constructor(private readonly httpActivity: HttpActivityService) {}

  intercept(req: HttpRequest<unknown>, next: HttpHandler): Observable<HttpEvent<unknown>> {
    this.httpActivity.requestStarted();

    return next.handle(req).pipe(
      finalize(() => this.httpActivity.requestFinished())
    );
  }
}
