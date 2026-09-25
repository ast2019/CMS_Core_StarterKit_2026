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
     * The single row, created on first access.
     */
    public static function current(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
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
        $path = (string) config('cms.changelog_path') ?: base_path('CHANGELOG.md');

        if (! is_file($path)) {
            return self::INITIAL_VERSION;
        }

        // Keep a Changelog puts the newest release first, so the first heading wins.
        $matched = preg_match(
            '/^##\s*\[(\d+\.\d+\.\d+)\]/m',
            (string) file_get_contents($path),
            $matches,
        );

        return $matched === 1 ? $matches[1] : self::INITIAL_VERSION;
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
