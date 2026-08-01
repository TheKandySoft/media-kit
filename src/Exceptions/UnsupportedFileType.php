<?php

namespace KandySoft\MediaKit\Exceptions;

use KandySoft\MediaKit\Enums\MediaType;
use RuntimeException;

final class UnsupportedFileType extends RuntimeException
{
    public static function extension(string $extension): self
    {
        $supported = [];

        foreach (MediaType::extensionMap() as $extensions) {
            $supported = [...$supported, ...$extensions];
        }

        sort($supported);

        return new self(sprintf(
            'Cannot store a ".%s" file. Supported extensions: %s.',
            ltrim($extension, '.'),
            implode(', ', $supported),
        ));
    }
}
