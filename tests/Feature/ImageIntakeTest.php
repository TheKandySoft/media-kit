<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * What happens to an original on its way in (`image.original`): scaled down
 * past the limits, stripped of the camera's metadata — and left alone whenever
 * nothing asks for either, since every lossy encode loses a little.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

function intakeBytes(MediaFile $media): string
{
    return (string) Storage::disk('public')->get($media->path);
}

function photoWithExif(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 60));

    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $payload = "Exif\x00\x00" . "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x00" . "\x00\x00\x00\x00" . 'GPS-50.4501N-30.5234E';
    $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    $path = tempnam(sys_get_temp_dir(), 'exif');
    file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));

    return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
}

function animatedGif(int $width, int $height): UploadedFile
{
    $image = imagecreate($width, $height);
    imagecolorallocate($image, 10, 200, 10);

    $path = tempnam(sys_get_temp_dir(), 'gif');
    imagegif($image, $path);

    return new UploadedFile($path, 'loop.gif', 'image/gif', null, true);
}

function storeOriginal(UploadedFile $file): MediaFile
{
    return app(MediaWriter::class)->store(MediaUpload::file($file));
}

it('removes the camera\'s metadata from an original within the limits', function () {
    config(['media-kit.image.original.strip_metadata' => true]);

    $upload = photoWithExif(600, 400);

    expect((string) file_get_contents($upload->getRealPath()))->toContain('GPS-50.4501N');

    $media = storeOriginal($upload);

    expect(intakeBytes($media))->not->toContain('GPS-50.4501N')
        ->and([$media->width, $media->height])->toBe([600, 400])
        ->and(getimagesizefromstring(intakeBytes($media))[0])->toBe(600);
});

it('keeps the camera\'s metadata when nobody asks to remove it', function () {
    config(['media-kit.image.original.strip_metadata' => false]);

    expect(intakeBytes(storeOriginal(photoWithExif(600, 400))))->toContain('GPS-50.4501N');
});

it('scales down to a height limit alone', function () {
    config(['media-kit.image.original.max_width' => null, 'media-kit.image.original.max_height' => 500]);

    $media = storeOriginal(UploadedFile::fake()->image('tall.png', 400, 1000));

    expect([$media->width, $media->height])->toBe([200, 500])
        ->and(array_slice(getimagesizefromstring(intakeBytes($media)), 0, 2))->toBe([200, 500]);
});

it('treats a zero limit as no limit', function () {
    config(['media-kit.image.original.max_width' => 0, 'media-kit.image.original.max_height' => 0]);

    $upload = UploadedFile::fake()->image('big.png', 1600, 800);
    $bytes = file_get_contents($upload->getRealPath());

    expect(intakeBytes(storeOriginal($upload)))->toBe($bytes);
});

it('records the size of the file it stored, not the one it was given', function () {
    config(['media-kit.image.original.max_width' => 300]);

    $upload = UploadedFile::fake()->image('big.png', 2000, 1000);
    $media = storeOriginal($upload);

    expect($media->size)->toBe(strlen(intakeBytes($media)))
        ->and($media->size)->toBeLessThan($upload->getSize());
});

it('encodes a scaled original at the original\'s own quality', function () {
    config(['media-kit.image.original.max_width' => 300, 'media-kit.image.original.quality' => 20]);
    $low = storeOriginal(noisyJpeg(800, 400));

    config(['media-kit.image.original.quality' => 95]);
    $high = storeOriginal(noisyJpeg(800, 400));

    expect(strlen(intakeBytes($low)))->toBeLessThan(strlen(intakeBytes($high)));
});

it('leaves a GIF as it came, frames and all', function () {
    config(['media-kit.image.original.max_width' => 100, 'media-kit.image.original.strip_metadata' => true]);

    $upload = animatedGif(400, 200);
    $bytes = file_get_contents($upload->getRealPath());

    $media = storeOriginal($upload);

    expect(intakeBytes($media))->toBe($bytes)
        ->and([$media->width, $media->height])->toBe([400, 200]);
});

it('leaves a document alone and gives it no picture size', function () {
    config(['media-kit.image.original.max_width' => 100, 'media-kit.image.original.strip_metadata' => true]);

    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, '%PDF-1.4 a document');

    $media = storeOriginal(new UploadedFile($path, 'terms.pdf', 'application/pdf', null, true));

    expect(intakeBytes($media))->toBe('%PDF-1.4 a document')
        ->and($media->width)->toBeNull()
        ->and($media->placeholder)->toBeNull();
});

it('stores a picture the library cannot open as it came', function () {
    config(['media-kit.image.original.max_width' => 100, 'media-kit.image.original.strip_metadata' => true]);

    // A truncated PNG: the signature is right, the rest is missing.
    $whole = UploadedFile::fake()->image('a.png', 400, 200);
    $bytes = substr((string) file_get_contents($whole->getRealPath()), 0, 40);
    $path = tempnam(sys_get_temp_dir(), 'broken');
    file_put_contents($path, $bytes);

    $media = storeOriginal(new UploadedFile($path, 'broken.png', 'image/png', null, true));

    expect(intakeBytes($media))->toBe($bytes)
        ->and($media->width)->toBeNull();
});

it('keeps a placeholder for an original it reworked', function () {
    config(['media-kit.image.original.max_width' => 300]);

    $media = storeOriginal(UploadedFile::fake()->image('big.png', 1200, 600));

    expect($media->placeholder)->toStartWith('data:image/');
});

it('reworks a replacement the way it reworks an upload', function () {
    config(['media-kit.image.original.max_width' => 300]);

    $media = storeOriginal(UploadedFile::fake()->image('first.png', 200, 100));
    $replaced = app(MediaWriter::class)->replace($media->uuid, UploadedFile::fake()->image('second.png', 1200, 600));

    expect([$replaced->width, $replaced->height])->toBe([300, 150])
        ->and(array_slice(getimagesizefromstring(intakeBytes($replaced)), 0, 2))->toBe([300, 150])
        ->and($replaced->size)->toBe(strlen(intakeBytes($replaced)));
});

it('leaves no temporary copy behind', function () {
    config(['media-kit.image.original.max_width' => 300, 'media-kit.image.original.strip_metadata' => true]);

    $directory = storage_path('app/media-kit-tmp');
    $before = glob($directory . '/*') ?: [];

    $media = storeOriginal(UploadedFile::fake()->image('big.png', 1200, 600));

    expect($media->width)->toBe(300)
        ->and(array_diff(glob($directory . '/*') ?: [], $before))->toBe([]);
});

/**
 * Noise does not compress the same at every quality — a flat colour would.
 */
function noisyJpeg(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    mt_srand(7);

    for ($x = 0; $x < $width; $x += 2) {
        for ($y = 0; $y < $height; $y += 2) {
            imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'noisy');
    imagejpeg($image, $path, 100);

    return new UploadedFile($path, 'noisy.jpg', 'image/jpeg', null, true);
}
