import { Injectable, Pipe, PipeTransform } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { catchError, map, shareReplay } from 'rxjs/operators';

/**
 * Loads images that need a login (profile photos under /api/v1/...) through HttpClient,
 * so the auth interceptor adds the Bearer token, and returns a blob: URL for [src] / background-image.
 * Each URL is fetched once per page load and shared by every place that shows it.
 */
@Injectable({ providedIn: 'root' })
export class AuthImageService {
  private cache = new Map<string, Observable<string | null>>();

  constructor(private http: HttpClient) {}

  load(url: string): Observable<string | null> {
    let image$ = this.cache.get(url);
    if (!image$) {
      image$ = this.http.get(url, { responseType: 'blob' }).pipe(
        map(blob => URL.createObjectURL(blob)),
        catchError(() => of(null)), // missing photo or no access: show the initials instead
        shareReplay(1),
      );
      this.cache.set(url, image$);
    }
    return image$;
  }
}

/**
 *   <img *ngIf="(emp.avatar_url | authImage | async) as src" [src]="src">
 *
 * Plain (non-API) URLs such as data: or blob: previews are passed through unchanged.
 */
@Pipe({ name: 'authImage', standalone: true })
export class AuthImagePipe implements PipeTransform {
  constructor(private images: AuthImageService) {}

  transform(url: string | null | undefined): Observable<string | null> {
    if (!url) {
      return of(null);
    }
    if (!url.includes('/api/')) {
      return of(url);
    }
    return this.images.load(url);
  }
}
