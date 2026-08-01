# Usage

## Attaching files to a model

Any Eloquent model can own media; nothing has to be added to it.

```php
use KandySoft\MediaKit\Contracts\MediaWriter;
use KandySoft\MediaKit\Data\MediaCaption;
use KandySoft\MediaKit\Data\MediaUpload;

$writer->sync($product, [
    MediaUpload::file($request->file('front'), tag: 'gallery', captions: [
        new MediaCaption('en', alt: 'Front view'),
        new MediaCaption('uk', alt: 'Вигляд спереду'),
    ]),
    MediaUpload::keep($existingUuid, tag: 'gallery'),
]);
```

`sync()` makes the collection match the list exactly: new files are stored, kept ones are
repositioned, anything missing from the list is deleted along with its variants. Order in the array
becomes `position`.

Narrow what a sync is allowed to touch with a filter — useful when one model holds several
independent collections:

```php
$writer->sync($master, $photos, MediaFilter::tagged(['photo']));
// files tagged 'gallery' are left alone
```

## Standalone files

A file needs no owner. Settings images, imported assets and anything else global:

```php
$logo = $writer->store(MediaUpload::file($request->file('logo'), tag: 'branding'));

$reader->tagged('branding');
```

## Replacing

`replace()` swaps the file but keeps the UUID, so anything already pointing at it — a settings
value, a cached payload, a link somebody bookmarked — keeps working:

```php
$writer->replace($logo->uuid, $request->file('logo'));
```

The old file and every variant generated from it are removed. The replacement is written first, so
a failure leaves the original in place rather than a record pointing at nothing.

## Reading

```php
use KandySoft\MediaKit\Data\ImageSize;
use KandySoft\MediaKit\Data\MediaFilter;

$images = $reader->forModel(
    $product,
    MediaFilter::images(captionLocale: 'uk', withFallback: true),
    ImageSize::of(800, 600),
);
```

`MediaFilter` is immutable and composes:

```php
MediaFilter::images('uk')
    ->withTags('gallery')
    ->inLocale('uk')      // files that belong to one locale
    ->fallingBack();
```

`inLocale()` and `withoutLocale` are different questions: the first asks for files belonging to a
locale, the second for files belonging to none. Leaving both alone means "any".

`ImageSize` replaces the width/height/format triple. Give one dimension and the other follows the
original's aspect ratio; give none and the configured default applies.

## What you get back

`MediaResource` — a readonly DTO, `Arrayable` and `JsonSerializable`:

```php
$image->uuid;
$image->url;
$image->type;                    // MediaType enum
$image->caption?->alt;           // in the requested locale
$image->captions['en']->title;   // every locale
$image->variant('medium')?->url;
$image->aspect();
```

Disk names, storage paths and owner ids are not part of it, by design.

## Copying between models

```php
$writer->copy($product, $duplicate);
$writer->copy($product, $duplicate, MediaFilter::tagged(['gallery']), limit: 5);
```

Files are physically copied, not shared, so deleting one model's media leaves the other intact.

## Fallback image

Ask for it explicitly, or let a filter substitute it:

```php
$reader->fallbackImage();
$reader->firstForModel($product, MediaFilter::images(withFallback: true));
```

The fallback only stands in when images were requested — it would be a surprise in place of a
missing PDF. It is stored on the disk the first time it is actually needed; nothing is written just
because a service was resolved.

Point `media-kit.fallback_image` at your own file to replace the one shipped with the package.

## Facade or injection

The facades resolve the very same singletons the container injects:

```php
use KandySoft\MediaKit\Facades\MediaRead;
use KandySoft\MediaKit\Facades\MediaWrite;
use KandySoft\MediaKit\Facades\MediaDisks;

MediaRead::forModel($product);
MediaWrite::delete($uuid);
MediaDisks::disk('s3')->exists($path);
```

Injecting `MediaReader`, `MediaWriter` or `MediaStorage` is equivalent and preferable in code you
intend to test.

## Errors

| Exception | When |
| --- | --- |
| `MediaNotFound` | unknown UUID, missing file on disk, no fallback available |
| `UnsupportedFileType` | an extension the package will not store |

Both extend `RuntimeException`.
