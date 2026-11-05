<?php

namespace KandySoft\MediaKit\Data;

/**
 * What a video is, as `ffprobe` reads it.
 *
 * Width and height are the ones it is shown at, not the ones it is stored
 * at: a phone records upright video as a landscape frame plus "turn it a
 * quarter", and a frame described without the turn is a portrait clip laid
 * on its side.
 */
final readonly class VideoInfo
{
    public function __construct(
        public int $width,
        public int $height,
        public ?float $duration,
        public bool $hasAudio,
        /** HLG or PQ: needs tone mapping, or the copy comes out grey and flat. */
        public bool $hdr = false,
        public ?float $fps = null,
        /** The transfer function as ffprobe names it (`arib-std-b67`, `smpte2084`, `bt709`…). */
        public ?string $transfer = null,
    ) {}

    /**
     * The size of a copy whose longer side is at most `$max` — never larger
     * than the source, and even on both sides, which H.264 in 4:2:0 needs.
     *
     * @return array{0: int, 1: int}
     */
    public function fitWithin(int $max): array
    {
        $scale = $max > 0 ? min(1.0, $max / max($this->width, $this->height)) : 1.0;

        return [
            self::even($this->width * $scale),
            self::even($this->height * $scale),
        ];
    }

    private static function even(float $value): int
    {
        return max(2, 2 * (int) round($value / 2));
    }
}
