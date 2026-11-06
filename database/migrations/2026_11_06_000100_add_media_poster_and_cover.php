<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which picture stands for a video, and which file stands for a collection.
 *
 * `poster_at` — the moment of the video its poster is taken from, in seconds.
 * Empty is the configured moment (`media-kit.video.poster_at`).
 *
 * `poster_uuid` — a picture uploaded to be the video's poster instead of any
 * frame of it. It is a rendition of the video (`parent_uuid`), so it goes
 * where the video goes.
 *
 * `is_cover` — the file a list shows for its owner: a product card shows one
 * picture of a gallery, and it need not be the first one.
 *
 * All empty for everything stored before, which is exactly how it behaved.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->decimal('poster_at', 10, 3)->nullable()->after('duration');
            $table->uuid('poster_uuid')->nullable()->after('poster_at');
            $table->boolean('is_cover')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['poster_at', 'poster_uuid', 'is_cover']);
        });
    }
};
