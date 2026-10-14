<?php

namespace KandySoft\MediaKit\Data;

use KandySoft\MediaKit\Models\MediaFile;

/**
 * How one stored file was encrypted — everything needed to read it back.
 *
 * The file's own key travels only wrapped: `wrappedKey` is that key encrypted
 * with the application key named by `keyId`. `checksum` is the SHA-256 of the
 * plaintext, kept to prove a conversion before the original is deleted.
 * `size` is the plaintext length, which is what a record and a response
 * describe; the disk holds a few bytes more.
 */
final readonly class Envelope
{
    /**
     * libsodium secretstream (XChaCha20-Poly1305), 64 KiB chunks. The chunk
     * size is part of the format: a file is read back in exactly the chunks it
     * was written in, so a different size would be a different scheme.
     */
    public const SCHEME = 'secretstream-v1';

    public function __construct(
        public string $scheme,
        public string $keyId,
        public string $wrappedKey,
        public string $checksum,
        public int $size,
    ) {}

    /**
     * The envelope of a stored file, or null when it was stored as it came.
     */
    public static function of(MediaFile $media): ?self
    {
        if ($media->encryption === null || $media->encryption === '') {
            return null;
        }

        return new self(
            scheme: (string) $media->encryption,
            keyId: (string) $media->key_id,
            wrappedKey: (string) $media->wrapped_key,
            checksum: (string) $media->checksum,
            size: (int) $media->size,
        );
    }

    /**
     * Columns of a record for a file stored this way — or stored as it came.
     *
     * @return array{encryption: ?string, key_id: ?string, wrapped_key: ?string, checksum: ?string}
     */
    public static function columns(?self $envelope): array
    {
        return [
            'encryption' => $envelope?->scheme,
            'key_id' => $envelope?->keyId,
            'wrapped_key' => $envelope?->wrappedKey,
            'checksum' => $envelope?->checksum,
        ];
    }

    /**
     * Columns to write for a file stored this way — and none for a file stored
     * as it came, unless it replaces one that was encrypted.
     *
     * Nothing is written for a plain file on purpose: a host whose code is
     * updated before its migrations run, or whose tests build only part of the
     * schema, keeps storing plain files exactly as before.
     *
     * @return array<string, ?string>
     */
    public static function changes(?self $envelope, ?MediaFile $replacing = null): array
    {
        if ($envelope !== null || ($replacing !== null && $replacing->encryption !== null)) {
            return self::columns($envelope);
        }

        return [];
    }

    public function withKey(string $keyId, string $wrappedKey): self
    {
        return new self($this->scheme, $keyId, $wrappedKey, $this->checksum, $this->size);
    }
}
