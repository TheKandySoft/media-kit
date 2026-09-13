# Changelog

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
