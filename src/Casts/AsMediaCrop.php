<?php

namespace KandySoft\MediaKit\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use KandySoft\MediaKit\Data\MediaCrop;

/**
 * The chosen area of a picture as a column.
 *
 * The whole picture is stored as nothing: it is what a file without an area
 * already means, and two spellings of one state would make every comparison
 * ask both.
 *
 * @implements CastsAttributes<MediaCrop|null, MediaCrop|array<string, mixed>|null>
 */
final class AsMediaCrop implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?MediaCrop
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $crop = MediaCrop::fromArray(json_decode($value, true));

        return $crop === null || $crop->isWhole() ? null : $crop;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $crop = $value instanceof MediaCrop ? $value : MediaCrop::fromArray($value);

        return $crop === null || $crop->isWhole()
            ? null
            : json_encode($crop->toArray(), JSON_THROW_ON_ERROR);
    }
}
