<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaCrop;
use KandySoft\MediaKit\Data\VariantSize;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Storage\MediaDisk;
use KandySoft\MediaKit\Support\ImageFactory;
use KandySoft\MediaKit\Support\ImageOperations;

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
        private readonly ImageOperations $operations,
    ) {}

    /**
     * Which renditions exist for a file asked for at a given size.
     *
     * The single source of truth for that question: describing a variant and
     * generating it have to agree on the numbers, and they used to work them
     * out separately.
     *
     * With a chosen area the area is the picture: a side left free follows its
     * proportions, and no rendition is larger than the frame it is cut from.
     *
     * @return list<VariantSize>
     */
    public function sizesFor(?int $mediaWidth, ?int $mediaHeight, ImageSize $requested, ?MediaCrop $area = null): array
    {
        $area = $mediaWidth && $mediaHeight && $area !== null && ! $area->isWhole() ? $area : null;
        [$visibleWidth, $visibleHeight] = $area?->size((int) $mediaWidth, (int) $mediaHeight) ?? [$mediaWidth, $mediaHeight];

        [$width, $height] = $requested->resolve(
            $visibleWidth,
            $visibleHeight,
            (int) $this->config->get('media-kit.image.default_width', 300),
            (int) $this->config->get('media-kit.image.default_height', 300),
        );

        if ($area !== null) {
            $frame = $area->frame((int) $mediaWidth, (int) $mediaHeight, $width / max(1, $height));
            [$mediaWidth, $mediaHeight] = [$frame['width'], $frame['height']];
        }

        $format = $requested->format ?? (string) $this->config->get('media-kit.image.format', 'webp');
        $sizes = [];

        foreach ((array) $this->config->get('media-kit.image.variants', []) as $name => $multiplier) {
            [$variantWidth, $variantHeight] = $this->fit(
                (int) round($width * (float) $multiplier),
                (int) round($height * (float) $multiplier),
                $mediaWidth,
                $mediaHeight,
            );

            $sizes[] = new VariantSize(
                name: (string) $name,
                width: $variantWidth,
                height: $variantHeight,
                format: $format,
            );
        }

        return $sizes;
    }

    /**
     * Shrink a rendition that would be larger than its original.
     *
     * Enlarging cannot add detail, only blur: a 1200-pixel photo stretched to
     * 2400 looks worse than the 1200 it was, and costs four times the bytes.
     * The proportions are kept, so the crop is the same; only the scale stops
     * at the original. Two variants may then end up the same size, and the
     * srcset simply lists that width once.
     *
     * @return array{0: int, 1: int}
     */
    private function fit(int $width, int $height, ?int $mediaWidth, ?int $mediaHeight): array
    {
        $width = max(1, $width);
        $height = max(1, $height);

        if ($this->config->get('media-kit.image.upscale', false) || ! $mediaWidth || ! $mediaHeight) {
            return [$width, $height];
        }

        $scale = min(1.0, $mediaWidth / $width, $mediaHeight / $height);

        if ($scale >= 1.0) {
            return [$width, $height];
        }

        return [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
    }

    /**
     * Deterministic path, so the same size always maps to the same file and a
     * second request never regenerates what the first one produced.
     *
     * The settings that shape the pixels — quality, crop, metadata, sharpening
     * — are part of it too. Otherwise a new quality would never reach a single
     * picture: the path would be the same, the file would exist, and the old
     * one would be served for good.
     *
     * So is the chosen area — only when there is one, which leaves the path of
     * every rendition made before areas existed as it was.
     */
    public function pathFor(string $originalPath, int $width, int $height, string $format, ?MediaCrop $area = null): string
    {
        $format = $this->normaliseFormat($originalPath, $format);
        $root = trim((string) $this->config->get('media-kit.directory', 'media'), '/');
        $crop = $area !== null && ! $area->isWhole() ? "|crop:{$area->key()}" : '';
        $hash = md5("{$originalPath}|{$width}x{$height}|{$format}|{$this->profile($format)}{$crop}");

        return "{$root}/variants/{$width}x{$height}/{$hash}.{$format}";
    }

    /**
     * Generate a rendition unless its file exists, for an original stored as
     * it came. Kept for callers outside the package; the package itself uses
     * `render()`, which also reads and writes encrypted files.
     */
    public function generate(MediaDisk $disk, string $originalPath, string $variantPath, int $width, int $height, string $format): void
    {
        if ($disk->exists($variantPath)) {
            return;
        }

        $disk->put($variantPath, $this->encode($disk->get($originalPath), $originalPath, $width, $height, $format));
    }

    /**
     * Make a rendition of a stored original and write it — decrypting the
     * original and encrypting the rendition where the disk asks for it.
     *
     * @return array{0: ?Envelope, 1: int, 2: string}  envelope, size and MIME type of the rendition
     */
    public function render(MediaDisk $disk, MediaFile $original, string $variantPath, int $width, int $height, string $format): array
    {
        $bytes = $this->encode(
            $disk->read((string) $original->path, Envelope::of($original)),
            (string) $original->path,
            $width,
            $height,
            $format,
            $original->crop,
        );

        $envelope = $disk->write($variantPath, $bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        return [$envelope, strlen($bytes), $mime];
    }

    private function encode(string $original, string $originalPath, int $width, int $height, string $format, ?MediaCrop $area = null): string
    {
        $format = $this->normaliseFormat($originalPath, $format);
        $image = $this->images->fromContents($original);

        // Colour first, before metadata goes: the profile it is converted by
        // is stripped together with the rest.
        $this->operations->toSrgb($image);
        $this->operations->cover($image, $width, $height, $area);

        $sharpen = (int) $this->config->get('media-kit.image.sharpen', 0);

        if ($sharpen > 0) {
            $image->sharpen(min(100, $sharpen));
        }

        return (string) $image->encodeByExtension($format, ...$this->operations->encoderOptions($format));
    }

    /**
     * Everything that decides how a rendition of this format looks.
     */
    private function profile(string $format): string
    {
        return json_encode([
            $this->operations->encoderOptions($format),
            $this->operations->fingerprint(),
        ], JSON_THROW_ON_ERROR);
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
