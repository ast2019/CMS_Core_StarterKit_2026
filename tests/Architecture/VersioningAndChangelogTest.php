<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Widgets\VersionWidget;
use App\Models\Changelog;
use App\Models\SystemInfo;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\artisan;

/*
 * Every release test writes to a TEMPORARY changelog. Pointing them at the real
 * CHANGELOG.md appended fake entries and duplicate versions to a tracked file on
 * every run, which is trivially easy to commit by mistake.
 */
beforeEach(function (): void {
    $this->changelogPath = sys_get_temp_dir().'/cms-changelog-'.bin2hex(random_bytes(6)).'.md';

    config()->set('cms.changelog_path', $this->changelogPath);
});

afterEach(function (): void {
    if (isset($this->changelogPath) && file_exists($this->changelogPath)) {
        unlink($this->changelogPath);
    }
});

/**
 * RULE #1 — CHANGELOG: "`changelogs` table + CHANGELOG.md, updated on every release."
 * RULE #2 — VERSIONING: "Semantic Versioning in `system_info`, shown in admin panel."
 *
 * Blueprint §12.1-12.2, Requirements 10.1-10.3.
 *
 * The blueprint is explicit that these are enforced features rather than
 * documentation, so what matters is that the THREE sources cannot drift apart: the
 * table, the file and the version shown in the panel.
 */
it('stores a semantic version in system_info', function (): void {
    expect(SystemInfo::version())->toMatch('/^\d+\.\d+\.\d+$/');
});

it('shows the version in the admin panel', function (): void {
    // RULE #2's second half. A version stored but never displayed is not "shown in
    // admin panel", and it is the first thing anyone needs when reporting a problem.
    $widgets = Filament::getPanel('admin')->getWidgets();

    expect($widgets)->toContain(VersionWidget::class);
});

it('renders the version widget for every role', function (): void {
    // Not admin-only: an editor who cannot see the version cannot tell support which
    // release they are on.
    foreach (['admin', 'editor', 'author', 'viewer'] as $role) {
        $user = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($user);

        Livewire\Livewire::test(VersionWidget::class)
            ->assertOk()
            ->assertSee(SystemInfo::version());
    }
});

it('bumps the version, records a changelog row and writes CHANGELOG.md together', function (): void {
    /*
     * One command doing all three is the whole point of RULE #1: three manual steps is
     * three chances to do two of them, and the one that gets skipped is always the file
     * nobody reads until they need it.
     */
    $before = SystemInfo::version();

    artisan('cms:release', [
        'type' => 'minor',
        '--added' => ['A new capability'],
    ])->assertSuccessful();

    $after = SystemInfo::version();

    expect($after)->not->toBe($before);

    $changelog = Changelog::query()->where('version', $after)->first();

    expect($changelog)->not->toBeNull()
        ->and($changelog->entries['added'])->toContain('A new capability');

    $file = (string) file_get_contents($this->changelogPath);

    expect($file)->toContain("## [{$after}]")
        ->and($file)->toContain('A new capability');
});

it('bumps each version component correctly', function (): void {
    $info = SystemInfo::current();
    $info->update(['version' => '1.2.3']);

    expect($info->nextVersion('patch'))->toBe('1.2.4')
        ->and($info->nextVersion('minor'))->toBe('1.3.0')
        // A major bump resets minor and patch; carrying them over is the classic
        // SemVer mistake.
        ->and($info->nextVersion('major'))->toBe('2.0.0');
});

it('refuses a release with no entries', function (): void {
    /*
     * An entry-less release produces an empty CHANGELOG.md section and a row
     * describing nothing, which is worse than no entry: it reads as though the release
     * genuinely changed nothing.
     */
    $before = SystemInfo::version();

    artisan('cms:release', ['type' => 'patch'])->assertFailed();

    expect(SystemInfo::version())->toBe($before);
});

it('refuses an invalid release type', function (): void {
    artisan('cms:release', ['type' => 'huge', '--added' => ['x']])->assertFailed();
});

it('changes nothing on a dry run', function (): void {
    $before = SystemInfo::version();
    $countBefore = Changelog::query()->count();

    artisan('cms:release', [
        'type' => 'major',
        '--added' => ['Would be released'],
        '--dry-run' => true,
    ])->assertSuccessful();

    expect(SystemInfo::version())->toBe($before)
        ->and(Changelog::query()->count())->toBe($countBefore);
});

it('keeps the newest release at the top of CHANGELOG.md', function (): void {
    // Keep a Changelog puts newest first, and a reader opening the file wants the
    // latest release without scrolling.
    artisan('cms:release', ['type' => 'patch', '--added' => ['First entry']])->assertSuccessful();
    $first = SystemInfo::version();

    artisan('cms:release', ['type' => 'patch', '--added' => ['Second entry']])->assertSuccessful();
    $second = SystemInfo::version();

    $file = (string) file_get_contents($this->changelogPath);

    expect(strpos($file, "## [{$second}]"))->toBeLessThan(strpos($file, "## [{$first}]"));

    // And the preamble stays at the top rather than being buried by each release.
    expect(strpos($file, '# Changelog'))->toBe(0);
});

it('never releases the same version twice', function (): void {
    artisan('cms:release', ['type' => 'patch', '--added' => ['One']])->assertSuccessful();

    $version = SystemInfo::version();

    // Force system_info back so the command would compute the same next version.
    $info = SystemInfo::current();
    $parts = explode('.', $version);
    $info->update(['version' => $parts[0].'.'.$parts[1].'.'.((int) $parts[2] - 1)]);

    artisan('cms:release', ['type' => 'patch', '--added' => ['Duplicate']])->assertFailed();
});

it('reports the same version in the API spec as in system_info', function (): void {
    /*
     * The spec's version and the panel's must not drift: a consumer reading the spec
     * needs to know which release it describes, and two different answers is worse
     * than none.
     */
    $spec = json_decode((string) file_get_contents(projectPath('docs/openapi.json')), true);

    expect($spec['info']['version'] ?? null)->toMatch('/^\d+\.\d+\.\d+$/');
});

it('ships a CHANGELOG.md in the repository', function (): void {
    // RULE #1 requires the file to exist, not merely to be writable on demand.
    expect(file_exists(projectPath('CHANGELOG.md')))->toBeTrue(
        'RULE #1 violated: CHANGELOG.md is missing.',
    );
});
