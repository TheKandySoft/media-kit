<?php

namespace KandySoft\MediaKit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use KandySoft\MediaKit\Enums\MediaType;

/**
 * A stored file.
 *
 * The UUID is the primary key: there is no numeric id to accidentally expose in
 * a URL or an API payload, and none to guess at.
 *
 * @property string $uuid
 * @property string|null $model_type
 * @property int|string|null $model_id
 * @property string $disk
 * @property string $path
 * @property string|null $original_name
 * @property MediaType $type
 * @property string|null $mime_type
 * @property string|null $extension
 * @property int|null $width
 * @property int|null $height
 * @property int|null $size
 * @property int $position
 * @property bool $is_public
 * @property bool $is_variant
 * @property string|null $tag
 * @property string|null $locale
 * @property string|null $parent_uuid
 */
class MediaFile extends Model
{
    protected $table = 'media_files';

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'uuid',
        'model_type',
        'model_id',
        'disk',
        'path',
        'original_name',
        'type',
        'mime_type',
        'extension',
        'width',
        'height',
        'size',
        'position',
        'is_public',
        'is_variant',
        'tag',
        'locale',
        'parent_uuid',
    ];

    protected $casts = [
        'type' => MediaType::class,
        'is_public' => 'boolean',
        'is_variant' => 'boolean',
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            if (empty($media->uuid)) {
                $media->uuid = (string) Str::uuid();
            }
        });
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_uuid', 'uuid');
    }

    /**
     * Resized renditions generated from this file.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_uuid', 'uuid');
    }

    public function captions(): HasMany
    {
        return $this->hasMany(MediaFileTranslation::class, 'media_file_uuid', 'uuid');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Files uploaded as themselves, as opposed to generated variants.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOriginals(Builder $query): Builder
    {
        return $query->whereNull('parent_uuid');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, MediaType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        return $query->where('tag', $tag);
    }
}
