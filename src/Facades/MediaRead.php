<?php

namespace KandySoft\MediaKit\Facades;

use Illuminate\Support\Facades\Facade;
use KandySoft\MediaKit\Contracts\MediaReader;

/**
 * The facade and the container hand back the same instance: the accessor is the
 * contract, and the contract is aliased to a singleton. Inject MediaReader in a
 * constructor or reach for this facade — it makes no difference.
 *
 * @see \KandySoft\MediaKit\Services\MediaReadService
 *
 * @mixin \KandySoft\MediaKit\Services\MediaReadService
 */
class MediaRead extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaReader::class;
    }
}
