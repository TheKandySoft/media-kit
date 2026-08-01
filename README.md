# MediaKit

A media library for Laravel that stores files on **your** disks. Uploads, per-locale captions and
on-demand image variants — with no storage abstraction of its own to get in the way.

```bash
composer require kandysoft/media-kit
php artisan migrate
```

## Storage

Anything in `config/filesystems.php` works: local, S3, MinIO, Google Cloud, whatever the host has
configured. Point the package at one:

```dotenv
MEDIA_KIT_DISK=public
MEDIA_KIT_DIRECTORY=media
```

A disk counts as public when it declares `'visibility' => 'public'`. Public files get a permanent
URL from the disk; private files get a temporary one, or a signed route that streams through the
application when the driver cannot mint temporary URLs.

## Writing

```php
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaUpload;

public function __construct(private readonly MediaWriter $media) {}

$this->media->sync($product, [
    MediaUpload::file($request->file('photo'), tag: 'gallery', captions: [
        new MediaCaption('en', alt: 'Front view'),
        new MediaCaption('uk', alt: 'Вигляд спереду'),
    ]),
    MediaUpload::keep($existingUuid, tag: 'gallery'),
]);
```

Anything not in the list is deleted, together with the variants generated from it. A file needs no
owner at all — `store()` with `$model = null` keeps it standalone under its tag.

Replacing keeps the UUID, so links and stored references survive:

```php
$this->media->replace($logo->uuid, $request->file('logo'));
```

## Reading

```php
use KandySoft\MediaKit\Contracts\MediaReader;
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaFilter;

$images = $reader->forModel(
    $product,
    MediaFilter::images(captionLocale: 'uk', withFallback: true),
    ImageSize::of(800, 600),
);

$images[0]->url;                       // the original
$images[0]->variant('medium')?->url;   // signed, generated on first request
$images[0]->caption?->alt;
```

Reads return `MediaResource` objects, never models or loose arrays. The disk, the path and the
owning model stay inside the package.

## Facade or injection

The facades resolve the very same singletons the container injects, so both styles are equivalent:

```php
MediaRead::forModel($product);            // facade
app(MediaReader::class)->forModel($product);  // container
```

## Identity

Files are addressed by UUID, everywhere. There is no auto-increment key — not in the schema, not in
URLs, not in the payloads clients receive.

## Configuration

`php artisan vendor:publish --tag=media-kit-config` for the full file. The interesting parts:

| Key | Purpose |
| --- | --- |
| `disk`, `directory` | where files land |
| `image.driver` | `gd` or `imagick` |
| `image.variants` | named multipliers of the requested size |
| `temporary_url_ttl`, `variant_url_ttl` | link lifetimes, in minutes |
| `routes.enabled`, `routes.prefix`, `routes.middleware` | serving endpoints, or none at all |

## Commands

```bash
php artisan media:show <uuid>     # what a client would receive
php artisan media:adopt           # register files already on a disk
```

## Documentation

- [docs/usage.md](docs/usage.md) — attaching, replacing, reading, copying, fallbacks
- [docs/storage.md](docs/storage.md) — disks, visibility, variants, serving routes
- [CHANGELOG.md](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
