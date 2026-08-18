<?php

namespace KandySoft\MediaKit\Contracts;

use Illuminate\Database\Eloquent\Model;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\LinkLifetime;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaResource;

/**
 * Reading side of the library. Always returns DTOs, never models or arrays,
 * so nothing downstream can reach into storage paths or owner ids.
 *
 * `$lifetime` overrides the disk's own policy for one read. Leave it out and
 * the file is handed out exactly as the disk it lives on says it should be;
 * pass it when this particular caller needs a link that dies sooner — an admin
 * preview, an export leaving the building.
 */
interface MediaReader
{
    /**
     * @return array<int, MediaResource>
     */
    public function forModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize(), ?LinkLifetime $lifetime = null): array;

    public function firstForModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize(), ?LinkLifetime $lifetime = null): ?MediaResource;

    /**
     * @throws \KandySoft\MediaKit\Exceptions\MediaNotFound
     */
    public function byUuid(string $uuid, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize(), ?LinkLifetime $lifetime = null): MediaResource;

    /**
     * @return array<int, MediaResource>
     */
    public function tagged(string $tag, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize(), ?LinkLifetime $lifetime = null): array;

    public function fallbackImage(MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize(), ?LinkLifetime $lifetime = null): MediaResource;
}
