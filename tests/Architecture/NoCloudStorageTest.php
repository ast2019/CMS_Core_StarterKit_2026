<?php

declare(strict_types=1);

use App\Providers\CmsServiceProvider;

/**
 * RULE #9 — LOCAL MEDIA STORAGE.
 *
 * "no S3/cloud object storage; all files on local disk"
 *
 * Blueprint §12.9, Requirements 2.3, 2.4.
 *
 * This is enforced at four independent layers, because any single check is
 * defeatable: a driver can be re-added to config, a package can be reinstalled,
 * or a stray Storage::disk('s3') can slip into a controller.
 */
it('has no S3 or cloud flysystem adapter installed', function (): void {
    $lock = json_decode((string) file_get_contents(projectPath('composer.lock')), true);

    $packages = array_map(
        fn (array $package): string => strtolower($package['name']),
        [...$lock['packages'], ...$lock['packages-dev']],
    );

    // These are the only adapters that could make a cloud disk actually work.
    // Without them a cloud driver cannot resolve, so their absence is the
    // strongest guarantee available.
    $forbidden = [
        'league/flysystem-aws-s3-v3',
        'league/flysystem-async-aws-s3',
        'league/flysystem-google-cloud-storage',
        'league/flysystem-azure-blob-storage',
        'aws/aws-sdk-php',
        'spatie/flysystem-dropbox',
    ];

    $installed = array_intersect($forbidden, $packages);

    expect($installed)->toBeEmpty(
        'RULE #9 violated: cloud storage adapter(s) installed: '.implode(', ', $installed),
    );
});

it('resolves no forbidden disk driver at runtime', function (): void {
    // Laravel 11+ MERGES the framework's own config/filesystems.php into the
    // application's, so deleting the `s3` disk from the published file is not
    // enough on its own — CmsServiceProvider prunes it at register() time.
    // This test proves the prune actually happened.
    $disks = config('filesystems.disks');

    $drivers = array_map(
        fn (array $disk): ?string => $disk['driver'] ?? null,
        $disks,
    );

    foreach (CmsServiceProvider::FORBIDDEN_DISK_DRIVERS as $forbidden) {
        // in_array + toBeFalse rather than not->toContain: toContain() is variadic
        // and takes no message, so a message passed there becomes a second needle
        // and the assertion silently weakens to "contains neither".
        expect(in_array($forbidden, $drivers, true))->toBeFalse(
            "RULE #9 violated: disk driver [{$forbidden}] is resolvable.",
        );
    }
});

it('stores media on the local public disk', function (): void {
    expect(config('cms.media.disk'))->toBe('public')
        ->and(config('filesystems.disks.public.driver'))->toBe('local');

    // media-library.php is published later in the build; once present its disk
    // must agree with cms.media.disk, or uploads silently bypass the rule.
    if (file_exists(projectPath('config/media-library.php'))) {
        expect(config('media-library.disk_name'))->toBe('public');
    }
});

it('ships no AWS credentials in the example environment', function (): void {
    $env = (string) file_get_contents(projectPath('.env.example'));

    expect($env)->not->toContain('AWS_ACCESS_KEY_ID')
        ->and($env)->not->toContain('AWS_SECRET_ACCESS_KEY')
        ->and($env)->not->toContain('AWS_BUCKET');
});

it('never references a cloud disk in application code', function (): void {
    $offenders = [];

    foreach (collectFiles(projectPath('app'), ['php']) as $file) {
        // Comments stripped: CmsServiceProvider documents the very call pattern
        // it exists to prevent, and that explanation is not itself a violation.
        $contents = stripComments($file);

        foreach (CmsServiceProvider::FORBIDDEN_DISK_DRIVERS as $driver) {
            if (preg_match('/disk\(\s*[\'"]'.preg_quote($driver, '/').'[\'"]\s*\)/i', $contents)) {
                $offenders[] = str_replace(projectPath().'/', '', $file)." => disk('{$driver}')";
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'RULE #9 violated: '.implode('; ', $offenders),
    );
});
