<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Exceptions\UnsupportedFileType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Repositories\MediaFileRepository;
use KandySoft\MediaKit\Services\MediaReadService;
use KandySoft\MediaKit\Services\MediaWriteService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

function owner(): MediaFile
{
    // Any model will do as an owner; a media row is the one always available.
    return MediaFile::query()->create([
        'disk' => 'public',
        'path' => 'media/owners/' . uniqid() . '.png',
        'type' => MediaType::Image,
        'is_public' => true,
    ]);
}

it('hands the same instance to the facade and to an injected contract', function () {
    expect(app(MediaReader::class))->toBe(app(MediaReadService::class))
        ->and(app(MediaWriter::class))->toBe(app(MediaWriteService::class));
});

it('stores a file on the configured disk and addresses it by uuid', function () {
    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->image('photo.png', 800, 400), tag: 'gallery'),
    );

    Storage::disk('public')->assertExists($media->path);

    expect($media->uuid)->toBeString()
        ->and($media->getKeyName())->toBe('uuid')
        ->and($media->disk)->toBe('public')
        ->and($media->type)->toBe(MediaType::Image)
        ->and($media->width)->toBe(800)
        ->and($media->height)->toBe(400);
});

it('refuses a file type it cannot describe', function () {
    app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->create('payload.exe', 10)),
    );
})->throws(UnsupportedFileType::class);

it('writes captions alongside the file', function () {
    $media = app(MediaWriter::class)->store(
        MediaUpload::file(
            UploadedFile::fake()->image('photo.png'),
            captions: [new MediaCaption('uk', alt: 'Опис'), new MediaCaption('en', alt: 'Caption')],
        ),
    );

    $resource = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(captionLocale: 'uk'));

    expect($resource->caption?->alt)->toBe('Опис')
        ->and($resource->captions)->toHaveKeys(['uk', 'en']);
});

it('keeps listed files and deletes the rest on sync', function () {
    $model = owner();
    $writer = app(MediaWriter::class);

    $writer->sync($model, [
        MediaUpload::file(UploadedFile::fake()->image('one.png'), tag: 'gallery'),
        MediaUpload::file(UploadedFile::fake()->image('two.png'), tag: 'gallery'),
    ]);

    $kept = app(MediaFileRepository::class)->forModel($model)->first();

    $writer->sync($model, [MediaUpload::keep($kept->uuid, tag: 'gallery')]);

    $remaining = app(MediaFileRepository::class)->forModel($model);

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->uuid)->toBe($kept->uuid);
});

it('deletes the file from the disk along with its record', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('gone.png')));
    $path = $media->path;

    app(MediaWriter::class)->delete($media->uuid);

    Storage::disk('public')->assertMissing($path);
    expect(MediaFile::query()->find($media->uuid))->toBeNull();
});

it('swaps the file behind a uuid without changing the uuid', function () {
    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->image('old.png', 100, 100), tag: 'logo'),
    );
    $oldPath = $media->path;

    $replaced = app(MediaWriter::class)->replace(
        $media->uuid,
        UploadedFile::fake()->image('new.png', 400, 200),
    );

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($replaced->path);

    expect($replaced->uuid)->toBe($media->uuid)
        ->and($replaced->tag)->toBe('logo')
        ->and($replaced->width)->toBe(400)
        ->and($replaced->original_name)->toBe('new.png')
        ->and(MediaFile::query()->count())->toBe(1);
});

it('throws away variants of the file it replaces', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('old.png', 800, 400)));
    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    app(MediaWriter::class)->replace($media->uuid, UploadedFile::fake()->image('new.png', 800, 400));

    Storage::disk('public')->assertMissing($variant->path);
    expect(MediaFile::query()->find($variant->uuid))->toBeNull();
});

it('refuses to replace a file with a type it cannot store', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('old.png')));

    app(MediaWriter::class)->replace($media->uuid, UploadedFile::fake()->create('payload.exe', 4));
})->throws(UnsupportedFileType::class);

it('generates a variant on demand and links it back to the original', function () {
    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));

    $variant = app(MediaWriter::class)->ensureVariant($original, 200, 100, 'webp');

    Storage::disk('public')->assertExists($variant->path);

    expect($variant->is_variant)->toBeTrue()
        ->and($variant->parent_uuid)->toBe($original->uuid)
        ->and($variant->width)->toBe(200);
});

it('reuses an existing variant instead of generating it twice', function () {
    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));

    $first = app(MediaWriter::class)->ensureVariant($original, 200, 100, 'webp');
    $second = app(MediaWriter::class)->ensureVariant($original, 200, 100, 'webp');

    expect($second->uuid)->toBe($first->uuid)
        ->and($original->variants()->count())->toBe(1);
});

it('removes generated variants when the original goes', function () {
    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));
    $variant = app(MediaWriter::class)->ensureVariant($original, 200, 100, 'webp');

    app(MediaWriter::class)->delete($original->uuid);

    Storage::disk('public')->assertMissing($variant->path);
    expect(MediaFile::query()->find($variant->uuid))->toBeNull();
});

it('substitutes the fallback image only when images were asked for', function () {
    $model = owner();
    $reader = app(MediaReader::class);

    expect($reader->firstForModel($model, MediaFilter::images(withFallback: true)))->not->toBeNull()
        ->and($reader->firstForModel($model, new MediaFilter(types: [MediaType::Video], withFallback: true)))->toBeNull();
});

it('touches no storage until a fallback is actually requested', function () {
    app(MediaStorage::class)->disk();

    expect(MediaFile::query()->where('tag', MediaFileRepository::FALLBACK_TAG)->exists())->toBeFalse();

    app(MediaWriter::class)->fallbackImage();

    expect(MediaFile::query()->where('tag', MediaFileRepository::FALLBACK_TAG)->exists())->toBeTrue();
});

it('describes variants as signed links without generating them', function () {
    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));

    $resource = app(MediaReader::class)->byUuid($original->uuid, new MediaFilter(), ImageSize::of(400, 200));

    expect($resource->variant('large')?->width)->toBe(400)
        ->and($resource->variant('medium')?->width)->toBe(240)
        ->and($resource->variant('large')?->url)->toContain('signature=')
        ->and($original->variants()->count())->toBe(0);
});

it('reports a public disk as public and a private one as private', function () {
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);

    expect(app(MediaStorage::class)->disk('public')->isPublic())->toBeTrue()
        ->and(app(MediaStorage::class)->disk('secret')->isPublic())->toBeFalse();
});
