<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Serving a piece of a file: a video player seeks with ranges, a PDF viewer
 * fetches pages with them. Files served by the application — private ones and
 * encrypted ones — need them as much as files a bucket serves itself.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    config(['media-kit.disk' => 'public', 'media-kit.directory' => 'media']);
});

/**
 * A private file the application streams itself, addressed by a signed link.
 */
function privateDownload(string $contents): string
{
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);

    $media = MediaFile::query()->create([
        'disk' => 'secret',
        'path' => 'media/private/' . uniqid() . '.bin',
        'type' => MediaType::Document,
        'mime_type' => 'application/octet-stream',
        'is_public' => false,
    ]);

    Storage::disk('secret')->put($media->path, $contents);

    return URL::temporarySignedRoute('media-kit.download', now()->addMinutes(15), ['uuid' => $media->uuid]);
}

/**
 * An encrypted file and its address through the application.
 */
function encryptedAddress(string $contents): string
{
    config([
        'media-kit.encryption.enabled' => true,
        'media-kit.encryption.keys' => 'k1:' . base64_encode(str_repeat('a', 32)),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'ranged');
    file_put_contents($path, $contents);
    $media = app(MediaWriter::class)->store(MediaUpload::file(new UploadedFile($path, 'clip.pdf', null, null, true)));

    return app(MediaReader::class)->byUuid($media->uuid)->url;
}

it('says it serves pieces even when asked for the whole file', function () {
    $response = $this->get(privateDownload('0123456789'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->headers->get('Content-Length'))->toBe('10')
        ->and($response->streamedContent())->toBe('0123456789');
});

it('serves a piece of a private file', function () {
    $response = $this->get(privateDownload('0123456789'), ['Range' => 'bytes=3-6']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('3456')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 3-6/10')
        ->and($response->headers->get('Content-Length'))->toBe('4');
});

it('serves from an offset to the end when no end is given', function () {
    $response = $this->get(privateDownload('0123456789'), ['Range' => 'bytes=7-']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('789')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');
});

it('stops a range that runs past the end at the end', function () {
    $response = $this->get(privateDownload('0123456789'), ['Range' => 'bytes=8-500']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('89')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 8-9/10');
});

it('serves the whole file for a suffix longer than the file', function () {
    $response = $this->get(privateDownload('0123456789'), ['Range' => 'bytes=-50']);

    expect($response->streamedContent())->toBe('0123456789')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 0-9/10');
});

it('refuses a range that starts past the end', function () {
    $response = $this->get(privateDownload('0123456789'), ['Range' => 'bytes=10-12']);

    expect($response->getStatusCode())->toBe(416)
        ->and($response->headers->get('Content-Range'))->toBe('bytes */10');
});

it('refuses a range whose end comes before its start', function () {
    expect($this->get(privateDownload('0123456789'), ['Range' => 'bytes=6-3'])->getStatusCode())->toBe(416);
});

it('serves the whole file for a range header it does not understand', function (string $header) {
    $response = $this->get(privateDownload('0123456789'), ['Range' => $header]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe('0123456789');
})->with([
    'several ranges' => ['bytes=0-1,5-6'],
    'another unit' => ['items=0-1'],
    'no numbers' => ['bytes=-'],
    'garbage' => ['bytes=abc'],
]);

it('serves the whole file when the client\'s copy is of another version', function () {
    $url = privateDownload('0123456789');

    $response = $this->get($url, ['Range' => 'bytes=3-6', 'If-Range' => 'W/"another-version"']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe('0123456789');
});

it('serves the piece when the client\'s copy is of this version', function () {
    $url = privateDownload('0123456789');
    $etag = $this->get($url)->headers->get('ETag');

    $response = $this->get($url, ['Range' => 'bytes=3-6', 'If-Range' => $etag]);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe('3456');
});

it('serves a piece of an encrypted file across the boundary of two sealed chunks', function () {
    $secret = random_bytes(200_000);
    $url = encryptedAddress($secret);

    // Chunks are 64 KiB: this range starts in the first and ends in the second.
    $response = $this->get($url, ['Range' => 'bytes=65530-65545']);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->streamedContent())->toBe(substr($secret, 65530, 16))
        ->and($response->headers->get('Content-Range'))->toBe('bytes 65530-65545/200000');
});

it('serves the tail of a large encrypted file', function () {
    $secret = random_bytes(200_000);

    $response = $this->get(encryptedAddress($secret), ['Range' => 'bytes=150000-']);

    expect($response->streamedContent())->toBe(substr($secret, 150_000))
        ->and($response->headers->get('Content-Length'))->toBe('50000');
});

it('answers a repeat request for an unchanged encrypted file in a few bytes', function () {
    $url = encryptedAddress('%PDF-1.4 statement');
    $etag = $this->get($url)->headers->get('ETag');

    expect($this->get($url, ['If-None-Match' => $etag])->getStatusCode())->toBe(304);
});

it('serves an empty file without inventing a range', function () {
    $response = $this->get(privateDownload(''), ['Range' => 'bytes=0-10']);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->streamedContent())->toBe('');
});
