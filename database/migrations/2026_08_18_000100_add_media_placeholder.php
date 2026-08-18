<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tiny copy of an image, kept next to it.
 *
 * Until the real file arrives a card has nothing to show, so it shows nothing —
 * an empty box that fills in a moment later. That moment is what a visitor
 * reads as slowness, and it is also what a layout-shift score measures.
 *
 * Text rather than a fixed-length column: this holds a `data:` URI, a few
 * hundred bytes of it, and the exact length depends on the encoder.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->text('placeholder')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('placeholder');
        });
    }
};
