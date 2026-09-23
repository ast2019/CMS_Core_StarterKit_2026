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

    public const INITIAL_VERSION = '0.1.0';

    /**
     * The single row, created on first access.
     */
    public static function current(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'version' => self::INITIAL_VERSION,
            'installed_at' => now(),
        ]);
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
