<?php

namespace KandySoft\MediaKit\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use RuntimeException;

/**
 * One Laravel filesystem disk, seen through the narrow slit this package needs.
 *
 * There is no driver hierarchy any more: local, S3, MinIO, GCS or anything else
 * the host configures all arrive here as a plain Filesystem. Whether files are
 * publicly reachable comes from the disk's own `visibility`, not from the class
 * that happens to wrap it.
 */
final class MediaDisk
{
    public function __construct(
        private readonly string $name,
        private readonly Filesystem $filesystem,
        private readonly bool $public,
        private readonly int $temporaryUrlTtl,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->exists($path);
    }

    public function put(string $path, string $contents): void
    {
        $this->filesystem->put($path, $contents);
    }

    public function upload(UploadedFile $file, string $directory, string $filename): string
    {
        $this->filesystem->putFileAs($directory, $file, $filename);

        return $directory === '' ? $filename : "{$directory}/{$filename}";
    }

    public function delete(string $path): bool
    {
        return ! $this->exists($path) || $this->filesystem->delete($path);
    }

    public function get(string $path): string
    {
        $contents = $this->filesystem->get($path);

        if ($contents === null) {
            throw MediaNotFound::path($this->name, $path);
        }

        return $contents;
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        $stream = $this->filesystem->readStream($path);

        if (! is_resource($stream)) {
            throw MediaNotFound::path($this->name, $path);
        }

        return $stream;
    }

    public function size(string $path): ?int
    {
        return $this->exists($path) ? $this->filesystem->size($path) : null;
    }

    public function mimeType(string $path): ?string
    {
        $mimeType = $this->exists($path) ? $this->filesystem->mimeType($path) : false;

        return $mimeType === false ? null : $mimeType;
    }

    /**
     * A directly usable URL, or null when the driver cannot mint one.
     *
     * Local private disks land in the null branch — the caller then falls back
     * to a signed route that streams the file through the application.
     */
    public function url(string $path): ?string
    {
        try {
            return $this->public
                ? $this->filesystem->url($path)
                : $this->filesystem->temporaryUrl($path, now()->addMinutes($this->temporaryUrlTtl));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Copy a stored file into local temporary space so it can be re-uploaded
     * or handed to a library that insists on a real path.
     */
    public function pull(string $path): TemporaryFile
    {
        if (! $this->exists($path)) {
            throw MediaNotFound::path($this->name, $path);
        }

        return TemporaryFile::fromStream($this->readStream($path), basename($path));
    }
}
