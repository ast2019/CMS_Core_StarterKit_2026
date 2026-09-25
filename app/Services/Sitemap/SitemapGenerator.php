<?php

declare(strict_types=1);

namespace App\Services\Sitemap;

use App\Contracts\TracksTranslationStatus;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\UrlBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapIndex;
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
 */
class SitemapGenerator
{
    /**
     * Query factories for the indexable content types.
     *
     * Closures returning CONCRETE typed queries rather than a list of class strings.
     * A `$modelClass::query()` built from a dynamic class-string resolves to
     * Builder<Model>, which hides `live()` and the mediaAssets relation from static
     * analysis — and suppressing that would also hide a genuine mistake such as
     * calling live() on a model with no publish workflow.
     *
     * Each factory returns a plain list rather than a Collection, because PHP generics
     * are invariant: Collection<int, Content> is not accepted where
     * Collection<int, Model> is expected, and every factory yields a different
     * concrete model type.
     *
     * Types whose MODULE is switched off are filtered out (Requirement 1.1).
     * config/cms.php promises that a disabled module "contributes no sitemap entries",
     * and until now this class never consulted the toggles — so a site with the gallery
     * module off still submitted every gallery URL to Search Console, where each one
     * resolves to a 404 on the frontend. UrlBuilder::isPubliclyRoutable() answers the
     * same question for navigation, which is why it is asked here rather than
     * re-deriving the module map.
     *
     * @return array<class-string<Model>, callable(): list<Model>>
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
     * @return array<class-string<Model>, callable(): list<Model>>
     */
    private function allIndexableQueries(): array
    {
        return [
            Content::class => fn (): array => Content::query()
                ->live()
                ->with(['translationStates', 'mediaAssets'])
                ->get()
                ->all(),

            Page::class => fn (): array => Page::query()
                ->live()
                ->with(['translationStates', 'mediaAssets'])
                ->get()
                ->all(),

            Gallery::class => fn (): array => Gallery::query()
                ->live()
                ->with(['translationStates', 'mediaAssets'])
                ->get()
                ->all(),

            // Categories have no publish workflow: a category exists or it does not.
            Category::class => fn (): array => Category::query()
                ->with(['translationStates'])
                ->get()
                ->all(),
        ];
    }

    public function __construct(
        private readonly UrlBuilder $urls,
        private readonly HreflangBuilder $hreflang,
    ) {}

    /**
     * The sitemap index, listing one sitemap per locale plus images and videos.
     */
    public function index(): SitemapIndex
    {
        $index = SitemapIndex::create();

        foreach ($this->locales() as $locale) {
            $index->add("/sitemap-{$locale}.xml");
        }

        $index->add('/sitemap-images.xml');
        $index->add('/sitemap-videos.xml');

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
         * returns the locale root for it rather than /{locale}/{slug}, so the loop below
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
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_DAILY),
            );
        }

        foreach ($this->indexableQueries() as $modelClass => $factory) {
            foreach ($this->filterEligible($factory(), $locale) as $record) {
                $url = $this->urls->canonicalFor($record, $locale);

                if ($url === null) {
                    continue;
                }

                $entry = Url::create($url)
                    ->setLastModificationDate($record->updated_at ?? now())
                    ->setPriority($this->priorityFor($modelClass))
                    ->setChangeFrequency($this->changeFrequencyFor($modelClass));

                $this->addAlternates($entry, $record);

                $sitemap->add($entry);
            }
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

        foreach ($this->locales() as $locale) {
            foreach ($this->eligibleArticles($locale) as $content) {
                $url = $this->urls->canonicalFor($content, $locale);

                if ($url === null) {
                    continue;
                }

                $images = $content->mediaAssets
                    ->filter(fn (MediaAsset $asset): bool => $asset->isImage());

                if ($images->isEmpty()) {
                    continue;
                }

                $entry = Url::create($url);

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
            }
        }

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

        foreach ($this->locales() as $locale) {
            foreach ($this->eligibleArticles($locale) as $content) {
                $url = $this->urls->canonicalFor($content, $locale);

                if ($url === null) {
                    continue;
                }

                $videos = $content->mediaAssets
                    ->filter(fn (MediaAsset $asset): bool => $asset->isVideo()
                        && $asset->hasVideoSitemapMetadata());

                if ($videos->isEmpty()) {
                    continue;
                }

                $entry = Url::create($url);
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
            }
        }

        return $sitemap;
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
     * Typed to Content specifically because the image and video sitemaps read the
     * mediaAssets relation, which only exists on media-bearing models.
     *
     * Returns nothing when the content module is off (Requirement 1.1): the image and
     * video sitemaps hang images off the article URL that shows them, so with articles
     * unreachable there is no host page for any of those images to be indexed against.
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
