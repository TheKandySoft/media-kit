<?php

namespace KandySoft\MediaKit\Services;

use Closure;
use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaCrop;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use KandySoft\MediaKit\Exceptions\UnsupportedFileType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Models\MediaFileTranslation;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Storage\MediaDisk;
use KandySoft\MediaKit\Storage\TemporaryFile;
use KandySoft\MediaKit\Support\ImageFactory;
use KandySoft\MediaKit\Support\ImageIntake;
use KandySoft\MediaKit\Support\ImageOperations;
use KandySoft\MediaKit\Support\MediaTypeResolver;
use KandySoft\MediaKit\Support\PlaceholderFactory;
use Throwable;

final class MediaWriteService implements MediaWriter
{
    public function __construct(
        private readonly MediaFileRepository $media,
        private readonly MediaStorage $storage,
        private readonly ImageVariantGenerator $variants,
        private readonly ImageFactory $images,
        private readonly PlaceholderFactory $placeholders,
        private readonly MediaTypeResolver $types,
        private readonly Config $config,
        private readonly Cache $cache,
        private readonly ImageIntake $intake,
        private readonly ImageOperations $operations,
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

            if ($upload->crop !== null) {
                $this->cropFile($file, $upload->crop);
            }

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

        $local = $this->localCopy($file);

        if ($local !== null) {
            try {
                return $this->store(
                    MediaUpload::file($local->toUploadedFile($file->getClientOriginalName()), $upload->tag, $upload->locale, $upload->captions, $upload->crop),
                    $model,
                    $disk,
                );
            } finally {
                $local->delete();
            }
        }

        $mediaDisk = $this->storage->disk($disk);
        $extension = strtolower($file->getClientOriginalExtension());
        $type = $this->types->fromExtension($extension) ?? throw UnsupportedFileType::extension($extension);

        // Read once — size, placeholder and, if the host asks, a reworked
        // original. A placeholder is a readable copy kept in the database: a
        // disk that encrypts what it stores gets none.
        $intake = $this->intake->read($type, $file, $extension, withPlaceholder: ! $mediaDisk->encrypts());

        // Measured before the reworked copy is released with the temporary file.
        $size = $intake->file->getSize() ?: null;

        try {
            [$path, $envelope] = $mediaDisk->store(
                $intake->file,
                $this->directoryFor($model, $upload->tag),
                Str::random(40) . '.' . $extension,
            );
        } finally {
            $intake->release();
        }

        $record = $this->media->store([
            'model_type' => $model?->getMorphClass(),
            'model_id' => $model?->getKey(),
            'disk' => $mediaDisk->name(),
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'type' => $type,
            'mime_type' => $this->mimeTypeOf($file),
            'extension' => $extension,
            'width' => $intake->width,
            'height' => $intake->height,
            'placeholder' => $intake->placeholder,
            'size' => $envelope?->size ?? $size,
            ...Envelope::changes($envelope),
            'position' => 0,
            'is_public' => $mediaDisk->isPublic(),
            'is_variant' => false,
            'tag' => $upload->tag,
            'locale' => $upload->locale,
        ]);

        $this->writeCaptions($record, $upload->captions);

        if ($upload->crop !== null) {
            $this->cropFile($record, $upload->crop);
        }

        return $record;
    }

    /**
     * @param  array<int, MediaCaption>  $captions
     */
    public function replace(string $uuid, UploadedFile $file, array $captions = []): MediaFile
    {
        $existing = $this->media->findByUuid($uuid) ?? throw MediaNotFound::uuid($uuid);
        $local = $this->localCopy($file);

        if ($local !== null) {
            try {
                return $this->replace($uuid, $local->toUploadedFile($file->getClientOriginalName()), $captions);
            } finally {
                $local->delete();
            }
        }

        $disk = $this->storage->disk($existing->disk);
        $extension = strtolower($file->getClientOriginalExtension());
        $type = $this->types->fromExtension($extension) ?? throw UnsupportedFileType::extension($extension);

        $intake = $this->intake->read($type, $file, $extension, withPlaceholder: ! $disk->encrypts());

        // Write the replacement before touching the old one: a failure here
        // leaves the original in place rather than a record pointing at nothing.
        $size = $intake->file->getSize() ?: null;

        try {
            [$path, $envelope] = $disk->store(
                $intake->file,
                dirname($existing->path),
                Str::random(40) . '.' . $extension,
            );
        } finally {
            $intake->release();
        }

        $stalePath = $existing->path;

        foreach ($existing->variants as $variant) {
            $this->forget($variant);
        }

        $existing->update([
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'type' => $type,
            'mime_type' => $this->mimeTypeOf($file),
            'extension' => $extension,
            'width' => $intake->width,
            'height' => $intake->height,
            'placeholder' => $intake->placeholder,
            'crop' => null,
            'size' => $envelope?->size ?? $size,
            ...Envelope::changes($envelope, $existing),
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

    /**
     * @param  array<int, MediaCaption>  $captions
     */
    public function caption(string $uuid, array $captions): MediaFile
    {
        $file = $this->media->findByUuid($uuid) ?? throw MediaNotFound::uuid($uuid);

        foreach ($captions as $caption) {
            if ($caption->isEmpty()) {
                MediaFileTranslation::query()
                    ->where('media_file_uuid', $file->uuid)
                    ->where('locale', $caption->locale)
                    ->delete();

                continue;
            }

            $this->writeCaptions($file, [$caption]);
        }

        return $file->load('captions');
    }

    public function crop(string $uuid, ?MediaCrop $crop): MediaFile
    {
        $file = $this->media->findByUuid($uuid) ?? throw MediaNotFound::uuid($uuid);

        $this->cropFile($file, $crop ?? MediaCrop::whole());

        return $file;
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
        $type = $this->types->fromExtension($extension) ?? throw UnsupportedFileType::extension($extension);

        [$width, $height, $placeholder] = $this->readFromDisk($type, $mediaDisk, $path);

        return $this->media->store([
            'disk' => $mediaDisk->name(),
            'path' => $path,
            'original_name' => basename($path),
            'type' => $type,
            'mime_type' => $mediaDisk->mimeType($path),
            'extension' => $extension,
            'width' => $width,
            'height' => $height,
            'placeholder' => $placeholder,
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
            $temporary = $this->storage->disk($original->disk)->pull($original->path, Envelope::of($original));

            try {
                $copies[] = $this->store(
                    MediaUpload::file(
                        file: $temporary->toUploadedFile($original->original_name ?? basename($original->path)),
                        tag: $original->tag,
                        locale: $original->locale,
                        captions: $this->captionsOf($original),
                        crop: $original->crop,
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

    /**
     * Warm every rendition of everything a model owns.
     *
     * The model-level counterpart of the reader, and the one an indexer wants:
     * it is walking the catalogue anyway, and warming there is what lets the
     * pictures on a page be addressed directly the first time anybody opens it.
     *
     * @return int  how many renditions exist afterwards
     */
    public function warmForModel(Model $model, MediaFilter $filter = new MediaFilter(), ImageSize $size = new ImageSize()): int
    {
        $warmed = 0;

        foreach ($this->media->forModel($model, $filter) as $file) {
            $warmed += count($this->warmVariants($file, $size));
        }

        return $warmed;
    }

    /**
     * Produce every rendition of a file up front.
     *
     * Generating on first request means somebody waits for it, and on a
     * catalogue that somebody is usually a search engine reading a page nobody
     * has opened yet. Warming moves the cost to indexing time, where a few
     * extra seconds are free, and leaves the address of a finished file behind
     * — no redirect, no signature, no database lookup between a visitor and a
     * picture.
     *
     * @return list<MediaFile>
     */
    public function warmVariants(MediaFile $media, ImageSize $size = new ImageSize()): array
    {
        if (! $media->type->isImage()) {
            return [];
        }

        $warmed = [];

        foreach ($this->variants->sizesFor($media->width, $media->height, $size, $media->crop) as $variant) {
            $warmed[] = $this->ensureVariant($media, $variant->width, $variant->height, $variant->format);
        }

        return $warmed;
    }

    public function ensureVariant(MediaFile $original, int $width, int $height, string $format): MediaFile
    {
        $disk = $this->storage->disk($original->disk);
        $path = $this->variants->pathFor($original->path, $width, $height, $format, $original->crop);

        return $this->exclusively("{$disk->name()}:{$path}", function () use ($original, $disk, $path, $width, $height, $format): MediaFile {
            $existing = $this->media->findByPath($disk->name(), $path);

            if ($existing !== null && $disk->exists($path)) {
                return $existing;
            }

            [$envelope, $size, $mime] = $this->variants->render($disk, $original, $path, $width, $height, $format);

            $attributes = [
                'mime_type' => $mime,
                'size' => $size,
                ...Envelope::changes($envelope, $existing),
            ];

            // A record whose file went missing: the file is back, and with an
            // encrypting disk under a new key, so the record follows it.
            if ($existing !== null) {
                $existing->update($attributes);

                return $existing;
            }

            try {
                return $this->media->store([
                    'model_type' => $original->model_type,
                    'model_id' => $original->model_id,
                    'disk' => $disk->name(),
                    'path' => $path,
                    'original_name' => basename($path),
                    'type' => MediaType::Image,
                    'extension' => pathinfo($path, PATHINFO_EXTENSION),
                    'width' => $width,
                    'height' => $height,
                    'position' => $original->position,
                    'is_public' => $disk->isPublic(),
                    'is_variant' => true,
                    'tag' => $original->tag,
                    'locale' => $original->locale,
                    'parent_uuid' => $original->uuid,
                    ...$attributes,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // Another worker without a shared lock recorded the same size
                // a moment earlier. Its record is the answer, not an error page.
                return $this->media->findByPath($disk->name(), $path) ?? throw $exception;
            }
        });
    }

    /**
     * One rendition is made by one request at a time.
     *
     * Two first requests for the same size used to both write the file, and the
     * second to record it failed on the unique path. With encryption it would
     * be worse than an error page: each write has its own key, and the record
     * that won could describe the file that lost. Where the cache cannot lock,
     * the unique-path fallback above still holds for files stored as they came.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function exclusively(string $name, Closure $work): mixed
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            return $work();
        }

        return $store->lock('media-kit:' . md5($name), 30)->block(15, $work);
    }

    /**
     * Give a file its area — and the placeholder of that area, since the
     * blurred copy shown before a rendition arrives has to be of what arrives.
     *
     * Nothing else changes, and the renditions cut before stay: the area is in
     * their path, so they are simply not asked for again, while a page cached
     * with their address still shows a picture. The record's timestamp moves,
     * and with it the version in every address not yet cut.
     */
    private function cropFile(MediaFile $file, MediaCrop $crop): void
    {
        $crop = $crop->isWhole() ? null : $crop;

        if ($crop === null ? $file->crop === null : $crop->equals($file->crop)) {
            return;
        }

        $file->update([
            'crop' => $crop,
            'placeholder' => $this->placeholderOf($file, $crop) ?? $file->placeholder,
        ]);
    }

    /**
     * The placeholder of a stored picture, of its area alone when it has one.
     * None for an encrypted file — a readable copy in the database would undo
     * the encryption — and none when the picture cannot be read.
     */
    private function placeholderOf(MediaFile $file, ?MediaCrop $crop): ?string
    {
        if (! $file->type->isImage()) {
            return null;
        }

        $disk = $this->storage->disk($file->disk);

        if ($disk->encrypts()) {
            return null;
        }

        try {
            $image = $this->images->fromContents($disk->read((string) $file->path, Envelope::of($file)));
        } catch (Throwable) {
            return null;
        }

        if ($crop !== null) {
            $this->operations->area($image, $crop);
        }

        return $this->placeholders->forImage($image);
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
     * The type the bytes actually have, not the one the client claimed.
     *
     * The stored type is what the file is later served with, and the browser's
     * claim is whatever the uploader wanted it to be: a page renamed to
     * `invoice.pdf` and sent as `application/pdf` would otherwise come back out
     * as a PDF that is really HTML. The claim is only kept when the content
     * cannot be identified at all.
     */
    /**
     * A copy on this machine of an upload whose bytes are elsewhere, or null
     * when they are already here.
     *
     * Livewire keeps an upload on its temporary disk, and on an object store
     * the file's "real path" is only its key there. Storing it, measuring it
     * and drawing its placeholder all need a file that can be opened.
     */
    private function localCopy(UploadedFile $file): ?TemporaryFile
    {
        if (is_file((string) $file->getRealPath()) || ! method_exists($file, 'readStream')) {
            return null;
        }

        $stream = $file->readStream();

        return is_resource($stream)
            ? TemporaryFile::fromStream($stream, Str::random(20) . '.' . strtolower($file->getClientOriginalExtension()))
            : null;
    }

    private function mimeTypeOf(UploadedFile $file): ?string
    {
        try {
            $detected = $file->getMimeType();
        } catch (Throwable) {
            $detected = null;
        }

        return $detected ?: ($file->getClientMimeType() ?: null);
    }

    /**
     * Size and placeholder of a file already on a disk — from one read.
     *
     * @return array{0: int|null, 1: int|null, 2: string|null}
     */
    private function readFromDisk(MediaType $type, MediaDisk $disk, string $path): array
    {
        if (! $type->isImage()) {
            return [null, null, null];
        }

        try {
            $image = $this->images->fromContents($disk->get($path));
        } catch (Throwable) {
            return [null, null, null];
        }

        return [$image->width(), $image->height(), $this->placeholders->forImage($image)];
    }
}
