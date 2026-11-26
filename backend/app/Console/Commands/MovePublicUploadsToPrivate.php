<?php

namespace App\Console\Commands;

use App\Support\PrivateUploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Moves sensitive uploads that were stored on the web-served "public" disk
 * (storage/app/public/...) to the private "local" disk (storage/app/...), keeping
 * the same relative paths so the database columns need no change.
 *
 * Safe to run repeatedly: a file already present on the private disk with the same
 * size is just removed from the public disk; a conflicting file is left alone.
 */
class MovePublicUploadsToPrivate extends Command
{
    public const DIRECTORIES = ['leave-documents', 'contracts', 'recruitment', 'requests', 'avatars'];

    protected $signature = 'uploads:move-private
                            {--dry-run : List the files that would be moved without changing anything}';

    protected $description = 'Move sensitive uploads (leave documents, contracts, CVs, request files, profile photos) from the public disk to the private local disk';

    public function handle(): int
    {
        $public = Storage::disk(PrivateUploads::LEGACY_DISK);
        $private = Storage::disk(PrivateUploads::DISK);
        $dryRun = (bool) $this->option('dry-run');

        $moved = $alreadyPrivate = $conflicts = $failed = 0;

        foreach (self::DIRECTORIES as $directory) {
            if (!$public->exists($directory)) {
                continue;
            }

            foreach ($public->allFiles($directory) as $path) {
                if ($private->exists($path)) {
                    if ($private->size($path) === $public->size($path)) {
                        $alreadyPrivate++;
                        $this->line(($dryRun ? '[dry-run] would remove public copy: ' : 'Removed public copy: ') . $path);
                        if (!$dryRun) {
                            $public->delete($path);
                        }
                    } else {
                        $conflicts++;
                        $this->warn("Skipped (a different file already exists on the private disk): {$path}");
                    }
                    continue;
                }

                if ($dryRun) {
                    $moved++;
                    $this->line("[dry-run] would move: {$path}");
                    continue;
                }

                $stream = $public->readStream($path);
                $copied = $stream !== null && $private->writeStream($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if (!$copied || !$private->exists($path) || $private->size($path) !== $public->size($path)) {
                    $failed++;
                    $this->error("Failed to copy: {$path}");
                    continue;
                }

                $public->delete($path);
                $moved++;
                $this->line("Moved: {$path}");
            }
        }

        $verb = $dryRun ? 'would be moved' : 'moved';
        $this->info("{$moved} file(s) {$verb}; {$alreadyPrivate} already on the private disk; {$conflicts} conflict(s); {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
