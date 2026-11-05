import { Injectable } from '@angular/core';
import { Observable, from, of } from 'rxjs';
import { catchError, map, switchMap } from 'rxjs/operators';

export interface DevicePosition {
  latitude:  number;
  longitude: number;
  accuracy:  number;
}

@Injectable({ providedIn: 'root' })
export class GeolocationService {

  /**
   * Shows the browser's location popup if it has not been answered yet.
   * Emits the translation key of the problem, or null when location is usable.
   * A site cannot reopen the popup once blocked, so that case only reports it.
   */
  requestAccess(): Observable<string | null> {
    const state = navigator.permissions?.query({ name: 'geolocation' }).then(s => s.state).catch(() => null);

    return from(state ?? Promise.resolve(null)).pipe(
      switchMap(permission => {
        if (permission === 'granted') return of(null);
        if (permission === 'denied')  return of('ATTENDANCE.GEO.DENIED');
        return this.current().pipe(
          map(() => null),
          catchError((key: string) => of(key)),
        );
      }),
    );
  }

  /** Emits one fresh GPS fix, or errors with the translation key that explains why there is none. */
  current(): Observable<DevicePosition> {
    return new Observable(subscriber => {
      // Browsers only expose location on HTTPS (and localhost)
      if (!window.isSecureContext || !navigator.geolocation) {
        subscriber.error('ATTENDANCE.GEO.UNSUPPORTED');
        return;
      }

      navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
          subscriber.next({ latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy });
          subscriber.complete();
        },
        (error) => subscriber.error(
          error.code === error.PERMISSION_DENIED ? 'ATTENDANCE.GEO.DENIED'
            : error.code === error.TIMEOUT ? 'ATTENDANCE.GEO.TIMEOUT'
            : 'ATTENDANCE.GEO.UNAVAILABLE'
        ),
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 },
      );
    });
  }
}
