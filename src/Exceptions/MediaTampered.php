<?php

namespace KandySoft\MediaKit\Exceptions;

use RuntimeException;

/**
 * An encrypted file does not decrypt: it was altered, truncated or written
 * under another key.
 *
 * Authenticated encryption is what makes this detectable at all — a changed
 * byte fails the chunk it is in, a cut-off file never reaches its final chunk.
 */
final class MediaTampered extends RuntimeException
{
    public static function path(string $disk, string $path): self
    {
        return new self("Encrypted media [{$disk}:{$path}] failed to decrypt — altered, truncated or wrongly keyed.");
    }
}
