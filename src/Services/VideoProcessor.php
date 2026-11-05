<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\VideoInfo;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Events\VideoProcessed;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Storage\MediaDisk;
use KandySoft\MediaKit\Support\PlaceholderFactory;
use KandySoft\MediaKit\Support\VideoEncoder;
use KandySoft\MediaKit\Support\VideoProbe;
use KandySoft\MediaKit\Support\VideoRenditions;
use RuntimeException;

/**
 * Turns a stored video into something a page can show: a web copy and a
 * poster.
 *
 * The original stays as it came, the way an uploaded picture does — a copy
 * made for today's gallery cannot be unmade for tomorrow's, and a setting
 * changed later is applied to the original, not to a copy of a copy.
 *
 * Runs in the background (`ProcessVideo`): encoding takes as long as the
 * video plays, or longer. Until it is done the original is what is served.
 */
final class VideoProcessor
{
    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaStorage $storage,
        private readonly MediaWriter $writer,
        private readonly VideoProbe $probe,
        private readonly VideoEncoder $encoder,
        private readonly VideoRenditions $renditions,
        private readonly PlaceholderFactory $placeholders,
        private readonly Config $config,
        private readonly Cache $cache,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('media-kit.video.enabled', true);
    }

    /**
     * Whether this video still needs its copy or poster made with today's
     * settings.
     */
    public function needsProcessing(MediaFile $video): bool
    {
        if ($video->type !== MediaType::Video || $video->is_variant) {
            return false;
        }

        $video->loadMissing('variants');

        return $this->renditions->copyOf($video) === null || $this->renditions->posterOf($video) === null;
    }

    /**
     * Make the web copy and the poster, unless both already exist — or again,
     * with `$force`.
     *
     * Returns false when nothing could be made here: processing is off, or
     * ffmpeg is not installed. A video ffmpeg cannot read is an error.
     */
    public function process(MediaFile $video, bool $force = false): bool
    {
        if ($video->type !== MediaType::Video || $video->is_variant) {
            return false;
        }

        if (! $this->enabled()) {
            return false;
        }

        if (! $this->encoder->available()) {
            Log::warning('media-kit: ffmpeg is not available, video left as uploaded.', ['uuid' => $video->uuid]);

            return false;
        }

        return $this->exclusively($video, function () use ($video, $force): bool {
            $video->refresh()->load('variants');

            if (! $force && ! $this->needsProcessing($video)) {
                return true;
            }

            $disk = $this->storage->disk($video->disk);
            $source = $disk->pull((string) $video->path, Envelope::of($video));

            try {
                $info = $this->probe->probe($source->path())
                    ?? throw new RuntimeException("Video {$video->uuid} cannot be read.");

                $copy = $this->makeCopy($disk, $video, $source->path(), $info);
                $poster = $this->makePoster($disk, $video, $source->path(), $info);
            } finally {
                $source->delete();
            }

            // The poster is cut from the area chosen for the video — and the
            // video's placeholder is that of the area, not of the whole frame.
            if ($video->crop !== null) {
                $poster = $this->writer->crop((string) $poster->uuid, $video->crop)->refresh();
            }

            $video->update([
                'width' => $info->width,
                'height' => $info->height,
                'duration' => $info->duration,
                'placeholder' => $poster->placeholder ?? $video->placeholder,
            ]);

            $this->forgetStale($video, [$copy->uuid, $poster->uuid]);

            VideoProcessed::dispatch($video->refresh()->load('variants'));

            return true;
        });
    }

    private function makeCopy(MediaDisk $disk, MediaFile $video, string $source, VideoInfo $info): MediaFile
    {
        $output = $this->scratch('mp4');

        try {
            [$width, $height] = $this->encoder->transcode($source, $output, $info);

            return $this->record($disk, $video, $this->renditions->copyPathFor($video), $output, [
                'type' => MediaType::Video,
                'mime_type' => 'video/mp4',
                'extension' => 'mp4',
                'width' => $width,
                'height' => $height,
                'duration' => $info->duration,
            ]);
        } finally {
            @unlink($output);
        }
    }

    private function makePoster(MediaDisk $disk, MediaFile $video, string $source, VideoInfo $info): MediaFile
    {
        $output = $this->scratch('jpg');

        try {
            [$width, $height] = $this->encoder->poster($source, $output, $info);

            return $this->record($disk, $video, $this->renditions->posterPathFor($video), $output, [
                'type' => MediaType::Image,
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'width' => $width,
                'height' => $height,
                // The area is given again below, from the video: a poster made
                // anew starts whole, so its placeholder is drawn from the area.
                'crop' => null,
                // A readable copy in the database would undo encryption.
                'placeholder' => $disk->encrypts() ? null : $this->placeholders->forContents((string) file_get_contents($output)),
            ]);
        } finally {
            @unlink($output);
        }
    }

    /**
     * Write a made file and record it as a rendition of the video — over the
     * record of an earlier run at the same path, if there is one.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(MediaDisk $disk, MediaFile $video, string $path, string $file, array $attributes): MediaFile
    {
        $stream = fopen($file, 'rb') ?: throw new RuntimeException("Cannot open {$file}.");

        try {
            $envelope = $disk->write($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $existing = $this->media->findByPath($disk->name(), $path);

        $attributes = [
            ...$attributes,
            'size' => $envelope?->size ?? (filesize($file) ?: null),
            ...Envelope::changes($envelope, $existing),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return $this->media->store([
            'model_type' => $video->model_type,
            'model_id' => $video->model_id,
            'disk' => $disk->name(),
            'path' => $path,
            'original_name' => basename($path),
            'position' => $video->position,
            'is_public' => $disk->isPublic(),
            'is_variant' => true,
            'tag' => $video->tag,
            'locale' => $video->locale,
            'parent_uuid' => $video->uuid,
            ...$attributes,
        ]);
    }

    /**
     * Copies and posters made with earlier settings: nothing asks for them any
     * more. Deleted through the writer, so a poster takes its renditions along.
     *
     * @param  list<string>  $keep
     */
    private function forgetStale(MediaFile $video, array $keep): void
    {
        foreach ($video->variants()->get() as $variant) {
            if (! in_array($variant->uuid, $keep, true)) {
                $this->writer->delete((string) $variant->uuid);
            }
        }
    }

    private function scratch(string $extension): string
    {
        $directory = storage_path('app/media-kit-tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        return $directory . '/' . Str::uuid() . '.' . $extension;
    }

    /**
     * One video is encoded by one worker at a time: a job retried while the
     * first attempt still runs would encode it twice and race on the record.
     *
     * @param  \Closure(): bool  $work
     */
    private function exclusively(MediaFile $video, \Closure $work): bool
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $work();
        }

        $timeout = max(60, (int) $this->config->get('media-kit.video.timeout', 1800));

        // Held by another worker: that one is making the same files.
        $result = $store->lock('media-kit:video:' . $video->uuid, $timeout + 60)->get($work);

        return $result !== false;
    }
}
