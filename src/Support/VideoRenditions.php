<?php

namespace KandySoft\MediaKit\Support;

use Illuminate\Contracts\Config\Repository as Config;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Where a video's web copy and poster live, and which stored ones are current.
 *
 * Both are renditions of the video — records with `parent_uuid` on it — told
 * apart by type: the copy is a video, the poster an image. The poster's own
 * renditions hang off the poster, exactly as an uploaded picture's do.
 *
 * Like an image rendition's, the path carries the settings that made it, so a
 * change in settings is a new file rather than the old one served forever,
 * and a file whose path is not today's is known to be stale.
 */
final class VideoRenditions
{
    public function __construct(
        private readonly Config $config,
        private readonly VideoEncoder $encoder,
    ) {}

    public function copyPathFor(MediaFile $video): string
    {
        return $this->root() . '/variants/video/' . md5("{$video->path}|{$this->encoder->profile()}") . '.mp4';
    }

    public function posterPathFor(MediaFile $video): string
    {
        return $this->root() . '/variants/poster/' . md5("{$video->path}|{$this->encoder->posterProfile()}") . '.jpg';
    }

    /**
     * The web copy made with today's settings, if there is one — from the
     * loaded renditions, without a query per video in a listing.
     */
    public function copyOf(MediaFile $video): ?MediaFile
    {
        $path = $this->copyPathFor($video);

        return $video->variants->first(
            fn(MediaFile $variant): bool => $variant->type === MediaType::Video && $variant->path === $path,
        );
    }

    public function posterOf(MediaFile $video): ?MediaFile
    {
        $path = $this->posterPathFor($video);

        return $video->variants->first(
            fn(MediaFile $variant): bool => $variant->type === MediaType::Image && $variant->path === $path,
        );
    }

    /**
     * Whether a rendition of a video is the one today's settings make.
     */
    public function isCurrent(MediaFile $rendition, MediaFile $video): bool
    {
        return $rendition->path === ($rendition->type === MediaType::Video
            ? $this->copyPathFor($video)
            : $this->posterPathFor($video));
    }

    private function root(): string
    {
        return trim((string) $this->config->get('media-kit.directory', 'media'), '/');
    }
}
