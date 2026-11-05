<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a page needs to show a video.
 *
 * `duration` — how long it plays, in seconds. Read with its size when the
 * video is stored: a player shows it before the first byte of the file
 * arrives, and a structured-data `VideoObject` cannot be described without it.
 * `media:videos` reads it for videos stored before.
 *
 * `fit` — how the video is placed in a frame of other proportions: `cover`
 * fills the frame and cuts the rest, as a picture's rendition is cut;
 * `contain` shows it whole, with bars. Empty is `cover`.
 *
 * Both empty for everything stored before, which is exactly how it behaved.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->decimal('duration', 10, 3)->nullable()->after('height');
            $table->string('fit', 16)->nullable()->after('crop');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['duration', 'fit']);
        });
    }
};
