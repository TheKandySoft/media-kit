<?php

namespace KandySoft\MediaKit\Data;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * One entry of a media collection being synchronised.
 *
 * Either a new file to store, or the UUID of one already stored that should
 * survive the sync — anything absent from the list is deleted. Captions travel
 * with the entry so a single call can create the file and its translations.
 */
final readonly class MediaUpload
{
    /**
     * @param  array<int, MediaCaption>  $captions
     */
    private function __construct(
        public ?UploadedFile $file,
        public ?string $uuid,
        public ?string $tag,
        public ?string $locale,
        public array $captions,
    ) {
        if ($this->file === null && $this->uuid === null) {
            throw new InvalidArgumentException('A media upload needs either a file to store or the uuid of one to keep.');
        }
    }

    /**
     * @param  array<int, MediaCaption>  $captions
     */
    public static function file(
        UploadedFile $file,
        ?string $tag = null,
        ?string $locale = null,
        array $captions = [],
    ): self {
        return new self($file, null, $tag, $locale, $captions);
    }

    /**
     * @param  array<int, MediaCaption>  $captions
     */
    public static function keep(
        string $uuid,
        ?string $tag = null,
        ?string $locale = null,
        array $captions = [],
    ): self {
        return new self(null, $uuid, $tag, $locale, $captions);
    }

    /**
     * Build from the shape a form posts: a file input plus optional captions.
     *
     * @param  array{file?: UploadedFile|null, uuid?: string|null, tag?: string|null, locale?: string|null, captions?: array<string, array{alt?: string|null, title?: string|null}>}  $item
     */
    public static function fromArray(array $item): self
    {
        $captions = [];

        foreach ($item['captions'] ?? [] as $locale => $attributes) {
            $captions[] = MediaCaption::fromArray((string) $locale, $attributes);
        }

        $file = $item['file'] ?? null;

        return $file instanceof UploadedFile
            ? self::file($file, $item['tag'] ?? null, $item['locale'] ?? null, $captions)
            : self::keep((string) ($item['uuid'] ?? ''), $item['tag'] ?? null, $item['locale'] ?? null, $captions);
    }

    public function isNewFile(): bool
    {
        return $this->file !== null;
    }
}
