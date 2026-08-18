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
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Models\MediaFileTranslation;

/**
 * Turns a stored file into the DTO callers receive.
 *
 * This is the boundary: everything internal — disk name, path, owner — stops
 * here. Variant links are signed and point at the on-demand endpoint, so the
 * renditions themselves are only ever produced for sizes somebody asked for.
 * Those links are stable: the same file at the same size is always the same
 * address, which is what makes them cacheable and worth indexing.
 *
 * Lifetimes are not read from configuration here. They come from the disk the
 * file lives on, so the link and the cache header that describes it can never
 * be minted from two different numbers.
 */
final class MediaResourceFactory
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly Config $config,
        private readonly UrlGenerator $urls,
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
                ['uuid' => $media->uuid],
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
     * @return array<string, ImageVariant>
     */
    private function variants(MediaFile $media, ImageSize $size, MediaPolicy $policy): array
    {
        if (! $this->routesEnabled()) {
            return [];
        }

        [$width, $height] = $size->resolve(
            $media->width,
            $media->height,
            (int) $this->config->get('media-kit.image.default_width', 300),
            (int) $this->config->get('media-kit.image.default_height', 300),
        );

        $format = $size->format ?? (string) $this->config->get('media-kit.image.format', 'webp');
        $variants = [];

        foreach ($this->config->get('media-kit.image.variants', []) as $name => $multiplier) {
            $variantWidth = max(1, (int) round($width * (float) $multiplier));
            $variantHeight = max(1, (int) round($height * (float) $multiplier));

            $variants[$name] = new ImageVariant(
                width: $variantWidth,
                height: $variantHeight,
                url: $this->urls->signedRoute('media-kit.variant', [
                    'uuid' => $media->uuid,
                    'width' => $variantWidth,
                    'height' => $variantHeight,
                    'format' => $format,
                ], $policy->variantUrlExpiry()),
            );
        }

        return $variants;
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
