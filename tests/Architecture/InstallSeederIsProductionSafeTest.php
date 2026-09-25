<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SystemInfo;
use Database\Seeders\InstallSeeder;

/**
 * InstallSeeder must run in a production image.
 *
 * Production images are built with `composer install --no-dev`, which excludes
 * `fakerphp/faker`. Anything reaching a model factory or `fake()` therefore dies with
 * "Call to undefined function fake()" — after writing some rows and before writing
 * others, leaving a half-installed database.
 *
 * That is not hypothetical: the deployment guide told operators to run `db:seed --force`
 * on every deploy, and it failed every time in the production image, because
 * DatabaseSeeder generates demo content with factories. The split exists to fix it, and
 * this test exists so the install path cannot quietly acquire a dev dependency again.
 *
 * Checked by reading the source rather than by running it: the test suite always has dev
 * dependencies installed, so actually executing the seeder proves nothing about whether
 * it would work without them.
 */
it('reaches no factory or faker helper', function (): void {
    $source = stripComments(projectPath('database/seeders/InstallSeeder.php'));

    $forbidden = [
        '::factory(' => 'a model factory',
        'fake(' => 'the fake() helper',
        'faker' => 'Faker',
        'Factory::' => 'a factory class',
    ];

    foreach ($forbidden as $needle => $description) {
        expect(str_contains(strtolower($source), strtolower($needle)))->toBeFalse(
            "InstallSeeder references {$description}, which comes from a dev dependency. "
            .'A production image built with --no-dev will fail partway through the install. '
            .'Move anything needing test data into DatabaseSeeder.'
        );
    }
});

it('creates what a production site cannot work without', function (): void {
    /*
     * Asserts the outcome, not the implementation: a production install needs the version
     * row (RULES #1, #2), the settings singletons the panel edits, and the branded 404
     * page (Requirement 3.8) whose absence makes the error handler fall back to an
     * unbranded response.
     */
    $this->seed(InstallSeeder::class);

    expect(SystemInfo::query()->count())->toBe(1)
        ->and(Changelog::query()->count())->toBeGreaterThan(0)
        ->and(Setting::query()->count())->toBeGreaterThan(0)
        ->and(Page::notFoundPage())->not->toBeNull();
});

it('can be run twice without duplicating anything', function (): void {
    // Deployments re-run it after an upgrade, and a second run must be a no-op.
    $this->seed(InstallSeeder::class);

    $before = [
        SystemInfo::query()->count(),
        Changelog::query()->count(),
        Setting::query()->count(),
        Page::query()->count(),
    ];

    $this->seed(InstallSeeder::class);

    expect([
        SystemInfo::query()->count(),
        Changelog::query()->count(),
        Setting::query()->count(),
        Page::query()->count(),
    ])->toBe($before);
});
