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
     *
     * INCLUDING RECORDS IN THE TRASH, and that is the whole point of this method not being a
     * one-line query.
     *
     * A soft-deleted row still occupies its slug: the MySQL unique index sits on a generated
     * column extracting the JSON path (see the per-locale slug migration) and knows nothing
     * about `deleted_at`. So checking `static::query()`, which the default scope narrows to
     * live rows, produced the worst possible split — the application cheerfully accepted a
     * slug and the database then rejected the INSERT with an integrity error naming a record
     * the editor cannot see and has no way to act on.
     *
     * Worse for the automatic side: makeUniqueSlug() loops on this answer, so it would settle
     * on the first "free" candidate and hand it to a write that could not succeed.
     *
     * Note this deliberately does NOT hide the collision. Silently suffixing to `-2` because
     * something in the trash holds `-1` is the right outcome: the trashed record may be
     * restored, and two records cannot share a URL segment in one locale. Page::systemKey
     * uniqueness already worked this way (Page::otherPageWithSystemKey), for the same reason.
     */
    public function slugExistsForLocale(string $slug, string $locale): bool
    {
        /** @var Builder<static> $query */
        $query = static::slugUniquenessQuery()->where(
            fn (Builder $inner) => $inner->whereJsonContainsLocale('slug', $locale, $slug),
        );

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        return $query->exists();
    }

    /**
     * The query slug uniqueness is judged against — the trash included.
     *
     * Unconditional `withTrashed()`, which rests on an invariant: every model using HasSlug also
     * uses SoftDeletes. That holds for all five (Content, Page, Gallery, Category, Tag) and is
     * not a coincidence — a record worth giving a public URL is a record worth being able to
     * recover, and the per-locale unique index makes the two inseparable anyway, since a trashed
     * row keeps occupying its slug.
     *
     * An earlier version probed `method_exists(static::class, 'bootSoftDeletes')` to cope with a
     * hypothetical slugged model that had no trash. Static analysis pointed out the branch can
     * never be taken, which was fair: dead code carrying a reassuring comment. The invariant is
     * asserted in tests/Architecture/SoftDeletesAreCoherentTest.php instead, so a future slugged
     * model without SoftDeletes fails a test that explains the problem rather than a runtime
     * call to a method it does not have.
     *
     * @return Builder<static>
     */
    protected static function slugUniquenessQuery(): Builder
    {
        /** @var Builder<static> */
        return static::withTrashed();
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
