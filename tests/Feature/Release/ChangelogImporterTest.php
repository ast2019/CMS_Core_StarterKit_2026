<?php

declare(strict_types=1);

use App\Models\Changelog;
use App\Services\Release\ChangelogImporter;

/**
 * RULE #1 — the release history must be present in the database, not only in the file.
 *
 * These exist because of a gap that only appeared in a real deployment: `cms:release`
 * writes CHANGELOG.md and a `changelogs` row together, but only for releases cut in that
 * database. A fresh install therefore had a file listing five releases and a table with
 * none — so the panel's About page was blank and `cms:audit-rules` reported RULE #1
 * violated on a correct install. The test suite could not have caught it; nothing was
 * wrong with the code, only with what a new database contained.
 */
function writeChangelog(string $body): string
{
    $path = storage_path('app/changelog-test-'.Str::random(8).'.md');

    file_put_contents($path, $body);

    return $path;
}

afterEach(function (): void {
    foreach (glob(storage_path('app/changelog-test-*.md')) ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('imports every release with its entries and date', function (): void {
    $path = writeChangelog(<<<'MARKDOWN'
        # Changelog

        Preamble that must not be parsed as a release.

        ## [1.2.0] - 2026-04-05

        ### Added

        - A thing that was added.
        - A second thing.

        ### Fixed

        - A thing that was fixed.

        ## [1.1.0] - 2026-03-01

        ### Changed

        - Something changed.
        MARKDOWN);

    $imported = app(ChangelogImporter::class)->import($path);

    // Ordered explicitly: an unordered query has no guaranteed row order, and asserting
    // on it makes the test depend on the database engine rather than on the importer.
    expect($imported)->toBe(2)
        ->and(Changelog::query()->orderByDesc('released_at')->pluck('version')->all())
        ->toBe(['1.2.0', '1.1.0']);

    $latest = Changelog::query()->where('version', '1.2.0')->sole();

    expect($latest->entries)->toBe([
        'added' => ['A thing that was added.', 'A second thing.'],
        'fixed' => ['A thing that was fixed.'],
    ])->and($latest->released_at->toDateString())->toBe('2026-04-05');
});

it('does not duplicate or overwrite releases already recorded', function (): void {
    /*
     * Runs on every seed, and seeding is not guaranteed to happen once. A second run
     * inserting duplicates would break the unique index; one that overwrote rows would
     * discard the `released_by` of a release genuinely cut on this installation.
     */
    $path = writeChangelog(<<<'MARKDOWN'
        # Changelog

        ## [2.0.0] - 2026-05-05

        ### Added

        - From the file.
        MARKDOWN);

    app(ChangelogImporter::class)->import($path);
    $second = app(ChangelogImporter::class)->import($path);

    expect($second)->toBe(0)
        ->and(Changelog::query()->where('version', '2.0.0')->count())->toBe(1);
});

it('ignores headings that are not changelog categories', function (): void {
    // A junk key here would end up rendered in the panel's About page.
    $path = writeChangelog(<<<'MARKDOWN'
        # Changelog

        ## [3.0.0] - 2026-06-06

        ### Notes

        - Not a Keep a Changelog category.

        ### Security

        - A real category.
        MARKDOWN);

    app(ChangelogImporter::class)->import($path);

    expect(Changelog::query()->where('version', '3.0.0')->sole()->entries)
        ->toBe(['security' => ['A real category.']]);
});

it('survives a missing file rather than failing an install', function (): void {
    expect(app(ChangelogImporter::class)->import(storage_path('app/definitely-absent.md')))
        ->toBe(0);
});

it('records no releaser for an imported release', function (): void {
    // Attributing a release to a user who did not cut it would be a false audit trail.
    $path = writeChangelog(<<<'MARKDOWN'
        # Changelog

        ## [4.0.0] - 2026-07-07

        ### Added

        - Imported, not released here.
        MARKDOWN);

    app(ChangelogImporter::class)->import($path);

    expect(Changelog::query()->where('version', '4.0.0')->sole()->released_by)->toBeNull();
});
