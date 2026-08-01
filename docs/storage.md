# Storage

MediaKit has no storage abstraction of its own. Every file lives on a disk from
`config/filesystems.php`, reached through `Storage::disk()`. Whatever the host can configure, the
package can store on.

## Choosing a disk

```dotenv
MEDIA_KIT_DISK=public
MEDIA_KIT_DIRECTORY=media
```

`MEDIA_KIT_DISK` is the default; any write can override it:

```php
$writer->store($upload, $product, disk: 's3');
```

The disk name is recorded on the file, so mixing disks is fine — reads always go back to the disk
the file was written to.

## Public and private

A disk counts as public when it says so:

```php
'media' => [
    'driver' => 's3',
    'visibility' => 'public',   // ← this line
    // ...
],
```

The default is private. That direction is deliberate: mislabelling a private disk as public would
hand out permanent links to files meant to stay behind a signature.

What a caller gets back as `url` depends on the disk:

| Disk | URL |
| --- | --- |
| public, with a `url` | permanent URL straight from the disk |
| private, driver supports temporary URLs (S3, GCS) | pre-signed URL, `temporary_url_ttl` minutes |
| private, local | signed route; the application streams the bytes |

Nothing in the calling code changes between these cases.

## Image variants

Variants are never generated on upload. `MediaResource::variants` contains **signed links** to the
on-demand endpoint; the file is produced the first time somebody follows one and reused afterwards.
A variant is itself a media record, tied to the original through `parent_uuid`, and it is deleted
with it.

Sizes come from `media-kit.image.variants` — names mapped to multipliers of the requested size:

```php
'variants' => [
    'large' => 1.0,
    'medium' => 0.6,
    'small' => 0.3,
],
```

The signature matters: without it anyone could ask for arbitrary dimensions and fill the disk.

## Serving

Three routes, registered under `media-kit.routes.prefix`:

| Name | Purpose |
| --- | --- |
| `media-kit.file` | serve a file; a public one redirects to the disk, anything else needs a signature |
| `media-kit.download` | same, as an attachment |
| `media-kit.variant` | generate a rendition and serve it; always signed |

Set `media-kit.routes.enabled` to `false` to register none of them and serve media from your own
controllers. Note that variant links need the routes: with them disabled, `MediaResource::variants`
comes back empty.

## Adopting existing files

Files copied onto a disk out of band can be registered afterwards:

```bash
php artisan media:adopt --disk=s3 --tag=imported
```

or one at a time:

```php
$writer->adopt('media/imports/brochure.pdf', disk: 's3', tag: 'imported');
```

Adoption reads dimensions and size from the disk, so it is a little more expensive than a normal
upload — it is a maintenance path, not a hot one.
