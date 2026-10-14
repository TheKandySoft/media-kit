<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Services\ImageVariantGenerator;
use KandySoft\MediaKit\Support\ImageFactory;
use KandySoft\MediaKit\Support\ImageOperations;

/**
 * The steps every rendition goes through — crop, colour, metadata, encoder
 * options — and that each setting behind them reaches the file.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

/**
 * A picture whose top half is red and bottom half blue: where a crop was
 * anchored shows in a single pixel.
 */
function halvesPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 2) - 1, imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 0, intdiv($height, 2), $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * A JPEG carrying an EXIF block with a recognisable marker — what a phone puts
 * there is the place and the time the photo was taken.
 */
function texturePng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    mt_srand(11);

    for ($x = 0; $x < $width; $x += 4) {
        for ($y = 0; $y < $height; $y += 4) {
            $grey = mt_rand(40, 215);
            imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, imagecolorallocate($image, $grey, $grey, $grey));
        }
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function jpegWithExif(int $width, int $height, string $marker = 'GPS-48.4647N-35.0462E'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 60));

    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    // Big-endian TIFF header, an empty IFD, then the marker as trailing data.
    $payload = "Exif\x00\x00" . "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x00" . "\x00\x00\x00\x00" . $marker;
    $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    $path = tempnam(sys_get_temp_dir(), 'exif');
    file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));

    return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
}

function useDriver(string $driver): void
{
    config(['media-kit.image.driver' => $driver]);
    app()->forgetInstance(ImageFactory::class);
}

function renditionContents(string $path): string
{
    return (string) Storage::disk('public')->get($path);
}

dataset('drivers', [
    'gd' => ['gd'],
    'imagick' => ['imagick'],
]);

it('crops at the configured anchor', function (string $driver, string $position, array $rgb) {
    if ($driver === 'imagick' && ! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not installed');
    }

    useDriver($driver);
    config(['media-kit.image.crop_position' => $position, 'media-kit.image.resample' => 'lanczos']);

    $image = app(ImageFactory::class)->fromContents(halvesPng(100, 200));
    app(ImageOperations::class)->cover($image, 50, 50);

    $colour = $image->pickColor(25, 25);

    expect([$image->width(), $image->height()])->toBe([50, 50])
        ->and([$colour->red()->value(), $colour->green()->value(), $colour->blue()->value()])->toBe($rgb);
})->with('drivers')->with([
    'top' => ['top', [255, 0, 0]],
    'bottom' => ['bottom', [0, 0, 255]],
]);

it('falls back to the centre for an anchor the library does not know', function () {
    config(['media-kit.image.crop_position' => 'somewhere']);

    expect(app(ImageOperations::class)->cropPosition())->toBe('center');
});

it('accepts an anchor written in capitals', function () {
    config(['media-kit.image.crop_position' => 'TOP-LEFT']);

    expect(app(ImageOperations::class)->cropPosition())->toBe('top-left');
});

it('keeps the quality between 1 and 100 whatever the configuration says', function () {
    config(['media-kit.image.quality.webp' => 150, 'media-kit.image.quality.jpg' => -5]);

    $operations = app(ImageOperations::class);

    expect($operations->encoderOptions('webp')['quality'])->toBe(100)
        ->and($operations->encoderOptions('jpg')['quality'])->toBe(1);
});

it('lets an explicit quality win over the configured one', function () {
    config(['media-kit.image.quality.jpg' => 60]);

    expect(app(ImageOperations::class)->encoderOptions('jpg', 92)['quality'])->toBe(92);
});

it('leaves the quality to the encoder for a format nobody configured', function () {
    expect(app(ImageOperations::class)->encoderOptions('gif'))->not->toHaveKey('quality');
});

it('passes metadata and interlacing switches to the encoder', function () {
    config(['media-kit.image.strip_metadata' => false, 'media-kit.image.progressive' => false]);

    expect(app(ImageOperations::class)->encoderOptions('jpg'))->toMatchArray([
        'strip' => false,
        'progressive' => false,
        'interlaced' => false,
    ]);
});

it('gives a rendition a new path whenever a setting that shapes its pixels changes', function (string $key, mixed $value) {
    $generator = app(ImageVariantGenerator::class);
    $before = $generator->pathFor('media/a.png', 200, 100, 'webp');

    config(["media-kit.image.{$key}" => $value]);

    expect($generator->pathFor('media/a.png', 200, 100, 'webp'))->not->toBe($before);
})->with([
    'crop' => ['crop_position', 'top'],
    'sharpen' => ['sharpen', 5],
    'colour' => ['srgb', false],
    'resampler' => ['resample', 'default'],
    'metadata' => ['strip_metadata', false],
    'interlacing' => ['progressive', false],
    'version' => ['version', 2],
    'quality' => ['quality.webp', 50],
]);

it('keeps the path when only another format\'s quality changes', function () {
    $generator = app(ImageVariantGenerator::class);
    $before = $generator->pathFor('media/a.png', 200, 100, 'webp');

    config(['media-kit.image.quality.jpg' => 40]);

    expect($generator->pathFor('media/a.png', 200, 100, 'webp'))->toBe($before);
});

it('sharpens a rendition when asked to', function (string $driver) {
    if ($driver === 'imagick' && ! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not installed');
    }

    useDriver($driver);
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));
    // Fine texture, not flat colour: a clean edge between two flat fields is
    // already as sharp as it gets, and some GD builds leave it byte for byte.
    Storage::disk('public')->put($media->path, texturePng(400, 400));

    config(['media-kit.image.sharpen' => 0, 'media-kit.image.quality.png' => 100]);
    $plain = app(MediaWriter::class)->ensureVariant($media, 100, 100, 'png');

    config(['media-kit.image.sharpen' => 60]);
    $sharp = app(MediaWriter::class)->ensureVariant($media, 100, 100, 'png');

    expect(renditionContents($sharp->path))->not->toBe(renditionContents($plain->path));
})->with('drivers');

it('drops the camera\'s metadata from a rendition', function (string $driver) {
    if ($driver === 'imagick' && ! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not installed');
    }

    useDriver($driver);
    $media = app(MediaWriter::class)->store(MediaUpload::file(jpegWithExif(400, 300)));

    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 150, 'jpg');

    expect(renditionContents($media->path))->toContain('GPS-48.4647N')
        ->and(renditionContents($variant->path))->not->toContain('GPS-48.4647N');
})->with('drivers');

it('keeps the metadata of a rendition when the host turns stripping off', function () {
    useDriver('imagick');
    config(['media-kit.image.strip_metadata' => false]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(jpegWithExif(400, 300)));
    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 150, 'jpg');

    expect(renditionContents($variant->path))->toContain('Exif');
})->skip(fn() => ! extension_loaded('imagick'), 'Imagick is not installed');

it('writes a progressive JPEG only when asked to', function (string $driver) {
    if ($driver === 'imagick' && ! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not installed');
    }

    useDriver($driver);
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.jpg', 400, 300)));

    config(['media-kit.image.progressive' => true]);
    $progressive = renditionContents(app(MediaWriter::class)->ensureVariant($media, 200, 150, 'jpg')->path);

    config(['media-kit.image.progressive' => false]);
    $baseline = renditionContents(app(MediaWriter::class)->ensureVariant($media, 200, 150, 'jpg')->path);

    // SOF2 starts a progressive frame, SOF0 a baseline one.
    expect($progressive)->toContain("\xFF\xC2")
        ->and($baseline)->toContain("\xFF\xC0")
        ->and($baseline)->not->toContain("\xFF\xC2");
})->with('drivers');

it('converts a CMYK picture to RGB', function () {
    useDriver('imagick');

    $native = new Imagick();
    $native->newImage(20, 20, new ImagickPixel('rgb(200,30,30)'));
    $native->transformImageColorspace(Imagick::COLORSPACE_CMYK);
    $native->setImageFormat('jpg');

    $image = app(ImageFactory::class)->fromContents($native->getImageBlob());

    app(ImageOperations::class)->toSrgb($image);

    expect($image->core()->native()->getImageColorspace())->toBe(Imagick::COLORSPACE_SRGB);
})->skip(fn() => ! extension_loaded('imagick'), 'Imagick is not installed');

it('leaves the colour alone when the host turns conversion off', function () {
    useDriver('imagick');
    config(['media-kit.image.srgb' => false]);

    $native = new Imagick();
    $native->newImage(20, 20, new ImagickPixel('rgb(200,30,30)'));
    $native->transformImageColorspace(Imagick::COLORSPACE_CMYK);
    $native->setImageFormat('jpg');

    $image = app(ImageFactory::class)->fromContents($native->getImageBlob());
    $before = $image->core()->native()->getImageColorspace();

    app(ImageOperations::class)->toSrgb($image);

    expect($image->core()->native()->getImageColorspace())->toBe($before);
})->skip(fn() => ! extension_loaded('imagick'), 'Imagick is not installed');

it('does nothing about colour on GD, which cannot', function () {
    useDriver('gd');

    $image = app(ImageFactory::class)->fromContents(halvesPng(20, 20));

    app(ImageOperations::class)->toSrgb($image);

    expect([$image->width(), $image->height()])->toBe([20, 20]);
});

it('still makes renditions through the method kept for outside callers', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 200)));
    $generator = app(ImageVariantGenerator::class);
    $disk = app(KandySoft\MediaKit\Contracts\MediaStorage::class)->disk('public');
    $path = $generator->pathFor($media->path, 100, 50, 'webp');

    $generator->generate($disk, $media->path, $path, 100, 50, 'webp');
    $first = renditionContents($path);

    // A second call finds the file and leaves it as it is.
    Storage::disk('public')->put($path, 'kept');
    $generator->generate($disk, $media->path, $path, 100, 50, 'webp');

    expect(substr($first, 8, 4))->toBe('WEBP')
        ->and(renditionContents($path))->toBe('kept');
});
