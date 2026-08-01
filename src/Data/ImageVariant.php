<?php

namespace KandySoft\MediaKit\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A resized rendition of an image.
 *
 * The URL is signed and points at the on-demand endpoint: the file itself is
 * only produced the first time somebody follows the link, and reused after.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ImageVariant implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $width,
        public int $height,
        public string $url,
    ) {}

    public function aspect(): float
    {
        return $this->height > 0 ? round($this->width / $this->height, 4) : 1.0;
    }

    /**
     * @return array{width: int, height: int, aspect: float, url: string}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'aspect' => $this->aspect(),
            'url' => $this->url,
        ];
    }

    /**
     * @return array{width: int, height: int, aspect: float, url: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
