<?php

namespace KandySoft\MediaKit\Contracts;

/**
 * How hard a rendition of a format is compressed.
 *
 * The default reads `image.quality` from configuration
 * (`ConfiguredRenditionQuality`). A host that lets someone choose it — a
 * setting in an admin panel — binds its own implementation, asked at the
 * moment of encoding: a value copied into configuration at boot would stay
 * stale for the whole life of a long-running worker.
 *
 * Quality is part of a rendition's path, so a new answer produces new files
 * instead of overwriting the old ones.
 */
interface RenditionQuality
{
    /**
     * 1–100, or null for the encoder's own default (and for lossless formats).
     */
    public function for(string $format): ?int;
}
