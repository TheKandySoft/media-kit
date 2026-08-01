# Changelog

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
