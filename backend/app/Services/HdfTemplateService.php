<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class HdfTemplateService
{
    private const DIRECTORY = 'hr-templates';

    public function info(): array
    {
        $path = $this->path();

        return [
            'exists' => $path !== null,
            'name' => $path ? basename($path) : null,
            'path' => $path,
            'size' => $path ? Storage::size($path) : null,
        ];
    }

    public function replace(UploadedFile $file): array
    {
        foreach ($this->matchingFiles() as $existing) {
            Storage::delete($existing);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = self::DIRECTORY . '/HDF.' . $extension;
        Storage::putFileAs(self::DIRECTORY, $file, basename($path));

        return $this->info();
    }

    public function attachment(): ?array
    {
        $path = $this->path();
        if (!$path) {
            return null;
        }

        return [
            'path' => $path,
            'name' => basename($path),
            'mime' => Storage::mimeType($path) ?: 'application/octet-stream',
        ];
    }

    public function exists(): bool
    {
        return $this->path() !== null;
    }

    private function path(): ?string
    {
        return collect($this->matchingFiles())->first();
    }

    private function matchingFiles(): array
    {
        return collect(Storage::files(self::DIRECTORY))
            ->filter(fn (string $path) => preg_match('/^HDF\.(pdf|doc|docx)$/i', basename($path)))
            ->sort()
            ->values()
            ->all();
    }
}
