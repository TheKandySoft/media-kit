<?php

namespace KandySoft\MediaKit\Exceptions;

use RuntimeException;

final class MediaNotFound extends RuntimeException
{
    public static function uuid(string $uuid): self
    {
        return new self("No media file with uuid [{$uuid}].");
    }

    public static function path(string $disk, string $path): self
    {
        return new self("No file at [{$path}] on disk [{$disk}].");
    }

    public static function fallbackImage(): self
    {
        return new self('No fallback image is available. Configure media-kit.fallback_image or ship resources/default.webp.');
    }
}
