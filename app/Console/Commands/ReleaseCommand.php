<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Changelog;
use App\Models\SystemInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RULES #1 and #2 — cut a release: bump the version, record the changelog entry,
 * update CHANGELOG.md.
 *
 * Requirements 10.1, 10.3.
 *
 * One command doing all three is the point. The blueprint asks for a `changelogs`
 * table AND a CHANGELOG.md AND a version in `system_info`; three manual steps is
 * three chances to do two of them, and the one that gets skipped is always the file
 * nobody reads until they need it.
 */
class ReleaseCommand extends Command
{
    protected $signature = 'cms:release
        {type : major, minor or patch}
        {--added=* : Entries for the Added section}
        {--changed=* : Entries for the Changed section}
        {--deprecated=* : Entries for the Deprecated section}
        {--removed=* : Entries for the Removed section}
        {--fixed=* : Entries for the Fixed section}
        {--security=* : Entries for the Security section}
        {--dry-run : Show what would happen without writing anything}';

    protected $description = 'Cut a release: bump the semantic version, record a changelog entry and update CHANGELOG.md.';

    public function handle(): int
    {
        $type = (string) $this->argument('type');

        if (! in_array($type, ['major', 'minor', 'patch'], true)) {
            $this->error("Release type must be major, minor or patch; got [{$type}].");

            return self::INVALID;
        }

        $entries = $this->collectEntries();

        if ($entries === []) {
            /*
             * A release with no entries would produce an empty CHANGELOG.md section and
             * a changelogs row describing nothing, which is worse than no entry at all:
             * it looks like the release genuinely changed nothing.
             */
            $this->error('A release needs at least one entry. Pass --added, --fixed and so on.');

            return self::INVALID;
        }

        $systemInfo = SystemInfo::current();
        $current = $systemInfo->version;
        $next = $systemInfo->nextVersion($type);

        if (Changelog::query()->where('version', $next)->exists()) {
            // The unique index would catch this, but a clear message beats a
            // constraint violation halfway through a release.
            $this->error("Version {$next} already exists in the changelog.");

            return self::FAILURE;
        }

        $this->line("Releasing <info>{$current}</info> → <info>{$next}</info>");

        foreach ($entries as $section => $items) {
            $this->line("  <comment>{$section}</comment>");

            foreach ($items as $item) {
                $this->line("    - {$item}");
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        /*
         * The database writes are one transaction so a failure cannot leave
         * system_info bumped without a matching changelog row — which would make the
         * panel advertise a version with no recorded contents.
         *
         * CHANGELOG.md is written AFTER the transaction commits. A file write cannot
         * participate in a rollback, so doing it inside would risk a committed file
         * describing a release the database rejected.
         */
        $changelog = DB::transaction(function () use ($systemInfo, $next, $entries): Changelog {
            /** @var Changelog $changelog */
            $changelog = Changelog::query()->create([
                'version' => $next,
                'entries' => $entries,
                'released_at' => now(),
                'released_by' => auth()->id(),
            ]);

            $systemInfo->update([
                'version' => $next,
                'last_migrated_at' => $systemInfo->last_migrated_at,
            ]);

            return $changelog;
        });

        $this->writeChangelogFile($changelog);

        $this->newLine();
        $this->info("Released {$next}.");
        $this->line('  system_info version bumped (RULE #2)');
        $this->line('  changelogs row created (RULE #1)');
        $this->line('  CHANGELOG.md updated (RULE #1)');
        $this->newLine();
        $this->comment('Regenerate the API spec if this release changed any route or response:');
        $this->comment('  php artisan scramble:export');

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<string>>
     */
    private function collectEntries(): array
    {
        $entries = [];

        foreach (Changelog::CATEGORIES as $category) {
            /** @var list<string> $items */
            $items = array_values(array_filter(
                (array) $this->option($category),
                fn (string $item): bool => trim($item) !== '',
            ));

            if ($items !== []) {
                $entries[$category] = $items;
            }
        }

        return $entries;
    }

    /**
     * Prepend the new release to CHANGELOG.md, creating the file if needed.
     *
     * Prepended rather than appended: Keep a Changelog puts newest first, and a reader
     * opening the file wants the latest release without scrolling.
     */
    private function writeChangelogFile(Changelog $changelog): void
    {
        $path = (string) config('cms.changelog_path', base_path('CHANGELOG.md'));
        $section = $changelog->toMarkdown();

        if (! file_exists($path)) {
            file_put_contents($path, $this->changelogHeader()."\n".$section);

            return;
        }

        $existing = (string) file_get_contents($path);

        /*
         * Split on the first release heading so the new section lands after the file's
         * preamble rather than above it. Without this, repeated releases would bury the
         * "what this file is" header further down each time.
         */
        $position = strpos($existing, "\n## ");

        if ($position === false) {
            file_put_contents($path, rtrim($existing)."\n\n".$section);

            return;
        }

        $header = substr($existing, 0, $position);
        $rest = substr($existing, $position + 1);

        file_put_contents($path, rtrim($header)."\n\n".$section."\n".$rest);
    }

    private function changelogHeader(): string
    {
        return <<<'MARKDOWN'
        # Changelog

        All notable changes to this project are documented here.

        The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
        and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

        Entries are written by `php artisan cms:release`, which also bumps the version in
        `system_info` and inserts a row into the `changelogs` table (RULES #1 and #2).
        Editing this file by hand will make the three sources disagree.

        MARKDOWN;
    }
}
