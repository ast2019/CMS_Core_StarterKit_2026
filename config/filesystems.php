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
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
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
