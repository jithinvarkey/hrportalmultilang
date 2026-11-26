<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sensitive uploads (medical certificates, contracts, CVs, request files) live on the
 * private "local" disk and are only served through authorized API endpoints.
 *
 * Files uploaded before this change may still sit on the "public" disk; every lookup
 * checks "local" first and falls back to "public" until `uploads:move-private` has run.
 */
class PrivateUploads
{
    public const DISK = 'local';
    public const LEGACY_DISK = 'public';

    /** Name of the disk currently holding the file, or null when it is missing. */
    public static function diskFor(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        foreach ([self::DISK, self::LEGACY_DISK] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return $disk;
            }
        }

        return null;
    }

    public static function exists(?string $path): bool
    {
        return self::diskFor($path) !== null;
    }

    /** Stream the file as an attachment; null when it is not on either disk. */
    public static function download(?string $path, ?string $name = null, array $headers = []): ?StreamedResponse
    {
        $disk = self::diskFor($path);
        if ($disk === null) {
            return null;
        }

        return Storage::disk($disk)->download($path, $name ?: basename($path), $headers);
    }

    /**
     * Stream the file for display in the page (e.g. an <img> loaded as a blob), not as a download.
     * Null when it is not on either disk.
     */
    public static function inline(?string $path, array $headers = []): ?StreamedResponse
    {
        $disk = self::diskFor($path);
        if ($disk === null) {
            return null;
        }

        return Storage::disk($disk)->response($path, basename($path), $headers);
    }

    /** Remove the file from whichever disk(s) hold it. */
    public static function delete(?string $path): void
    {
        if (!$path) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
        Storage::disk(self::LEGACY_DISK)->delete($path);
    }
}
