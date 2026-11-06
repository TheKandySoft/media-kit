<?php

namespace KandySoft\MediaKit\Data;

use Illuminate\Http\UploadedFile;

/**
 * What a video's poster is made from: a moment of the video, or a picture of
 * its own.
 *
 * The poster is what a page shows before the video plays and where it does
 * not play at all — a list, a link preview, a visitor who asked for less
 * motion. A fixed second of every video is a fade-in for one clip and a
 * blurred turn for another, so a person picks it.
 *
 * A frame (`frame()`) puts aside a picture given before; a picture (`image()`)
 * stands until a frame is chosen again. `frame(null)` is the configured moment.
 */
final readonly class MediaPoster
{
    private function __construct(
        public ?float $at,
        public ?UploadedFile $image,
    ) {}

    /**
     * The frame at this second of the video; null for the configured one.
     */
    public static function frame(?float $at): self
    {
        return new self($at === null ? null : round(max(0.0, $at), 3), null);
    }

    /**
     * A picture uploaded to stand for the video.
     */
    public static function image(UploadedFile $image): self
    {
        return new self(null, $image);
    }

    /**
     * From the shape a form posts: `['at' => seconds]` or `['image' => file]`.
     * Anything else — nothing chosen.
     *
     * @param  mixed  $value
     */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        if (($value['image'] ?? null) instanceof UploadedFile) {
            return self::image($value['image']);
        }

        if (array_key_exists('at', $value)) {
            return self::frame(is_numeric($value['at']) ? (float) $value['at'] : null);
        }

        return null;
    }

    public function isImage(): bool
    {
        return $this->image !== null;
    }
}
