<?php

namespace KandySoft\MediaKit\Console\Commands;

use Illuminate\Console\Command;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Jobs\ProcessVideo;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\VideoProcessor;
use KandySoft\MediaKit\Support\VideoEncoder;
use Throwable;

/**
 * Makes the web copy and poster of videos that have none made with today's
 * settings — the ones stored before the package made them, and all of them
 * after a video setting changed.
 *
 * Runs here, one video after another, unless `--queue` hands them to the
 * workers. Here means on a machine with ffmpeg.
 */
class ProcessMediaVideos extends Command
{
    protected $signature = 'media:videos
                            {--uuid=* : Only these videos}
                            {--force : Make them again even where they exist}
                            {--queue : Dispatch a job per video instead of encoding here}';

    protected $description = 'Make the missing web copies and posters of videos';

    public function handle(VideoProcessor $processor, VideoEncoder $encoder): int
    {
        if (! $processor->enabled()) {
            $this->error('Video processing is off (media-kit.video.enabled).');

            return self::FAILURE;
        }

        $queue = (bool) $this->option('queue');
        $force = (bool) $this->option('force');

        if (! $queue && ! $encoder->available()) {
            $this->error('ffmpeg is not available here. Install it, or pass --queue to leave it to the workers.');

            return self::FAILURE;
        }

        $query = MediaFile::query()
            ->where('type', MediaType::Video->value)
            ->where('is_variant', false)
            ->with('variants');

        if (($uuids = (array) $this->option('uuid')) !== []) {
            $query->whereIn('uuid', $uuids);
        }

        $done = 0;
        $failed = 0;

        foreach ($query->lazyById(50, 'uuid') as $video) {
            if (! $force && ! $processor->needsProcessing($video)) {
                continue;
            }

            if ($queue) {
                ProcessVideo::dispatch((string) $video->uuid, $force);
                $this->line("queued {$video->uuid}");
                $done++;

                continue;
            }

            $this->line("encoding {$video->uuid} ({$video->original_name})…");

            try {
                $processor->process($video, $force);
                $done++;
            } catch (Throwable $exception) {
                // One broken file is not a reason to leave the rest as they are.
                $failed++;
                $this->warn("{$video->uuid}: {$exception->getMessage()}");
            }
        }

        $this->info(($queue ? "Queued {$done} videos." : "Processed {$done} videos.")
            . ($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
