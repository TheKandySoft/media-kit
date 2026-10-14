<?php

namespace KandySoft\MediaKit\Storage;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\MediaPolicy;
use KandySoft\MediaKit\Encryption\FileCipher;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use KandySoft\MediaKit\Exceptions\MediaNotStored;
use LogicException;
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
        private readonly ?FileCipher $cipher = null,
    ) {}

    /**
     * The same disk, writing encrypted or not regardless of its configuration
     * — for converting files that are already stored.
     */
    public function withEncryption(bool $encrypt): self
    {
        return new self($this->name, $this->filesystem, $this->policy->withEncryption($encrypt), $this->cipher);
    }

    /**
     * Whether what is written here is written encrypted.
     */
    public function encrypts(): bool
    {
        return $this->policy->encrypt && $this->cipher !== null;
    }

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
     * Store an upload as it came. Refuses on an encrypting disk: the file's
     * key would be lost with the return value — use `store()` there.
     *
     * The file is opened here rather than by `putFileAs`: that one hands an
     * unopenable path on as `false`, and a worker that does not turn the
     * warning into an exception then stores an empty object under the name.
     */
    public function upload(UploadedFile $file, string $directory, string $filename): string
    {
        if ($this->encrypts()) {
            throw new LogicException("Disk [{$this->name}] encrypts; store uploads with store() to keep their key.");
        }

        return $this->store($file, $directory, $filename)[0];
    }

    /**
     * Store an upload — encrypted when this disk encrypts.
     *
     * @return array{0: string, 1: ?Envelope}  the path, and how it was encrypted
     */
    public function store(UploadedFile $file, string $directory, string $filename): array
    {
        $path = $directory === '' ? $filename : "{$directory}/{$filename}";
        $source = (string) $file->getRealPath();
        $stream = is_file($source) ? fopen($source, 'r') : false;

        if (! is_resource($stream)) {
            throw MediaNotStored::path($this->name, $path);
        }

        try {
            $envelope = $this->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [$path, $envelope];
    }

    /**
     * Write contents — encrypted when this disk encrypts.
     *
     * @param  string|resource  $contents
     */
    public function write(string $path, $contents): ?Envelope
    {
        if (is_string($contents)) {
            $stream = fopen('php://temp', 'r+b');
            fwrite($stream, $contents);
            rewind($stream);

            try {
                return $this->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }
        }

        return $this->writeStream($path, $contents);
    }

    /**
     * The contents of a stored file, decrypted when it was stored encrypted.
     */
    public function read(string $path, ?Envelope $envelope): string
    {
        if ($envelope === null) {
            return $this->get($path);
        }

        $stream = $this->openStream($path, $envelope);

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * A readable stream of a stored file, decrypted when it was stored
     * encrypted. The caller closes it.
     *
     * Decrypted into a temporary stream that spills to disk past a couple of
     * megabytes, so a large file is never held in memory whole.
     *
     * @return resource
     */
    public function openStream(string $path, ?Envelope $envelope)
    {
        if ($envelope === null) {
            return $this->readStream($path);
        }

        $cipher = $this->cipher ?? throw new LogicException('An encrypted file needs the package cipher to be read.');
        $source = $this->readStream($path);
        $plain = fopen('php://temp', 'r+b');

        try {
            $cipher->decrypt($source, $plain, $envelope, $this->name, $path);
        } catch (\Throwable $exception) {
            fclose($plain);

            throw $exception;
        } finally {
            fclose($source);
        }

        rewind($plain);

        return $plain;
    }

    /**
     * @param  resource  $stream
     */
    private function writeStream(string $path, $stream): ?Envelope
    {
        $envelope = null;

        if ($this->encrypts()) {
            $sealed = fopen('php://temp', 'r+b');

            try {
                $envelope = $this->cipher->encrypt($stream, $sealed);
                rewind($sealed);
                $stored = $this->filesystem->put($path, $sealed);
            } finally {
                fclose($sealed);
            }
        } else {
            $stored = $this->filesystem->put($path, $stream);
        }

        if ($stored !== true) {
            throw MediaNotStored::path($this->name, $path);
        }

        return $envelope;
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
     * the disk's own applies. `$sealed` says the file itself is encrypted —
     * it may have been written before this disk stopped encrypting.
     */
    public function url(string $path, ?DateTimeInterface $expiry = null, bool $sealed = false): ?string
    {
        // The store would hand out ciphertext: an encrypted file is only ever
        // served through the application, which decrypts it.
        if ($sealed || $this->encrypts()) {
            return null;
        }

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
    public function pull(string $path, ?Envelope $envelope = null): TemporaryFile
    {
        if (! $this->exists($path)) {
            throw MediaNotFound::path($this->name, $path);
        }

        return TemporaryFile::fromStream($this->openStream($path, $envelope), basename($path));
    }
}
