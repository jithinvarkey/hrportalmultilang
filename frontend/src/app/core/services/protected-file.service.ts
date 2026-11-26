import { Injectable } from '@angular/core';
import { HttpClient, HttpResponse } from '@angular/common/http';

/**
 * Opens or saves files served by authorized API endpoints.
 *
 * Sensitive uploads (leave documents, contracts, CVs, request files) are no longer
 * reachable under /storage; they are streamed by the API, which authenticates with
 * the Bearer header added by the auth interceptor. A plain <a href> or window.open()
 * cannot send that header, so the file is fetched as a blob and shown via an object URL.
 */
@Injectable({ providedIn: 'root' })
export class ProtectedFileService {
  constructor(private http: HttpClient) {}

  /** Show the file in a new tab (PDF/images render inline in the browser). */
  open(url: string, onError?: (err: unknown) => void): void {
    // Open the tab synchronously, inside the click handler, so popup blockers allow it.
    const previewWindow = window.open('', '_blank');
    this.http.get(url, { responseType: 'blob' }).subscribe({
      next: blob => {
        const objectUrl = URL.createObjectURL(blob);
        if (previewWindow) {
          previewWindow.location.href = objectUrl;
        } else {
          window.open(objectUrl, '_blank');
        }
        setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000);
      },
      error: err => {
        previewWindow?.close();
        onError?.(err);
      },
    });
  }

  /** Save the file, using the server's Content-Disposition filename when present. */
  download(url: string, fallbackFilename: string, onError?: (err: unknown) => void): void {
    this.http.get(url, { responseType: 'blob', observe: 'response' }).subscribe({
      next: (response: HttpResponse<Blob>) => {
        const disposition = response.headers.get('content-disposition') || '';
        const match = disposition.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
        const filename = match?.[1] ? decodeURIComponent(match[1]) : fallbackFilename;
        const objectUrl = URL.createObjectURL(response.body as Blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        link.click();
        setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
      },
      error: err => onError?.(err),
    });
  }
}
