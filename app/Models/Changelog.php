<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RULE #1 — CHANGELOG: "`changelogs` table + CHANGELOG.md, updated on every
 * release."
 *
 * Requirements 10.1, 10.3.
 *
 * @property array<string, list<string>> $entries
 * @property string $version
 * @property Carbon $released_at
 */
class Changelog extends Model
{
    use HasFactory;

    /**
     * Keep-a-Changelog categories. Fixed so the table and CHANGELOG.md cannot
     * drift into different vocabularies.
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'added',
        'changed',
        'deprecated',
        'removed',
        'fixed',
        'security',
    ];

    protected $fillable = [
        'version',
        'entries',
        'released_at',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'entries' => 'array',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeRecent(Builder $query, int $limit = 5): void
    {
        $query->orderByDesc('released_at')->limit($limit);
    }

    /**
     * Render this release as a Keep-a-Changelog markdown section.
     *
     * Living on the model rather than in the release command means the admin
     * panel and CHANGELOG.md render from one implementation, so they cannot
     * disagree about what a release contained (RULE #1).
     */
    public function toMarkdown(): string
    {
        $lines = ['## ['.$this->version.'] - '.$this->released_at->format('Y-m-d'), ''];

        foreach (self::CATEGORIES as $category) {
            $items = $this->entries[$category] ?? [];

            if ($items === []) {
                continue;
            }

            $lines[] = '### '.ucfirst($category);
            $lines[] = '';

            foreach ($items as $item) {
                $lines[] = '- '.$item;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function entryCount(): int
    {
        return array_sum(array_map('count', $this->entries));
    }
}
