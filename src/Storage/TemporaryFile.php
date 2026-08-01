<?php

namespace KandySoft\MediaKit\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A local scratch copy of a stored file, deleted when the caller is done.
 *
 * Needed whenever something outside the filesystem abstraction wants a real
 * path — re-uploading a file to another model, for instance.
 */
final class TemporaryFile
{
    private function __construct(
        private readonly string $path,
        private readonly string $originalName,
    ) {}

    /**
     * @param  resource  $stream
     */
    public static function fromStream($stream, string $originalName): self
    {
        $directory = storage_path('app/media-kit-tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $path = $directory . '/' . Str::uuid() . '-' . $originalName;

        $target = fopen($path, 'w');
        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return new self($path, $originalName);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function contents(): string
    {
        return (string) file_get_contents($this->path);
    }

    public function toUploadedFile(?string $originalName = null): UploadedFile
    {
        return new UploadedFile(
            $this->path,
            $originalName ?? $this->originalName,
            mime_content_type($this->path) ?: null,
            null,
            // Already on disk and not an actual HTTP upload, so skip the checks.
            true,
        );
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
