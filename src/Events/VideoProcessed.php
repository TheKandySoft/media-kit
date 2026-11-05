<?php

namespace KandySoft\MediaKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * A video got its web copy and poster.
 *
 * The description of the video changed under everything that holds it: its
 * address now points at the copy, and it has a poster and a size. An
 * application that caches media descriptions — a catalogue index, a page
 * cache — listens for this to rebuild what holds this video.
 */
final class VideoProcessed
{
    use Dispatchable;

    public function __construct(public readonly MediaFile $video) {}
}
