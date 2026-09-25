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

    public static function bootHasSlug(): void
    {
        static::saving(function (self $model): void {
            $model->fillMissingSlugs();
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

    /*
     * There is deliberately no slugChanges() / captureOriginalSlugs() here any more.
     *
     * The trait used to snapshot the loaded slugs on `retrieved` and diff them after
     * save, which was a second implementation of the rule that now lives in
     * RedirectSuggestionService::pendingFor(). That one is strictly better — it also
     * skips a locale already covered by an existing redirect, so repeated saves stop
     * raising the same suggestion — and nothing called the trait's version. Keeping
     * both meant one rule in two places with only one of them maintained.
     *
     * The caller (EditContent::mutateFormDataBeforeSave) captures the pre-save slugs
     * itself and hands them to the service, so the baseline is explicit at the call
     * site rather than hidden in a model event.
     */

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
