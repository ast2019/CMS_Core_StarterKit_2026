<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Makes a resource findable from the panel's global search, with translated columns.
 *
 * The search box at the top of the panel found NOTHING: no resource declared
 * `getGloballySearchableAttributes()`, so the field rendered and returned no results
 * for any query — worse than not offering it, because an editor tries it, gets
 * nothing, and concludes the article is missing.
 *
 * WHY A TRAIT IS NEEDED AT ALL.
 *
 * Every searchable column here is a Spatie-translatable JSON map —
 * `{"fa":"…","en":"…"}` — and Filament's default builds `where("title", 'like',
 * "%term%")` against the whole column. That matches the serialised document rather
 * than the text, which fails in both directions: searching `en` matches EVERY record
 * (the key is in every document), and a Persian search can hit an English translation
 * the editor is not looking at.
 *
 * WHAT THIS DOES, AND WHY IT IS THIS SMALL.
 *
 * It rewrites `title` to `title->fa` and hands the work back to Filament. That is the
 * whole change. An earlier version of this trait replaced the plural
 * `applyGlobalSearchAttributeConstraints()` and rebuilt the WHERE group by hand through
 * Spatie's `whereJsonContainsLocale`, which silently gave up three behaviours living in
 * the replaced method:
 *
 *   - MULTI-WORD SEARCH. Filament splits a term on whitespace and requires every word,
 *     so "Parliament election results" is found by "election Parliament". Rebuilding the
 *     group made the term one exact phrase, so two remembered words in the wrong order
 *     found nothing — the same failure this item set out to fix.
 *   - CASE-INSENSITIVITY ON THE PRODUCTION DATABASES. Filament wraps a JSON path in
 *     `lower()` for MySQL/MariaDB and lowercases for Postgres, precisely because JSON
 *     extraction there is case-sensitive. A bare LIKE loses it, and SQLite — which the
 *     test suite runs on — is case-insensitive for ASCII, so no test could have caught
 *     it.
 *   - RELATIONSHIP ATTRIBUTES. `author.name` is routed through `whereHas` by the
 *     default; a hand-built `where('author.name', …)` is invalid SQL.
 *
 * Filament's own column helper already understands the `->` JSON arrow — it is how the
 * framework searches JSON columns in tables — so delegating keeps all three and leaves
 * this trait responsible for one decision: which attributes are per-locale.
 */
trait SearchesTranslatedRecords
{
    /**
     * Point a translatable attribute at the current locale, then let Filament build it.
     *
     * @param  array<string>  $searchAttributes
     */
    protected static function applyGlobalSearchAttributeConstraint(Builder $query, string $search, array $searchAttributes, bool &$isFirst): Builder
    {
        $locale = app()->getLocale();
        $translatable = static::globallySearchableTranslatableAttributes($query->getModel());

        foreach ($searchAttributes as $attribute) {
            /*
             * `title` becomes `title->fa`. A dotted relationship attribute is left alone:
             * the translatable list describes THIS model's columns, and the related
             * model's own resource is what knows whether its column is per-locale.
             */
            $column = (! str_contains($attribute, '.') && in_array($attribute, $translatable, true))
                ? "{$attribute}->{$locale}"
                : $attribute;

            parent::applyGlobalSearchAttributeConstraint($query, $search, [$column], $isFirst);
        }

        return $query;
    }

    /**
     * The result heading: the first searchable attribute, in the panel's locale.
     *
     * Defined once here rather than per resource. Filament's default reads
     * `$recordTitleAttribute` straight off the model, which for a translatable column
     * returns the whole JSON map and renders `{"fa":"…"}` as the heading.
     */
    public static function getGlobalSearchResultTitle(Model $record): string
    {
        $attribute = Arr::first(Arr::flatten(static::getGloballySearchableAttributes()));

        if (! is_string($attribute)) {
            return (string) $record->getKey();
        }

        if (in_array($attribute, static::globallySearchableTranslatableAttributes($record), true)) {
            /** @var string $value */
            $value = $record->getTranslation($attribute, app()->getLocale(), useFallbackLocale: true);

            return $value;
        }

        return (string) $record->getAttribute($attribute);
    }

    /**
     * Which of this model's attributes are translatable.
     *
     * Read from the model so a column becoming translatable — or stopping — cannot
     * leave the search behind. A model without Spatie's trait answers with none, which
     * is what makes this trait safe on a plain resource.
     *
     * @return list<string>
     */
    protected static function globallySearchableTranslatableAttributes(Model $model): array
    {
        if (! method_exists($model, 'getTranslatableAttributes')) {
            return [];
        }

        /** @var list<string> */
        return $model->getTranslatableAttributes();
    }
}
