<?php

namespace KandySoft\MediaKit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\VideoProcessor;
use Throwable;

/**
 * Makes a stored video's web copy and poster in the background.
 *
 * Carries the uuid, not the record: the video may be replaced or deleted
 * before a worker gets to it, and then there is nothing to do.
 */
final class ProcessVideo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A broken file stays broken: one retry covers a worker restarted mid-encode. */
    public int $tries = 2;

    public int $timeout;

    public function __construct(public readonly string $uuid, public readonly bool $force = false)
    {
        $this->timeout = max(60, (int) config('media-kit.video.timeout', 1800));

        $this->onConnection(config('media-kit.video.connection') ?: null);
        $this->onQueue(config('media-kit.video.queue') ?: null);
    }

    public function handle(VideoProcessor $processor): void
    {
        $video = MediaFile::query()->find($this->uuid);

        if ($video === null) {
            return;
        }

        $processor->process($video, $this->force);
    }

    public function failed(Throwable $exception): void
    {
        // The original is still served: a page shows the video as uploaded.
        Log::error('media-kit: video could not be processed.', [
            'uuid' => $this->uuid,
            'error' => $exception->getMessage(),
        ]);
    }
}
