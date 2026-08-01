<?php

namespace KandySoft\MediaKit\Facades;

use Illuminate\Support\Facades\Facade;
use KandySoft\MediaKit\Contracts\MediaWriter;

/**
 * @see \KandySoft\MediaKit\Services\MediaWriteService
 *
 * @mixin \KandySoft\MediaKit\Services\MediaWriteService
 */
class MediaWrite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaWriter::class;
    }
}
