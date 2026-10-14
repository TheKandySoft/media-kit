<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default disk
    |--------------------------------------------------------------------------
    |
    | Any disk from config/filesystems.php. The package never defines disks of
    | its own: whatever the host application can configure — local, S3, MinIO,
    | GCS — it can store media on.
    |
    | A disk is treated as public when it declares 'visibility' => 'public'.
    | Public files get a permanent URL from the disk; private files get a
    | temporary URL, or a signed route when the driver cannot mint one.
    |
    */

    'disk' => env('MEDIA_KIT_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Directory
    |--------------------------------------------------------------------------
    |
    | Root directory for everything this package writes, relative to the disk.
    |
    */

    'directory' => env('MEDIA_KIT_DIRECTORY', 'media'),

    /*
    |--------------------------------------------------------------------------
    | Link lifetimes (minutes)
    |--------------------------------------------------------------------------
    |
    | 'temporary_url_ttl' covers private files: the link hands out bytes that
    | are not meant to be public, so it has to stop working.
    |
    | 'variant_url_ttl' covers the signed links that trigger on-demand image
    | resizing, and it is empty by default. A variant link is signed to stop
    | anyone from filling the disk with arbitrary sizes, not to keep the image
    | secret, and an expiry would cost more than it protects: the link changes
    | on every render, so browsers and CDNs re-download bytes they already
    | hold, search engines drop images they cannot re-fetch later, and a
    | product feed goes stale between the moment it is built and the moment
    | the crawler reads it. Signed but permanent keeps the protection and
    | none of that. Set a number of minutes only when the images themselves
    | are private.
    |
    */

    'temporary_url_ttl' => (int) env('MEDIA_KIT_TEMPORARY_URL_TTL', 60),

    'variant_url_ttl' => env('MEDIA_KIT_VARIANT_URL_TTL'),

    /*
    |--------------------------------------------------------------------------
    | Cache lifetime (seconds)
    |--------------------------------------------------------------------------
    |
    | How long a served file may be reused without asking again. Media is
    | immutable in practice — a replaced picture is a new record with a new
    | uuid — so the default is a year. Zero disables caching headers.
    |
    */

    'cache_ttl' => (int) env('MEDIA_KIT_CACHE_TTL', 31536000),

    /*
    |--------------------------------------------------------------------------
    | Per-disk policy
    |--------------------------------------------------------------------------
    |
    | The three settings above are the defaults; this is where a disk says it
    | wants something else. Keys are disk names from config/filesystems.php,
    | and a disk nobody mentions here behaves exactly like every other one.
    |
    | The unit of policy is the disk on purpose. Catalogue pictures want
    | permanent, heavily cached addresses; a scanned document wants a link that
    | stops working. Keeping that decision attached to where the file is stored
    | means the wrong file cannot quietly acquire the wrong policy.
    |
    |   'documents' => [
    |       'temporary_url_ttl' => 15,
    |       'variant_url_ttl'   => 15,
    |       'cache_ttl'         => 900,
    |   ],
    |
    | 'public' marks a disk public without the disk's own 'visibility'. On an
    | object store that setting also asks for a public-read ACL on every write,
    | which a bucket with ACLs disabled (the AWS default) refuses; a bucket
    | made readable by its policy, or read through a proxy, says it here:
    |
    |   's3_public' => ['public' => true],
    |
    */

    'disks' => [],

    /*
    |--------------------------------------------------------------------------
    | Encryption at rest
    |--------------------------------------------------------------------------
    |
    | Off by default. On, every file written to a disk is encrypted before it
    | leaves the application: a copied bucket, a stolen backup or a server
    | image hold nothing readable without the keys below. Turn it on for every
    | disk here, or for one disk with `'disks' => ['private' => ['encrypt' =>
    | true]]`.
    |
    | Each file gets its own key, encrypted ("wrapped") with the current key
    | from 'keys' and stored next to the record. Keys are listed by id so they
    | can rotate: add a new one, make it 'current', run `media:rekey` — no file
    | is rewritten, and the old key may go once nothing names it.
    |
    | 'keys' is `id:base64,id:base64` (32 random bytes each; `media:key` makes
    | one). Keep them out of the repository and the database: whoever has both
    | the keys and the disk has the files.
    |
    | An encrypted file is always served through the application, which
    | decrypts it — never by a direct address from the disk or a CDN.
    */

    'encryption' => [
        'enabled' => (bool) env('MEDIA_KIT_ENCRYPT', false),

        'keys' => env('MEDIA_KIT_KEYS', ''),

        'current' => env('MEDIA_KIT_KEY_CURRENT', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    |
    | 'driver' is the intervention/image backend: 'gd' or 'imagick'. Variants
    | are named multipliers of the requested size and are generated lazily on
    | first request, then reused.
    |
    */

    'image' => [
        'driver' => env('MEDIA_KIT_IMAGE_DRIVER', 'imagick'),

        'format' => env('MEDIA_KIT_IMAGE_FORMAT', 'webp'),

        'default_width' => 300,

        'default_height' => 300,

        'variants' => [
            'large' => 1.0,
            'medium' => 0.6,
            'small' => 0.3,
        ],

        /*
        | How renditions are made. Every key below goes into the rendition's
        | path, so changing one produces new files instead of serving the old
        | ones forever; `media:prune` removes the files nothing asks for any
        | more.
        |
        | 'quality' is per format, 1–100. A format left out uses the encoder's
        | own default (75). PNG is lossless and has no quality.
        |
        | 'upscale' false keeps a rendition no larger than its original: a
        | small photo stays small instead of being blown up and blurred, and
        | the srcset says how wide it really is. True restores the old
        | behaviour, where every rendition has exactly the asked-for size.
        |
        | 'crop_position' anchors the crop when the original's proportions
        | differ from the asked-for ones: center, top, bottom, left, right,
        | top-left, top-right, bottom-left, bottom-right.
        |
        | 'strip_metadata' drops EXIF — camera, time and GPS position — from
        | renditions. A photo taken at home should not publish the address.
        |
        | 'progressive' writes progressive JPEG and interlaced PNG, which show
        | a rough picture early on a slow connection.
        |
        | 'sharpen' (0–100) restores some edge contrast lost in downscaling;
        | 0 leaves the picture as resized. Small values (5–15) are enough.
        |
        | 'resample' — 'lanczos' (Imagick) keeps fine detail — weave, stitching,
        | small print — crisp when a picture is scaled down; 'default' is the
        | library's plain scale. GD has one resampler and ignores this.
        |
        | 'srgb' converts a picture with an embedded colour profile (Display P3
        | from a phone, Adobe RGB from a camera) to sRGB before anything else,
        | so its colours survive the profile being stripped. Imagick only.
        |
        | 'version' changes nothing by itself: raise it to regenerate every
        | rendition after something outside these keys changed — the driver,
        | the library, the encoder build.
        */
        'quality' => [
            'webp' => (int) env('MEDIA_KIT_QUALITY_WEBP', 82),
            'jpg' => (int) env('MEDIA_KIT_QUALITY_JPG', 85),
            'jpeg' => (int) env('MEDIA_KIT_QUALITY_JPG', 85),
            'avif' => (int) env('MEDIA_KIT_QUALITY_AVIF', 60),
        ],

        'upscale' => (bool) env('MEDIA_KIT_UPSCALE', false),

        'crop_position' => env('MEDIA_KIT_CROP_POSITION', 'center'),

        'strip_metadata' => (bool) env('MEDIA_KIT_STRIP_METADATA', true),

        'progressive' => (bool) env('MEDIA_KIT_PROGRESSIVE', true),

        'sharpen' => (int) env('MEDIA_KIT_SHARPEN', 0),

        'resample' => env('MEDIA_KIT_RESAMPLE', 'lanczos'),

        'srgb' => (bool) env('MEDIA_KIT_SRGB', true),

        'version' => (int) env('MEDIA_KIT_RENDITION_VERSION', 1),

        /*
        | The uploaded original itself. Nothing is done to it by default — it
        | is stored exactly as it came. Set a limit and an original larger than
        | it is scaled down, converted to sRGB and re-encoded at 'quality'
        | before it is stored; set 'strip_metadata' and its EXIF (camera, time,
        | GPS) goes too. An original within the limits and without stripping is
        | never re-encoded: every lossy encode costs a little detail.
        |
        | JPEG, PNG, WebP and AVIF only; animated and archival formats are kept.
        */
        'original' => [
            'max_width' => env('MEDIA_KIT_ORIGINAL_MAX_WIDTH'),
            'max_height' => env('MEDIA_KIT_ORIGINAL_MAX_HEIGHT'),
            'quality' => env('MEDIA_KIT_ORIGINAL_QUALITY', 92),
            'strip_metadata' => (bool) env('MEDIA_KIT_ORIGINAL_STRIP_METADATA', false),
        ],

        /*
        | A tiny copy of every image, stored next to it and small enough to
        | travel inside the page. Until the real file arrives a card has
        | nothing to show, and that gap is what a visitor reads as slowness.
        |
        | Anything larger than 'placeholder_max_bytes' is dropped rather than
        | shipped: a placeholder that outgrows its purpose is just another
        | image on the page.
        */
        'placeholder' => (bool) env('MEDIA_KIT_PLACEHOLDER', true),

        'placeholder_width' => (int) env('MEDIA_KIT_PLACEHOLDER_WIDTH', 16),

        'placeholder_max_bytes' => (int) env('MEDIA_KIT_PLACEHOLDER_MAX_BYTES', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra extensions
    |--------------------------------------------------------------------------
    |
    | The package knows the usual extensions of every type; this is where the
    | host adds its own. Keys are type names (image, video, document, audio,
    | archive, vector), values are the extensions to accept on top:
    |
    |   'document' => ['xml', 'csv', 'odt', 'ods', 'rtf', 'p7s'],
    |
    | Extensions are added, never moved: a built-in one keeps its type. Adding
    | an extension to `image` makes the package try to read dimensions and
    | produce variants from it, so only list formats the image driver decodes.
    |
    */

    'types' => [],

    /*
    |--------------------------------------------------------------------------
    | Fallback image
    |--------------------------------------------------------------------------
    |
    | Served when a model has no media and the caller asked for a fallback.
    | Point it at your own file to override the one shipped with the package.
    |
    */

    'fallback_image' => env('MEDIA_KIT_FALLBACK_IMAGE'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Set 'enabled' to false to register nothing and serve media entirely from
    | your own controllers.
    |
    */

    'routes' => [
        'enabled' => (bool) env('MEDIA_KIT_ROUTES', true),

        'prefix' => env('MEDIA_KIT_ROUTE_PREFIX', 'media'),

        'middleware' => [],

        /*
        | Where the links start, as the client reaches the application.
        |
        | Empty: links are absolute and signed over the whole address, host
        | included — right when the application answers on the address its
        | links carry.
        |
        | Set it when something rewrites the address on the way in, such as a
        | proxy that forwards /api/media/* to this application's /media/*:
        | links are then signed over path and query only and this root is put
        | in front of them, so the signature still matches what arrives.
        |
        |   'url' => '/api/media',
        */
        'url' => env('MEDIA_KIT_ROUTE_URL'),
    ],
];
