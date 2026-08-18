<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\VariantSize;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Storage\MediaDisk;
use KandySoft\MediaKit\Support\ImageFactory;

/**
 * Produces resized renditions and remembers where they live.
 *
 * By default nothing is generated at upload time: the first request for a size
 * creates the file, every later request is served straight from the disk. An
 * application that would rather pay for this up front — a catalogue, where the
 * first visitor to every page is a search engine — can warm the renditions
 * instead, and then no request ever waits for one.
 */
final class ImageVariantGenerator
{
    public function __construct(
        private readonly ImageFactory $images,
        private readonly Config $config,
    ) {}

    /**
     * Which renditions exist for a file asked for at a given size.
     *
     * The single source of truth for that question: describing a variant and
     * generating it have to agree on the numbers, and they used to work them
     * out separately.
     *
     * @return list<VariantSize>
     */
    public function sizesFor(?int $mediaWidth, ?int $mediaHeight, ImageSize $requested): array
    {
        [$width, $height] = $requested->resolve(
            $mediaWidth,
            $mediaHeight,
            (int) $this->config->get('media-kit.image.default_width', 300),
            (int) $this->config->get('media-kit.image.default_height', 300),
        );

        $format = $requested->format ?? (string) $this->config->get('media-kit.image.format', 'webp');
        $sizes = [];

        foreach ((array) $this->config->get('media-kit.image.variants', []) as $name => $multiplier) {
            $sizes[] = new VariantSize(
                name: (string) $name,
                width: max(1, (int) round($width * (float) $multiplier)),
                height: max(1, (int) round($height * (float) $multiplier)),
                format: $format,
            );
        }

        return $sizes;
    }

    /**
     * Deterministic path, so the same size always maps to the same file and a
     * second request never regenerates what the first one produced.
     */
    public function pathFor(string $originalPath, int $width, int $height, string $format): string
    {
        $format = $this->normaliseFormat($originalPath, $format);
        $root = trim((string) $this->config->get('media-kit.directory', 'media'), '/');
        $hash = md5("{$originalPath}|{$width}x{$height}|{$format}");

        return "{$root}/variants/{$width}x{$height}/{$hash}.{$format}";
    }

    public function generate(MediaDisk $disk, string $originalPath, string $variantPath, int $width, int $height, string $format): void
    {
        if ($disk->exists($variantPath)) {
            return;
        }

        $image = $this->images->fromContents($disk->get($originalPath));

        $image->cover($width, $height);

        $disk->put($variantPath, (string) $image->encodeByExtension($this->normaliseFormat($originalPath, $format)));
    }

    /**
     * Fall back to the original extension when asked for a format we do not
     * recognise, rather than writing a file the encoder cannot produce.
     */
    private function normaliseFormat(string $originalPath, string $format): string
    {
        $format = strtolower(ltrim($format, '.'));

        return in_array($format, MediaType::Image->extensions(), true)
            ? $format
            : strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
    }
}
