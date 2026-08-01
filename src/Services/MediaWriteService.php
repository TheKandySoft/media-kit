<?php

namespace KandySoft\MediaKit\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use KandySoft\MediaKit\Exceptions\UnsupportedFileType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Models\MediaFileTranslation;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Storage\MediaDisk;
use KandySoft\MediaKit\Support\ImageFactory;
use Throwable;

final class MediaWriteService implements MediaWriter
{
    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaStorage $storage,
        private readonly ImageVariantGenerator $variants,
        private readonly ImageFactory $images,
        private readonly Config $config,
    ) {}

    /**
     * @param  array<int, MediaUpload>  $uploads
     */
    public function sync(Model $model, array $uploads, MediaFilter $filter = new MediaFilter(), ?string $disk = null): void
    {
        $existing = $this->media->forModel($model, $filter)->keyBy('uuid');
        $kept = [];
        $position = 0;

        foreach ($uploads as $upload) {
            $position++;

            if ($upload->isNewFile()) {
                $file = $this->store($upload, $model, $disk);
                $file->update(['position' => $position]);
                $kept[] = $file->uuid;

                continue;
            }

            $file = $existing->get($upload->uuid);

            if ($file === null) {
                continue;
            }

            $file->update([
                'tag' => $upload->tag,
                'locale' => $upload->locale,
                'position' => $position,
            ]);

            $this->writeCaptions($file, $upload->captions);
            $kept[] = $file->uuid;
        }

        foreach ($existing as $file) {
            if (! in_array($file->uuid, $kept, true)) {
                $this->forget($file);
            }
        }
    }

    public function store(MediaUpload $upload, ?Model $model = null, ?string $disk = null): MediaFile
    {
        $file = $upload->file;

        if ($file === null) {
            throw new \InvalidArgumentException('MediaUpload::store() needs an upload carrying a file.');
        }

        $mediaDisk = $this->storage->disk($disk);
        $extension = strtolower($file->getClientOriginalExtension());
        $type = MediaType::fromExtension($extension) ?? throw UnsupportedFileType::extension($extension);

        $path = $mediaDisk->upload(
            $file,
            $this->directoryFor($model, $upload->tag),
            Str::random(40) . '.' . $extension,
        );

        [$width, $height] = $this->dimensions($type, $file);

        $record = $this->media->store([
            'model_type' => $model?->getMorphClass(),
            'model_id' => $model?->getKey(),
            'disk' => $mediaDisk->name(),
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'type' => $type,
            'mime_type' => $file->getClientMimeType(),
            'extension' => $extension,
            'width' => $width,
            'height' => $height,
            'size' => $file->getSize() ?: null,
            'position' => 0,
            'is_public' => $mediaDisk->isPublic(),
            'is_variant' => false,
            'tag' => $upload->tag,
            'locale' => $upload->locale,
        ]);

        $this->writeCaptions($record, $upload->captions);

        return $record;
    }

    /**
     * @param  array<int, MediaCaption>  $captions
     */
    public function replace(string $uuid, UploadedFile $file, array $captions = []): MediaFile
    {
        $existing = $this->media->findByUuid($uuid) ?? throw MediaNotFound::uuid($uuid);

        $disk = $this->storage->disk($existing->disk);
        $extension = strtolower($file->getClientOriginalExtension());
        $type = MediaType::fromExtension($extension) ?? throw UnsupportedFileType::extension($extension);

        // Write the replacement before touching the old one: a failure here
        // leaves the original in place rather than a record pointing at nothing.
        $path = $disk->upload(
            $file,
            dirname($existing->path),
            Str::random(40) . '.' . $extension,
        );

        $stalePath = $existing->path;

        foreach ($existing->variants as $variant) {
            $this->forget($variant);
        }

        [$width, $height] = $this->dimensions($type, $file);

        $existing->update([
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'type' => $type,
            'mime_type' => $file->getClientMimeType(),
            'extension' => $extension,
            'width' => $width,
            'height' => $height,
            'size' => $file->getSize() ?: null,
            'is_public' => $disk->isPublic(),
        ]);

        if ($stalePath !== $path) {
            $disk->delete($stalePath);
        }

        if ($captions !== []) {
            $this->writeCaptions($existing, $captions);
        }

        return $existing->refresh();
    }

    public function adopt(string $path, ?string $disk = null, ?string $tag = null): MediaFile
    {
        $mediaDisk = $this->storage->disk($disk);

        if (! $mediaDisk->exists($path)) {
            throw MediaNotFound::path($mediaDisk->name(), $path);
        }

        $existing = $this->media->findByPath($mediaDisk->name(), $path);

        if ($existing !== null) {
            return $existing;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $type = MediaType::fromExtension($extension) ?? throw UnsupportedFileType::extension($extension);

        [$width, $height] = $this->dimensionsFromDisk($type, $mediaDisk, $path);

        return $this->media->store([
            'disk' => $mediaDisk->name(),
            'path' => $path,
            'original_name' => basename($path),
            'type' => $type,
            'mime_type' => $mediaDisk->mimeType($path),
            'extension' => $extension,
            'width' => $width,
            'height' => $height,
            'size' => $mediaDisk->size($path),
            'position' => 0,
            'is_public' => $mediaDisk->isPublic(),
            'is_variant' => false,
            'tag' => $tag,
        ]);
    }

    /**
     * @return array<int, MediaFile>
     */
    public function copy(Model $from, Model $to, MediaFilter $filter = new MediaFilter(), ?int $limit = null): array
    {
        $originals = $this->media->forModel($from, $filter);

        if ($limit !== null) {
            $originals = $originals->take($limit);
        }

        $copies = [];

        foreach ($originals as $original) {
            $temporary = $this->storage->disk($original->disk)->pull($original->path);

            try {
                $copies[] = $this->store(
                    MediaUpload::file(
                        file: $temporary->toUploadedFile($original->original_name ?? basename($original->path)),
                        tag: $original->tag,
                        locale: $original->locale,
                        captions: $this->captionsOf($original),
                    ),
                    $to,
                    $original->disk,
                );
            } finally {
                $temporary->delete();
            }
        }

        return $copies;
    }

    public function delete(string $uuid): bool
    {
        $file = $this->media->findByUuid($uuid);

        return $file !== null && $this->forget($file);
    }

    public function purge(Model $model, MediaFilter $filter = new MediaFilter()): void
    {
        foreach ($this->media->forModel($model, $filter) as $file) {
            $this->forget($file);
        }
    }

    /**
     * Stored the first time somebody actually asks for it — the package must
     * not touch storage merely because a service was resolved.
     */
    public function fallbackImage(): MediaFile
    {
        $existing = $this->media->findFallbackImage();

        if ($existing !== null) {
            return $existing;
        }

        $source = $this->config->get('media-kit.fallback_image')
            ?: dirname(__DIR__, 2) . '/resources/default.webp';

        if (! is_file($source)) {
            throw MediaNotFound::fallbackImage();
        }

        $disk = $this->storage->disk();
        $root = trim((string) $this->config->get('media-kit.directory', 'media'), '/');
        $path = "{$root}/fallback/" . basename($source);

        if (! $disk->exists($path)) {
            $disk->put($path, (string) file_get_contents($source));
        }

        return $this->adopt($path, $disk->name(), MediaFileRepository::FALLBACK_TAG);
    }

    public function ensureVariant(MediaFile $original, int $width, int $height, string $format): MediaFile
    {
        $disk = $this->storage->disk($original->disk);
        $path = $this->variants->pathFor($original->path, $width, $height, $format);

        $this->variants->generate($disk, $original->path, $path, $width, $height, $format);

        $existing = $this->media->findByPath($disk->name(), $path);

        if ($existing !== null) {
            return $existing;
        }

        return $this->media->store([
            'model_type' => $original->model_type,
            'model_id' => $original->model_id,
            'disk' => $disk->name(),
            'path' => $path,
            'original_name' => basename($path),
            'type' => MediaType::Image,
            'mime_type' => $disk->mimeType($path),
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'width' => $width,
            'height' => $height,
            'size' => $disk->size($path),
            'position' => $original->position,
            'is_public' => $disk->isPublic(),
            'is_variant' => true,
            'tag' => $original->tag,
            'locale' => $original->locale,
            'parent_uuid' => $original->uuid,
        ]);
    }

    /**
     * Delete a file, its generated variants and everything they occupy on disk.
     */
    private function forget(MediaFile $file): bool
    {
        foreach ($file->variants as $variant) {
            $this->forget($variant);
        }

        $this->storage->disk($file->disk)->delete($file->path);

        return (bool) $file->delete();
    }

    /**
     * @param  array<int, MediaCaption>  $captions
     */
    private function writeCaptions(MediaFile $file, array $captions): void
    {
        foreach ($captions as $caption) {
            MediaFileTranslation::query()->updateOrCreate(
                ['media_file_uuid' => $file->uuid, 'locale' => $caption->locale],
                ['alt' => $caption->alt, 'title' => $caption->title],
            );
        }
    }

    /**
     * @return array<int, MediaCaption>
     */
    private function captionsOf(MediaFile $file): array
    {
        return $file->captions
            ->map(fn(MediaFileTranslation $t): MediaCaption => new MediaCaption($t->locale, $t->alt, $t->title))
            ->all();
    }

    private function directoryFor(?Model $model, ?string $tag): string
    {
        $root = trim((string) $this->config->get('media-kit.directory', 'media'), '/');

        if ($model === null) {
            return $root . '/' . Str::slug($tag ?? 'files');
        }

        return $root . '/' . Str::snake(class_basename($model)) . '/' . $model->getKey();
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(MediaType $type, UploadedFile $file): array
    {
        if (! $type->isImage()) {
            return [null, null];
        }

        try {
            $image = $this->images->fromUploadedFile($file);

            return [$image->width(), $image->height()];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensionsFromDisk(MediaType $type, MediaDisk $disk, string $path): array
    {
        if (! $type->isImage()) {
            return [null, null];
        }

        try {
            $image = $this->images->fromContents($disk->get($path));

            return [$image->width(), $image->height()];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
