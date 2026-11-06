<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\ImageVariant;
use KandySoft\MediaKit\Data\LinkLifetime;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaPolicy;
use KandySoft\MediaKit\Data\MediaResource;
use KandySoft\MediaKit\Data\VariantSize;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Models\MediaFileTranslation;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Storage\MediaDisk;

/**
 * Turns a stored file into the DTO callers receive.
 *
 * This is the boundary: everything internal — disk name, path, owner — stops
 * here. Variant links are signed and point at the on-demand endpoint, so the
 * renditions themselves are only ever produced for sizes somebody asked for.
 * Those links are stable: the same file at the same size is always the same
 * address, which is what makes them cacheable and worth indexing. Stable is
 * not the same as frozen — the address carries the file's version, so
 * replacing the picture behind a uuid produces a new address instead of
 * leaving caches holding the old bytes.
 *
 * Lifetimes are not read from configuration here. They come from the disk the
 * file lives on, so the link and the cache header that describes it can never
 * be minted from two different numbers.
 *
 * A rendition that has already been produced is addressed directly on the
 * disk. Only one that does not exist yet needs the on-demand endpoint, and
 * with it a redirect, a signature check and a database lookup on the way to a
 * static file. An application that warms its renditions therefore never sends
 * a visitor through the application to fetch a picture at all.
 */
final class MediaResourceFactory
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly Config $config,
        private readonly SignedLinks $links,
        private readonly ImageVariantGenerator $variants,
        private readonly VideoRenditions $videos,
    ) {}

    public function make(
        MediaFile $media,
        MediaFilter $filter = new MediaFilter(),
        ImageSize $size = new ImageSize(),
        ?LinkLifetime $lifetime = null,
    ): MediaResource {
        $captions = $this->captions($media);
        $policy = $this->policy($media, $lifetime);

        if ($media->type === MediaType::Video && ! $media->is_variant) {
            return $this->video($media, $captions, $filter, $size, $lifetime, $policy);
        }

        return new MediaResource(
            uuid: $media->uuid,
            type: $media->type,
            url: $this->url($media, $policy),
            originalName: $media->original_name,
            mimeType: $media->mime_type,
            extension: $media->extension,
            size: $media->size,
            width: $media->width,
            height: $media->height,
            position: $media->position,
            caption: $this->selectCaption($captions, $filter->captionLocale),
            variants: $media->type->isImage() ? $this->variants($media, $size, $policy) : [],
            captions: $captions,
            placeholder: $media->placeholder,
            crop: $media->type->isImage() ? $media->crop : null,
            cover: (bool) $media->is_cover,
        );
    }

    /**
     * A video as the page needs it: the address of its web copy once one is
     * made — the original until then — and its poster as a picture.
     *
     * The captions are the video's; the poster carries them too, so a page
     * that shows only the poster still has its alt text.
     *
     * @param  array<string, MediaCaption>  $captions
     */
    private function video(
        MediaFile $media,
        array $captions,
        MediaFilter $filter,
        ImageSize $size,
        ?LinkLifetime $lifetime,
        MediaPolicy $policy,
    ): MediaResource {
        $copy = $this->videos->copyOf($media);
        $poster = $this->videos->posterOf($media);
        $served = $copy ?? $media;
        $caption = $this->selectCaption($captions, $filter->captionLocale);
        $posterResource = null;

        if ($poster !== null) {
            $poster->setRelation('captions', $media->captions);
            $posterResource = $this->make($poster, $filter, $size, $lifetime);
        }

        return new MediaResource(
            uuid: $media->uuid,
            type: $media->type,
            url: $this->url($served, $policy),
            originalName: $media->original_name,
            mimeType: $served->mime_type,
            extension: $served->extension,
            size: $served->size,
            width: $served->width ?? $media->width,
            height: $served->height ?? $media->height,
            position: $media->position,
            caption: $caption,
            captions: $captions,
            placeholder: $poster?->placeholder ?? $media->placeholder,
            crop: $media->crop,
            duration: $media->duration,
            poster: $posterResource,
            fit: $media->fit,
            focusPoint: $this->videoFocus($media, $size),
            cover: (bool) $media->is_cover,
            posterAt: $media->poster_at,
            posterOwn: $media->poster_uuid !== null && $poster?->uuid === $media->poster_uuid,
        );
    }

    /**
     * Where a video filling a frame of the requested proportions has to be
     * moved to keep the chosen area in it — as `object-position`, in fractions.
     *
     * The frame is the one a picture of the same proportions would be cut to
     * (`MediaCrop::frame`), and the poster is cut to it too: what a page shows
     * before the video plays and while it plays is the same picture.
     *
     * @return array{x: float, y: float}|null
     */
    private function videoFocus(MediaFile $video, ImageSize $size): ?array
    {
        $crop = $video->crop;
        $width = (int) $video->width;
        $height = (int) $video->height;

        if ($crop === null || $width <= 0 || $height <= 0) {
            return null;
        }

        $target = $this->variants->sizesFor($width, $height, $size, $crop)[0] ?? null;

        if ($target === null || $target->height <= 0) {
            return null;
        }

        $frame = $crop->frame($width, $height, $target->width / $target->height);
        $position = static fn(int $offset, int $span, int $whole): float => $whole > $span
            ? round($offset / ($whole - $span), 4)
            : 0.5;

        return [
            'x' => $position($frame['x'], $frame['width'], $width),
            'y' => $position($frame['y'], $frame['height'], $height),
        ];
    }

    /**
     * A permanent URL for public files, a temporary one for private files, and
     * a signed route when the driver can mint neither — a local private disk,
     * typically, where the application has to stream the bytes itself.
     */
    private function url(MediaFile $media, MediaPolicy $policy): string
    {
        // The policy, not the disk: a caller's shorter lifetime has to reach the
        // store's own pre-signed link too, not only the signed route.
        $url = $this->storage->disk($media->disk)->url($media->path, $policy->temporaryUrlExpiry(), $media->encryption !== null);

        if ($url !== null) {
            return $url;
        }

        return $this->routesEnabled()
            ? $this->links->sign(
                'media-kit.file',
                ['uuid' => $media->uuid, 'v' => $this->version($media)],
                $policy->temporaryUrlExpiry(),
            )
            : '';
    }

    /**
     * The policy this file is handed out under.
     *
     * The disk decides, unless the caller asked for something else for this
     * one read — a preview, an export — in which case the override replaces
     * the policy rather than sitting beside it.
     */
    private function policy(MediaFile $media, ?LinkLifetime $lifetime): MediaPolicy
    {
        $policy = $this->storage->disk($media->disk)->policy();

        return $lifetime === null ? $policy : $policy->with($lifetime);
    }

    /**
     * Which version of the file an address refers to.
     *
     * The serving routes are keyed by uuid, and `replace()` deliberately keeps
     * the uuid so existing references survive. Without a version in the
     * address that means the same address quietly starts returning different
     * bytes — and anyone who cached it keeps the old picture. The timestamp
     * changes on replacement, so the old address is simply orphaned.
     */
    private function version(MediaFile $media): int
    {
        return $media->updated_at?->getTimestamp() ?? 0;
    }

    /**
     * @return array<string, ImageVariant>
     */
    private function variants(MediaFile $media, ImageSize $size, MediaPolicy $policy): array
    {
        $disk = $this->storage->disk($media->disk);
        $variants = [];

        foreach ($this->variants->sizesFor($media->width, $media->height, $size, $media->crop) as $variant) {
            $url = $this->variantUrl($media, $variant, $policy, $disk);

            if ($url === null) {
                continue;
            }

            $variants[$variant->name] = new ImageVariant(
                width: $variant->width,
                height: $variant->height,
                url: $url,
            );
        }

        return $variants;
    }

    /**
     * The address of one rendition: the file itself when it exists, the
     * endpoint that would create it when it does not.
     *
     * Existence is answered from the recorded variants rather than by asking
     * the disk. A listing describes dozens of pictures at three sizes each, and
     * a `stat` per rendition would be dozens of round trips to object storage
     * for a question the database already holds the answer to.
     */
    private function variantUrl(
        MediaFile $media,
        VariantSize $variant,
        MediaPolicy $policy,
        MediaDisk $disk,
    ): ?string {
        if ($policy->public) {
            $existing = $this->storedVariant($media, $variant);

            // The path already contains the original's name, and replacing a
            // file gives it a new one, so this address needs no version of its
            // own: a replaced picture simply lives somewhere else.
            if ($existing !== null && ($url = $disk->url($existing->path, sealed: $existing->encryption !== null)) !== null) {
                return $url;
            }
        }

        if (! $this->routesEnabled()) {
            return null;
        }

        return $this->links->sign('media-kit.variant', [
            'uuid' => $media->uuid,
            'width' => $variant->width,
            'height' => $variant->height,
            'format' => $variant->format,
            'v' => $this->version($media),
        ], $policy->variantUrlExpiry());
    }

    /**
     * The stored rendition made with today's settings, if there is one.
     *
     * Matched by path, not by size: the path carries the rendition settings,
     * and a file of the right size made at yesterday's quality is exactly the
     * one that must not be served.
     */
    private function storedVariant(MediaFile $media, VariantSize $variant): ?MediaFile
    {
        $path = $this->variants->pathFor($media->path, $variant->width, $variant->height, $variant->format, $media->crop);

        return $media->variants->first(fn(MediaFile $stored): bool => $stored->path === $path);
    }

    /**
     * @return array<string, MediaCaption>
     */
    private function captions(MediaFile $media): array
    {
        $captions = [];

        foreach ($media->captions as $translation) {
            /** @var MediaFileTranslation $translation */
            $captions[$translation->locale] = new MediaCaption(
                locale: $translation->locale,
                alt: $translation->alt,
                title: $translation->title,
            );
        }

        return $captions;
    }

    /**
     * @param  array<string, MediaCaption>  $captions
     */
    private function selectCaption(array $captions, ?string $locale): ?MediaCaption
    {
        if ($locale !== null && isset($captions[$locale])) {
            return $captions[$locale];
        }

        return $captions === [] ? null : reset($captions);
    }

    private function routesEnabled(): bool
    {
        return (bool) $this->config->get('media-kit.routes.enabled', true);
    }

}
