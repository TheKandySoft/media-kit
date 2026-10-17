<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The part of a picture a person chose to be seen.
 *
 * Renditions were cut from the middle of their original, and a wide photo
 * asked for as a tall card showed whatever stood in the middle. The area is
 * kept beside the file, in fractions of it; the original stays whole. Empty
 * for every file stored before, which is exactly the middle they had.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->json('crop')->nullable()->after('placeholder');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('crop');
        });
    }
};
