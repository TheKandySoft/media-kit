<?php

namespace KandySoft\MediaKit\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use KandySoft\MediaKit\Enums\MediaType;

/**
 * A file as the outside world sees it.
 *
 * Deliberately narrow: a UUID, a URL and presentation metadata. The disk name,
 * the storage path and the owning model stay inside the package — they were
 * previously shipped to clients inside a `meta` blob built from the raw model.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class MediaResource implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, ImageVariant>  $variants  keyed by variant name
     * @param  array<string, MediaCaption>  $captions  keyed by locale
     */
    public function __construct(
        public string $uuid,
        public MediaType $type,
        public string $url,
        public ?string $originalName = null,
        public ?string $mimeType = null,
        public ?string $extension = null,
        public ?int $size = null,
        public ?int $width = null,
        public ?int $height = null,
        public int $position = 0,
        public ?MediaCaption $caption = null,
        public array $variants = [],
        public array $captions = [],
        /** A tiny copy as a `data:` URI — what is seen while the real one arrives. */
        public ?string $placeholder = null,
    ) {}

    public function aspect(): ?float
    {
        return $this->width && $this->height
            ? round($this->width / $this->height, 4)
            : null;
    }

    public function variant(string $name): ?ImageVariant
    {
        return $this->variants[$name] ?? null;
    }

    /**
     * The renditions as a `srcset`, widest last.
     *
     * Without this every device downloads the same picture, and the one that
     * suffers is the phone: it pulls a 700-pixel image to paint it 210 wide,
     * over the connection least able to afford it. The browser picks from this
     * list on its own — the only thing it needs is to be told the list exists.
     */
    public function srcset(): ?string
    {
        if ($this->variants === []) {
            return null;
        }

        $sources = [];

        foreach ($this->variants as $variant) {
            // Keyed by width: two renditions of the same width give the
            // browser nothing to choose between, while looking like a choice.
            $sources[$variant->width] = sprintf('%s %dw', $variant->url, $variant->width);
        }

        if (count($sources) < 2) {
            return null;
        }

        ksort($sources);

        return implode(', ', $sources);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'uuid' => $this->uuid,
            'type' => $this->type->value,
            'url' => $this->url,
            'original_name' => $this->originalName,
            'alt' => $this->caption?->alt,
            'title' => $this->caption?->title,
            'position' => $this->position,
            'captions' => array_map(
                static fn(MediaCaption $caption): array => $caption->toArray(),
                $this->captions,
            ),
        ];

        if ($this->width !== null) {
            $payload['width'] = $this->width;
        }

        if ($this->height !== null) {
            $payload['height'] = $this->height;
        }

        if ($this->aspect() !== null) {
            $payload['aspect'] = $this->aspect();
        }

        if ($this->mimeType !== null) {
            $payload['mime_type'] = $this->mimeType;
        }

        if ($this->extension !== null) {
            $payload['extension'] = $this->extension;
        }

        if ($this->size !== null) {
            $payload['size'] = $this->size;
        }

        if ($this->variants !== []) {
            $payload['variants'] = array_map(
                static fn(ImageVariant $variant): array => $variant->toArray(),
                $this->variants,
            );
        }

        if (($srcset = $this->srcset()) !== null) {
            $payload['srcset'] = $srcset;
        }

        if ($this->placeholder !== null) {
            $payload['placeholder'] = $this->placeholder;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
