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

## Captions

`caption()` rewrites alt and title for the locales it is given and leaves the file alone. A caption
with neither removes its locale:

```php
$writer->caption($image->uuid, [
    new MediaCaption('uk', alt: 'Вигляд спереду', title: 'Фасад'),
    new MediaCaption('en'),            // drops the English caption
]);
```

The address does not change — the bytes behind it did not.

## Chosen area

A rendition is cut from its original to the proportions it is asked for — by default from the
middle (`image.crop_position`). A wide photo asked for as a tall card then shows whatever stands in
the middle. `MediaCrop` lets a person choose instead:

```php
use KandySoft\MediaKit\Data\MediaCrop;

$writer->crop($photo->uuid, MediaCrop::of(x: 0.55, y: 0.0, width: 0.45, height: 1.0));
$writer->crop($photo->uuid, MediaCrop::whole());   // back to the middle; null does the same

MediaUpload::file($request->file('photo'), crop: MediaCrop::of(0.2, 0.0, 0.5, 1.0));
MediaUpload::keep($uuid, crop: $crop);             // null leaves the area as it is
```

The area is in fractions of the original, and the original stays whole: proportions change, and a
picture cut for yesterday's card could not be uncut for tomorrow's.

- Asked for at **the area's own proportions**, a rendition is exactly the area.
- Asked for at **other proportions**, it is the smallest frame of those proportions that holds the
  area, centred on it — and where the original is too small for that, the largest it has, still
  centred on the area.
- A **free side** (`ImageSize::of(1920, null)`) follows the area's proportions, not the original's.
- The **placeholder** is drawn from the area.

The area is part of a rendition's path. Changing it cuts new renditions on the next request and
leaves the old files for pages that still point at them; `media:prune` removes them once nothing
asks for them. `replace()` drops the area — it was chosen for another picture — and `copy()` carries
it over.

A page that shows a rendition in a box of yet other proportions — a fixed-height banner, a tile
that changes shape on a phone — reads `focus` from the payload: where the area's centre sits in the
rendition, in fractions. As `object-position: {x*100}% {y*100}%` it keeps the chosen part in view.
It is absent for a file without an area, where the middle is right.

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
$image->crop;                    // the chosen area, or null
$image->focus();                 // ['x' => 0.5, 'y' => 0.3] inside the renditions, or null
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

## Accepting more extensions

The built-in list covers the usual pictures, videos, documents, sounds and archives. Anything else
is refused with `UnsupportedFileType` until the host adds it to a type:

```php
// config/media-kit.php
'types' => [
    'document' => ['xml', 'csv', 'odt', 'ods', 'rtf', 'p7s'],
],
```

Extensions are added, never moved: `png` stays an image even when listed under `document`. Adding
one to `image` makes the package read dimensions and produce variants from it, so list only formats
the image driver decodes.

## Errors

| Exception | When |
| --- | --- |
| `MediaNotFound` | unknown UUID, missing file on disk, no fallback available |
| `UnsupportedFileType` | an extension the package will not store |

Both extend `RuntimeException`.
