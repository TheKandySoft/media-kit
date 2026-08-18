<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Routing\UrlGenerator;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\ImageVariant;
use KandySoft\MediaKit\Data\LinkLifetime;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaPolicy;
use KandySoft\MediaKit\Data\MediaResource;
use KandySoft\MediaKit\Data\VariantSize;
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
        private readonly UrlGenerator $urls,
        private readonly ImageVariantGenerator $variants,
    ) {}

    public function make(
        MediaFile $media,
        MediaFilter $filter = new MediaFilter(),
        ImageSize $size = new ImageSize(),
        ?LinkLifetime $lifetime = null,
    ): MediaResource {
        $captions = $this->captions($media);
        $policy = $this->policy($media, $lifetime);

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
        );
    }

    /**
     * A permanent URL for public files, a temporary one for private files, and
     * a signed route when the driver can mint neither — a local private disk,
     * typically, where the application has to stream the bytes itself.
     */
    private function url(MediaFile $media, MediaPolicy $policy): string
    {
        $url = $this->storage->disk($media->disk)->url($media->path);

        if ($url !== null) {
            return $url;
        }

        return $this->routesEnabled()
            ? $this->urls->signedRoute(
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

        foreach ($this->variants->sizesFor($media->width, $media->height, $size) as $variant) {
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
            if ($existing !== null && ($url = $disk->url($existing->path)) !== null) {
                return $url;
            }
        }

        if (! $this->routesEnabled()) {
            return null;
        }

        return $this->urls->signedRoute('media-kit.variant', [
            'uuid' => $media->uuid,
            'width' => $variant->width,
            'height' => $variant->height,
            'format' => $variant->format,
            'v' => $this->version($media),
        ], $policy->variantUrlExpiry());
    }

    private function storedVariant(MediaFile $media, VariantSize $variant): ?MediaFile
    {
        return $media->variants
            ->first(fn(MediaFile $stored): bool => $stored->width === $variant->width
                && $stored->height === $variant->height
                && $stored->extension === $variant->format);
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
