<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TranslationStatus;
use App\Support\TranslationBacklog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Per-locale translation status for any translatable record.
 *
 * Named TranslationState rather than TranslationStatus to avoid colliding with
 * the enum of the same name.
 *
 * Requirements 5.3, 5.4, 5.6.
 *
 * @property TranslationStatus $status
 * @property string $locale
 * @property string|null $source_hash
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property Model|null $translatable
 */
class TranslationState extends Model
{
    protected static function booted(): void
    {
        /*
         * Item 38 — every change to a state can move the panel's two backlog badges, which are
         * cached because they render on every page. Forgotten here so the numbers are exact on the
         * next render. See App\Support\TranslationBacklog.
         */
        static::saved(fn () => TranslationBacklog::forget());
        static::deleted(fn () => TranslationBacklog::forget());
    }

    protected $fillable = [
        'locale',
        'status',
        'source_hash',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TranslationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Records eligible to appear in a locale's sitemap (Decision D-5).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSitemapEligible(Builder $query, string $locale): void
    {
        $query->where('locale', $locale)
            ->whereIn('status', (array) config('cms.translation.sitemap_eligible_statuses', []));
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeNeedingAttention(Builder $query): void
    {
        $query->whereIn('status', [
            TranslationStatus::NotTranslated,
            TranslationStatus::AiTranslated,
            TranslationStatus::Outdated,
        ]);
    }
}
