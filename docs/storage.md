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

On an object store `'visibility' => 'public'` is more than a label: every write then asks for a
public-read ACL, and a bucket with ACLs disabled — the AWS default for new buckets — refuses it.
Leave the disk's visibility alone and tell the package instead:

```php
// config/media-kit.php
'disks' => [
    's3_public' => ['public' => true],
],
```

The bucket is then made readable by its own policy, or read only through a proxy (below).

A write the disk refuses throws `MediaNotStored`, even on a disk with `'throw' => false`: no record
is created for a file that was never stored.

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

## Behind a proxy

Links are absolute by default and signed over the whole address, host included. That breaks as soon
as something rewrites the address between the client and the application — a backend-for-frontend
forwarding `/api/media/*` to the service's own `/media/*`, or a gateway in front of an internal
hostname: the request that arrives is not the one that was signed, and every link answers 403.

Give the links a root instead:

```dotenv
MEDIA_KIT_ROUTE_URL=/api/media
```

Links are then signed over path and query only, and the root is put in front of them:

```text
/api/media/media/9b1c…?expires=…&signature=…     what the client receives
          /media/9b1c…?expires=…&signature=…     what the proxy hands on — still valid
```

The root can be a full address too (`https://files.example.com`). Links a disk mints itself — a
public URL, an S3 pre-signed one — are the disk's own and are not touched.

## Buckets behind your own domain

Nothing has to reach the browser with the bucket's real address on it. Point all three kinds of
link at one host of your own — say `storage.example.com` — and let its proxy forward to the
store:

```php
// config/filesystems.php — path-style, so the bucket is the first segment of every path
's3_public' => [
    'driver' => 's3',
    'bucket' => 'shop-public',
    'url' => 'https://storage.example.com/shop-public',      // permanent links
    'use_path_style_endpoint' => true,
    // ...
],
's3_private' => [
    'driver' => 's3',
    'bucket' => 'shop-private',
    'temporary_url' => 'https://storage.example.com',        // pre-signed links: host only
    'use_path_style_endpoint' => true,
    // ...
],
```

```dotenv
MEDIA_KIT_ROUTE_URL=https://storage.example.com             # signed links: /media/…
```

The proxy sends `/<public bucket>/…` and `/<private bucket>/…` to the store and `/media/…` to the
application. A pre-signed link stays valid only if the proxy forwards it unchanged — the path and
query as they arrived, `Host` set to the store's own host — because that is what was signed.
`temporary_url` replaces the host of a pre-signed link and nothing else, which is why the buckets
are told apart by path.

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

## Encryption at rest

Off by default. Switched on, every file is encrypted before it leaves the application, so a
copied bucket, a stolen backup or a server image holds nothing readable without the keys —
the at-rest safeguard that GDPR Art. 32 and most data-protection audits ask for.

```bash
php artisan media:key            # prints e.g. 20261013:base64…
```

```dotenv
MEDIA_KIT_ENCRYPT=true                      # every disk; or per disk, see below
MEDIA_KIT_KEYS="20261013:base64…"           # id:key, comma-separated
MEDIA_KIT_KEY_CURRENT=20261013              # optional; defaults to the last listed
```

```php
// config/media-kit.php — only some disks
'disks' => [
    'private' => ['encrypt' => true],
],
```

**How.** Every file gets its own random key and is encrypted with it as a libsodium
secretstream (XChaCha20-Poly1305) in 64 KiB chunks — streamed, so a video costs no more memory
than a thumbnail. The file key is stored in the record wrapped with the application key
(`key_id`, `wrapped_key`), next to the SHA-256 of the plaintext (`checksum`). An altered byte
fails its chunk and a file cut short never reaches its final one: both throw `MediaTampered`
instead of serving garbage. A record naming a key the application does not have throws
`MediaKeyMissing`.

**What changes when it is on.**

- An encrypted file has **no direct address**. The disk or a CDN in front of it would hand out
  ciphertext, so every link goes through the package's signed route, which decrypts on the
  way out. Cache headers stay as they were; what is lost is serving straight from the store.
- Encrypted pictures get **no placeholder**: that is a readable copy kept in the database.
- Renditions are encrypted too, each with its own key.
- Plain and encrypted files live side by side: each record says how its file was stored.

**Turning it on for files already stored.** `media:encrypt` writes each one encrypted under a
new name, reads it back, checks it against the plaintext's hash, and only then moves the record
and deletes the original; renditions are dropped and come back encrypted. `--disk` limits it,
`--dry-run` counts. `media:decrypt` is the way back — switch encryption off first, or new
uploads keep arriving encrypted.

**Rotating keys.** Add a new key to `MEDIA_KIT_KEYS`, make it current, run `media:rekey`. Only
the wrapped keys in the records change — no file is rewritten — and once it has run, the old
key can leave the configuration.

**Keys elsewhere.** `MEDIA_KIT_KEYS` is the default source. A host that keeps keys in a KMS or a
vault binds its own `KandySoft\MediaKit\Contracts\KeyProvider`; files only ever name a key by
its id.

**Object stores have a cheaper option.** S3 server-side encryption (SSE-S3 or SSE-KMS, set in
the disk's `options`) needs nothing from this package and keeps direct addresses working. It
protects the disks of the provider, not against whoever holds the bucket credentials — package
encryption does, because the keys never reach the store.
