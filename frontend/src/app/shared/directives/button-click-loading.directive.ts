import { Directive, ElementRef, HostListener, OnDestroy } from '@angular/core';
import { Subscription } from 'rxjs';
import { HttpActivityService } from '../../core/services/http-activity.service';

@Directive({
  selector: 'button:not([appNoClickLoading])',
  standalone: true,
})
export class ButtonClickLoadingDirective implements OnDestroy {
  private timer: number | null = null;
  private activitySub: Subscription | null = null;
  private readonly minVisibleMs = 250;

  constructor(
    private readonly elementRef: ElementRef<HTMLButtonElement>,
    private readonly httpActivity: HttpActivityService
  ) {}

  @HostListener('click')
  onClick(): void {
    const button = this.elementRef.nativeElement;
    if (button.disabled || button.classList.contains('app-click-loading')) return;

    const initialActiveRequests = this.httpActivity.activeCount;
    const startedAt = Date.now();

    button.classList.add('app-click-loading');
    button.setAttribute('aria-busy', 'true');

    this.clearTimer();
    this.clearActivitySub();

    this.timer = window.setTimeout(() => {
      if (this.httpActivity.activeCount <= initialActiveRequests) {
        this.scheduleClear(startedAt);
        return;
      }

      this.activitySub = this.httpActivity.activeRequests$.subscribe(activeRequests => {
        if (activeRequests <= initialActiveRequests) {
          this.scheduleClear(startedAt);
        }
      });
    }, 0);

  }

  ngOnDestroy(): void {
    this.clearTimer();
    this.clearActivitySub();
  }

  private scheduleClear(startedAt: number): void {
    const remainingMs = Math.max(0, this.minVisibleMs - (Date.now() - startedAt));
    this.clearTimer();
    this.timer = window.setTimeout(() => this.clearLoading(), remainingMs);
  }

  private clearTimer(): void {
    if (this.timer !== null) {
      window.clearTimeout(this.timer);
      this.timer = null;
    }
  }

  private clearActivitySub(): void {
    if (this.activitySub) {
      this.activitySub.unsubscribe();
      this.activitySub = null;
    }
  }

  private clearLoading(): void {
    const button = this.elementRef.nativeElement;
    button.classList.remove('app-click-loading');
    button.removeAttribute('aria-busy');
    this.clearTimer();
    this.clearActivitySub();
  }
}
