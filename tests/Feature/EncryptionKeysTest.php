<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use KandySoft\MediaKit\Contracts\KeyProvider;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\Envelope;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Exceptions\MediaKeyMissing;
use KandySoft\MediaKit\Exceptions\MediaTampered;
use KandySoft\MediaKit\Models\MediaFile;

/**
 * Where the keys come from, which disks encrypt, and the commands that move
 * files between plain and encrypted — the parts an operator touches.
 */
uses(RefreshDatabase::class);

const KEY_A = 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='; // 32 × "a"
const KEY_B = 'YmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmJiYmI='; // 32 × "b"

beforeEach(function () {
    Storage::fake('public');

    config([
        'media-kit.disk' => 'public',
        'media-kit.directory' => 'media',
        'media-kit.encryption.enabled' => false,
        'media-kit.encryption.keys' => 'k1:' . KEY_A,
        'media-kit.encryption.current' => '',
    ]);
});

function documentUpload(string $contents, string $name = 'contract.pdf'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

function readPlain(MediaFile $media): string
{
    return app(MediaStorage::class)->disk($media->disk)->read($media->path, Envelope::of($media));
}

it('takes the last listed key as the current one', function () {
    config(['media-kit.encryption.keys' => 'k1:' . KEY_A . ',k2:' . KEY_B]);

    expect(app(KeyProvider::class)->currentId())->toBe('k2');
});

it('reads keys listed with spaces around them', function () {
    config(['media-kit.encryption.keys' => ' k1 : ' . KEY_A . ' , , k2:' . KEY_B . ' ']);

    expect(app(KeyProvider::class)->key('k1'))->toBe(str_repeat('a', 32))
        ->and(app(KeyProvider::class)->key('k2'))->toBe(str_repeat('b', 32));
});

it('reads keys from a published config file as an array', function () {
    config(['media-kit.encryption.keys' => ['k1' => KEY_A, 'k7' => KEY_B]]);

    expect(app(KeyProvider::class)->currentId())->toBe('k7')
        ->and(app(KeyProvider::class)->key('k7'))->toBe(str_repeat('b', 32));
});

it('refuses a current key that is not listed', function () {
    config(['media-kit.encryption.current' => 'k9']);

    app(KeyProvider::class)->currentId();
})->throws(MediaKeyMissing::class, 'k9');

it('refuses a key that is not 32 bytes', function () {
    config(['media-kit.encryption.keys' => 'short:' . base64_encode('too short')]);

    app(KeyProvider::class)->key('short');
})->throws(MediaKeyMissing::class, 'base64-encoded 32-byte');

it('refuses a key that is not base64', function () {
    config(['media-kit.encryption.keys' => 'bad:***']);

    app(KeyProvider::class)->key('bad');
})->throws(MediaKeyMissing::class);

it('stores nothing when encryption is on but no key is configured', function () {
    config(['media-kit.encryption.enabled' => true, 'media-kit.encryption.keys' => '']);

    try {
        app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 lost')));
        $this->fail('The upload was stored without a key.');
    } catch (MediaKeyMissing) {
        // expected
    }

    expect(MediaFile::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('encrypts one disk and leaves another as it was', function () {
    Storage::fake('vault');
    config([
        'filesystems.disks.vault.visibility' => 'private',
        'media-kit.disks.vault' => ['encrypt' => true],
    ]);

    $sealed = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 passport')), null, 'vault');
    $plain = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 brochure')));

    expect($sealed->encryption)->toBe(Envelope::SCHEME)
        ->and(Storage::disk('vault')->get($sealed->path))->not->toContain('passport')
        ->and($plain->encryption)->toBeNull()
        ->and(Storage::disk('public')->get($plain->path))->toBe('%PDF-1.4 brochure');
});

it('lets one disk opt out when every disk encrypts', function () {
    config([
        'media-kit.encryption.enabled' => true,
        'media-kit.disks.public' => ['encrypt' => false],
    ]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 price list')));

    expect($media->encryption)->toBeNull();
});

it('encrypts and reads back an empty file', function () {
    config(['media-kit.encryption.enabled' => true]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('', 'empty.pdf')));

    expect($media->encryption)->toBe(Envelope::SCHEME)
        ->and($media->size)->toBe(0)
        ->and(readPlain($media))->toBe('');
});

it('encrypts a replacement of an encrypted file under a fresh key', function () {
    config(['media-kit.encryption.enabled' => true]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 first')));
    $wrapped = $media->wrapped_key;

    $replaced = app(MediaWriter::class)->replace($media->uuid, documentUpload('%PDF-1.4 second'));

    expect($replaced->encryption)->toBe(Envelope::SCHEME)
        ->and($replaced->wrapped_key)->not->toBe($wrapped)
        ->and(Storage::disk('public')->get($replaced->path))->not->toContain('second')
        ->and(readPlain($replaced))->toBe('%PDF-1.4 second');
});

it('refuses a file whose wrapped key was altered', function () {
    config(['media-kit.encryption.enabled' => true]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 statement')));
    $wrapped = base64_decode((string) $media->wrapped_key, true);

    if ($wrapped === false) {
        $wrapped = (string) $media->wrapped_key;
        $wrapped[5] = chr(ord($wrapped[5]) ^ 1);
    } else {
        $wrapped[30] = chr(ord($wrapped[30]) ^ 1);
        $wrapped = base64_encode($wrapped);
    }

    $media->forceFill(['wrapped_key' => $wrapped])->save();

    readPlain($media->fresh());
})->throws(MediaTampered::class);

it('only counts what it would encrypt when asked to look', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 old')));
    config(['media-kit.encryption.enabled' => true]);

    $this->artisan('media:encrypt', ['--dry-run' => true])
        ->expectsOutputToContain('1 files would be encrypted')
        ->assertSuccessful();

    expect($media->fresh()->encryption)->toBeNull()
        ->and(Storage::disk('public')->get($media->path))->toBe('%PDF-1.4 old');
});

it('leaves files on a disk that does not encrypt unless that disk is named', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 old')));

    $this->artisan('media:encrypt')->assertSuccessful();
    expect($media->fresh()->encryption)->toBeNull();

    $this->artisan('media:encrypt', ['--disk' => ['public']])->assertSuccessful();
    expect($media->fresh()->encryption)->toBe(Envelope::SCHEME)
        ->and(readPlain($media->fresh()))->toBe('%PDF-1.4 old');
});

it('drops the plain renditions of a picture it encrypts, and makes them again encrypted', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('face.png', 400, 200)));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(200, 100));
    $plainPaths = $media->variants()->pluck('path')->all();

    expect($plainPaths)->not->toBe([]);

    config(['media-kit.encryption.enabled' => true]);
    $this->artisan('media:encrypt')->assertSuccessful();

    $media->refresh();

    expect($media->variants()->count())->toBe(0)
        ->and($media->placeholder)->toBeNull();

    foreach ($plainPaths as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    expect($variant->encryption)->toBe(Envelope::SCHEME);
});

it('decrypts only the disks it is told to', function () {
    Storage::fake('vault');
    config(['media-kit.encryption.enabled' => true]);

    $public = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 a')));
    $vault = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 b')), null, 'vault');

    config(['media-kit.encryption.enabled' => false]);
    $this->artisan('media:decrypt', ['--disk' => ['public']])->assertSuccessful();

    expect($public->fresh()->encryption)->toBeNull()
        ->and($vault->fresh()->encryption)->toBe(Envelope::SCHEME);
});

it('only counts what it would decrypt when asked to look', function () {
    config(['media-kit.encryption.enabled' => true]);
    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 kept')));

    $this->artisan('media:decrypt', ['--dry-run' => true])
        ->expectsOutputToContain('1 files would be decrypted')
        ->assertSuccessful();

    expect($media->fresh()->encryption)->toBe(Envelope::SCHEME);
});

it('fails and keeps the file when the key to decrypt it is gone', function () {
    config(['media-kit.encryption.enabled' => true]);
    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 kept')));
    $path = $media->path;

    config(['media-kit.encryption.keys' => 'k2:' . KEY_B]);

    $this->artisan('media:decrypt')->assertFailed();

    expect($media->fresh()->path)->toBe($path)
        ->and($media->fresh()->encryption)->toBe(Envelope::SCHEME);

    Storage::disk('public')->assertExists($path);
});

it('only counts what it would rewrap when asked to look', function () {
    config(['media-kit.encryption.enabled' => true]);
    $media = app(MediaWriter::class)->store(MediaUpload::file(documentUpload('%PDF-1.4 kept')));

    config(['media-kit.encryption.keys' => 'k1:' . KEY_A . ',k2:' . KEY_B]);

    $this->artisan('media:rekey', ['--dry-run' => true])->assertSuccessful();

    expect($media->fresh()->key_id)->toBe('k1');
});

it('names a new key after today when no id is given', function () {
    $this->travelTo(now()->setDate(2026, 10, 14));

    $this->artisan('media:key')
        ->expectsOutputToContain('20261014:')
        ->assertSuccessful();
});

it('refuses a key id that would break the list it goes into', function () {
    $this->artisan('media:key', ['id' => 'a:b'])->assertExitCode(2);
});
