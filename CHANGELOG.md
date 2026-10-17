# Changelog

## 1.10.0

- **`Services\VariantPruner`** — cleaning up from the application instead of a scheduled
  `media:prune`. `stale(?uuid)` deletes renditions made with settings that have since changed
  (quality, sharpening, a file's area); `retired(old, current, modelType, tag)` deletes the ones a
  size the application stopped asking for produced, keeping any the current size shares and the
  same picture's renditions at other sizes. Neither checks for links: call them once whatever links
  to renditions directly has been rebuilt.
- `media:prune` judges "made with other settings" through the same check.

## 1.9.0

- **`Contracts\RenditionQuality`** — where the quality of a rendition comes from. The default,
  `ConfiguredRenditionQuality`, reads `image.quality` as before; a host that lets someone choose it
  binds its own (`bindIf`, so a binding made earlier wins). It is asked at the moment of encoding:
  a value copied into configuration at boot would stay stale in a long-running worker. Quality is
  part of a rendition's path, so a new answer makes new files.
- `ImageOperations` takes the contract as a second constructor argument.

## 1.8.0

### Chosen area

A rendition was cut from the middle of its original, or from one anchor for every file. A wide
photo asked for as a tall card showed whatever stood in the middle, and nothing could say which
part mattered.

- **`MediaCrop`** — the part of a picture to be seen, in fractions of the original; the original
  stays whole. Kept in a new `media_files.crop` column (migration `add_media_crop`).
- **`MediaWriter::crop()`**, and a `crop` on `MediaUpload::file()` / `keep()` / `fromArray()`. Null
  on `keep()` leaves the area alone, `MediaCrop::whole()` takes it away. `replace()` drops it,
  `copy()` carries it.
- **Renditions are cut from the area**: exactly the area at its own proportions, the smallest frame
  holding it at others. A free side of `ImageSize` follows the area. The area is part of the
  rendition's path — only when there is one, so every existing path stays as it was. Renditions
  cut before a change stay on disk for cached pages until `media:prune`.
- **The placeholder** is drawn from the area.
- **`focus`** in the payload (and `MediaResource::focus()`): where the area's centre sits in the
  renditions, for `object-position` in a box of other proportions. Absent without an area.
- `ImageVariantGenerator::sizesFor()` and `pathFor()` take the area as a new optional argument.

## 1.7.0

### Rendition quality

Renditions could not be made sharper: the quality was the library's 75, a small original was
stretched to whatever size was asked for, and a rendition's path held only its size — so even a
new quality would never have reached a picture already on disk.

- **Quality per format** (`image.quality`), plus the crop anchor, progressive encoding and
  sharpening. All of them, with a manual `image.version`, are part of the rendition's path: a
  change produces new files, and renditions are found by path, so one made with older settings
  is never served again.
- **No enlarging** (`image.upscale`, off by default). A rendition stops at its original's size and
  keeps the proportions; the `srcset` lists each real width once.
- **Metadata is stripped** from renditions (`image.strip_metadata`): Imagick used to carry EXIF —
  GPS position included — into every published copy.
- **`media:prune`** removes renditions made with other settings without being told a size, and
  `--model` limits the size check to one owner: product photos, banners and page images are
  asked for at different sizes, and judging all of them by one deleted the rest.
- **The `image` block merges per key.** A host that set one image option lost every other one,
  variants included, because only top-level keys were merged.
- **The first two requests for a size no longer fail.** Both generated the file, and the second
  to record it hit the unique path and answered 500; it now returns the record the first one made.
- **Lanczos resampling** (`image.resample`, Imagick): fine detail — weave, stitching, small print —
  stays crisp when a picture is scaled down, instead of the library's plain scale.
- **Colour converted to sRGB** (`image.srgb`, Imagick) before anything else: a Display P3 photo from
  a phone no longer turns dull once its profile is stripped. The sRGB profile ships with the package
  (CC0).
- **Originals can be reworked on upload** (`image.original`): larger than the limits — scaled down,
  converted and re-encoded; `strip_metadata` — EXIF and GPS removed. Off by default, and an original
  that needs neither is never re-encoded.
- **One decode per upload**: size and placeholder used to read the picture twice.
- **`media:regenerate`** makes every rendition again under the current settings, so a new quality
  is in place before the first visitor asks; `--prune` removes the old files.
- Removed `src/Data/MediaFile.php`, a stray copy of the model declaring the wrong namespace.
- Requires `intervention/image` 3.11: before it the Imagick encoders ignored `strip`, and EXIF —
  GPS included — stayed in every rendition.

### Encryption at rest

**Encryption at rest**, off by default. Switched on for every disk or for one, every file is
encrypted before it leaves the application: a copied bucket or a stolen backup holds nothing
readable without the keys. See [storage](docs/storage.md#encryption-at-rest).

- Envelope encryption: a random key per file, libsodium secretstream in 64 KiB chunks
  (streamed, authenticated, truncation-proof), the file key wrapped with the application key.
- Keys by id in `MEDIA_KIT_KEYS`; `media:key` makes one, `media:rekey` rotates without rewriting
  a file. A host can supply keys from elsewhere by binding `Contracts\KeyProvider`.
- An encrypted file is served only through the signed route, decrypted on the way out; it gets
  no direct address and no placeholder. Renditions are encrypted too.
- **Byte ranges**: the file route answers `Range` with `206 Partial Content`, decrypting only what is
  sent — a video on an encrypted disk can be seeked without downloading it from the start.
- `media:encrypt` converts files stored before (verified against the plaintext hash before the
  original goes), `media:decrypt` converts them back.
- A rendition is made by one request at a time (a cache lock where the store has one): with
  per-file keys two concurrent writes could otherwise leave a record describing the other file.
- New nullable columns on `media_files`: `encryption`, `key_id`, `wrapped_key`, `checksum`.
  `MediaDisk::upload()` refuses on an encrypting disk — use `store()`, which also returns the
  envelope. `ext-sodium` is suggested, needed only when encryption is used.

## 1.6.2

- **An upload kept on an object store arrives whole.** Livewire keeps an upload on its temporary
  disk, and on S3 the file's "real path" is only its key in the bucket. The package opened that
  path as a local file; a worker that does not turn the failed open into an exception (Octane on
  FrankenPHP) then stored an empty object, while the record carried the full size — a picture
  that showed until it was saved. Such an upload is now copied from its own stream first, and a
  file that cannot be opened at all throws `MediaNotStored` instead of being stored empty.

## 1.6.1

- **A caller's lifetime reaches the store's own link.** `LinkLifetime` shortened only the signed
  route; a private file on a store that mints pre-signed links (S3, GCS) still got the disk's
  lifetime — a receipt meant to be readable for fifteen minutes stayed readable for an hour.

## 1.6.0

Two ways an object store could leave the package holding records for files that do not exist.

- **A refused write is an error.** A disk configured with `'throw' => false` answers a rejected
  write with `false`, and the package went on to create the record anyway — a picture in the
  catalogue whose file was never stored, found only when somebody opened it. Writes now throw
  `MediaNotStored` and nothing is recorded.
- **`disks.<name>.public`** marks a disk public without its own `'visibility' => 'public'`. On an
  object store that setting asks for a public-read ACL on every write, and a bucket with ACLs
  disabled — the AWS default for new buckets — refuses every one of them. A bucket made readable
  by its policy, or read only through a proxy, now says it in the package's configuration and
  sends no ACL at all.

## 1.5.0

What an application needs to run the package as a service of its own — behind a proxy, on the
framework version it already has, accepting the files its users actually send.

- **Laravel 12 and PHP 8.3.** The code never needed Laravel 13 or PHP 8.4; the constraints did,
  and they kept the package out of every application a major version behind. The suite runs on
  both (PHP 8.3 and 8.4, Laravel 12 and 13, lowest and stable dependencies).
- **Links that survive a proxy.** A link was absolute and signed over the whole address, so
  anything that rewrote the address on the way in — a backend-for-frontend forwarding
  `/api/media/*` to the service's `/media/*`, a gateway in front of an internal hostname — turned
  every link into a 403. `routes.url` signs the path and query only and puts the configured root
  in front of the link. Left empty, nothing changes.
- **Extensions the host adds.** The list of accepted extensions was written for a catalogue, so an
  XML declaration, a CSV statement or a detached `.p7s` signature was refused as unsupported.
  `types` adds extensions to a type; a built-in extension keeps its type, so configuration cannot
  switch a picture's variants off by listing it elsewhere.
- **`MediaWriter::caption()`** rewrites alt and title without replacing the file. The only way to
  change a caption was `replace()` with the same bytes, or a `sync()` of a model the file may not
  have. The file's address stays the same, because the bytes behind it did.

### Security

- **The stored type is the one the bytes have.** An upload was recorded with the type the client
  claimed and served back with it, so a page renamed to `invoice.pdf` and sent as `application/pdf`
  came back out as a "PDF" that was really HTML. The type is now read from the content; the claim
  is kept only when the content cannot be identified.
- **Active content is never shown in place.** A streamed file of a type a browser executes — HTML,
  XHTML, SVG, XML, JavaScript — is sent as an attachment, and every streamed file carries
  `X-Content-Type-Options: nosniff`. Opened from the application's own origin such a file would run
  with the application's rights. An `<img>` still renders an SVG sent this way.

`MediaWriter` gains a method: an application implementing the contract itself has to add it.

## 1.4.0

- **`media:placeholders`** fills in placeholders for images stored before 1.3.0 kept them. Without
  it the column stays empty until every picture happens to be uploaded again, which for a catalogue
  means never — 1.3.0 shipped the feature and no way to apply it to what was already there.
  `--force` rebuilds existing ones; a file that cannot be read is reported and skipped rather than
  stopping the rest.

## 1.3.0

Three things a page needs and the package was not giving it.

- **`MediaResource::srcset()`** hands the renditions to the browser as a choice. Without it a phone
  downloads the same picture a desktop gets, to paint it a third of the size, over the connection
  least able to afford it. A single rendition returns `null` — a list of one is not a choice.
- **Placeholders.** Every image now keeps a copy a few hundred bytes big, encoded as a `data:` URI
  and shipped inside the payload. Until the real file arrives a card had nothing to show, and that
  gap is both what a visitor reads as slowness and what a layout-shift score measures. Tunable
  through `image.placeholder*`; oversized ones are dropped rather than shipped.
- **`media:prune`** removes renditions nothing can reach: a record whose file is gone, and — when
  told the size the application currently asks for — everything left over from a size it used to
  ask for. Nothing removed those before, so they accumulated for the life of the shop. `--dry-run`
  lists without deleting.

Adds a migration for the `placeholder` column. Images stored before this release get one from
`media:placeholders` — see 1.4.0.

## 1.2.0

The application is no longer in the path of a picture that already exists.

- **A finished rendition is addressed on the disk.** Variant links pointed at an on-demand endpoint
  that answered with a redirect, so every thumbnail on a page cost a PHP process, a signature check
  and a database lookup on the way to a static file. A rendition that has been produced is now
  linked directly; only one that does not exist yet still goes through the endpoint.
- **`warmForModel()` and `warmVariants()`** produce renditions up front. An indexer walking the
  catalogue can warm there, where a few extra seconds are free, instead of leaving the bill with
  whoever opens the page first — usually a crawler.
- Existence is read from the recorded variants rather than from the disk, so a listing costs one
  query instead of a `stat` per rendition. Variants are eager-loaded alongside captions.
- A private disk keeps the endpoint: a direct address there is a temporary link with a lifetime of
  its own, which would step around the disk's policy.
- The size of each named rendition is now worked out in one place (`ImageVariantGenerator::sizesFor`)
  instead of separately where they are described and where they are written.

## 1.1.0

Image links became stable, cacheable and honest. All of it matters most to a storefront, where the
same picture is fetched by browsers, CDNs, image crawlers and product feeds rather than by one
logged-in user.

### Links

- **Variant links no longer expire by default.** They were signed with a 24-hour expiry, which meant
  the address changed on every render: caches never hit, image crawlers found the address dead by
  the time they came back for it, and a product feed went stale between being built and being read.
  The signature stays — it is what stops anyone requesting arbitrary sizes and filling the disk —
  but it is now permanent unless asked otherwise.
- **Lifetimes belong to the disk.** They used to be one application-wide number, so "documents
  expire in a quarter of an hour, catalogue pictures never" was not expressible. Top-level
  configuration holds the defaults, `disks.<name>` holds the exceptions, and a disk nobody mentions
  behaves exactly as before. Visibility moved in with them, since it answers the same question.
- **A single read can override the disk.** `LinkLifetime::minutes(15)` passed to a read shortens
  that read's links — original and variants together, so a short-lived preview cannot have
  long-lived thumbnails.

### Cache

- **Served files carry cache headers.** A public file is `public, max-age=31536000, immutable`; a
  private one is `private` and never outlives the link it redirects to. Tunable through `cache_ttl`.
- **An answer never outlives its link.** The lifetime is read from the expiry the signed address
  carries, not from configuration, so the two cannot drift apart — a link deliberately shortened for
  one caller no longer arrives described by the disk's year-long default, and `immutable` is stated
  only when there is no expiry at all.
- **Addresses carry the file's version.** The serving routes are keyed by uuid and `replace()` keeps
  the uuid deliberately, so the same address used to start returning different bytes while every
  cache kept the old picture. Replacing now orphans the old address.
- **Repeat requests get a 304.** A weak `ETag` over uuid and version, for the streamed responses
  that cannot be cached for long and therefore come back often.

### Notes

- The disk wrapper is no longer memoised, so configuration changed at runtime takes effect.
- For a public disk the package answers with a redirect, so its cache headers apply to the redirect;
  the bytes are served by the storage or CDN under their own configuration. See the README.

## 1.0.1

Packaging only — no code changes.

- `homepage` and the `support` links pointed at `github.com/kandysoft/media-kit`, an organisation
  that does not exist; every one of them 404'd from the Packagist page. They now point at
  `TheKandySoft`.
- `LICENSE`, `pint.json` and `resources/default.webp` were committed with the executable bit set.
- `NOTICE` now says explicitly that the two packages it lists are the direct requirements, and that
  the transitive set is permissive too.

## 1.0.0

First release as a standalone package, extracted from kandyBack.

### Storage

- Files live on **Laravel disks**. The bespoke `StorageInterface` and its five driver classes are
  gone, so any disk the host configures — local, S3, MinIO, GCS — works without a line of code here.
- Public or private is decided by the disk's own `visibility`. Public files get a permanent URL,
  private files a temporary one, and a signed streaming route when the driver can mint neither.
- Configurable image backend (`gd` or `imagick`) instead of a hardcoded Imagick dependency.

### Identity

- **UUID is the primary key.** There is no auto-increment id in the schema, in URLs or in payloads.
- Variants point at their original through `parent_uuid`; captions through `media_file_uuid`.

### API

- Reads return `MediaResource` objects instead of nested arrays. Disk names, storage paths and owner
  ids are no longer shipped to callers.
- Filters, uploads, captions and sizes are typed DTOs: `MediaFilter`, `MediaUpload`, `MediaCaption`,
  `ImageSize`.
- `MediaType` is an enum; an unsupported extension is refused at upload rather than stored as
  "unknown".
- `replace()` swaps the file behind an existing UUID, so references to it survive.
- `store()` accepts no model, which makes standalone files (settings images, imported assets) a
  first-class case rather than a workaround.
- Facades and the container resolve the same singletons.

### Fixed

- Serving routes passed a disk name where a driver key was expected, so every file on the default
  local disk raised `Unsupported storage driver`.
- Private storage built URLs through a route that was never registered.
- A proxy route redirected to any URL given in a query parameter — an open redirect.
- `StorageFactory` ignored the documented `drivers` config and matched on a hardcoded list, so
  custom drivers could not be registered at all.
- Resolving the storage service performed I/O — an existence check, and sometimes an upload — inside
  a constructor. The fallback image is now stored on first use.
- Downloads fetched the application's own public URL over HTTP and buffered the whole file in
  memory; they stream from the disk now.
