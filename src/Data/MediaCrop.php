<?php

namespace KandySoft\MediaKit\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * The part of a picture that has to be seen, chosen by a person.
 *
 * A rendition used to be cut from the middle of its original — or from one
 * corner for every file, by configuration. A wide photo of a model asked for
 * as a tall card then shows whatever happens to stand in the middle, and no
 * setting can say "her, not the wall".
 *
 * The area is kept in fractions of the original, not in pixels, and the
 * original itself is never cut: a storefront changes its proportions, and a
 * picture cropped for yesterday's card cannot be uncropped for tomorrow's.
 * Asked for at the area's own proportions, a rendition is exactly the area;
 * asked for at others, it is the smallest frame of those proportions that
 * holds the area, centred on it — and where the original is too small for
 * that, the largest one it has, still centred on the area.
 *
 * @implements Arrayable<string, float>
 */
final readonly class MediaCrop implements Arrayable, JsonSerializable
{
    /**
     * Below this a side is a slip of the mouse rather than a choice.
     */
    private const MIN_SIDE = 0.01;

    private function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    /**
     * An area from fractions of the original, kept inside it.
     */
    public static function of(float $x, float $y, float $width, float $height): self
    {
        $width = self::round(min(1.0, max(self::MIN_SIDE, $width)));
        $height = self::round(min(1.0, max(self::MIN_SIDE, $height)));

        return new self(
            self::round(min(1.0 - $width, max(0.0, $x))),
            self::round(min(1.0 - $height, max(0.0, $y))),
            $width,
            $height,
        );
    }

    /**
     * The whole picture — what an area is reset to.
     */
    public static function whole(): self
    {
        return new self(0.0, 0.0, 1.0, 1.0);
    }

    /**
     * From the shape a form posts or a column holds; anything else is no area.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($value[$key]) || ! is_numeric($value[$key])) {
                return null;
            }
        }

        return self::of((float) $value['x'], (float) $value['y'], (float) $value['width'], (float) $value['height']);
    }

    public function isWhole(): bool
    {
        return $this->width >= 0.9999 && $this->height >= 0.9999;
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $this->key() === $other->key();
    }

    /**
     * A stable spelling of the area, for a rendition's path.
     */
    public function key(): string
    {
        return sprintf('%.4f,%.4f,%.4f,%.4f', $this->x, $this->y, $this->width, $this->height);
    }

    /**
     * The area in pixels of an original of the given size.
     *
     * @return array{0: int, 1: int}  width and height
     */
    public function size(int $originalWidth, int $originalHeight): array
    {
        return [
            max(1, (int) round($this->width * $originalWidth)),
            max(1, (int) round($this->height * $originalHeight)),
        ];
    }

    /**
     * What a rendition of the given proportions is cut from, in pixels.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function frame(int $originalWidth, int $originalHeight, float $ratio): array
    {
        [$x, $y, $width, $height] = $this->frameIn($originalWidth, $originalHeight, $ratio);

        $width = max(1, min($originalWidth, (int) round($width)));
        $height = max(1, min($originalHeight, (int) round($height)));

        return [
            'x' => max(0, min($originalWidth - $width, (int) round($x))),
            'y' => max(0, min($originalHeight - $height, (int) round($y))),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Where the area's centre sits inside a rendition of the given proportions,
     * in fractions of it.
     *
     * What a page needs when it shows the rendition in a box of yet other
     * proportions: as `object-position`, it keeps the chosen part in view
     * instead of the middle of the frame.
     *
     * @return array{x: float, y: float}
     */
    public function focus(int $originalWidth, int $originalHeight, float $ratio): array
    {
        [$x, $y, $width, $height] = $this->frameIn($originalWidth, $originalHeight, $ratio);

        $centreX = ($this->x + $this->width / 2) * $originalWidth;
        $centreY = ($this->y + $this->height / 2) * $originalHeight;

        return [
            'x' => self::round(min(1.0, max(0.0, ($centreX - $x) / max(1.0, $width)))),
            'y' => self::round(min(1.0, max(0.0, ($centreY - $y) / max(1.0, $height)))),
        ];
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function toArray(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * The frame before rounding: the smallest one of the given proportions
     * holding the area, shrunk to fit the original, centred on the area and
     * moved back inside the original where it sticks out.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}  x, y, width, height
     */
    private function frameIn(int $originalWidth, int $originalHeight, float $ratio): array
    {
        $areaWidth = max(1.0, $this->width * $originalWidth);
        $areaHeight = max(1.0, $this->height * $originalHeight);
        $ratio = $ratio > 0 ? $ratio : $areaWidth / $areaHeight;

        if ($areaWidth / $areaHeight < $ratio) {
            $width = $areaHeight * $ratio;
            $height = $areaHeight;
        } else {
            $width = $areaWidth;
            $height = $areaWidth / $ratio;
        }

        $scale = min(1.0, $originalWidth / $width, $originalHeight / $height);
        $width *= $scale;
        $height *= $scale;

        $centreX = ($this->x + $this->width / 2) * $originalWidth;
        $centreY = ($this->y + $this->height / 2) * $originalHeight;

        return [
            min($originalWidth - $width, max(0.0, $centreX - $width / 2)),
            min($originalHeight - $height, max(0.0, $centreY - $height / 2)),
            $width,
            $height,
        ];
    }

    private static function round(float $value): float
    {
        return round($value, 4);
    }
}
