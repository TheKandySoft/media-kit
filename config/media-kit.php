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
    | 'temporary_url' covers private files, 'variant_url' the signed links that
    | trigger on-demand image resizing.
    |
    */

    'temporary_url_ttl' => (int) env('MEDIA_KIT_TEMPORARY_URL_TTL', 60),

    'variant_url_ttl' => (int) env('MEDIA_KIT_VARIANT_URL_TTL', 1440),

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
