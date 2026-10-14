<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What it takes to read a file stored encrypted.
 *
 * Every file has its own key, and that key is kept here encrypted with the
 * application's key (`wrapped_key`, under `key_id`). The disk then holds
 * nothing readable on its own: a copied bucket or a stolen backup is noise
 * without the application's keys, and rotating those keys rewraps these rows
 * without touching a single file.
 *
 * All nullable: `encryption` null is a file stored as it came, and the two
 * kinds live side by side, so a disk can be switched on and its old files
 * converted later (`media:encrypt`).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->string('encryption', 32)->nullable()->after('size');
            $table->string('key_id', 64)->nullable()->after('encryption');
            $table->text('wrapped_key')->nullable()->after('key_id');
            $table->string('checksum', 64)->nullable()->after('wrapped_key');
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn(['encryption', 'key_id', 'wrapped_key', 'checksum']);
        });
    }
};
