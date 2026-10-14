<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Imagick;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Modifiers\CoverModifier;
use Throwable;

/**
 * What is done to a picture before it is published — the same for renditions
 * and for originals, so the two never disagree about colour or crop.
 *
 * The Imagick-only steps (colour conversion, Lanczos) fall back to the
 * library's own behaviour on GD, which can do neither.
 */
final class ImageOperations
{
    /**
     * Crop anchors the image library understands.
     */
    public const POSITIONS = [
        'center', 'top', 'bottom', 'left', 'right',
        'top-left', 'top-right', 'bottom-left', 'bottom-right',
    ];

    public function __construct(private readonly Config $config) {}

    /**
     * Convert a picture with an embedded colour profile to sRGB.
     *
     * A phone shoots in Display P3 and says so in an ICC profile. Stripping the
     * profile without converting — which is what removing metadata does — leaves
     * P3 numbers read as sRGB: reds and greens come out dull and shifted, and on
     * clothing the colour is the product. Converted first, the numbers mean in
     * sRGB what they meant in P3, and the profile can go.
     */
    public function toSrgb(ImageInterface $image): void
    {
        if (! $this->config->get('media-kit.image.srgb', true)) {
            return;
        }

        $profile = $this->srgbProfile();

        foreach ($image as $frame) {
            $native = $frame->native();

            if (! $native instanceof Imagick) {
                return;
            }

            try {
                if ($native->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                    $native->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                }

                if ($native->getImageProfiles('icc', false) !== [] && $profile !== null) {
                    $native->profileImage('icc', $profile);
                }
            } catch (Throwable) {
                // A profile Imagick cannot read is left as it is: a picture with
                // slightly wrong colour is better than no picture.
            }
        }
    }

    /**
     * Crop to the asked-for proportions at the configured anchor and resize.
     *
     * With Imagick and `resample = lanczos` the resize uses the Lanczos filter
     * rather than the library's plain scale — the difference is fine detail:
     * fabric weave, stitching, print on a label stay crisp instead of going soft.
     */
    public function cover(ImageInterface $image, int $width, int $height): void
    {
        $position = $this->cropPosition();

        if (! $this->usesLanczos($image)) {
            $image->cover($width, $height, $position);

            return;
        }

        $crop = (new CoverModifier($width, $height, $position))->getCropSize($image);

        foreach ($image as $frame) {
            /** @var Imagick $native */
            $native = $frame->native();
            $native->cropImage($crop->width(), $crop->height(), $crop->pivot()->x(), $crop->pivot()->y());
            $native->setImagePage(0, 0, 0, 0);
            $native->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }
    }

    /**
     * Encoder options for a format. Options a format does not know are dropped
     * by the encoder itself, so one set serves every format.
     *
     * @return array<string, int|bool>
     */
    public function encoderOptions(string $format, ?int $quality = null): array
    {
        $progressive = (bool) $this->config->get('media-kit.image.progressive', true);

        $options = [
            'strip' => (bool) $this->config->get('media-kit.image.strip_metadata', true),
            'progressive' => $progressive,
            'interlaced' => $progressive,
        ];

        $quality ??= is_numeric($value = $this->config->get("media-kit.image.quality.{$format}")) ? (int) $value : null;

        if ($quality !== null) {
            $options['quality'] = max(1, min(100, $quality));
        }

        return $options;
    }

    public function cropPosition(): string
    {
        $position = strtolower((string) $this->config->get('media-kit.image.crop_position', 'center'));

        return in_array($position, self::POSITIONS, true) ? $position : 'center';
    }

    /**
     * Everything here that changes the pixels of a rendition — part of its path.
     *
     * @return array<string, mixed>
     */
    public function fingerprint(): array
    {
        return [
            'crop' => $this->cropPosition(),
            'resample' => strtolower((string) $this->config->get('media-kit.image.resample', 'lanczos')),
            'srgb' => (bool) $this->config->get('media-kit.image.srgb', true),
            'sharpen' => (int) $this->config->get('media-kit.image.sharpen', 0),
            'version' => (int) $this->config->get('media-kit.image.version', 1),
        ];
    }

    private function usesLanczos(ImageInterface $image): bool
    {
        return strtolower((string) $this->config->get('media-kit.image.resample', 'lanczos')) === 'lanczos'
            && $image->core()->native() instanceof Imagick;
    }

    private function srgbProfile(): ?string
    {
        $path = dirname(__DIR__, 2) . '/resources/icc/sRGB-v2-micro.icc';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
