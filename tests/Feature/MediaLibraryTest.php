<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Contracts\MediaStorage;
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\LinkLifetime;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaFilter;
use KandySoft\MediaKit\Data\MediaUpload;
use KandySoft\MediaKit\Enums\MediaType;
use KandySoft\MediaKit\Exceptions\MediaNotFound;
use KandySoft\MediaKit\Exceptions\MediaNotStored;
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

    $this->get(URL::temporarySignedRoute('media-kit.file', now()->addMinutes(15), ['uuid' => $media->uuid]))
        ->assertHeader('Cache-Control', 'max-age=900, private');
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

it('takes the package\'s word for a public disk over the disk\'s own visibility', function () {
    // An object store asks for a public-read ACL on every write of a disk with
    // 'visibility' => 'public', and a bucket with ACLs disabled refuses it. The
    // package still has to know such a disk hands out permanent addresses.
    Storage::fake('bucket');
    Storage::fake('secret');
    config([
        'filesystems.disks.secret.visibility' => 'public',
        'media-kit.disks.bucket' => ['public' => true],
        'media-kit.disks.secret' => ['public' => false],
    ]);

    expect(app(MediaStorage::class)->disk('bucket')->isPublic())->toBeTrue()
        ->and(app(MediaStorage::class)->disk('secret')->isPublic())->toBeFalse();

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->image('photo.png', 100, 100)),
        null,
        'bucket',
    );

    expect($media->is_public)->toBeTrue();
});

it('records nothing when the disk refuses to store the file', function () {
    // A disk with 'throw' => false answers a refused write with false. The
    // record used to be created anyway, pointing at a file that never existed.
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->andReturnFalse();
    Storage::set('refusing', $disk);

    expect(fn() => app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->image('photo.png', 100, 100)),
        null,
        'refusing',
    ))->toThrow(MediaNotStored::class);

    expect(MediaFile::query()->count())->toBe(0);
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

it('shortens every link of one read when the caller asks, not just some', function () {
    // Скоротити лише частину означало б віддати прев'ю на чверть години, чиї
    // мініатюри переживуть його на рік.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));

    Storage::disk('public')->delete($media->path);

    $resource = app(MediaReader::class)->byUuid(
        $media->uuid,
        new MediaFilter(),
        ImageSize::of(200, 200),
        LinkLifetime::minutes(15),
    );

    expect($resource->variant('large')?->url)->toContain('expires=');
});

it('gives a store\'s own pre-signed link the lifetime the caller asked for', function () {
    // A receipt meant to be readable for fifteen minutes was handed out for the
    // disk's hour: the override reached only the signed route, never the link
    // the store mints itself.
    Carbon::setTestNow('2026-10-12 12:00:00');
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);
    Storage::disk('secret')->buildTemporaryUrlsUsing(
        fn(string $path, DateTimeInterface $expiration): string => "https://store.test/{$path}?expires={$expiration->getTimestamp()}",
    );

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')),
        null,
        'secret',
    );

    $expires = fn(?LinkLifetime $lifetime): string => (string) parse_url(
        app(MediaReader::class)->byUuid($media->uuid, lifetime: $lifetime)->url,
        PHP_URL_QUERY,
    );

    expect($expires(LinkLifetime::minutes(15)))->toBe('expires=' . now()->addMinutes(15)->getTimestamp())
        ->and($expires(null))->toBe('expires=' . now()->addMinutes(60)->getTimestamp());

    Carbon::setTestNow();
});

it('leaves the disk to decide when nobody overrides it', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));

    expect(app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(200, 200))->variant('large')?->url)
        ->not->toContain('expires=');
});

it('never promises a cache longer than the link it describes', function () {
    // Раніше межу задавав конфіг, і разове коротке посилання могло приїхати з
    // річним заголовком: заголовок питав одне джерело, посилання — інше.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 200, 200)));

    $this->get(URL::temporarySignedRoute('media-kit.file', now()->addMinutes(5), ['uuid' => $media->uuid]))
        ->assertHeader('Cache-Control', 'max-age=300, public');
});

it('does not call a link immutable when it expires', function () {
    // Публічний диск із увімкненим строком: файл видимий усім, але адреса його
    // помре, і `immutable` на ній — неправда.
    config(['media-kit.disks.public' => ['variant_url_ttl' => 5]]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));

    $url = app(MediaReader::class)
        ->byUuid($media->uuid, new MediaFilter(), ImageSize::of(200, 200))
        ->variant('large')?->url;

    $header = $this->get($url)->headers->get('Cache-Control');

    expect($header)->not->toContain('immutable')
        ->and($header)->toContain('max-age=');
});

it('gives a replaced picture a new address', function () {
    // `replace` навмисно зберігає uuid, щоб посилання на нього не зникли, — а
    // роути ключовані саме по uuid. Без версії в адресі та сама адреса тихо
    // починала б віддавати інший файл, і всі, хто її закешував, лишились би зі
    // старим фото на рік.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('before.png', 400, 400)));

    $before = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(200, 200))->variant('large')?->url;

    Carbon::setTestNow(now()->addMinutes(5));
    app(MediaWriter::class)->replace($media->uuid, UploadedFile::fake()->image('after.png', 400, 400));

    $after = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(200, 200))->variant('large')?->url;

    expect($after)->not->toBe($before);

    Carbon::setTestNow();
});

it('answers a repeat request for an unchanged file in a few bytes', function () {
    // Файл, який тече через застосунок, кешується коротко за визначенням, тож
    // повертаються по нього часто — і без валідатора щоразу їде все тіло.
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);

    $media = MediaFile::query()->create([
        'disk' => 'secret',
        'path' => 'media/private/' . uniqid() . '.png',
        'type' => MediaType::Image,
        'is_public' => false,
    ]);

    Storage::disk('secret')->put($media->path, 'bytes');

    $url = URL::temporarySignedRoute('media-kit.download', now()->addMinutes(15), ['uuid' => $media->uuid]);
    $etag = $this->get($url)->headers->get('ETag');

    expect($etag)->not->toBeNull();

    $this->get($url, ['If-None-Match' => $etag])->assertStatus(304);
});

it('addresses a warmed rendition on the disk, not through the application', function () {
    // Заради цього все й робилося: доти адреса варіанта вела в застосунок, і той
    // відповідав редиректом — тобто на кожну картинку каталогу припадало
    // пробудження PHP і запит у базу заради статичного файлу.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    $before = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))->variant('large')?->url;

    expect($before)->toContain("/media/{$media->uuid}/")->toContain('signature=');

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    $after = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))->variant('large')?->url;

    expect($after)->not->toContain("/media/{$media->uuid}/")
        ->and($after)->not->toContain('signature=')
        ->and($after)->toContain('/storage/')
        ->and($after)->toContain('/variants/400x200/');
});

it('warms every size it describes, not just the largest', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    $resource = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200));

    foreach (['large', 'medium', 'small'] as $name) {
        expect($resource->variant($name)?->url)->not->toContain('signature=');
    }
});

it('does not produce the same rendition twice', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    expect($media->variants()->count())->toBe(3);
});

it('keeps a private rendition behind the application even when warmed', function () {
    // Пряма адреса на приватному диску — це тимчасове посилання зі своїм
    // строком, тобто політика диска обходиться стороною. Такий файл лишається
    // за роутом.
    Storage::fake('secret');
    config(['filesystems.disks.secret.visibility' => 'private']);

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)),
        null,
        'secret',
    );

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    expect(app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))->variant('large')?->url)
        ->toContain("/media/{$media->uuid}/");
});

it('moves a warmed rendition when the picture behind it is replaced', function () {
    // Шлях варіанта рахується з імені оригіналу, а `replace` дає файлу нове —
    // тож пряма адреса змінюється сама, без версії в запиті.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('before.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $before = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))->variant('large')?->url;

    app(MediaWriter::class)->replace($media->uuid, UploadedFile::fake()->image('after.png', 1000, 500));
    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $after = app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))->variant('large')?->url;

    expect($after)->not->toBe($before)
        ->and($after)->not->toContain('signature=');
});

it('ignores a request to warm something that is not an image', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->create('manual.pdf', 10, 'application/pdf')));

    expect(app(MediaWriter::class)->warmVariants($media))->toBe([]);
});

it('warms everything a model owns in one call', function () {
    // Індексатор і так обходить каталог: там прогрів безкоштовний, а на першому
    // показі сторінки — ні.
    $product = owner();

    app(MediaWriter::class)->sync($product, [
        MediaUpload::file(UploadedFile::fake()->image('one.png', 800, 400)),
        MediaUpload::file(UploadedFile::fake()->image('two.png', 800, 400)),
    ]);

    $warmed = app(MediaWriter::class)->warmForModel($product, MediaFilter::images(), ImageSize::of(400, 200));

    expect($warmed)->toBe(6);

    foreach (app(MediaReader::class)->forModel($product, MediaFilter::images(), ImageSize::of(400, 200)) as $resource) {
        expect($resource->variant('large')?->url)->not->toContain('signature=');
    }
});

it('offers the renditions as a srcset, narrowest first', function () {
    // Без нього телефон тягне ту саму картинку, що й десктоп: 700 пікселів,
    // щоб намалювати 210, по найгіршому з'єднанню.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    $srcset = app(MediaReader::class)
        ->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))
        ->srcset();

    // Порядок важливий: браузер читає список як зростання ширини.
    expect(strpos($srcset, '120w'))->toBeLessThan(strpos($srcset, '240w'))
        ->and(strpos($srcset, '240w'))->toBeLessThan(strpos($srcset, '400w'))
        ->and(substr_count($srcset, 'w,') + 1)->toBe(3);
});

it('does not offer a choice of one', function () {
    // Список з одного розміру — не вибір, а зайвий атрибут у розмітці.
    config(['media-kit.image.variants' => ['large' => 1.0]]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    expect(app(MediaReader::class)->byUuid($media->uuid, new MediaFilter(), ImageSize::of(400, 200))->srcset())
        ->toBeNull();
});

it('has no srcset for something that is not an image', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->create('manual.pdf', 10, 'application/pdf')));

    expect(app(MediaReader::class)->byUuid($media->uuid)->srcset())->toBeNull();
});

it('keeps a tiny copy of a picture to show while the real one arrives', function () {
    // Порожня картка, яка заповнюється за мить, — це і є те, що відвідувач
    // читає як повільність, і те, що міряє показник зсуву розмітки.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    $placeholder = app(MediaReader::class)->byUuid($media->uuid)->placeholder;

    expect($placeholder)->toStartWith('data:image/webp;base64,')
        ->and(strlen($placeholder))->toBeLessThan(2800);
});

it('ships the placeholder to the client', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    expect(app(MediaReader::class)->byUuid($media->uuid)->toArray())->toHaveKey('placeholder');
});

it('does not invent a placeholder for a file nobody looks at', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->create('manual.pdf', 10, 'application/pdf')));

    expect(app(MediaReader::class)->byUuid($media->uuid)->placeholder)->toBeNull();
});

it('refuses the upload over nothing when the placeholder cannot be made', function () {
    // Заглушка — приємність. Відмовити в завантаженні через неї — ні.
    config(['media-kit.image.placeholder' => false]);

    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));

    expect($media->exists)->toBeTrue()
        ->and($media->placeholder)->toBeNull();
});

it('sweeps a rendition whose file somebody deleted by hand', function () {
    // Запис без файлу — це промах на кожен запит того розміру: він щоразу
    // генерується наново.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));
    $variant = app(MediaWriter::class)->ensureVariant($media, 200, 100, 'webp');

    Storage::disk('public')->delete($variant->path);

    $this->artisan('media:prune')->assertSuccessful();

    expect(MediaFile::query()->find($variant->uuid))->toBeNull();
});

it('sweeps renditions left behind when the shop changed its picture size', function () {
    // Адреса виводиться з розміру, тож після зміни розміру старих файлів ніхто
    // більше не питає — і ніхто не прибирає.
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));
    $stale = $media->variants()->pluck('uuid')->all();

    expect($stale)->toHaveCount(3);

    // 900 дає 900/540/270 — жодного перетину з 400/240/120. Розміри тут
    // навмисно без спільних чисел: інакше один зі старих збігся б із новим і
    // вижив би, і тест перевіряв би не те, що заявляє.
    $this->artisan('media:prune', ['--width' => 900, '--height' => 450])->assertSuccessful();

    expect(MediaFile::query()->whereIn('uuid', $stale)->count())->toBe(0);
});

it('keeps the renditions the shop is actually serving', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    $this->artisan('media:prune', ['--width' => 400, '--height' => 200])->assertSuccessful();

    expect($media->variants()->count())->toBe(3);
});

it('touches nothing when asked to look only', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 1000, 500)));

    app(MediaWriter::class)->warmVariants($media->fresh(), ImageSize::of(400, 200));

    $this->artisan('media:prune', ['--width' => 900, '--height' => 450, '--dry-run' => true])->assertSuccessful();

    expect($media->variants()->count())->toBe(3);
});

it('fills in placeholders for pictures stored before it kept them', function () {
    // Інакше колонка лишається порожньою, доки кожну картинку не завантажать
    // заново, — для каталогу це «ніколи».
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));
    $media->forceFill(['placeholder' => null])->save();

    $this->artisan('media:placeholders')->assertSuccessful();

    expect($media->fresh()->placeholder)->toStartWith('data:image/webp;base64,');
});

it('leaves a placeholder it already has alone', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));
    $media->forceFill(['placeholder' => 'data:image/webp;base64,ЗБЕРЕЖЕНО'])->save();

    $this->artisan('media:placeholders')->assertSuccessful();

    expect($media->fresh()->placeholder)->toBe('data:image/webp;base64,ЗБЕРЕЖЕНО');
});

it('rebuilds them when told to', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('a.png', 400, 400)));
    $media->forceFill(['placeholder' => 'data:image/webp;base64,СТАРЕ'])->save();

    $this->artisan('media:placeholders', ['--force' => true])->assertSuccessful();

    expect($media->fresh()->placeholder)->not->toBe('data:image/webp;base64,СТАРЕ');
});

it('does not stop on a file it cannot read', function () {
    // Один зниклий файл не має лишати решту каталогу без заглушок.
    $broken = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('gone.png', 400, 400)));
    $intact = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->image('here.png', 400, 400)));

    MediaFile::query()->whereIn('uuid', [$broken->uuid, $intact->uuid])->update(['placeholder' => null]);
    Storage::disk('public')->delete($broken->path);

    $this->artisan('media:placeholders')->assertSuccessful();

    expect($intact->fresh()->placeholder)->not->toBeNull()
        ->and($broken->fresh()->placeholder)->toBeNull();
});

it('accepts an extension the host adds to a type', function () {
    // An accounting system receives XML declarations and CSV statements; a list
    // written for a shop refused both.
    config(['media-kit.types' => ['document' => ['xml', '.CSV']]]);

    $xml = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->create('declaration.xml', 4, 'application/xml')));
    $csv = app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->create('statement.csv', 4, 'text/csv')));

    expect($xml->type)->toBe(MediaType::Document)
        ->and($csv->type)->toBe(MediaType::Document);
});

it('still refuses an extension nobody added', function () {
    config(['media-kit.types' => ['document' => ['xml']]]);

    app(MediaWriter::class)->store(MediaUpload::file(UploadedFile::fake()->create('tool.exe', 4)));
})->throws(UnsupportedFileType::class);

function privateVault(): void
{
    // A real local disk: the faked one mints temporary URLs of its own, which
    // would step around the serving route this is about.
    config([
        'filesystems.disks.vault' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/vault'),
            'visibility' => 'private',
        ],
    ]);

    Storage::disk('vault')->deleteDirectory('media');
}

it('signs links over the path alone when they start at a configured root', function () {
    // Behind a proxy that forwards /api/media/* to /media/*, the address that
    // arrives is not the one that was signed; an absolute signature fails there.
    privateVault();
    config(['media-kit.routes.url' => '/api/media/']);

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf')),
        null,
        'vault',
    );

    $url = app(MediaReader::class)->byUuid($media->uuid)->url;

    expect($url)->toStartWith("/api/media/media/{$media->uuid}?")
        ->and($url)->toContain('signature=');

    // What the proxy hands on: the same link without its own prefix.
    $this->get(substr($url, strlen('/api/media')))->assertOk();
});

it('refuses a link that starts at a root when its signature was tampered with', function () {
    privateVault();
    config(['media-kit.routes.url' => '/api/media']);

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf')),
        null,
        'vault',
    );

    $url = substr(app(MediaReader::class)->byUuid($media->uuid)->url, strlen('/api/media'));

    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=' . str_repeat('0', 64), $url))->assertForbidden();
});

it('keeps absolute links when no root is configured', function () {
    privateVault();

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf')),
        null,
        'vault',
    );

    $url = app(MediaReader::class)->byUuid($media->uuid)->url;

    expect($url)->toStartWith('http://localhost/media/');

    $this->get($url)->assertOk();
});

it('rewrites captions without touching the file or its address', function () {
    $media = app(MediaWriter::class)->store(MediaUpload::file(
        UploadedFile::fake()->image('front.png', 200, 200),
        captions: [new MediaCaption('uk', alt: 'Спереду', title: 'Фасад'), new MediaCaption('en', alt: 'Front')],
    ));

    $before = app(MediaReader::class)->byUuid($media->uuid);

    Carbon::setTestNow(now()->addMinute());

    $captioned = app(MediaWriter::class)->caption($media->uuid, [
        new MediaCaption('uk', alt: 'Вигляд спереду', title: 'Фасад'),
        new MediaCaption('en'),
    ]);

    $after = app(MediaReader::class)->byUuid($media->uuid);

    expect($captioned->captions->pluck('alt', 'locale')->all())->toBe(['uk' => 'Вигляд спереду'])
        ->and($after->captions)->toHaveKeys(['uk'])
        ->and($after->captions)->not->toHaveKey('en')
        ->and($after->url)->toBe($before->url)
        ->and($after->variant('large')?->url)->toBe($before->variant('large')?->url);

    Carbon::setTestNow();
});

it('cannot caption a file that does not exist', function () {
    app(MediaWriter::class)->caption('00000000-0000-4000-8000-000000000000', [new MediaCaption('uk', alt: 'x')]);
})->throws(MediaNotFound::class);

function uploadedWithContent(string $name, string $claimedType, string $content): UploadedFile
{
    // The testing fake reports a type from the file name, which is the very
    // claim this is about; a real upload reads the bytes.
    $path = tempnam(sys_get_temp_dir(), 'media-kit');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $claimedType, null, true);
}

it('records the type the bytes have, not the one the client claimed', function () {
    // A page renamed to invoice.pdf and sent as application/pdf used to be
    // stored — and served back — as a PDF.
    $page = uploadedWithContent('invoice.pdf', 'application/pdf', '<!doctype html><html><body><script>alert(1)</script></body></html>');

    $media = app(MediaWriter::class)->store(MediaUpload::file($page));

    expect($media->mime_type)->toBe('text/html');
});

it('hands active content out as a download, never in place', function () {
    privateVault();
    $media = app(MediaWriter::class)->store(
        MediaUpload::file(uploadedWithContent('notes.txt', 'text/plain', '<!doctype html><html><script>alert(1)</script></html>')),
        null,
        'vault',
    );

    $this->get(app(MediaReader::class)->byUuid($media->uuid)->url)
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename="notes.txt"');
});

it('shows a harmless file in place', function () {
    privateVault();

    $media = app(MediaWriter::class)->store(
        MediaUpload::file(UploadedFile::fake()->image('scan.png', 40, 40)),
        null,
        'vault',
    );

    $this->get(app(MediaReader::class)->byUuid($media->uuid)->url)
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline; filename="scan.png"');
});
