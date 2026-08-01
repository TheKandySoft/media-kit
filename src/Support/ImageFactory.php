<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Builds intervention/image instances.
 *
 * The backend is configurable rather than hardcoded to Imagick: a host that
 * only has GD compiled in should still be able to install this package.
 */
final class ImageFactory
{
    private ?ImageManager $manager = null;

    public function __construct(private readonly Config $config) {}

    public function fromContents(string $contents): ImageInterface
    {
        return $this->manager()->read($contents);
    }

    public function fromUploadedFile(UploadedFile $file): ImageInterface
    {
        return $this->manager()->read($file->getRealPath());
    }

    private function manager(): ImageManager
    {
        return $this->manager ??= new ImageManager(
            $this->config->get('media-kit.image.driver', 'imagick') === 'gd'
                ? new GdDriver()
                : new ImagickDriver(),
        );
    }
}
