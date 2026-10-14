<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Http\UploadedFile;
use KandySoft\MediaKit\Storage\TemporaryFile;

/**
 * An upload as it will be stored: the file itself (the original, or its
 * reworked copy), what it measures, and its placeholder.
 */
final class IntakeResult
{
    public function __construct(
        public readonly UploadedFile $file,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $placeholder,
        private readonly ?TemporaryFile $temporary = null,
    ) {}

    /**
     * Drop the reworked copy once it has been stored.
     */
    public function release(): void
    {
        $this->temporary?->delete();
    }
}
