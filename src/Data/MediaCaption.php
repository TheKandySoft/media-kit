<?php

namespace KandySoft\MediaKit\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The translatable part of a file: what a screen reader says and what a tooltip
 * shows, for one locale.
 *
 * @implements Arrayable<string, string|null>
 */
final readonly class MediaCaption implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $locale,
        public ?string $alt = null,
        public ?string $title = null,
    ) {}

    /**
     * @param  array{alt?: string|null, title?: string|null}  $attributes
     */
    public static function fromArray(string $locale, array $attributes): self
    {
        return new self(
            locale: $locale,
            alt: $attributes['alt'] ?? null,
            title: $attributes['title'] ?? null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->alt === null && $this->title === null;
    }

    /**
     * @return array{alt: string|null, title: string|null}
     */
    public function toArray(): array
    {
        return [
            'alt' => $this->alt,
            'title' => $this->title,
        ];
    }

    /**
     * @return array{alt: string|null, title: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
