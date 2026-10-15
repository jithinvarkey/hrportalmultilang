import { Injectable } from '@angular/core';
import { BehaviorSubject } from 'rxjs';

@Injectable({ providedIn: 'root' })
export class HttpActivityService {
  private activeRequests = 0;
  private readonly activeRequestsSubject = new BehaviorSubject<number>(0);

  readonly activeRequests$ = this.activeRequestsSubject.asObservable();

  get activeCount(): number {
    return this.activeRequests;
  }

  requestStarted(): void {
    this.activeRequests += 1;
    this.activeRequestsSubject.next(this.activeRequests);
  }

  requestFinished(): void {
    this.activeRequests = Math.max(0, this.activeRequests - 1);
    this.activeRequestsSubject.next(this.activeRequests);
  }
}
