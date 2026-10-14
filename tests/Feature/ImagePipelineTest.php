<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Providers\MediaKitServiceProvider;
use KandySoft\MediaKit\Support\ImageFactory;
use KandySoft\MediaKit\Support\ImageOperations;

/**
 * Що робиться з картинкою дорогою на вітрину: оригінал, колір, масштабування,
 * повторна генерація й віддача шматками.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

function storedSize(MediaFile $media): array
{
    return array_slice(getimagesizefromstring((string) Storage::disk('public')->get($media->path)), 0, 2);
}

it('scales an original larger than the limits down before storing it', function () {
    config(['media-kit.image.original.max_width' => 1200, 'media-kit.image.original.max_height' => 1200]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('big.jpg', 3000, 1500)));

    expect([$media->width, $media->height])->toBe([1200, 600])
        ->and(storedSize($media))->toBe([1200, 600]);
});

it('stores an original within the limits byte for byte', function () {
    config(['media-kit.image.original.max_width' => 4000]);

    $upload = UploadedFile::fake()->image('small.png', 800, 400);
    $bytes = file_get_contents($upload->getRealPath());

    $media = app(MediaWriter::class)->store(MediaUpload::file($upload));

    expect(Storage::disk('public')->get($media->path))->toBe($bytes);
});

it('keeps the package defaults for the original a host did not set', function () {
    config(['media-kit.image' => ['original' => ['max_width' => 2000]]]);

    (new MediaKitServiceProvider(app()))->register();

    expect(config('media-kit.image.original.max_width'))->toBe(2000)
        ->and(config('media-kit.image.original.quality'))->toBe(92)
        ->and(config('media-kit.image.original.strip_metadata'))->toBeFalse();
});

it('scales with Lanczos on Imagick and still gives the exact size', function () {
    config(['media-kit.image.driver' => 'imagick', 'media-kit.image.resample' => 'lanczos']);
    app()->forgetInstance(ImageFactory::class);

    $upload = UploadedFile::fake()->image('a.png', 1000, 600);
    $image = app(ImageFactory::class)->fromContents((string) file_get_contents($upload->getRealPath()));

    app(ImageOperations::class)->cover($image, 300, 300);

    expect([$image->width(), $image->height()])->toBe([300, 300]);
})->skip(fn() => ! extension_loaded('imagick'), 'Imagick is not installed');

it('converts a picture with a colour profile to sRGB', function () {
    config(['media-kit.image.driver' => 'imagick', 'media-kit.image.srgb' => true]);
    app()->forgetInstance(ImageFactory::class);

    $srgb = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/icc/sRGB-v2-micro.icc');

    $native = new Imagick();
    $native->newImage(40, 20, new ImagickPixel('rgb(200,30,30)'));
    $native->setImageFormat('png');
    $native->profileImage('icc', $srgb);

    $image = app(ImageFactory::class)->fromContents($native->getImageBlob());

    app(ImageOperations::class)->toSrgb($image);

    expect($image->core()->native()->getImageProfiles('icc', true))->toHaveKey('icc');
})->skip(fn() => ! extension_loaded('imagick'), 'Imagick is not installed');

it('puts the resampler and colour handling into the rendition path', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    config(['media-kit.image.resample' => 'lanczos']);
    $lanczos = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp')->path;

    config(['media-kit.image.resample' => 'default']);
    $plain = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp')->path;

    expect($lanczos)->not->toBe($plain);
});

it('makes every rendition again under new settings and drops the old ones', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $old = $media->variants()->pluck('path')->all();

    config(['media-kit.image.quality.webp' => 60]);

    $this->artisan('media:regenerate', ['--prune' => true])->assertSuccessful();

    $new = $media->variants()->pluck('path')->all();

    expect($new)->toHaveCount(3)
        ->and(array_intersect($old, $new))->toBe([]);

    foreach ($old as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});

it('serves the part of a file that was asked for, decrypting only what is sent', function () {
    // Зашифрований файл віддається лише через застосунок — саме там і
    // потрібні шматки: відео з такого диска інакше не перемотати.
    config([
        'media-kit.encryption.enabled' => true,
        'media-kit.encryption.keys' => 'k1:' . base64_encode(str_repeat('a', 32)),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, '0123456789abcdefghij');
    $media = app(MediaWriter::class)->store(MediaUpload::file(new UploadedFile($path, 'doc.pdf', null, null, true)));

    $url = app(MediaReader::class)->byUuid($media->uuid)->url;

    $part = $this->get($url, ['Range' => 'bytes=2-5']);
    $tail = $this->get($url, ['Range' => 'bytes=-3']);
    $beyond = $this->get($url, ['Range' => 'bytes=50-60']);

    expect($part->getStatusCode())->toBe(206)
        ->and($part->streamedContent())->toBe('2345')
        ->and($part->headers->get('Content-Range'))->toBe('bytes 2-5/20')
        ->and($tail->streamedContent())->toBe('hij')
        ->and($beyond->getStatusCode())->toBe(416);
});

it('only counts the renditions it would make again when asked to look', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $old = $media->variants()->pluck('path')->sort()->values()->all();

    config(['media-kit.image.version' => 2]);

    $this->artisan('media:regenerate', ['--dry-run' => true])
        ->expectsOutputToContain('3 renditions would be made again')
        ->assertSuccessful();

    expect($media->variants()->pluck('path')->sort()->values()->all())->toBe($old);
});

it('leaves a rendition made under today\'s settings alone', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    $this->artisan('media:regenerate')
        ->expectsOutputToContain('Made 0 renditions again')
        ->assertSuccessful();

    expect($media->variants()->count())->toBe(3);
});

it('keeps the old renditions until told to prune them', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $old = $media->variants()->pluck('path')->all();

    config(['media-kit.image.version' => 2]);

    $this->artisan('media:regenerate')->assertSuccessful();

    expect($media->variants()->count())->toBe(6);

    foreach ($old as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('makes again only the renditions of the owner it was told about', function () {
    $owner = MediaFile::query()->create(['disk' => 'public', 'path' => 'media/owner.png', 'type' => 'image', 'is_public' => true]);
    $product = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('p.png', 1000, 500)), $owner);
    $banner = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('b.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($product->fresh(), ImageSize::of(400, 200));
    app(MediaWriter::class)->warmVariants($banner->fresh(), ImageSize::of(400, 200));

    config(['media-kit.image.version' => 2]);

    $this->artisan('media:regenerate', ['--model' => MediaFile::class, '--prune' => true])->assertSuccessful();

    $generator = app(KandySoft\MediaKit\Services\ImageVariantGenerator::class);
    $current = fn(MediaFile $original) => $original->variants()->get()
        ->filter(fn(MediaFile $v) => $v->path === $generator->pathFor($original->path, (int) $v->width, (int) $v->height, (string) $v->extension))
        ->count();

    expect($current($product))->toBe(3)
        ->and($current($banner))->toBe(0)
        ->and($banner->variants()->count())->toBe(3);
});

it('makes again only the renditions on the disks it was told about', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    config(['media-kit.image.version' => 2]);

    $this->artisan('media:regenerate', ['--disk' => ['elsewhere']])
        ->expectsOutputToContain('Made 0 renditions again')
        ->assertSuccessful();
});
