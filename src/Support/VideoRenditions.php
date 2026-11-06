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
 * and a file whose path is not today's is known to be stale. The moment the
 * poster is taken from is one of them.
 *
 * A picture uploaded to be the poster (`poster_uuid`) is a rendition too, and
 * it is the poster for as long as it is there — whatever frame is made.
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
        // A chosen moment only: posters of the configured one keep their paths.
        $at = $video->poster_at === null ? '' : sprintf('|at:%.3F', $video->poster_at);

        return $this->root() . '/variants/poster/' . md5("{$video->path}|{$this->encoder->posterProfile()}{$at}") . '.jpg';
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

    /**
     * The poster a page shows: the uploaded picture, else the frame made with
     * today's settings — and while a newly chosen frame is still being made,
     * the frame made before it, rather than no poster at all.
     */
    public function posterOf(MediaFile $video): ?MediaFile
    {
        return $this->ownPosterOf($video)
            ?? $this->framePosterOf($video)
            ?? $video->variants
                ->filter(fn(MediaFile $variant): bool => $variant->type === MediaType::Image)
                ->sortByDesc(fn(MediaFile $variant): int => $variant->updated_at?->getTimestamp() ?? 0)
                ->first();
    }

    /**
     * The frame made with today's settings and the chosen moment, if there is one.
     */
    public function framePosterOf(MediaFile $video): ?MediaFile
    {
        $path = $this->posterPathFor($video);

        return $video->variants->first(
            fn(MediaFile $variant): bool => $variant->type === MediaType::Image && $variant->path === $path,
        );
    }

    /**
     * The picture uploaded to be the poster, if there is one.
     */
    public function ownPosterOf(MediaFile $video): ?MediaFile
    {
        if ($video->poster_uuid === null) {
            return null;
        }

        return $video->variants->first(
            fn(MediaFile $variant): bool => $variant->uuid === $video->poster_uuid,
        );
    }

    /**
     * Whether a poster still has to be made: none uploaded, and no frame made
     * with today's settings.
     */
    public function needsPoster(MediaFile $video): bool
    {
        return $this->ownPosterOf($video) === null && $this->framePosterOf($video) === null;
    }

    /**
     * Whether a rendition of a video is the one today's settings make.
     */
    public function isCurrent(MediaFile $rendition, MediaFile $video): bool
    {
        if ($video->poster_uuid !== null && $rendition->uuid === $video->poster_uuid) {
            return true;
        }

        return $rendition->path === ($rendition->type === MediaType::Video
            ? $this->copyPathFor($video)
            : $this->posterPathFor($video));
    }

    private function root(): string
    {
        return trim((string) $this->config->get('media-kit.directory', 'media'), '/');
    }
}
