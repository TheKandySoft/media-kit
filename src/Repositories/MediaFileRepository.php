<?php

namespace KandySoft\MediaKit\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Every query this package makes, in one place.
 *
 * A plain Eloquent repository on purpose: the generic repository library it used
 * to extend brought caching, criteria and presenters that nothing here touched,
 * and a public package should not force that dependency on its host.
 */
class MediaFileRepository
{
    /**
     * Tag reserved for the image served when a model has none of its own.
     */
    public const FALLBACK_TAG = 'fallback';

    /**
     * @return Builder<MediaFile>
     */
    public function query(): Builder
    {
        return MediaFile::query();
    }

    public function findByUuid(string $uuid): ?MediaFile
    {
        return MediaFile::query()->find($uuid);
    }

    public function findByPath(string $disk, string $path): ?MediaFile
    {
        return MediaFile::query()
            ->where('disk', $disk)
            ->where('path', $path)
            ->first();
    }

    public function findFallbackImage(): ?MediaFile
    {
        return MediaFile::query()
            ->whereNull('model_type')
            ->whereNull('model_id')
            ->where('tag', self::FALLBACK_TAG)
            ->where('type', 'image')
            ->first();
    }

    /**
     * @return Collection<int, MediaFile>
     */
    public function forModel(Model $model, MediaFilter $filter = new MediaFilter()): Collection
    {
        return $this->applyFilter(
            MediaFile::query()
                ->originals()
                ->where('model_type', $model->getMorphClass())
                ->where('model_id', $model->getKey()),
            $filter,
        )->orderBy('position')->get();
    }

    public function firstForModel(Model $model, MediaFilter $filter = new MediaFilter()): ?MediaFile
    {
        return $this->forModel($model, $filter)->first();
    }

    /**
     * @return Collection<int, MediaFile>
     */
    public function taggedStandalone(string $tag, MediaFilter $filter = new MediaFilter()): Collection
    {
        return $this->applyFilter(
            MediaFile::query()
                ->originals()
                ->whereNull('model_type')
                ->whereNull('model_id')
                ->where('tag', $tag),
            $filter,
        )->orderBy('position')->get();
    }

    public function findVariant(MediaFile $original, int $width, int $height, string $format): ?MediaFile
    {
        return $original->variants()
            ->where('width', $width)
            ->where('height', $height)
            ->where('extension', $format)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function store(array $attributes): MediaFile
    {
        return MediaFile::query()->create($attributes);
    }

    /**
     * @param  Builder<MediaFile>  $query
     * @return Builder<MediaFile>
     */
    private function applyFilter(Builder $query, MediaFilter $filter): Builder
    {
        $query->with(['captions' => function ($relation) use ($filter): void {
            if ($filter->captionLocale !== null) {
                $relation->where('locale', $filter->captionLocale);
            }
        }]);

        if ($filter->types !== []) {
            $query->whereIn('type', $filter->typeValues());
        }

        if ($filter->tags !== []) {
            $query->whereIn('tag', $filter->tags);
        }

        if ($filter->withoutLocale) {
            $query->whereNull('locale');
        } elseif ($filter->locale !== null) {
            $query->where('locale', $filter->locale);
        }

        return $query;
    }
}
