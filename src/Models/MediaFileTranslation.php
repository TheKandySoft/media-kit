<?php

namespace KandySoft\MediaKit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alt text and title for one file in one locale.
 *
 * @property string $media_file_uuid
 * @property string $locale
 * @property string|null $alt
 * @property string|null $title
 */
class MediaFileTranslation extends Model
{
    protected $table = 'media_file_translations';

    protected $fillable = [
        'media_file_uuid',
        'locale',
        'alt',
        'title',
    ];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_file_uuid', 'uuid');
    }
}
