<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Contracts\RenditionQuality;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Providers\MediaKitServiceProvider;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Support\ImageOperations;

/**
 * Як робиться рендиція: якість, обрізання, метадані — і що зміна цих
 * налаштувань справді доходить до файлів.
 *
 * Доти якість була захардкоджена (75 у бібліотеці), малий оригінал
 * розтягувався до замовленого розміру, а шлях рендиції залежав лише від
 * розміру — тож нова якість не дійшла б до жодної картинки: файл за тим самим
 * шляхом уже існував.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

/**
 * Шум, а не однотонна заливка: однотонне стискається однаково за будь-якої
 * якості, і різниці не було б видно.
 */
function noisyPhoto(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);

    mt_srand(42);

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            imagesetpixel($image, $x, $y, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'noise') . '.png';
    imagepng($image, $path);

    return new UploadedFile($path, 'noise.png', 'image/png', null, true);
}

function renditionBytes(MediaFile $variant): int
{
    return strlen((string) Storage::disk('public')->get($variant->path));
}

it('encodes renditions at the configured quality', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(noisyPhoto(200, 100)));

    config(['media-kit.image.quality.webp' => 20]);
    $low = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    config(['media-kit.image.quality.webp' => 95]);
    $high = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    expect($low->path)->not->toBe($high->path)
        ->and(renditionBytes($low))->toBeLessThan(renditionBytes($high));
});

it('does not blow a small original up to the asked-for size', function () {
    $sizes = app(ImageVariantGenerator::class)->sizesFor(1000, 500, ImageSize::of(2000, 1000));

    expect(collect($sizes)->mapWithKeys(fn($size) => [$size->name => [$size->width, $size->height]])->all())->toBe([
        'large' => [1000, 500],
        'medium' => [1000, 500],
        'small' => [600, 300],
    ]);
});

it('lists a width once when two renditions end up the same size', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    $resource = app(MediaReader::class)->byUuid($media->uuid, size: ImageSize::of(2000, 1000));

    expect(substr_count((string) $resource->srcset(), '1000w'))->toBe(1);
});

it('still enlarges when the host asks for exact sizes', function () {
    config(['media-kit.image.upscale' => true]);

    $sizes = app(ImageVariantGenerator::class)->sizesFor(1000, 500, ImageSize::of(2000, 1000));

    expect([$sizes[0]->width, $sizes[0]->height])->toBe([2000, 1000]);
});

it('stops serving a rendition made with yesterday\'s settings', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    config(['media-kit.image.quality.webp' => 60]);

    $resource = app(MediaReader::class)->byUuid($media->uuid, size: ImageSize::of(400, 200));
    $old = $media->variants()->pluck('path')->all();

    // Старий файл того самого розміру не підставляється: адреса веде на
    // роут, який зробить рендицію за новими налаштуваннями.
    foreach ($resource->variants as $variant) {
        foreach ($old as $path) {
            expect($variant->url)->not->toContain(basename($path));
        }
    }
});

it('prunes renditions made with other settings, without being told a size', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $stale = $media->variants()->pluck('uuid')->all();

    config(['media-kit.image.version' => 2]);
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    $this->artisan('media:prune')->assertSuccessful();

    expect(MediaFile::query()->whereIn('uuid', $stale)->count())->toBe(0)
        ->and($media->variants()->count())->toBe(3);
});

it('prunes by size only the renditions of the owner it was told about', function () {
    $owner = MediaFile::query()->create(['disk' => 'public', 'path' => 'media/owner.png', 'type' => 'image', 'is_public' => true]);
    $product = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('p.png', 1000, 500)), $owner);
    $banner = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('b.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($product->fresh(), ImageSize::of(400, 200));
    app(MediaWriter::class)->warmVariants($banner->fresh(), ImageSize::of(800, 400));

    // Розмір товару — не розмір банера: без `--model` банер пішов би під ніж.
    $this->artisan('media:prune', ['--width' => 400, '--height' => 200, '--model' => MediaFile::class])
        ->assertSuccessful();

    expect($product->variants()->count())->toBe(3)
        ->and($banner->variants()->count())->toBe(3);
});

it('keeps the package defaults a host did not override', function () {
    config(['media-kit.image' => ['quality' => ['webp' => 50]]]);

    (new MediaKitServiceProvider(app()))->register();

    expect(config('media-kit.image.quality.webp'))->toBe(50)
        ->and(config('media-kit.image.quality.jpg'))->toBe(85)
        ->and(config('media-kit.image.variants'))->toHaveKeys(['large', 'medium', 'small'])
        ->and(config('media-kit.image.placeholder'))->toBeTrue();
});

it('warns when it judges every owner against one size', function () {
    $this->artisan('media:prune', ['--width' => 400, '--height' => 200, '--dry-run' => true])
        ->expectsOutputToContain('No --model given')
        ->assertSuccessful();
});

it('makes a rendition again when its record is there but its file is gone', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 200)));
    $first = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    Storage::disk('public')->delete($first->path);

    $again = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    expect($again->uuid)->toBe($first->uuid)
        ->and(MediaFile::query()->where('path', $first->path)->count())->toBe(1);

    Storage::disk('public')->assertExists($first->path);
});

it('answers with the other worker\'s record when both made the same rendition at once', function () {
    // No lock to share — say, a file cache on two hosts: both requests see no
    // record, both write the file, and the second insert hits the unique path.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 200)));
    $path = app(ImageVariantGenerator::class)->pathFor($media->path, 200, 100, 'webp');

    $winner = MediaFile::query()->create([
        'disk' => 'public', 'path' => $path, 'type' => 'image', 'is_public' => true,
        'is_variant' => true, 'parent_uuid' => $media->uuid, 'width' => 200, 'height' => 100,
    ]);

    $repository = new class extends KandySoft\MediaKit\Repositories\MediaFileRepository {
        public bool $looked = false;

        public function findByPath(string $disk, string $path): ?MediaFile
        {
            // The first look happens before the other worker's insert.
            if (! $this->looked) {
                $this->looked = true;

                return null;
            }

            return parent::findByPath($disk, $path);
        }
    };

    app()->instance(KandySoft\MediaKit\Repositories\MediaFileRepository::class, $repository);
    app()->forgetInstance(MediaWriter::class);
    app()->forgetInstance(KandySoft\MediaKit\Services\MediaWriteService::class);

    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    // Taken from the failed insert, not from a second pass that found the
    // record and rewrote it: the winner's record is left as the winner wrote it.
    expect($repository->looked)->toBeTrue()
        ->and($variant->uuid)->toBe($winner->uuid)
        ->and($winner->fresh()->mime_type)->toBeNull();
});

it('asks the host for the quality at the moment of encoding', function () {
    // A setting in an admin panel changes while a worker lives: the answer is
    // asked for every time, not copied once.
    $chosen = new class implements RenditionQuality {
        public int $value = 70;

        public function for(string $format): ?int
        {
            return $format === 'webp' ? $this->value : null;
        }
    };

    app()->instance(RenditionQuality::class, $chosen);

    foreach ([ImageOperations::class, ImageVariantGenerator::class] as $service) {
        app()->forgetInstance($service);
    }

    $operations = app(ImageOperations::class);
    $generator = app(ImageVariantGenerator::class);
    $before = $generator->pathFor('media/a.jpg', 100, 100, 'webp');

    $chosen->value = 40;

    expect($operations->encoderOptions('webp')['quality'])->toBe(40)
        ->and($operations->encoderOptions('png'))->not->toHaveKey('quality')
        // A new quality is a new file, never the old one overwritten.
        ->and($generator->pathFor('media/a.jpg', 100, 100, 'webp'))->not->toBe($before);
});

it('reads the quality from configuration by default', function () {
    config(['media-kit.image.quality.webp' => 63]);

    expect(app(ImageOperations::class)->encoderOptions('webp')['quality'])->toBe(63);
});
