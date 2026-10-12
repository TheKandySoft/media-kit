<?php

namespace KandySoft\MediaKit\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use KandySoft\MediaKit\Data\MediaPolicy;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use KandySoft\MediaKit\Exceptions\MediaNotStored;
use RuntimeException;

/**
 * One Laravel filesystem disk, seen through the narrow slit this package needs.
 *
 * There is no driver hierarchy any more: local, S3, MinIO, GCS or anything else
 * the host configures all arrive here as a plain Filesystem. Whether files are
 * publicly reachable comes from the disk's own `visibility`, not from the class
 * that happens to wrap it.
 *
 * How long its links live comes from the same place — see `MediaPolicy`. That
 * is what makes "documents expire in fifteen minutes, catalogue pictures never"
 * expressible at all: the policy belongs to where the file is stored.
 */
final class MediaDisk
{
    public function __construct(
        private readonly string $name,
        private readonly Filesystem $filesystem,
        private readonly MediaPolicy $policy,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function isPublic(): bool
    {
        return $this->policy->public;
    }

    public function policy(): MediaPolicy
    {
        return $this->policy;
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function exists(string $path): bool
    {
        return $this->filesystem->exists($path);
    }

    /**
     * Writes refuse quietly on a disk with `'throw' => false`, so the result is
     * checked here rather than trusted: a record must never outlive its bytes.
     */
    public function put(string $path, string $contents): void
    {
        if ($this->filesystem->put($path, $contents) !== true) {
            throw MediaNotStored::path($this->name, $path);
        }
    }

    /**
     * The file is opened here rather than by `putFileAs`: that one hands an
     * unopenable path on as `false`, and a worker that does not turn the
     * warning into an exception then stores an empty object under the name.
     */
    public function upload(UploadedFile $file, string $directory, string $filename): string
    {
        $path = $directory === '' ? $filename : "{$directory}/{$filename}";
        $source = (string) $file->getRealPath();
        $stream = is_file($source) ? fopen($source, 'r') : false;

        if (! is_resource($stream)) {
            throw MediaNotStored::path($this->name, $path);
        }

        try {
            $stored = $this->filesystem->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($stored !== true) {
            throw MediaNotStored::path($this->name, $path);
        }

        return $path;
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
     *
     * `$expiry` is the caller's lifetime for a private file's link; without it
     * the disk's own applies.
     */
    public function url(string $path, ?DateTimeInterface $expiry = null): ?string
    {
        try {
            return $this->policy->public
                ? $this->filesystem->url($path)
                : $this->filesystem->temporaryUrl($path, $expiry ?? $this->policy->temporaryUrlExpiry());
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
