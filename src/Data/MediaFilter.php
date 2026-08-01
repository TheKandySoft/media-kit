<?php

namespace KandySoft\MediaKit\Data;

use KandySoft\MediaKit\Enums\MediaType;

/**
 * Which files to fetch for a model, and in which language to caption them.
 *
 * Replaces the loose array of magic string keys the read API used to take:
 * a typo in 'trans_locale' silently returned unlocalised captions, and there
 * was no way to tell "any locale" from "only files with no locale".
 */
final readonly class MediaFilter
{
    /**
     * @param  array<int, MediaType>  $types  empty means every type
     * @param  array<int, string>  $tags  empty means every tag
     * @param  string|null  $locale  files carrying this locale; null means any
     * @param  bool  $withoutLocale  only files with no locale of their own
     * @param  string|null  $captionLocale  language of alt/title; null means the first available
     * @param  bool  $withFallback  substitute the fallback image when nothing matches
     */
    public function __construct(
        public array $types = [],
        public array $tags = [],
        public ?string $locale = null,
        public bool $withoutLocale = false,
        public ?string $captionLocale = null,
        public bool $withFallback = false,
    ) {}

    public static function images(?string $captionLocale = null, bool $withFallback = false): self
    {
        return new self(
            types: [MediaType::Image],
            captionLocale: $captionLocale,
            withFallback: $withFallback,
        );
    }

    /**
     * @param  array<int, string>  $tags
     */
    public static function tagged(array $tags, ?string $captionLocale = null): self
    {
        return new self(tags: $tags, captionLocale: $captionLocale);
    }

    public function withTypes(MediaType ...$types): self
    {
        return new self($types, $this->tags, $this->locale, $this->withoutLocale, $this->captionLocale, $this->withFallback);
    }

    public function withTags(string ...$tags): self
    {
        return new self($this->types, $tags, $this->locale, $this->withoutLocale, $this->captionLocale, $this->withFallback);
    }

    public function inLocale(?string $locale): self
    {
        return new self($this->types, $this->tags, $locale, false, $this->captionLocale, $this->withFallback);
    }

    public function captionedIn(?string $captionLocale): self
    {
        return new self($this->types, $this->tags, $this->locale, $this->withoutLocale, $captionLocale, $this->withFallback);
    }

    public function fallingBack(bool $withFallback = true): self
    {
        return new self($this->types, $this->tags, $this->locale, $this->withoutLocale, $this->captionLocale, $withFallback);
    }

    public function wantsImages(): bool
    {
        return $this->types === [] || in_array(MediaType::Image, $this->types, true);
    }

    /**
     * @return array<int, string>
     */
    public function typeValues(): array
    {
        return array_map(static fn(MediaType $type): string => $type->value, $this->types);
    }
}
