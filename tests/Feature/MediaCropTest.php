<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaCrop;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Models\MediaFile;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Services\MediaWriteService;
use KandySoft\MediaKit\Support\ImageFactory;

/**
 * The part of a picture a person chose: what renditions are cut from, how
 * they are addressed, and what a page is told about it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

/**
 * A landscape picture in three vertical stripes — red, green, blue — so which
 * part a rendition was cut from shows in its middle pixel.
 */
function stripesPng(int $width = 300, int $height = 100): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    $third = intdiv($width, 3);
    imagefilledrectangle($image, 0, 0, $third - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, $third, 0, 2 * $third - 1, $height - 1, imagecolorallocate($image, 0, 255, 0));
    imagefilledrectangle($image, 2 * $third, 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));

    $path = tempnam(sys_get_temp_dir(), 'stripes') . '.png';
    imagepng($image, $path);

    return new UploadedFile($path, 'stripes.png', 'image/png', null, true);
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function middleColour(MediaFile $variant): array
{
    $image = app(ImageFactory::class)->fromContents(Storage::disk('public')->get($variant->path));
    $colour = $image->pickColor(intdiv($image->width(), 2), intdiv($image->height(), 2));

    return [$colour->red()->value(), $colour->green()->value(), $colour->blue()->value()];
}

it('cuts a rendition from the middle of a picture without an area', function () {
    $file = app(MediaWriter::class)->store(MediaUpload::file(stripesPng()));
    $variant = app(MediaWriter::class)->ensureVariant($file, 50, 50, 'png');

    expect(middleColour($variant))->toBe([0, 255, 0]);
});

it('cuts a rendition from the chosen area', function (string $driver) {
    if ($driver === 'imagick' && ! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not installed');
    }

    // Imagick takes the Lanczos path, the one production uses.
    config(['media-kit.image.driver' => $driver]);

    foreach ([ImageFactory::class, ImageVariantGenerator::class, MediaWriteService::class] as $service) {
        app()->forgetInstance($service);
    }

    $file = app(MediaWriter::class)->store(MediaUpload::file(stripesPng(), crop: MediaCrop::of(2 / 3, 0, 1 / 3, 1)));
    $variant = app(MediaWriter::class)->ensureVariant($file, 50, 50, 'png');

    expect($file->crop?->x)->toBe(0.6667)
        ->and(middleColour($variant))->toBe([0, 0, 255]);
})->with(['gd', 'imagick']);

it('addresses a rendition of an area apart from the uncut one, and leaves the uncut path alone', function () {
    $generator = app(ImageVariantGenerator::class);
    $plain = $generator->pathFor('media/a.png', 50, 50, 'png');

    expect($generator->pathFor('media/a.png', 50, 50, 'png', null))->toBe($plain)
        ->and($generator->pathFor('media/a.png', 50, 50, 'png', MediaCrop::whole()))->toBe($plain)
        ->and($generator->pathFor('media/a.png', 50, 50, 'png', MediaCrop::of(0, 0, 0.5, 1)))->not->toBe($plain);
});

it('cuts the renditions again once the area changes', function () {
    $writer = app(MediaWriter::class);
    $file = $writer->store(MediaUpload::file(stripesPng()));
    $before = $writer->ensureVariant($file, 50, 50, 'png');

    $file = $writer->crop($file->uuid, MediaCrop::of(0, 0, 1 / 3, 1));
    $after = $writer->ensureVariant($file, 50, 50, 'png');

    expect($after->path)->not->toBe($before->path)
        ->and(middleColour($after))->toBe([255, 0, 0])
        // A page cached with the old address still finds a picture there.
        ->and(Storage::disk('public')->exists($before->path))->toBeTrue();
});

it('takes the area away with the whole picture', function () {
    $writer = app(MediaWriter::class);
    $file = $writer->store(MediaUpload::file(stripesPng(), crop: MediaCrop::of(0, 0, 1 / 3, 1)));

    expect($writer->crop($file->uuid, MediaCrop::whole())->crop)->toBeNull()
        ->and($file->refresh()->getRawOriginal('crop'))->toBeNull();
});

it('draws the placeholder from the area', function () {
    $writer = app(MediaWriter::class);
    $file = $writer->store(MediaUpload::file(stripesPng()));
    $whole = $file->placeholder;

    $file = $writer->crop($file->uuid, MediaCrop::of(2 / 3, 0, 1 / 3, 1));
    $placeholder = app(ImageFactory::class)->fromContents(base64_decode(substr((string) $file->placeholder, strlen('data:image/webp;base64,'))));

    expect($file->placeholder)->not->toBe($whole)
        ->and($placeholder->width())->toBe($placeholder->height());
});

it('keeps an area through a sync that does not mention it, and changes it through one that does', function () {
    $writer = app(MediaWriter::class);
    $owner = owner();

    $writer->sync($owner, [MediaUpload::file(stripesPng(), crop: MediaCrop::of(0, 0, 1 / 3, 1))]);
    $file = MediaFile::query()->where('model_id', $owner->getKey())->sole();

    $writer->sync($owner, [MediaUpload::keep($file->uuid)]);
    expect($file->refresh()->crop?->width)->toBe(0.3333);

    $writer->sync($owner, [MediaUpload::fromArray(['uuid' => $file->uuid, 'crop' => ['x' => 0.5, 'y' => 0, 'width' => 0.5, 'height' => 1]])]);
    expect($file->refresh()->crop?->x)->toBe(0.5);
});

it('forgets the area of a replaced picture', function () {
    $writer = app(MediaWriter::class);
    $file = $writer->store(MediaUpload::file(stripesPng(), crop: MediaCrop::of(0, 0, 1 / 3, 1)));

    expect($writer->replace($file->uuid, stripesPng())->crop)->toBeNull();
});

it('sizes a free side by the area and tells a page where the area sits', function () {
    $file = app(MediaWriter::class)->store(MediaUpload::file(stripesPng(600, 200), crop: MediaCrop::of(0, 0, 0.25, 1)));

    // The area is 150×200: a width alone gives its proportions.
    $free = app(MediaReader::class)->byUuid($file->uuid, size: ImageSize::of(150, null));
    expect($free->variant('large')?->height)->toBe(200)
        ->and($free->toArray()['focus'])->toBe(['x' => 0.5, 'y' => 0.5]);

    // A square frame holding it is 200×200 from the left edge; the area's
    // centre is at 75 of 200.
    $square = app(MediaReader::class)->byUuid($file->uuid, size: ImageSize::square(200));
    expect($square->toArray()['focus'])->toBe(['x' => 0.375, 'y' => 0.5]);
});

it('tells a page nothing about the middle of an uncut picture', function () {
    $file = app(MediaWriter::class)->store(MediaUpload::file(stripesPng()));

    expect(app(MediaReader::class)->byUuid($file->uuid, size: ImageSize::square(50))->toArray())->not->toHaveKey('focus');
});
