<?php

namespace KandySoft\MediaKit\Data;

/**
 * The size an image is wanted at.
 *
 * Replaces the trailing `?int $width, ?int $height, string $format` triple that
 * every read call used to carry — three positional arguments that were easy to
 * transpose and impossible to name at the call site.
 *
 * Leaving a dimension null lets the other one drive the aspect ratio; leaving
 * both null falls back to the configured default size.
 */
final readonly class ImageSize
{
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public ?string $format = null,
    ) {}

    public static function of(?int $width, ?int $height, ?string $format = null): self
    {
        return new self(
            width: $width !== null && $width > 0 ? $width : null,
            height: $height !== null && $height > 0 ? $height : null,
            format: $format !== null && $format !== '' ? strtolower($format) : null,
        );
    }

    public static function square(int $side, ?string $format = null): self
    {
        return new self($side, $side, $format);
    }

    public function isEmpty(): bool
    {
        return $this->width === null && $this->height === null;
    }

    /**
     * Resolve to concrete pixels, using the original's own dimensions to keep
     * the aspect ratio when only one side was asked for.
     *
     * @return array{0: int, 1: int}
     */
    public function resolve(?int $originalWidth, ?int $originalHeight, int $defaultWidth, int $defaultHeight): array
    {
        if ($this->width !== null && $this->height !== null) {
            return [$this->width, $this->height];
        }

        $ratio = $originalWidth && $originalHeight ? $originalWidth / $originalHeight : null;

        if ($this->width !== null) {
            return [$this->width, (int) round($ratio ? $this->width / $ratio : $this->width)];
        }

        if ($this->height !== null) {
            return [(int) round($ratio ? $this->height * $ratio : $this->height), $this->height];
        }

        return [$defaultWidth, $defaultHeight];
    }
}
