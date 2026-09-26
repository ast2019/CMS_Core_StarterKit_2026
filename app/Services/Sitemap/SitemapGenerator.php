<?php

declare(strict_types=1);

namespace App\Services\Sitemap;

use App\Contracts\TracksTranslationStatus;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\TranslationState;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\UrlBuilder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapIndex;
use Spatie\Sitemap\Tags\Sitemap as SitemapTag;
use Spatie\Sitemap\Tags\Url;

/**
 * The sitemap suite: a per-locale index plus dedicated Image and Video sitemaps.
 *
 * Requirements 5.6, 7.2, 7.4. Blueprint §6.
 *
 * Two things shape every method here.
 *
 * Decision D-5: a record only appears in a locale's sitemap when that locale's
 * translation is `reviewed` or `outdated`. Submitting unreviewed machine output
 * invites a quality assessment across the whole locale, which is a site-wide cost
 * for a per-record shortcut.
 *
 * Requirement 7.2: each URL carries reciprocal xhtml:link alternates. Google
 * discards an hreflang cluster whose members do not all point at each other, so
 * emitting them per-URL in the sitemap is the cheapest way to keep the cluster
 * complete without the frontend having to render them.
 *
 * ---------------------------------------------------------------------------
 * Memory, and why every walk is chunked
 * ---------------------------------------------------------------------------
 * A sitemap is the one response whose cost grows with the WHOLE archive rather
 * than with a page of it, so it is the one place a `->get()` is a liability rather
 * than a convenience. Three shapes of waste were here:
 *
 *  - images() and videos() each called eligibleArticles($locale) INSIDE the locale
 *    loop, so each method re-ran a full `Content::query()->live()->with(...)->get()`
 *    once per locale — three complete loads each, six across the two methods, of
 *    identical rows. The locale loop is now INSIDE the record walk: rows are read
 *    once and offered to every locale while they are resident.
 *  - forLocale() called every factory with an unbounded `->get()`.
 *  - both loaded whole tables into memory at once.
 *
 * Everything now goes through chunkById() at `cms.sitemap.chunk`. chunkById()
 * rather than chunk(): the candidate set is read-only here so OFFSET paging would
 * be correct, but keyset paging on the primary key is what keeps it correct if a
 * publish lands mid-generation — with OFFSET, a row leaving the live set shifts
 * every later page and silently drops a URL from the sitemap.
 */
class SitemapGenerator
{
    /**
     * Chunked walkers for the indexable content types.
     *
     * Each value takes a handler and feeds it successive batches of records. It is a
     * WALKER rather than a query object for a static-analysis reason the previous
     * `callable(): list<Model>` shape already had to solve: PHP generics are
     * invariant, so Collection<int, Content> is not accepted where
     * Collection<int, Model> is expected, and a `$modelClass::query()` built from a
     * dynamic class-string resolves to Builder<Model>, which hides `live()` and the
     * mediaAssets relation from static analysis. Arrays, unlike Collections, ARE
     * covariant in PHPStan, so a `list<Content>` batch satisfies a
     * `list<Model>` handler and each walker can build its own concrete typed query.
     *
     * Types whose MODULE is switched off are filtered out (Requirement 1.1).
     * config/cms.php promises that a disabled module "contributes no sitemap entries",
     * and until now this class never consulted the toggles — so a site with the gallery
     * module off still submitted every gallery URL to Search Console, where each one
     * resolves to a 404 on the frontend. UrlBuilder::isPubliclyRoutable() answers the
     * same question for navigation, which is why it is asked here rather than
     * re-deriving the module map.
     *
     * @return array<class-string<Model>, callable(callable(list<Model>): void): void>
     */
    private function indexableQueries(): array
    {
        return array_filter(
            $this->allIndexableQueries(),
            fn (string $modelClass): bool => $this->urls->isPubliclyRoutable($modelClass),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Every indexable type, before module filtering.
     *
     * @return array<class-string<Model>, callable(callable(list<Model>): void): void>
     */
    private function allIndexableQueries(): array
    {
        return [
            Content::class => function (callable $handle): void {
                Content::query()
                    ->live()
                    ->with(['translationStates', 'mediaAssets'])
                    ->chunkById($this->chunkSize(), function (Collection $records) use ($handle): void {
                        /** @var Collection<int, Content> $records */
                        $handle($records->all());
                    });
            },

            Page::class => function (callable $handle): void {
                Page::query()
                    ->live()
                    ->with(['translationStates', 'mediaAssets'])
                    ->chunkById($this->chunkSize(), function (Collection $records) use ($handle): void {
                        /** @var Collection<int, Page> $records */
                        $handle($records->all());
                    });
            },

            Gallery::class => function (callable $handle): void {
                Gallery::query()
                    ->live()
                    ->with(['translationStates', 'mediaAssets'])
                    ->chunkById($this->chunkSize(), function (Collection $records) use ($handle): void {
                        /** @var Collection<int, Gallery> $records */
                        $handle($records->all());
                    });
            },

            // Categories have no publish workflow: a category exists or it does not.
            Category::class => function (callable $handle): void {
                Category::query()
                    ->with(['translationStates'])
                    ->chunkById($this->chunkSize(), function (Collection $records) use ($handle): void {
                        /** @var Collection<int, Category> $records */
                        $handle($records->all());
                    });
            },
        ];
    }

    public function __construct(
        private readonly UrlBuilder $urls,
        private readonly HreflangBuilder $hreflang,
    ) {}

    /**
     * The sitemap index, listing one sitemap per locale plus images and videos.
     *
     * Each entry carries a REAL lastmod. Spatie's Sitemap tag defaults
     * `lastModificationDate` to Carbon::now() in its constructor, so the index was
     * already emitting a lastmod — the fetch time, identical on every entry, on every
     * request. That is worse than omitting it: lastmod is how a crawler decides what
     * to re-fetch, and an index that claims all five sitemaps changed this second
     * teaches Google to ignore the field for this site entirely, at which point the
     * whole archive is re-crawled on the crawler's own schedule.
     */
    public function index(): SitemapIndex
    {
        $index = SitemapIndex::create();

        foreach ($this->locales() as $locale) {
            $index->add(
                SitemapTag::create("/sitemap-{$locale}.xml")
                    ->setLastModificationDate($this->localeSitemapLastModified($locale)),
            );
        }

        $mediaLastModified = $this->mediaSitemapLastModified();

        $index->add(
            SitemapTag::create('/sitemap-images.xml')
                ->setLastModificationDate($mediaLastModified),
        );
        $index->add(
            SitemapTag::create('/sitemap-videos.xml')
                ->setLastModificationDate($mediaLastModified),
        );

        return $index;
    }

    /**
     * Standard sitemap for one locale.
     */
    public function forLocale(string $locale): Sitemap
    {
        $sitemap = Sitemap::create();

        /*
         * The locale home page, but only when no Page record owns it.
         *
         * A designated homepage (Page::SYSTEM_HOME) IS /{locale} — UrlBuilder::pathFor()
         * returns the locale root for it rather than /{locale}/{slug}, so the walk below
         * contributes that URL itself, with a real lastmod and the reciprocal hreflang
         * alternates Requirement 7.2 wants. Spatie's Sitemap de-duplicates by URL at
         * render time and keeps the FIRST occurrence, so adding this synthetic entry
         * unconditionally would shadow the richer one and quietly strip the homepage's
         * hreflang cluster.
         *
         * The fallback still matters: when there is no homepage record, or when the one
         * there is is unpublished or has no reviewed translation for this locale
         * (Decision D-5), /{locale} must still be listed. It is the site's entry point,
         * and a locale sitemap that omits its own root is a crawl dead end.
         */
        if (! $this->homePageCoversLocaleRoot($locale)) {
            $sitemap->add(
                Url::create($this->urls->localeHome($locale))
                    ->setPriority(1.0)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY)
                    /*
                     * A synthetic entry still needs a lastmod, or the one URL every
                     * crawler starts from is the one with nothing to say about its
                     * freshness. It stands in for the whole locale, so the locale's own
                     * high-water mark is the honest answer.
                     */
                    ->setLastModificationDate($this->localeSitemapLastModified($locale)),
            );
        }

        foreach ($this->indexableQueries() as $modelClass => $walk) {
            $walk(function (array $records) use ($sitemap, $modelClass, $locale): void {
                foreach ($this->filterEligible($records, $locale) as $record) {
                    $url = $this->urls->canonicalFor($record, $locale);

                    if ($url === null) {
                        continue;
                    }

                    $entry = Url::create($url)
                        ->setLastModificationDate($this->lastModifiedFor($record, $locale))
                        ->setPriority($this->priorityFor($modelClass))
                        ->setChangeFrequency($this->changeFrequencyFor($modelClass));

                    $this->addAlternates($entry, $record);

                    $sitemap->add($entry);
                }
            });
        }

        return $sitemap;
    }

    /**
     * Dedicated Image sitemap (blueprint §6).
     *
     * Images are attached to the PAGE that uses them, not listed standalone: Google's
     * image sitemap format associates each image with the URL it appears on, and an
     * image with no host page cannot be indexed.
     */
    public function images(): Sitemap
    {
        $sitemap = Sitemap::create();
        $source = $this->sourceLocale();

        $this->eachEligibleArticle(function (Content $content, string $locale) use ($sitemap, $source): void {
            $url = $this->urls->canonicalFor($content, $locale);

            if ($url === null) {
                return;
            }

            $images = $content->mediaAssets
                ->filter(fn (MediaAsset $asset): bool => $asset->isImage());

            if ($images->isEmpty()) {
                return;
            }

            $entry = Url::create($url)
                ->setLastModificationDate($this->lastModifiedFor($content, $locale));

            foreach ($images as $asset) {
                $media = $asset->getFirstMedia('file');

                if ($media === null) {
                    continue;
                }

                /*
                 * Alt text becomes the caption/title. Requirement 2.7 makes it
                 * mandatory per locale, which is what lets an image sitemap carry
                 * meaningful captions rather than filenames — and it falls back to
                 * the source locale rather than emitting an empty string.
                 */
                $caption = $asset->altTextFor($locale)
                    ?: $asset->altTextFor($source);

                $entry->addImage($media->getFullUrl(), $caption);
            }

            $sitemap->add($entry);
        });

        return $sitemap;
    }

    /**
     * Dedicated Video sitemap (blueprint §6).
     *
     * Decision D-6: an entry needs a thumbnail plus either a content or player
     * location. The Video tag THROWS without one of the locations, so assets that
     * cannot form a valid entry are skipped rather than allowed to abort the whole
     * sitemap — one misconfigured video must not take the file down with it.
     */
    public function videos(): Sitemap
    {
        $sitemap = Sitemap::create();
        $source = $this->sourceLocale();

        $this->eachEligibleArticle(function (Content $content, string $locale) use ($sitemap, $source): void {
            $url = $this->urls->canonicalFor($content, $locale);

            if ($url === null) {
                return;
            }

            $videos = $content->mediaAssets
                ->filter(fn (MediaAsset $asset): bool => $asset->isVideo()
                    && $asset->hasVideoSitemapMetadata());

            if ($videos->isEmpty()) {
                return;
            }

            $entry = Url::create($url)
                ->setLastModificationDate($this->lastModifiedFor($content, $locale));
            $added = 0;

            foreach ($videos as $asset) {
                $thumbnail = $asset->getFirstMedia('video_thumbnail')?->getFullUrl();
                $contentLoc = $asset->getFirstMedia('file')?->getFullUrl();
                $playerLoc = filled($asset->external_embed_url)
                    ? (string) $asset->external_embed_url
                    : null;

                // Thumbnail is required by Google; one of the two locations is
                // required by the tag itself.
                if ($thumbnail === null || ($contentLoc === null && $playerLoc === null)) {
                    continue;
                }

                $title = $asset->altTextFor($locale) ?: $asset->altTextFor($source);

                if ($title === '') {
                    continue;
                }

                $description = $asset->getTranslation('caption', $locale, useFallbackLocale: true);

                $options = [];

                // Duration is recommended, not required (Decision D-6), so it is
                // added only when known.
                if ($asset->duration_seconds !== null) {
                    $options['duration'] = $asset->duration_seconds;
                }

                $entry->addVideo(
                    thumbnailLoc: $thumbnail,
                    title: $title,
                    description: filled($description) ? (string) $description : $title,
                    contentLoc: $contentLoc,
                    playerLoc: $playerLoc,
                    options: $options,
                );

                $added++;
            }

            if ($added > 0) {
                $sitemap->add($entry);
            }
        });

        return $sitemap;
    }

    /**
     * Visit every (live article, eligible locale) pair exactly once, in batches.
     *
     * The locale loop is INSIDE the record walk on purpose, and that inversion is the
     * whole fix. Both media sitemaps used to loop locales on the outside and call
     * eligibleArticles($locale) within, which re-read the entire live article table
     * once per locale — three full loads per method, six in total, of identical rows.
     * Reading a batch once and offering it to each locale while it is still resident
     * costs one pass over the table for the same output.
     *
     * URL ORDER changes as a result: entries now group by record and then by locale
     * rather than by locale and then by record. Nothing depends on that. Sitemap order
     * carries no meaning to a crawler, and Spatie's URL de-duplication keeps the FIRST
     * occurrence of a URL — which is unaffected here because every entry is keyed by a
     * (record, locale) pair and is therefore already unique.
     *
     * Contributes nothing when the content module is off (Requirement 1.1): the image
     * and video sitemaps hang images off the article URL that shows them, so with
     * articles unreachable there is no host page for any of those images to be indexed
     * against.
     *
     * @param  callable(Content, string): void  $handle
     */
    private function eachEligibleArticle(callable $handle): void
    {
        if (! $this->urls->isPubliclyRoutable(Content::class)) {
            return;
        }

        $locales = $this->locales();

        Content::query()
            ->live()
            ->with(['translationStates', 'mediaAssets'])
            ->chunkById($this->chunkSize(), function (Collection $articles) use ($locales, $handle): void {
                /** @var Collection<int, Content> $articles */
                foreach ($articles as $article) {
                    foreach ($locales as $locale) {
                        if ($this->isEligible($article, $locale)) {
                            $handle($article, $locale);
                        }
                    }
                }
            });
    }

    /**
     * Keep only records eligible for a locale (Decision D-5).
     *
     * Takes and returns a plain list rather than a Collection, for the same variance
     * reason as indexableQueries(): Collection<int, Content> is not a subtype of
     * Collection<int, Model>.
     *
     * @param  list<Model>  $records
     * @return list<Model>
     */
    public function filterEligible(array $records, string $locale): array
    {
        return array_values(array_filter(
            $records,
            fn (Model $record): bool => $this->isEligible($record, $locale),
        ));
    }

    /**
     * Whether a designated homepage already contributes /{locale} to this sitemap.
     *
     * Three conditions, all necessary: a homepage exists, it is live (an unpublished
     * homepage contributes nothing), and it is eligible for this locale under Decision
     * D-5 — so `sitemap-en.xml` falls back to the synthetic root entry until the English
     * homepage has been reviewed, rather than losing its root altogether.
     */
    private function homePageCoversLocaleRoot(string $locale): bool
    {
        if (! (bool) config('cms.modules.page', true)) {
            return false;
        }

        $home = Page::homePage();

        return $home !== null
            && $home->isLive()
            && $this->isEligible($home, $locale);
    }

    /**
     * Live articles eligible for a locale, with media loaded.
     *
     * The single-locale form, kept for callers that genuinely want one locale's list
     * (and for the module-toggle assertions). The sitemaps themselves go through
     * eachEligibleArticle(), which walks the table once for ALL locales instead of
     * once per locale — see its docblock.
     *
     * Typed to Content specifically because the image and video sitemaps read the
     * mediaAssets relation, which only exists on media-bearing models.
     *
     * Returns nothing when the content module is off (Requirement 1.1).
     *
     * @return Collection<int, Content>
     */
    public function eligibleArticles(string $locale): Collection
    {
        if (! $this->urls->isPubliclyRoutable(Content::class)) {
            /** @var Collection<int, Content> */
            return new Collection;
        }

        return Content::query()
            ->live()
            ->with(['translationStates', 'mediaAssets'])
            ->get()
            ->filter(fn (Content $content): bool => $this->isEligible($content, $locale));
    }

    /**
     * Decision D-5 in one place.
     */
    public function isEligible(Model $record, string $locale): bool
    {
        // Must have a URL in this locale at all.
        if ($this->urls->pathFor($record, $locale) === null) {
            return false;
        }

        // The source locale is the content itself, never a translation.
        if ($locale === $this->sourceLocale()) {
            return true;
        }

        if (! $record instanceof TracksTranslationStatus) {
            return true;
        }

        return $record->isSitemapEligibleFor($locale);
    }

    /**
     * When this record's page in this locale last changed.
     *
     * The record's own `updated_at` is only half the answer for a translated URL. A
     * translator signing off the English text does not touch the article row — it
     * writes the TranslationState — yet /en/... is exactly the URL whose content just
     * became authoritative, and under Decision D-5 the sign-off is what put it in this
     * sitemap in the first place. Reporting the article's timestamp there would tell a
     * crawler the page had not changed since the Persian edit that preceded the review.
     *
     * Read from the already-eager-loaded relation, so this costs no query: every walk
     * in this class loads `translationStates`, because isEligible() needs it anyway.
     */
    private function lastModifiedFor(Model $record, string $locale): CarbonInterface
    {
        // getAttribute() rather than ->updated_at: this parameter is a bare Model, and
        // `updated_at` is a timestamp column rather than a declared property, so reading
        // it as one is invisible to static analysis.
        $timestamps = [$record->getAttribute('updated_at')];

        if ($record instanceof TracksTranslationStatus && $locale !== $this->sourceLocale()) {
            $timestamps[] = $record->translationStateFor($locale)?->getAttribute('updated_at');
        }

        return $this->latest($timestamps) ?? now();
    }

    /**
     * High-water mark for a whole locale sitemap, for the index entry.
     *
     * Aggregates rather than rows: MAX(updated_at) per enabled type plus MAX over this
     * locale's translation states. Five cheap index-backed aggregates answer "has
     * anything in this locale's sitemap changed?" without loading a single record,
     * which matters because the index is fetched before the sitemaps themselves and
     * must not cost more than they do.
     *
     * The translation-state term is what makes the number correct rather than
     * approximate: a review or an AI run changes which records this locale's sitemap
     * CONTAINS (Decision D-5) while touching no content row at all.
     */
    private function localeSitemapLastModified(string $locale): CarbonInterface
    {
        $timestamps = [];

        foreach (array_keys($this->indexableQueries()) as $modelClass) {
            $timestamps[] = $this->maxUpdatedAt((new $modelClass)->getTable());
        }

        if ($locale !== $this->sourceLocale()) {
            /** @var string|null $reviewed */
            $reviewed = DB::table((new TranslationState)->getTable())
                ->where('locale', $locale)
                ->max('updated_at');

            $timestamps[] = $reviewed;
        }

        return $this->latest($timestamps) ?? now();
    }

    /**
     * High-water mark for the image and video sitemaps.
     *
     * Three terms, because three independent things change those documents: the
     * article (its URL or publish state), the asset (its alt text, which becomes the
     * caption, or its video metadata), and the ATTACHMENT — adding an existing library
     * asset to an article writes only a pivot row, touching neither end, so without
     * this term the image sitemap's lastmod would not move when an article gained a
     * picture.
     */
    private function mediaSitemapLastModified(): CarbonInterface
    {
        return $this->latest([
            $this->maxUpdatedAt((new Content)->getTable()),
            $this->maxUpdatedAt((new MediaAsset)->getTable()),
            $this->maxUpdatedAt('media_attachments'),
        ]) ?? now();
    }

    private function maxUpdatedAt(string $table): ?string
    {
        /** @var string|null $max */
        $max = DB::table($table)->max('updated_at');

        return $max;
    }

    /**
     * The newest of a mixed bag of timestamps, ignoring the ones that are absent.
     *
     * Deliberately typed loosely, because the callers genuinely differ: the aggregates
     * come back as raw driver values (SQLite and MySQL both hand back a string), the
     * model attributes come back as Carbon instances, and a model with no timestamps at
     * all hands back null. Normalising here rather than at four call sites is what keeps
     * a missing timestamp from silently becoming "now" in one of them.
     *
     * @param  list<mixed>  $timestamps
     */
    private function latest(array $timestamps): ?CarbonInterface
    {
        $newest = null;

        foreach ($timestamps as $timestamp) {
            $candidate = match (true) {
                $timestamp instanceof CarbonInterface => $timestamp,
                is_string($timestamp) && $timestamp !== '' => Carbon::parse($timestamp),
                default => null,
            };

            if ($candidate === null) {
                continue;
            }

            if ($newest === null || $candidate->greaterThan($newest)) {
                $newest = $candidate;
            }
        }

        return $newest;
    }

    /**
     * Reciprocal xhtml:link alternates (Requirement 7.2).
     */
    private function addAlternates(Url $entry, Model $record): void
    {
        foreach ($this->hreflang->for($record) as $hreflang => $alternateUrl) {
            $entry->addAlternate($alternateUrl, $hreflang);
        }
    }

    private function priorityFor(string $modelClass): float
    {
        return match ($modelClass) {
            Content::class => 0.8,
            Category::class => 0.6,
            Gallery::class => 0.6,
            Page::class => 0.5,
            default => 0.5,
        };
    }

    private function changeFrequencyFor(string $modelClass): string
    {
        return match ($modelClass) {
            Content::class => Url::CHANGE_FREQUENCY_WEEKLY,
            Category::class => Url::CHANGE_FREQUENCY_DAILY,
            default => Url::CHANGE_FREQUENCY_MONTHLY,
        };
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('cms.sitemap.chunk', 500));
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return (array) config('cms.locales.supported', ['fa']);
    }

    private function sourceLocale(): string
    {
        return (string) config('cms.locales.source', 'fa');
    }
}
