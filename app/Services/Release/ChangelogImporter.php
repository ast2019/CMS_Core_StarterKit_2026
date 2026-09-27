<?php

declare(strict_types=1);

namespace App\Services\Release;

use App\Models\Changelog;
use App\Models\SystemInfo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Populate the `changelogs` table from CHANGELOG.md.
 *
 * RULE #1 asks for both a changelog file and a `changelogs` table, and `cms:release`
 * writes both — but only for releases cut in THAT database. A fresh deployment therefore
 * had a file listing five releases and a table containing none, which meant the panel's
 * About page showed no history at all and `cms:audit-rules` reported RULE #1 violated on
 * a perfectly correct install.
 *
 * Importing on install fixes both. The file is the source of truth here, because it is
 * what `cms:release` writes and what git carries between machines; the table is how the
 * panel renders it.
 *
 * Idempotent: existing versions are left alone, so running it twice — or after a release —
 * changes nothing.
 */
class ChangelogImporter
{
    /**
     * @return int the number of releases inserted
     */
    public function import(?string $path = null): int
    {
        $path ??= SystemInfo::changelogPath();

        if (! is_file($path)) {
            return 0;
        }

        $existing = Changelog::query()->pluck('version')->all();
        $imported = 0;

        foreach ($this->parse((string) file_get_contents($path)) as $release) {
            if (in_array($release['version'], $existing, true)) {
                continue;
            }

            try {
                Changelog::query()->create([
                    'version' => $release['version'],
                    'entries' => $release['entries'],
                    'released_at' => $release['released_at'],
                    // Nobody in this database cut the release; claiming a user did would be a
                    // false audit trail (RULE #8).
                    'released_by' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                /*
                 * Another process inserted the same release between the pluck above and
                 * this insert. `cms:sync-release` runs at every container start, so two
                 * web containers booting together (replicas, or an old and a new one
                 * overlapping during a rolling deploy) is an ordinary race,
                 * and the loser has nothing left to do for this row — failing would
                 * stop a container from starting over a row that is already correct.
                 */
                continue;
            }

            $imported++;
        }

        return $imported;
    }

    /**
     * Parse Keep a Changelog sections into releases.
     *
     * @return list<array{version: string, released_at: Carbon, entries: array<string, list<string>>}>
     */
    private function parse(string $markdown): array
    {
        // Split on release headings, keeping the heading with its body.
        $parts = preg_split(
            SystemInfo::RELEASE_HEADING,
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            return [];
        }

        $releases = [];

        // Element 0 is the file preamble; then repeating [version, date, body].
        for ($i = 1; $i + 2 < count($parts) + 1; $i += 3) {
            $version = $parts[$i] ?? null;

            if ($version === null) {
                break;
            }

            $date = $parts[$i + 1] ?? '';
            $body = $parts[$i + 2] ?? '';

            $entries = $this->parseEntries($body);

            if ($entries === []) {
                continue;
            }

            $releases[] = [
                'version' => $version,
                'released_at' => $date !== ''
                    ? Carbon::parse($date)
                    : Carbon::now(),
                'entries' => $entries,
            ];
        }

        return $releases;
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseEntries(string $body): array
    {
        $entries = [];
        $section = null;

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^###\s*(.+)$/', $line, $matches) === 1) {
                $candidate = strtolower(trim($matches[1]));

                // Only the Keep a Changelog categories the model knows about, so an
                // unexpected heading cannot create a junk key.
                $section = in_array($candidate, Changelog::CATEGORIES, true) ? $candidate : null;

                continue;
            }

            if ($section !== null && str_starts_with($line, '- ')) {
                $entries[$section][] = trim(substr($line, 2));
            }
        }

        return $entries;
    }
}
