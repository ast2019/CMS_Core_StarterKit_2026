<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Services\Content\SlugGenerator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-locale slugs.
 *
 * Decision D-1: the blueprint described `slug` as both "non-localizable" and
 * "(per locale)" — contradictory. Resolved as translatable, because a Persian
 * article and its English translation must not share a URL segment.
 *
 * Uniqueness is the hard part. MySQL cannot place a unique index inside a JSON
 * document, so each slugged table also carries a stored generated column per
 * locale (see the migrations) with a unique index on it. SQLite cannot express
 * that, so local dev and the SQLite test suite rely on the application-level
 * check in `slugExistsForLocale()`. Both layers run in production: the
 * application check produces a readable validation error, the index is the
 * backstop against a race between two concurrent saves.
 *
 * Requirements 7.1, 7.5.
 */
trait HasSlug
{
    use InteractsWithLocales;

    /**
     * Slug values as they were when the model was loaded, so a change can be
     * detected after save and offered as a 301.
     *
     * @var array<string, string|null>
     */
    protected array $originalSlugs = [];

    public static function bootHasSlug(): void
    {
        static::retrieved(function (self $model): void {
            $model->captureOriginalSlugs();
        });

        static::saving(function (self $model): void {
            $model->fillMissingSlugs();
        });

        static::created(function (self $model): void {
            $model->captureOriginalSlugs();
        });
    }

    /**
     * The translatable attribute a slug is derived from when left blank.
     */
    public function slugSourceAttribute(): string
    {
        return 'title';
    }

    /**
     * Generate a slug for any locale that has source text but no slug yet.
     *
     * Only *missing* slugs are filled. Regenerating an existing slug from a
     * retitled article would silently change a live URL, which is precisely the
     * event the redirect engine exists to handle deliberately rather than by
     * accident.
     */
    public function fillMissingSlugs(): void
    {
        $generator = app(SlugGenerator::class);
        $source = $this->slugSourceAttribute();

        foreach ($this->configuredLocales() as $locale) {
            $existing = $this->getTranslation('slug', $locale, useFallbackLocale: false);

            if (filled($existing)) {
                continue;
            }

            $sourceText = $this->getTranslation($source, $locale, useFallbackLocale: false);

            if (blank($sourceText)) {
                continue;
            }

            $this->setTranslation(
                'slug',
                $locale,
                $this->makeUniqueSlug($generator->generate((string) $sourceText, $locale), $locale),
            );
        }
    }

    /**
     * Append a numeric suffix until the slug is free in that locale.
     */
    public function makeUniqueSlug(string $slug, string $locale): string
    {
        if ($slug === '') {
            return '';
        }

        $candidate = $slug;
        $suffix = 1;

        while ($this->slugExistsForLocale($candidate, $locale)) {
            $suffix++;
            $candidate = "{$slug}-{$suffix}";
        }

        return $candidate;
    }

    /**
     * Whether another record of this type already uses the slug in a locale.
     */
    public function slugExistsForLocale(string $slug, string $locale): bool
    {
        /** @var Builder<static> $query */
        $query = static::query()->where(
            fn (Builder $inner) => $inner->whereJsonContainsLocale('slug', $locale, $slug),
        );

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        return $query->exists();
    }

    public function captureOriginalSlugs(): void
    {
        $this->originalSlugs = $this->getTranslations('slug');
    }

    /**
     * Locales whose slug changed in the last save.
     *
     * Consumed by the redirect engine to offer a 301 (Requirement 7.5). It
     * returns the data rather than creating redirects itself: a slug corrected
     * three times while drafting would otherwise leave two dead redirect hops
     * behind, so creating them stays an explicit, published-content-only action.
     *
     * @return array<string, array{from: string, to: string}>
     */
    public function slugChanges(): array
    {
        $changes = [];
        $current = $this->getTranslations('slug');

        foreach ($current as $locale => $new) {
            $old = $this->originalSlugs[$locale] ?? null;

            if (blank($old) || blank($new) || $old === $new) {
                continue;
            }

            $changes[$locale] = ['from' => (string) $old, 'to' => (string) $new];
        }

        return $changes;
    }

    /**
     * Resolve a record by slug within a locale, for Delivery API route binding.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereSlug(Builder $query, string $slug, string $locale): Builder
    {
        return $query->whereJsonContainsLocale('slug', $locale, $slug);
    }
}
