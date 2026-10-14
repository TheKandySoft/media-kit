<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Exceptions\MediaKeyMissing;
use KandySoft\MediaKit\Exceptions\MediaTampered;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Шифрування файлів у сховищі.
 *
 * Скопійований бакет чи вкрадений бекап не мають містити нічого читабельного:
 * на диску — лише шифротекст, ключ кожного файлу загорнутий ключем
 * застосунку, і віддає файл тільки застосунок, розшифровуючи його.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    config([
        'media-kit.disk' => 'public',
        'media-kit.directory' => 'media',
        'media-kit.encryption.enabled' => true,
        'media-kit.encryption.keys' => 'k1:' . base64_encode(str_repeat('a', 32)),
        'media-kit.encryption.current' => '',
    ]);
});

function sealedUpload(string $contents, string $name = 'passport.pdf'): MediaFile
{
    $path = tempnam(sys_get_temp_dir(), 'plain');
    file_put_contents($path, $contents);

    return app(MediaWriter::class)->store(MediaUpload::file(new UploadedFile($path, $name, null, null, true)));
}

function storedBytes(MediaFile $media): string
{
    return (string) Storage::disk('public')->get($media->path);
}

function plainBytes(MediaFile $media): string
{
    return app(MediaStorage::class)->disk($media->disk)->read($media->path, Envelope::of($media));
}

it('keeps nothing readable on the disk', function () {
    $secret = '%PDF-1.4 passport of a customer';
    $media = sealedUpload($secret);

    expect(storedBytes($media))->not->toContain('passport')
        ->and($media->encryption)->toBe(Envelope::SCHEME)
        ->and($media->key_id)->toBe('k1')
        ->and($media->size)->toBe(strlen($secret))
        ->and($media->checksum)->toBe(hash('sha256', $secret))
        ->and(plainBytes($media))->toBe($secret);
});

it('reads back a file larger than one chunk', function () {
    $secret = random_bytes(200_000);
    $media = sealedUpload($secret, 'scan.pdf');

    expect(plainBytes($media))->toBe($secret);
});

it('gives an encrypted file no address on the disk and serves it decrypted', function () {
    $secret = '%PDF-1.4 contract';
    $media = sealedUpload($secret);

    $resource = app(MediaReader::class)->byUuid($media->uuid);

    // Адреса диска віддала б шифротекст — тільки роут застосунку.
    expect($resource->url)->not->toContain('/storage/');

    expect($this->get($resource->url)->streamedContent())->toBe($secret);
});

it('keeps no readable placeholder of an encrypted picture', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('face.png', 400, 200)));

    expect($media->placeholder)->toBeNull();
});

it('makes renditions of an encrypted picture, encrypted too', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('face.png', 400, 200)));

    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    expect($variant->encryption)->toBe(Envelope::SCHEME)
        ->and(substr(storedBytes($variant), 8, 4))->not->toBe('WEBP')
        ->and(substr(plainBytes($variant), 8, 4))->toBe('WEBP')
        ->and($variant->mime_type)->toBe('image/webp');

    $resource = app(MediaReader::class)->byUuid($media->uuid, size: ImageSize::of(200, 100));

    expect(collect($resource->variants)->pluck('url')->implode(' '))->not->toContain('/storage/');
});

it('refuses a file that was altered on the disk', function () {
    $media = sealedUpload('%PDF-1.4 statement');

    $bytes = storedBytes($media);
    $bytes[40] = chr(ord($bytes[40]) ^ 1);
    Storage::disk('public')->put($media->path, $bytes);

    plainBytes($media);
})->throws(MediaTampered::class);

it('refuses a file cut short', function () {
    $media = sealedUpload(random_bytes(100_000));

    Storage::disk('public')->put($media->path, substr(storedBytes($media), 0, 65_536));

    plainBytes($media);
})->throws(MediaTampered::class);

it('says so when the key a file names is gone', function () {
    $media = sealedUpload('%PDF-1.4 invoice');

    config(['media-kit.encryption.keys' => 'k2:' . base64_encode(str_repeat('b', 32))]);

    plainBytes($media);
})->throws(MediaKeyMissing::class);

it('rotates keys without rewriting a single file', function () {
    $media = sealedUpload('%PDF-1.4 receipt');
    $before = storedBytes($media);

    config([
        'media-kit.encryption.keys' => 'k1:' . base64_encode(str_repeat('a', 32)) . ',k2:' . base64_encode(str_repeat('b', 32)),
        'media-kit.encryption.current' => 'k2',
    ]);

    $this->artisan('media:rekey')->assertSuccessful();

    $media->refresh();

    // Старий ключ можна прибирати: файл читається новим.
    config(['media-kit.encryption.keys' => 'k2:' . base64_encode(str_repeat('b', 32))]);

    expect($media->key_id)->toBe('k2')
        ->and(storedBytes($media))->toBe($before)
        ->and(plainBytes($media))->toBe('%PDF-1.4 receipt');
});

it('encrypts files stored before encryption was switched on, and decrypts them back', function () {
    config(['media-kit.encryption.enabled' => false]);
    $media = sealedUpload('%PDF-1.4 old upload');
    $plainPath = $media->path;

    expect($media->encryption)->toBeNull();

    config(['media-kit.encryption.enabled' => true]);
    $this->artisan('media:encrypt')->assertSuccessful();
    $media->refresh();

    Storage::disk('public')->assertMissing($plainPath);

    expect($media->encryption)->toBe(Envelope::SCHEME)
        ->and(storedBytes($media))->not->toContain('old upload')
        ->and(plainBytes($media))->toBe('%PDF-1.4 old upload');

    config(['media-kit.encryption.enabled' => false]);
    $this->artisan('media:decrypt')->assertSuccessful();
    $media->refresh();

    expect($media->encryption)->toBeNull()
        ->and(storedBytes($media))->toBe('%PDF-1.4 old upload');
});

it('serves a plain file next to encrypted ones as before', function () {
    config(['media-kit.encryption.enabled' => false]);
    $plain = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 100, 100)));

    config(['media-kit.encryption.enabled' => true]);
    sealedUpload('%PDF-1.4 sealed');

    // Диск тепер шифрує, тож пряма адреса не видається й старому файлу, але
    // віддається він як є.
    $resource = app(MediaReader::class)->byUuid($plain->uuid);

    expect($this->get($resource->url)->streamedContent())->toBe(storedBytes($plain));
});

it('copies an encrypted file to another owner', function () {
    $owner = MediaFile::query()->create(['disk' => 'public', 'path' => 'media/a.png', 'type' => 'image', 'is_public' => true]);
    $other = MediaFile::query()->create(['disk' => 'public', 'path' => 'media/b.png', 'type' => 'image', 'is_public' => true]);

    $path = tempnam(sys_get_temp_dir(), 'plain');
    file_put_contents($path, '%PDF-1.4 shared');
    app(MediaWriter::class)->store(MediaUpload::file(new UploadedFile($path, 'shared.pdf', null, null, true)), $owner);

    [$copy] = app(MediaWriter::class)->copy($owner, $other);

    expect($copy->encryption)->toBe(Envelope::SCHEME)
        ->and(plainBytes($copy))->toBe('%PDF-1.4 shared');
});

it('prints a key that the keyring accepts', function () {
    $this->artisan('media:key', ['id' => 'k9'])
        ->expectsOutputToContain('k9:')
        ->assertSuccessful();
});

it('stores plain files on a schema that has not been migrated yet', function () {
    // Код оновили раніше за міграцію — або хост будує в тестах лише частину
    // схеми. Файл без шифрування мусить зберігатись, як і досі.
    config(['media-kit.encryption.enabled' => false]);

    Illuminate\Support\Facades\Schema::table('media_files', function ($table): void {
        $table->dropColumn(['encryption', 'key_id', 'wrapped_key', 'checksum']);
    });

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 100, 100)));

    expect(app(MediaWriter::class)->ensureVariant($media, 50, 50, 'webp')->exists)->toBeTrue();
});
