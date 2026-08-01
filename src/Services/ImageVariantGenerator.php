<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Storage\MediaDisk;
use KandySoft\MediaKit\Support\ImageFactory;

/**
 * Produces resized renditions on demand and remembers where they live.
 *
 * Nothing is generated at upload time: the first request for a size creates the
 * file, every later request is served straight from the disk.
 */
final class ImageVariantGenerator
{
    public function __construct(
        private readonly ImageFactory $images,
        private readonly Config $config,
    ) {}

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
