<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | RULE #9 — LOCAL MEDIA STORAGE. The `s3` disk that ships with the Laravel
    | skeleton has been removed deliberately, and no cloud/object-storage disk
    | (S3, MinIO, R2, Spaces, GCS) may be added. All uploads live on the local
    | `public` disk, symlinked to public/storage and served directly by the web
    | server, so media delivery has no internet dependency.
    |
    | tests/Architecture/NoCloudStorageTest.php fails the build if a cloud
    | driver, an AWS_* key, or an S3 flysystem package reappears.
    |
    | Requirements 2.3, 2.4.
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Media Library target. Requirement 2.4.
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),

            /*
             * The PUBLIC base URL for media, and the single place that decides it.
             *
             * Every media URL this application emits comes from here through
             * Media::getFullUrl() — the image and video sitemaps, the JSON-LD `image`
             * and publisher `logo`, og:image and twitter:image, and the `url` on every
             * MediaAsset in the Delivery API. So rebasing media onto another origin is
             * one variable rather than an audit of every call site.
             *
             * CMS_MEDIA_URL is the optional upgrade. This Core is headless, so images
             * are fetched by the same visitors who read the FRONTEND, and serving them
             * from the frontend's origin buys three things: the images are same-host as
             * the pages that embed them (which the sitemaps protocol asks for and Bing
             * enforces more strictly than Google), the frontend's CDN edge caches them
             * so this application stops paying for image bandwidth, and /storage/ can
             * stay closed to crawlers here.
             *
             * Point it at a path the frontend rewrites back to this host, e.g.
             * CMS_MEDIA_URL=https://www.example.com/media with a Next.js rewrite from
             * /media/:path* to https://api.example.com/storage/:path* — see
             * docs/deployment.md.
             *
             * UNSET is the default and must keep working on its own: media is then
             * served from this host under /storage, and RobotsController KEEPS THAT PATH
             * CRAWLABLE precisely because of this setting. Defaulting to the frontend
             * would 404 every image on a deployment that had not added the rewrite yet.
             */
            'url' => rtrim((string) (env('CMS_MEDIA_URL')
                ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage'), '/'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | `php artisan storage:link` is REQUIRED for media to be reachable. A
    | health check fails loudly when this link is missing rather than letting
    | the site serve broken image URLs. Requirement 2.5.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
