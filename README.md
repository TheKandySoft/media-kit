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

### Policy per disk

How long a link lives is a property of the disk that stores the file, not of the application. The
top-level settings are the defaults; `disks` holds the exceptions:

```php
'temporary_url_ttl' => 60,        // minutes
'variant_url_ttl'   => null,      // null — the address never expires
'cache_ttl'         => 31536000,  // seconds

'disks' => [
    'documents' => [
        'temporary_url_ttl' => 15,
        'variant_url_ttl'   => 15,
        'cache_ttl'         => 900,
    ],
],
```

A disk nobody lists behaves like every other one. Attaching the decision to where a file is stored
is the point: a scanned document cannot acquire a permanent address by accident, because it is not
kept where permanent addresses are handed out.

Variant links are signed but do not expire by default. The signature stops anyone requesting
arbitrary sizes and filling the disk; it is not there to keep the picture secret. An expiry would
change the address on every render, so browsers and CDNs would re-download bytes they already hold,
and anything that reads an address once and fetches it later — an image crawler, a product feed, a
link preview — would find it dead.

### Warming, so the first visitor does not wait

Renditions are produced on demand: the first request for a size creates the file. That request pays
for a redirect through the application, a signature check and a database lookup on the way to what
is ultimately a static file — and on a catalogue the one paying is usually a search engine reading a
page nobody has opened yet.

Warm them where the cost is free, and the address of a finished rendition points at the disk:

```php
$writer->warmForModel($product, MediaFilter::images(), ImageSize::of(800, 600));
```

Reads decide per rendition: one that already exists is addressed on the disk, one that does not
still gets the on-demand endpoint. Nothing has to be warmed for the package to work — warming only
removes the application from the path.

Existence is answered from the recorded variants, not by asking the disk, so describing a listing of
thirty pictures costs one query rather than ninety `stat` calls. A private disk keeps the endpoint
either way: a direct address there is a temporary link with a lifetime of its own, which would step
around the disk's policy.

### What the package can and cannot cache

Served files carry `Cache-Control`, and an answer never claims to live longer than the signed link
that produced it. For a **public** disk, though, the package answers with a redirect to the disk's
own URL, so these headers apply to the redirect: the bytes themselves are served by nginx, S3 or
the CDN in front of them, under **their** configuration. Set the cache headers there too, or most
of the benefit stays on the table.

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

Captions change without the file:

```php
$this->media->caption($logo->uuid, [new MediaCaption('uk', alt: 'Логотип', title: 'Бренд')]);
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
$images[0]->variant('medium')?->url;   // one rendition
$images[0]->srcset();                  // all of them, for the browser to choose from
$images[0]->placeholder;               // a tiny data: URI to show meanwhile
$images[0]->caption?->alt;
```

`srcset()` matters more than it looks: without it a phone downloads the picture a desktop gets, to
paint it a third of the size, over the connection least able to afford it. `placeholder` is a copy a
few hundred bytes big, stored beside the image — the card shows it instead of an empty box while the
real file is on its way.

Every read is handed out under the policy of the disk the file lives on. When one caller needs
something shorter — an admin preview, an export leaving the building — pass a lifetime and it
replaces the policy for that read, original and variants together:

```php
use KandySoft\MediaKit\Data\LinkLifetime;

$preview = $reader->byUuid($uuid, size: ImageSize::of(400, 300), lifetime: LinkLifetime::minutes(15));
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
| `temporary_url_ttl`, `variant_url_ttl` | link lifetimes, in minutes; empty means no expiry |
| `cache_ttl` | how long a served answer may be reused, in seconds |
| `disks` | per-disk overrides of the three above |
| `routes.enabled`, `routes.prefix`, `routes.middleware` | serving endpoints, or none at all |
| `routes.url` | the root links start at, when a proxy rewrites the address — see [storage](docs/storage.md#behind-a-proxy) |
| `types` | extensions accepted on top of the built-in ones, per type |

## Commands

```bash
php artisan media:show <uuid>     # what a client would receive
php artisan media:adopt           # register files already on a disk
php artisan media:prune           # drop renditions nothing can reach any more
php artisan media:placeholders    # fill in placeholders for images stored earlier
```

## Documentation

- [docs/usage.md](docs/usage.md) — attaching, replacing, reading, copying, fallbacks
- [docs/storage.md](docs/storage.md) — disks, visibility, variants, serving routes
- [CHANGELOG.md](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE) and [NOTICE](NOTICE).
