<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * A tiny copy of an image, small enough to travel inside the page.
 *
 * Until the real file arrives a card has nothing to show. The gap is short but
 * it is exactly what a visitor reads as slowness, and what a layout-shift
 * score measures — so something has to occupy the space, and it may as well be
 * the picture, blurred.
 *
 * No blur filter is applied: an image a dozen pixels wide, stretched by the
 * browser to fill a card, is blurred by the stretching. Filtering it would cost
 * time at upload and change nothing on screen.
 */
final class PlaceholderFactory
{
    public function __construct(
        private readonly ImageFactory $images,
        private readonly Config $config,
    ) {}

    public function forUploadedFile(UploadedFile $file): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->encode($this->images->fromUploadedFile($file));
        } catch (Throwable) {
            // A placeholder is a nicety. Refusing the upload because the
            // thumbnail of it could not be produced would not be.
            return null;
        }
    }

    /**
     * From a picture already read — without touching it: the caller keeps
     * using it.
     */
    public function forImage(\Intervention\Image\Interfaces\ImageInterface $image): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->encode(clone $image);
        } catch (Throwable) {
            return null;
        }
    }

    public function forContents(string $contents): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->encode($this->images->fromContents($contents));
        } catch (Throwable) {
            return null;
        }
    }

    private function encode(\Intervention\Image\Interfaces\ImageInterface $image): ?string
    {
        $width = max(4, (int) $this->config->get('media-kit.image.placeholder_width', 16));

        if (! $this->enabled()) {
            return null;
        }

        $encoded = (string) $image->scaleDown(width: $width)->toWebp(quality: 50);

        // A placeholder that outgrows its purpose is just an extra image on the
        // page. Better none than one the page has to carry into every card.
        $limit = (int) $this->config->get('media-kit.image.placeholder_max_bytes', 2048);

        if (strlen($encoded) > $limit) {
            return null;
        }

        return 'data:image/webp;base64,' . base64_encode($encoded);
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('media-kit.image.placeholder', true);
    }
}
