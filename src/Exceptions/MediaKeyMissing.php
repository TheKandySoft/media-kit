<?php

namespace KandySoft\MediaKit\Exceptions;

use RuntimeException;

/**
 * An encrypted file names a key the application does not have.
 *
 * Never answered with the ciphertext or an empty file: without the key the
 * bytes are unreadable, and saying so is the only honest answer.
 */
final class MediaKeyMissing extends RuntimeException
{
    public static function current(): self
    {
        return new self('Media encryption is on, but no key is configured (media-kit.encryption.keys / .current).');
    }

    public static function id(string $id): self
    {
        return new self("Media encryption key [{$id}] is not configured.");
    }

    public static function malformed(string $id): self
    {
        return new self("Media encryption key [{$id}] is not a base64-encoded 32-byte key.");
    }
}
