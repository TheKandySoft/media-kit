<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Database\Eloquent\Model;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaResource;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Support\MediaResourceFactory;

final class MediaReadService implements MediaReader
{
    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaResourceFactory $resources,
        private readonly MediaWriter $writer,
    ) {}

    /**
     * @return array<int, MediaResource>
     */
    public function forModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): array
    {
        $files = $this->media->forModel($model, $filter)->all();

        if ($files === [] && $this->wantsFallback($filter)) {
            $files = [$this->writer->fallbackImage()];
        }

        return array_map(
            fn(MediaFile $file): MediaResource => $this->resources->make($file, $filter, $size),
            $files,
        );
    }

    public function firstForModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): ?MediaResource
    {
        $file = $this->media->firstForModel($model, $filter);

        if ($file === null && $this->wantsFallback($filter)) {
            $file = $this->writer->fallbackImage();
        }

        return $file === null ? null : $this->resources->make($file, $filter, $size);
    }

    public function byUuid(string $uuid, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): MediaResource
    {
        $file = $this->media->findByUuid($uuid) ?? throw MediaNotFound::uuid($uuid);

        $file->loadMissing('captions');

        return $this->resources->make($file, $filter, $size);
    }

    /**
     * @return array<int, MediaResource>
     */
    public function tagged(string $tag, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): array
    {
        return array_map(
            fn(MediaFile $file): MediaResource => $this->resources->make($file, $filter, $size),
            $this->media->taggedStandalone($tag, $filter)->all(),
        );
    }

    public function fallbackImage(MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): MediaResource
    {
        return $this->resources->make($this->writer->fallbackImage(), $filter, $size);
    }

    /**
     * The fallback only makes sense when images were asked for in the first
     * place — substituting a picture for a missing PDF would be a surprise.
     */
    private function wantsFallback(MediaFilter $filter): bool
    {
        return $filter->withFallback && $filter->wantsImages();
    }
}
