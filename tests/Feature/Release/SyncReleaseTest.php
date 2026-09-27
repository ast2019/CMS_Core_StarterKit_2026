<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Models\SystemInfo;
use App\Models\User;
use Database\Seeders\InstallSeeder;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\artisan;

/**
 * RULES #1 and #2 on an install that already exists.
 *
 * The gap these cover was only visible in production: `cms:release` writes the version
 * and the changelog row into the database it runs in (a developer's), and the deployed
 * install imported CHANGELOG.md once, at install. After that, SystemInfo::current() found
 * its existing row and never looked at the file again — the live panel showed "0.6.0"
 * with "no changes recorded" for as long as the install lived.
 */
beforeEach(function (): void {
    $this->changelogPath = storage_path('app/sync-release-test-'.Str::random(8).'.md');

    config()->set('cms.changelog_path', $this->changelogPath);

    file_put_contents($this->changelogPath, <<<'MARKDOWN'
        # Changelog

        ## [0.8.0] - 2026-10-02

        ### Added

        - The newest release.

        ## [0.7.0] - 2026-10-01

        ### Fixed

        - The one before it.
        MARKDOWN);
});

afterEach(function (): void {
    @unlink($this->changelogPath);
});

it('imports releases into an install that already has a version row', function (): void {
    SystemInfo::query()->create(['version' => '0.6.0', 'installed_at' => now()->subMonth()]);

    artisan('cms:sync-release')
        ->expectsOutputToContain('imported 2 release(s)')
        ->assertSuccessful();

    expect(Changelog::query()->pluck('version')->sort()->values()->all())->toBe(['0.7.0', '0.8.0']);
});

it('raises the version to the newest release in CHANGELOG.md', function (): void {
    SystemInfo::query()->create(['version' => '0.6.0', 'installed_at' => now()->subMonth()]);

    artisan('cms:sync-release')
        ->expectsOutputToContain('0.6.0 → 0.8.0')
        ->assertSuccessful();

    expect(SystemInfo::query()->sole()->version)->toBe('0.8.0');
});

it('never lowers a version that is ahead of the file', function (): void {
    /*
     * A release cut locally but not yet committed, or an image rolled back to an older
     * tag. Lowering the version would make the panel claim the data was written by an
     * older release than it was.
     */
    SystemInfo::query()->create(['version' => '1.0.0', 'installed_at' => now()]);

    artisan('cms:sync-release')->assertSuccessful();

    expect(SystemInfo::query()->sole()->version)->toBe('1.0.0');
});

it('compares versions numerically, not as strings', function (): void {
    // "0.10.0" sorts before "0.8.0" as a string; a string comparison would downgrade it.
    SystemInfo::query()->create(['version' => '0.10.0', 'installed_at' => now()]);

    artisan('cms:sync-release')->assertSuccessful();

    expect(SystemInfo::query()->sole()->version)->toBe('0.10.0');
});

it('creates the version row when there is none', function (): void {
    artisan('cms:sync-release')
        ->expectsOutputToContain('created at 0.8.0')
        ->assertSuccessful();

    expect(SystemInfo::query()->sole()->version)->toBe('0.8.0');
});

it('changes nothing when run a second time', function (): void {
    // It runs at every container start, so the second run is the normal case.
    SystemInfo::query()->create(['version' => '0.6.0', 'installed_at' => now()]);

    artisan('cms:sync-release')->assertSuccessful();

    $before = [
        SystemInfo::query()->count(),
        SystemInfo::query()->sole()->version,
        Changelog::query()->count(),
    ];

    artisan('cms:sync-release')
        ->expectsOutputToContain('already up to date')
        ->expectsOutputToContain('unchanged')
        ->assertSuccessful();

    expect([
        SystemInfo::query()->count(),
        SystemInfo::query()->sole()->version,
        Changelog::query()->count(),
    ])->toBe($before);
});

it('leaves a release recorded in this database alone', function (): void {
    // Overwriting would discard the released_by of a release genuinely cut here.
    $user = User::factory()->create();
    Changelog::query()->create([
        'version' => '0.8.0',
        'entries' => ['added' => ['Cut on this machine.']],
        'released_at' => now(),
        'released_by' => $user->id,
    ]);

    artisan('cms:sync-release')->assertSuccessful();

    $row = Changelog::query()->where('version', '0.8.0')->sole();

    expect($row->released_by)->toBe($user->id)
        ->and($row->entries)->toBe(['added' => ['Cut on this machine.']]);
});

it('warns and succeeds when the tables do not exist yet', function (): void {
    /*
     * A deployment with migrations disabled starts before its schema exists. The
     * entrypoint runs this on every start, so a failure here would be a restart loop.
     */
    Schema::drop('changelogs');
    Schema::drop('system_info');

    artisan('cms:sync-release')
        ->expectsOutputToContain('run the migrations first')
        ->assertSuccessful();
});

it('is what the install seeder runs, so install and upgrade cannot diverge', function (): void {
    SystemInfo::query()->create(['version' => '0.6.0', 'installed_at' => now()]);

    $this->seed(InstallSeeder::class);

    expect(SystemInfo::query()->sole()->version)->toBe('0.8.0')
        ->and(Changelog::query()->count())->toBe(2);
});

it('reads the newest release as the highest version, not the first heading', function (): void {
    file_put_contents($this->changelogPath, <<<'MARKDOWN'
        # Changelog

        ## [Unreleased]

        ## [0.9.0] - 2026-10-01

        ## [0.10.0] - 2026-10-05

        ## [0.11.0] - 2026-10-06 (yanked)
        MARKDOWN);

    // The (yanked) heading is not a release to the importer either, so the version is
    // never raised to a release with no changelogs row.
    expect(SystemInfo::releasedVersion())->toBe('0.10.0');

    @unlink($this->changelogPath);

    expect(SystemInfo::releasedVersion())->toBeNull()
        ->and(SystemInfo::initialVersion())->toBe(SystemInfo::INITIAL_VERSION);
});

it('runs at container start, after the migrations', function (): void {
    /*
     * The fix is only a fix if deployments run it. The base image runs migrations in
     * 50-laravel-automations, so the script must sort after it, and the web stage must
     * copy it in the way it copies 15-validate-app-key.sh.
     */
    $script = projectPath('docker/entrypoint.d/60-cms-sync-release.sh');

    expect(file_exists($script))->toBeTrue()
        ->and((string) file_get_contents($script))->toStartWith("#!/bin/sh\n")
        ->toContain('cms:sync-release')
        ->and((string) file_get_contents(projectPath('Dockerfile')))
        ->toContain('COPY docker/entrypoint.d/60-cms-sync-release.sh /etc/entrypoint.d/60-cms-sync-release.sh');
});
