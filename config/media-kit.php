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
    */

    'disks' => [],

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
    ],

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
    ],
];
