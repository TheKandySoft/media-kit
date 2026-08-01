<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media library schema.
 *
 * A file is identified by its UUID and nothing else — there is no auto-increment
 * key to leak into URLs or APIs. `parent_uuid` links a generated variant back to
 * the original; `model_type`/`model_id` are nullable so a file can exist without
 * an owner (settings images, imported assets).
 *
 * `disk` holds a Laravel disk name straight out of config/filesystems.php.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('uuid')->primary();

            $table->nullableMorphs('model');

            $table->string('disk');
            $table->string('path');
            $table->string('original_name')->nullable();

            $table->string('type')->index();
            $table->string('mime_type')->nullable();
            $table->string('extension', 16)->nullable()->index();

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_public')->default(true)->index();
            $table->boolean('is_variant')->default(false)->index();
            $table->string('tag')->nullable()->index();
            $table->string('locale', 16)->nullable()->index();

            $table->uuid('parent_uuid')->nullable();

            $table->timestamps();

            $table->unique(['disk', 'path']);
        });

        // The self-reference has to wait until the table exists with its primary
        // key in place: Postgres emits the key as its own ALTER, and a foreign
        // key declared inside create() would run before it.
        Schema::table('media_files', function (Blueprint $table) {
            $table->foreign('parent_uuid')->references('uuid')->on('media_files')->cascadeOnDelete();
        });

        Schema::create('media_file_translations', function (Blueprint $table) {
            $table->id();

            $table->uuid('media_file_uuid');
            $table->foreign('media_file_uuid')->references('uuid')->on('media_files')->cascadeOnDelete();

            $table->string('locale', 16)->index();

            $table->string('alt')->nullable();
            $table->string('title')->nullable();

            $table->timestamps();

            $table->unique(['media_file_uuid', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_translations');
        Schema::dropIfExists('media_files');
    }
};
