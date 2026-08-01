<?php

namespace KandySoft\MediaKit\Contracts;

use Illuminate\Database\Eloquent\Model;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaResource;

/**
 * Reading side of the library. Always returns DTOs, never models or arrays,
 * so nothing downstream can reach into storage paths or owner ids.
 */
interface MediaReader
{
    /**
     * @return array<int, MediaResource>
     */
    public function forModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): array;

    public function firstForModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): ?MediaResource;

    /**
     * @throws \KandySoft\MediaKit\Exceptions\MediaNotFound
     */
    public function byUuid(string $uuid, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): MediaResource;

    /**
     * @return array<int, MediaResource>
     */
    public function tagged(string $tag, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): array;

    public function fallbackImage(MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): MediaResource;
}
