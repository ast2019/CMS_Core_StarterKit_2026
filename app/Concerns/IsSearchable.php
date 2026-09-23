<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\TipTap;
use Laravel\Scout\Searchable;

/**
 * Per-locale search indexing.
 *
 * Requirements 6.1, 6.2, 6.4. Decision D-7.
 *
 * ---------------------------------------------------------------------------
 * Why one index per locale
 * ---------------------------------------------------------------------------
 * Scout's default is a single index per model, which does not survive translatable
 * content. Persian, English and Arabic in one index share a stemmer, a stop-word
 * list and a relevance model, so a Persian query competes against English documents
 * and the scoring is meaningless for both. Splitting into `contents_fa`,
 * `contents_en` and `contents_ar` lets Meilisearch apply per-language settings.
 *
 * The cost is that `searchableAs()` becomes locale-dependent, so indexing has to
 * loop locales explicitly rather than relying on Scout's automatic single-index
 * sync. `searchableUsing()` and the observer below handle that.
 *
 * ---------------------------------------------------------------------------
 * Decision D-7: plain text, not TipTap JSON
 * ---------------------------------------------------------------------------
 * The body is a TipTap document (RULE #6). Handing that to the engine would index
 * its structural keys — "doc", "paragraph", "heading" — so every article would match
 * a search for "paragraph" and rank by how deeply nested it was.
 * TipTap::toPlainText() flattens it, including the prose inside custom blocks, which
 * would otherwise be invisible to search.
 */
trait IsSearchable
{
    use Searchable;

    /**
     * The locale currently being indexed.
     *
     * Scout calls searchableAs() without arguments, so the locale has to be carried
     * on the instance. Set by indexForLocale() and by the observer; defaults to the
     * application locale so an ad-hoc `Model::search()` still works.
     */
    protected ?string $indexingLocale = null;

    /**
     * Index name for the locale being indexed.
     */
    public function searchableAs(): string
    {
        $locale = $this->indexingLocale ?? app()->getLocale();

        return $this->getTable().'_'.$locale;
    }

    /**
     * Flattened, locale-specific document.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $locale = $this->indexingLocale ?? app()->getLocale();

        return [
            'id' => (string) $this->getKey(),
            'locale' => $locale,

            'title' => (string) $this->getTranslation('title', $locale, useFallbackLocale: false),
            'excerpt' => (string) $this->getTranslation('excerpt', $locale, useFallbackLocale: false),

            // Decision D-7.
            'body' => TipTap::toPlainText(
                $this->getTranslation('body', $locale, useFallbackLocale: false),
            ),

            // Requirement 6.1 — tag and category names are part of the searchable
            // payload, so "اقتصاد" finds articles filed under it even when the word
            // never appears in the text.
            'tags' => $this->relationLoaded('tags') || $this->exists
                ? $this->tags->map(fn ($tag): string => (string) $tag->getTranslation('name', $locale, useFallbackLocale: false))->filter()->values()->all()
                : [],

            'categories' => $this->relationLoaded('categories') || $this->exists
                ? $this->categories->map(fn ($category): string => (string) $category->getTranslation('name', $locale, useFallbackLocale: false))->filter()->values()->all()
                : [],

            // Sortable, and lets the endpoint exclude scheduled content without a
            // second database round trip per hit.
            'publish_timestamp' => $this->publish_date?->getTimestamp(),
        ];
    }

    /**
     * Whether this record should be in the index for the locale being written.
     *
     * Two conditions, and both matter:
     *
     *  - it must be LIVE. A draft in the search index is a content leak: the title and
     *    excerpt of unpublished work would be readable by anyone who guessed a query.
     *  - the locale must have real, reviewed content (Decision D-5). Indexing fallback
     *    Persian under the English index means an English search returns Persian
     *    results, which reads as a broken site rather than a missing translation.
     */
    public function shouldBeSearchable(): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        $locale = $this->indexingLocale ?? app()->getLocale();

        if ($locale === $this->sourceLocale()) {
            return true;
        }

        return $this->isSitemapEligibleFor($locale);
    }

    /**
     * Bind this instance to a locale for indexing.
     */
    public function forSearchLocale(string $locale): static
    {
        $this->indexingLocale = $locale;

        return $this;
    }

    public function currentSearchLocale(): ?string
    {
        return $this->indexingLocale;
    }

    /**
     * Sync this record across every configured locale index.
     *
     * Each locale is a separate index, so a single save has to write or remove the
     * document in all of them — and removal matters as much as writing: unpublishing
     * an article or letting its translation go stale must take it OUT, or search keeps
     * serving a result that 404s.
     */
    public function syncSearchIndexes(): void
    {
        foreach ((array) config('cms.locales.supported', ['fa']) as $locale) {
            $clone = $this->replicate();
            $clone->setRawAttributes($this->getAttributes(), sync: true);
            $clone->exists = true;
            $clone->setRelations($this->getRelations());
            $clone->forSearchLocale($locale);

            if ($clone->shouldBeSearchable()) {
                $clone->searchable();

                continue;
            }

            $clone->unsearchable();
        }
    }

    /**
     * Remove this record from every locale index.
     */
    public function removeFromSearchIndexes(): void
    {
        foreach ((array) config('cms.locales.supported', ['fa']) as $locale) {
            $clone = $this->replicate();
            $clone->setRawAttributes($this->getAttributes(), sync: true);
            $clone->exists = true;
            $clone->forSearchLocale($locale);
            $clone->unsearchable();
        }
    }
}
