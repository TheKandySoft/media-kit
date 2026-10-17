<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Contracts\RenditionQuality;

/**
 * Quality per format from `image.quality`; a format left out gets the
 * encoder's default.
 */
final class ConfiguredRenditionQuality implements RenditionQuality
{
    public function __construct(private readonly Config $config) {}

    public function for(string $format): ?int
    {
        $value = $this->config->get('media-kit.image.quality.' . strtolower($format));

        return is_numeric($value) ? (int) $value : null;
    }
}
