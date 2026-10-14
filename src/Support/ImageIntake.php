<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Interfaces\ImageInterface;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Storage\TemporaryFile;
use Throwable;

/**
 * An uploaded picture, read once and — if the host asks — reworked before it is
 * stored.
 *
 * Reading: the upload used to be decoded twice, once for its size and once for
 * its placeholder. A 12-megapixel photo costs a few hundred milliseconds and
 * about a hundred megabytes to decode, so once is the budget.
 *
 * Reworking (`image.original`): a phone uploads 4000 × 3000 at 6 MB with the
 * camera's EXIF — GPS included — and that original is what a direct link
 * serves. With limits set the original is scaled down to them, converted to
 * sRGB and re-encoded; with `strip_metadata` its EXIF goes. Nothing is
 * re-encoded when nothing asks for it: every lossy encode loses a little.
 */
final class ImageIntake
{
    /**
     * Formats an original may be re-encoded in. GIF is left alone (frames),
     * as are BMP and TIFF, which are archival rather than published.
     */
    private const REWORKABLE = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public function __construct(
        private readonly ImageFactory $images,
        private readonly ImageOperations $operations,
        private readonly PlaceholderFactory $placeholders,
        private readonly Config $config,
    ) {}

    public function read(MediaType $type, UploadedFile $file, string $extension, bool $withPlaceholder = true): IntakeResult
    {
        if (! $type->isImage()) {
            return new IntakeResult($file, null, null, null);
        }

        try {
            $image = $this->images->fromUploadedFile($file);
        } catch (Throwable) {
            // Something the library cannot open (an SVG, a broken file) is
            // stored as it came, without size or placeholder — as before.
            return new IntakeResult($file, null, null, null);
        }

        $temporary = $this->rework($image, $extension, $file->getClientOriginalName());

        return new IntakeResult(
            $temporary?->toUploadedFile($file->getClientOriginalName()) ?? $file,
            $image->width(),
            $image->height(),
            $withPlaceholder ? $this->placeholders->forImage($image) : null,
            $temporary,
        );
    }

    /**
     * Re-encode the original when it is larger than the limits or its metadata
     * has to go. Returns the reworked copy, or null when the upload stays as is.
     */
    private function rework(ImageInterface $image, string $extension, string $name): ?TemporaryFile
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::REWORKABLE, true) || count($image) > 1) {
            return null;
        }

        $maxWidth = $this->limit('max_width');
        $maxHeight = $this->limit('max_height');
        $strip = (bool) $this->config->get('media-kit.image.original.strip_metadata', false);

        $tooLarge = ($maxWidth !== null && $image->width() > $maxWidth)
            || ($maxHeight !== null && $image->height() > $maxHeight);

        if (! $tooLarge && ! $strip) {
            return null;
        }

        try {
            $this->operations->toSrgb($image);

            if ($tooLarge) {
                $image->scaleDown($maxWidth, $maxHeight);
            }

            $quality = $this->config->get('media-kit.image.original.quality');
            $options = $this->operations->encoderOptions($extension, is_numeric($quality) ? (int) $quality : null);
            $options['strip'] = $strip;

            $stream = fopen('php://temp', 'r+b');
            fwrite($stream, (string) $image->encodeByExtension($extension, ...$options));
            rewind($stream);

            return TemporaryFile::fromStream($stream, $name);
        } catch (Throwable) {
            // The original as it came is still a valid upload.
            return null;
        }
    }

    private function limit(string $key): ?int
    {
        $value = $this->config->get("media-kit.image.original.{$key}");

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
