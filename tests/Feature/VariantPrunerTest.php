<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaCrop;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Services\MediaWriteService;
use KandySoft\MediaKit\Services\VariantPruner;
use KandySoft\MediaKit\Support\ImageOperations;

/**
 * Renditions an application no longer asks for, deleted by the application
 * itself — after a new quality, a new area, a size it stopped using.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

function prunedOwner(): MediaFile
{
    return MediaFile::query()->create([
        'disk' => 'public',
        'path' => 'media/owners/' . uniqid() . '.png',
        'type' => MediaType::Image,
        'is_public' => true,
    ]);
}

/**
 * Forget the services that read settings once, as a new worker would.
 */
function freshRenditionServices(): void
{
    foreach ([ImageOperations::class, ImageVariantGenerator::class, MediaWriteService::class, VariantPruner::class] as $service) {
        app()->forgetInstance($service);
    }
}

it('deletes the renditions made at a quality no longer used, and only those', function () {
    $file = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));
    $old = app(MediaWriter::class)->ensureVariant($file, 100, 100, 'webp');

    config(['media-kit.image.quality.webp' => 41]);
    freshRenditionServices();
    $new = app(MediaWriter::class)->ensureVariant($file, 100, 100, 'webp');

    expect(app(VariantPruner::class)->stale())->toBe(1)
        ->and(MediaFile::query()->find($old->uuid))->toBeNull()
        ->and(Storage::disk('public')->exists($old->path))->toBeFalse()
        ->and(MediaFile::query()->find($new->uuid))->not->toBeNull();
});

it('deletes the renditions of a file cut from its previous area', function () {
    $writer = app(MediaWriter::class);
    $file = $writer->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 200)));
    $other = $writer->store(MediaUpload::file(UploadedFile::fake()->image('b.png', 400, 200)));
    $old = $writer->ensureVariant($file, 100, 100, 'webp');
    $untouched = $writer->ensureVariant($other, 100, 100, 'webp');

    $file = $writer->crop($file->uuid, MediaCrop::of(0, 0, 0.5, 1));
    $new = $writer->ensureVariant($file, 100, 100, 'webp');

    expect(app(VariantPruner::class)->stale($file->uuid))->toBe(1)
        ->and(MediaFile::query()->find($old->uuid))->toBeNull()
        ->and(MediaFile::query()->find($new->uuid))->not->toBeNull()
        ->and(MediaFile::query()->find($untouched->uuid))->not->toBeNull();
});

it('deletes the renditions of a retired size for one kind of file, keeping the rest', function () {
    $writer = app(MediaWriter::class);
    $owner = prunedOwner();
    $writer->sync($owner, [MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 1000), tag: 'gallery')]);
    $photo = MediaFile::query()->where('model_id', $owner->getKey())->where('is_variant', false)->sole();
    $standalone = $writer->store(MediaUpload::file(UploadedFile::fake()->image('b.png', 1000, 1000)));

    $writer->warmVariants($photo, ImageSize::of(500, 500, 'webp'));
    $writer->warmVariants($photo, ImageSize::of(600, 600, 'webp'));
    // The same picture asked for elsewhere at another size stays.
    $writer->warmVariants($photo, ImageSize::of(320, 320, 'webp'));
    $writer->warmVariants($standalone, ImageSize::of(500, 500, 'webp'));

    $removed = app(VariantPruner::class)->retired(
        ImageSize::of(500, 500, 'webp'),
        ImageSize::of(600, 600, 'webp'),
        $owner->getMorphClass(),
        'gallery',
    );

    $widths = fn(MediaFile $file) => $file->variants()->pluck('width')->sort()->values()->all();

    // 500 → 500, 300, 150 retired; 600 → 600, 360, 180 and 320 → 320, 192, 96 kept.
    expect($removed)->toBe(3)
        ->and($widths($photo))->toBe([96, 180, 192, 320, 360, 600])
        ->and($widths($standalone))->toBe([150, 300, 500]);
});

it('keeps a rendition the retired and the current size share', function () {
    $writer = app(MediaWriter::class);
    $owner = prunedOwner();
    $writer->sync($owner, [MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 1000))]);
    $photo = MediaFile::query()->where('model_id', $owner->getKey())->where('is_variant', false)->sole();

    $writer->warmVariants($photo, ImageSize::of(500, 500, 'webp'));

    // Only the format changed: every webp goes, nothing is shared.
    expect(app(VariantPruner::class)->retired(ImageSize::of(500, 500, 'webp'), ImageSize::of(500, 500, 'webp'), $owner->getMorphClass()))->toBe(0)
        ->and(app(VariantPruner::class)->retired(ImageSize::of(500, 500, 'webp'), ImageSize::of(500, 500, 'jpg'), $owner->getMorphClass()))->toBe(3);
});
