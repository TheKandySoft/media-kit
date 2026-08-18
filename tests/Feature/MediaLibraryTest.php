<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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

it('keeps a variant address stable, so a cache or a crawler can rely on it', function () {
    // The address used to carry an expiry, which meant it changed on every
    // render: browsers re-downloaded pictures they already held, and anything
    // that read the address once and fetched it later — an image crawler, a
    // product feed, a link preview — arrived at a dead link.
    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));

    $first = app(MediaReader::class)
        ->byUuid($original->uuid, new MediaFilter(), ImageSize::of(400, 200))
        ->variant('large')?->url;

    Carbon::setTestNow(now()->addDays(30));

    $later = app(MediaReader::class)
        ->byUuid($original->uuid, new MediaFilter(), ImageSize::of(400, 200))
        ->variant('large')?->url;

    expect($first)->toBe($later)
        ->and($first)->not->toContain('expires=')
        ->and($first)->toContain('signature=');

    Carbon::setTestNow();
});

it('still serves a variant a month after the address was published', function () {
    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));

    $url = app(MediaReader::class)
        ->byUuid($original->uuid, new MediaFilter(), ImageSize::of(400, 200))
        ->variant('large')?->url;

    Carbon::setTestNow(now()->addDays(30));

    // A public disk answers with a redirect to the file itself; what matters
    // is that the signature is still accepted instead of a 403.
    $this->get($url)->assertRedirect();

    Carbon::setTestNow();
});

it('expires a variant address when the shop asks for it', function () {
    // Permanent is the default, not the only option: images that are private
    // still need links that stop working.
    config(['media-kit.variant_url_ttl' => 5]);

    $original = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.png', 1000, 500)));

    $url = app(MediaReader::class)
        ->byUuid($original->uuid, new MediaFilter(), ImageSize::of(400, 200))
        ->variant('large')?->url;

    expect($url)->toContain('expires=');

    Carbon::setTestNow(now()->addMinutes(10));

    $this->get($url)->assertForbidden();

    Carbon::setTestNow();
});

it('tells caches a public file may be kept', function () {
    // Without this every visit re-downloads the same picture, and a CDN in
    // front of the shop caches nothing at all.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('photo.png', 200, 200)));

    $this->get(route('media-kit.file', ['uuid' => $media->uuid]))
        ->assertRedirect()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
});

it('keeps a private file out of shared caches and out of them for long', function () {
    // A private file is reached through a link that expires, so the answer may
    // not outlive it: a browser that cached the redirect for a year would keep
    // following it to an address that stopped working the same day.
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);

    $media = MediaFile::query()->create([
        'disk' => 'secret',
        'path' => 'media/private/' . uniqid() . '.png',
        'type' => MediaType::Image,
        'is_public' => false,
    ]);

    Storage::disk('secret')->put($media->path, 'bytes');

    $this->get(URL::signedRoute('media-kit.file', ['uuid' => $media->uuid]))
        ->assertHeader('Cache-Control', 'max-age=3600, private');
});

it('stops advertising a cache lifetime when it is switched off', function () {
    config(['media-kit.cache_ttl' => 0]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('photo.png', 200, 200)));

    $this->get(route('media-kit.file', ['uuid' => $media->uuid]))
        ->assertRedirect()
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');
});

it('reports a public disk as public and a private one as private', function () {
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);

    expect(app(MediaStorage::class)->disk('public')->isPublic())->toBeTrue()
        ->and(app(MediaStorage::class)->disk('secret')->isPublic())->toBeFalse();
});

it('lets one disk expire its links while another keeps them forever', function () {
    // Це те, чого раніше не можна було висловити взагалі: строк був один на
    // весь застосунок, тож «документи живуть чверть години, а фото каталогу —
    // завжди» доводилось обирати як щось одне.
    Storage::fake('documents');
    config([
        'filesystems.disks.documents.visibility' => 'public',
        'media-kit.disks.documents' => ['variant_url_ttl' => 15],
    ]);

    $catalogue = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));
    $document = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('b.png', 400, 400)), null, 'documents');

    $read = fn(string $uuid): ?string => app(MediaReader::class)
        ->byUuid($uuid, new MediaFilter(), ImageSize::of(200, 200))
        ->variant('large')?->url;

    expect($read($catalogue->uuid))->not->toContain('expires=')
        ->and($read($document->uuid))->toContain('expires=');
});

it('leaves a disk nobody configured exactly as it was', function () {
    config(['media-kit.disks.documents' => ['variant_url_ttl' => 15]]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));

    expect(app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(200, 200))->variant('large')?->url)
        ->not->toContain('expires=');
});
