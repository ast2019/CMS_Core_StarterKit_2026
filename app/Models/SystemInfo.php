<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * RULE #2 — VERSIONING: "Semantic Versioning in `system_info`, shown in admin
 * panel."
 *
 * Requirements 10.1, 10.2.
 *
 * @property string $version
 * @property Carbon|null $installed_at
 * @property Carbon|null $last_migrated_at
 */
class SystemInfo extends Model
{
    protected $table = 'system_info';

    protected $fillable = ['version', 'installed_at', 'last_migrated_at'];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'last_migrated_at' => 'datetime',
        ];
    }

    /**
     * Used only when the version cannot be read from CHANGELOG.md.
     */
    public const INITIAL_VERSION = '0.1.0';

    /**
     * A release heading in CHANGELOG.md: `## [1.2.3]`, optionally ` - YYYY-MM-DD`, and
     * nothing else on the line. Captures the version and the date.
     *
     * Shared by releasedVersion() and ChangelogImporter so the two cannot disagree about
     * which headings are releases. With a looser pattern here, a hand-edited
     * `## [0.9.0] (yanked)` — which the importer does not split on — would make
     * `cms:sync-release` raise the version to a release with no `changelogs` row.
     */
    public const RELEASE_HEADING = '/^##\s*\[(\d+\.\d+\.\d+)\](?:\s*-\s*(\d{4}-\d{2}-\d{2}))?\s*$/m';

    /**
     * The single row, created on first access.
     */
    public static function current(): self
    {
        /** @var self */
        // Ordered so every reader settles on the same row should a second one ever exist.
        return static::query()->orderBy('id')->firstOrCreate([], [
            'version' => self::initialVersion(),
            'installed_at' => now(),
        ]);
    }

    /**
     * The version a fresh installation starts at: whatever the deployed code's newest
     * release is.
     *
     * Read from CHANGELOG.md rather than hardcoded, because a hardcoded initial version
     * is simply untrue — a brand-new install of 0.5.0 is at 0.5.0, not at 0.1.0. That
     * discrepancy was not cosmetic: it made `cms:audit-rules` report RULES #1 and #3 as
     * violated on every fresh deployment, because the changelog and the published API
     * spec both named a release the database disagreed with. A gate that cries wolf on a
     * correct install is a gate that gets ignored.
     *
     * CHANGELOG.md is the right source because `cms:release` writes it, so it cannot fall
     * behind the code without the release command itself being bypassed.
     */
    public static function initialVersion(): string
    {
        return self::releasedVersion() ?? self::INITIAL_VERSION;
    }

    /**
     * The newest release named in CHANGELOG.md, or null when the file is absent or names
     * none.
     *
     * The ONE place that reads the deployed code's version from the file. Both a fresh
     * install (initialVersion()) and an upgrade (`cms:sync-release`) depend on it, and two
     * parsers would eventually disagree about which heading counts.
     *
     * Keep a Changelog puts the newest release first, but the highest version is taken
     * rather than the first heading: on a correctly written file they are the same, and
     * on a file where someone pasted a section in the wrong place, taking the first
     * heading could move an install BACKWARDS — which `cms:sync-release` must never do.
     * `[Unreleased]` and other non-SemVer headings are ignored.
     */
    public static function releasedVersion(?string $path = null): ?string
    {
        $path ??= self::changelogPath();

        if (! is_file($path)) {
            return null;
        }

        preg_match_all(self::RELEASE_HEADING, (string) file_get_contents($path), $matches);

        $newest = null;

        foreach ($matches[1] as $version) {
            if ($newest === null || version_compare($version, $newest, '>')) {
                $newest = $version;
            }
        }

        return $newest;
    }

    /**
     * Where CHANGELOG.md lives. Configurable so tests can point release code at a
     * temporary file instead of the tracked one.
     */
    public static function changelogPath(): string
    {
        return (string) config('cms.changelog_path') ?: base_path('CHANGELOG.md');
    }

    public static function version(): string
    {
        return static::current()->version;
    }

    /**
     * Parse the stored SemVer into its parts.
     *
     * @return array{major: int, minor: int, patch: int}
     */
    public function semver(): array
    {
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $this->version, $matches);

        return [
            'major' => (int) ($matches[1] ?? 0),
            'minor' => (int) ($matches[2] ?? 1),
            'patch' => (int) ($matches[3] ?? 0),
        ];
    }

    /**
     * Next version for a release type, without persisting it.
     */
    public function nextVersion(string $type): string
    {
        ['major' => $major, 'minor' => $minor, 'patch' => $patch] = $this->semver();

        return match ($type) {
            'major' => ($major + 1).'.0.0',
            'minor' => $major.'.'.($minor + 1).'.0',
            'patch' => $major.'.'.$minor.'.'.($patch + 1),
            default => $this->version,
        };
    }
}
