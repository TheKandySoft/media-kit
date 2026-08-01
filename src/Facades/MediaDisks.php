<?php

namespace KandySoft\MediaKit\Facades;

use Illuminate\Support\Facades\Facade;
use KandySoft\MediaKit\Contracts\MediaStorage;

/**
 * Named MediaDisks rather than MediaStorage so it cannot be mistaken for the
 * contract of the same name when both are imported into one file.
 *
 * @see \KandySoft\MediaKit\Storage\MediaStorageManager
 *
 * @mixin \KandySoft\MediaKit\Storage\MediaStorageManager
 */
class MediaDisks extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaStorage::class;
    }
}
