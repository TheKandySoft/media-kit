<?php

namespace KandySoft\MediaKit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Writing side of the library. Files are addressed by UUID everywhere.
 */
interface MediaWriter
{
    /**
     * Make a model's media match the given list: new files are stored, kept
     * ones are repositioned, everything else is deleted.
     *
     * @param  array<int, MediaUpload>  $uploads
     */
    public function sync(Model $model, array $uploads, MediaFilter $filter = new MediaFilter(), ?string $disk = null): void;

    /**
     * Store a single file, attached to a model or standing on its own.
     */
    public function store(MediaUpload $upload, ?Model $model = null, ?string $disk = null): MediaFile;

    /**
     * Swap the file behind an existing UUID.
     *
     * The identifier survives, so anything already pointing at this media —
     * a settings value, a cached payload, a printed link — keeps working. The
     * old file and every variant generated from it are removed.
     *
     * @param  array<int, MediaCaption>  $captions  replaces the existing ones when given
     */
    public function replace(string $uuid, UploadedFile $file, array $captions = []): MediaFile;

    /**
     * Register a file that is already sitting on a disk.
     */
    public function adopt(string $path, ?string $disk = null, ?string $tag = null): MediaFile;

    /**
     * @return array<int, MediaFile>
     */
    public function copy(Model $from, Model $to, MediaFilter $filter = new MediaFilter(), ?int $limit = null): array;

    public function delete(string $uuid): bool;

    public function purge(Model $model, MediaFilter $filter = new MediaFilter()): void;

    /**
     * The shared fallback image, stored on first use.
     */
    public function fallbackImage(): MediaFile;

    /**
     * Produce the resized rendition if it is missing and return its record.
     */
    public function ensureVariant(MediaFile $original, int $width, int $height, string $format): MediaFile;
}
