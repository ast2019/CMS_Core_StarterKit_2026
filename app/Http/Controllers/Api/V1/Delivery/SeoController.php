<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Contracts\HasSeoMetadata;
use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use App\Services\Api\DeliveryCache;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\SchemaBuilder;
use App\Services\Seo\SocialTagBuilder;
use App\Services\Seo\UrlBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-locale SEO payload for anything with a public URL: meta, canonical, hreflang,
 * social cards and JSON-LD.
 *
 * Requirements 7.1, 7.2, 7.3.
 *
 * Delivered as data rather than rendered HTML because the frontend is a separate
 * deployment (blueprint §1) and the Core cannot know its templating. The JSON-LD
 * arrives as a ready `@graph` so the frontend only has to serialise it into a script
 * tag — leaving schema assembly to each client site would mean re-implementing
 * Requirement 7.3 per project, which is exactly what a reusable Core should prevent.
 *
 * WHY THIS COVERS FIVE THINGS AND NOT ONE:
 *
 * It served articles alone, which made the paragraph above true only of articles. A
 * frontend rendering the homepage, an "About us" page, a category archive or a gallery
 * got three meta strings at most — no canonical, no hreflang, no Open Graph, no
 * JSON-LD — so it had to build them itself from the record payload. That is the
 * per-project re-implementation this endpoint exists to prevent, and on a site where
 * the homepage and the section archives carry the most links, it was missing from the
 * pages that matter most.
 *
 * The four SEO services were already model-generic — UrlBuilder::canonicalFor(),
 * HreflangBuilder::for() and SocialTagBuilder take a Model or an intersection type, and
 * every HasSeoMetadata getter works on all four models. Only the JSON-LD needed new
 * builders, because an Article is genuinely not a WebPage: see SchemaBuilder::forPage(),
 * forCategory(), forGallery() and forHome().
 *
 * Each method gates its own module (Requirement 1.1), so a site with galleries switched
 * off answers 404 here exactly as the gallery endpoint does, and each resolves a slug
 * through the same source-locale fallback as its sibling `show()` route — an untranslated
 * record must not be reachable through one door and absent through the other.
 */
class SeoController extends Controller
{
    use ResolvesDeliveryRequest;

    public function __construct(
        private readonly SchemaBuilder $schema,
        private readonly HreflangBuilder $hreflang,
        private readonly UrlBuilder $urls,
        private readonly SocialTagBuilder $social,
        private readonly DeliveryCache $cache,
    ) {}

    public function forArticle(Request $request, string $slug): JsonResponse
    {
        $this->ensureModuleEnabled('content');

        $locale = $this->locale($request);

        return $this->respond($locale, $this->cachedPayload(
            'seo.article',
            $slug,
            $locale,
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SETTINGS, DeliveryCache::TAG_MEDIA],
            function () use ($slug, $locale): array {
                $content = $this->findOrFail(
                    fn () => Content::query()
                        ->live()
                        ->with(['author:id,name', 'primaryCategory', 'mediaAssets', 'translationStates']),
                    $locale,
                    $slug,
                    'article',
                );

                /** @var Content $content */
                return $this->payload($content, $locale, $this->schema->forArticle($content, $locale));
            },
        ));
    }

    public function forPage(Request $request, string $slug): JsonResponse
    {
        $this->ensureModuleEnabled('page');

        $locale = $this->locale($request);

        return $this->respond($locale, $this->cachedPayload(
            'seo.page',
            $slug,
            $locale,
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SETTINGS, DeliveryCache::TAG_MEDIA],
            function () use ($slug, $locale): array {
                $page = $this->findOrFail(
                    fn () => Page::query()->live()->with(['mediaAssets', 'translationStates']),
                    $locale,
                    $slug,
                    'page',
                );

                /** @var Page $page */
                return $this->payload($page, $locale, $this->schema->forPage($page, $locale));
            },
        ));
    }

    public function forCategory(Request $request, string $slug): JsonResponse
    {
        $this->ensureModuleEnabled('category');

        $locale = $this->locale($request);

        return $this->respond($locale, $this->cachedPayload(
            'seo.category',
            $slug,
            $locale,
            [DeliveryCache::TAG_TAXONOMY, DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SETTINGS],
            function () use ($slug, $locale): array {
                $category = $this->findOrFail(
                    // `parent` eager-loaded because the breadcrumb walks the ancestry.
                    fn () => Category::query()->with(['parent', 'translationStates']),
                    $locale,
                    $slug,
                    'category',
                );

                /** @var Category $category */
                return $this->payload($category, $locale, $this->schema->forCategory($category, $locale));
            },
        ));
    }

    public function forGallery(Request $request, string $slug): JsonResponse
    {
        $this->ensureModuleEnabled('gallery');

        $locale = $this->locale($request);

        return $this->respond($locale, $this->cachedPayload(
            'seo.gallery',
            $slug,
            $locale,
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SETTINGS, DeliveryCache::TAG_MEDIA],
            function () use ($slug, $locale): array {
                $gallery = $this->findOrFail(
                    fn () => Gallery::query()->live()->with(['items', 'mediaAssets', 'translationStates']),
                    $locale,
                    $slug,
                    'gallery',
                );

                /** @var Gallery $gallery */
                return $this->payload($gallery, $locale, $this->schema->forGallery($gallery, $locale));
            },
        ));
    }

    /**
     * The homepage's SEO payload.
     *
     * Separate from forPage() because the homepage is addressed by ROLE rather than by
     * slug — a frontend rendering `/fa` has no slug to ask with, which is the same
     * reason GET /api/v1/home-page exists. The graph is the WebSite one either way:
     * SchemaBuilder::forPage() delegates for the page holding the homepage role, so both
     * doors describe the site identically.
     */
    public function forHome(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled('page');

        $locale = $this->locale($request);

        $payload = $this->cache->remember(
            $this->cache->key('seo.home', $locale),
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SETTINGS, DeliveryCache::TAG_MEDIA],
            function () use ($locale): array {
                $home = Page::homePage();

                /*
                 * No designated homepage is a legitimate configuration — the per-client
                 * checklist calls it optional — so this 404s rather than inventing a
                 * payload, exactly as GET /api/v1/home-page does.
                 */
                if ($home === null || ! $home->isPubliclyVisible()) {
                    throw new NotFoundHttpException('No homepage is designated on this site.');
                }

                return $this->payload($home, $locale, $this->schema->forHome($locale));
            },
        );

        /** @var array<string, mixed> $payload */
        return $this->respond($locale, $payload);
    }

    /**
     * The payload for one record, in one locale.
     *
     * One shape for all five endpoints, deliberately: a frontend should not need a
     * different reader per content type to put a title in a tag. Keys that cannot apply
     * degrade rather than disappear — a Category has no Open Graph image, so `og:image`
     * is absent from the map while the map itself is still there, and
     * `seo_analysis.checks` is a shorter list for a record with no prose rather than a
     * missing key.
     *
     * @param  list<array<string, mixed>>  $graph
     * @return array{
     *     canonical: string|null,
     *     meta: array{title: string, description: string, robots: string},
     *     open_graph: array<string, mixed>,
     *     twitter: array<string, mixed>,
     *     hreflang: array<string, string>,
     *     json_ld: array<string, mixed>,
     *     seo_warnings: list<string>,
     *     seo_analysis: array{
     *         keyphrase: string,
     *         score: int|null,
     *         band: string|null,
     *         checks: list<array{id: string, status: string, value: string|null}>
     *     }
     * }
     */
    private function payload(Model&HasSeoMetadata $record, string $locale, array $graph): array
    {
        return [
            'canonical' => $this->urls->canonicalFor($record, $locale),
            'meta' => [
                'title' => $record->metaTitleFor($locale),
                'description' => $record->metaDescriptionFor($locale),
                'robots' => $record->robotsMetaFor($locale),
            ],
            'open_graph' => $this->social->openGraph($record, $locale),
            'twitter' => $this->social->twitter($record, $locale),
            'hreflang' => $this->hreflang->for($record),
            // A @graph rather than separate keys: one script tag, and search engines
            // resolve cross-references between the nodes.
            'json_ld' => [
                '@context' => 'https://schema.org',
                '@graph' => $graph,
            ],
            'seo_warnings' => $record->seoWarningsFor($locale),
            /*
             * Served for the same reason the warnings are: this Core is headless, so
             * anything wanting to show an editor — or a content audit, or a CI check on a
             * build — how a page scores has no other way to ask. Computed by the model,
             * so it is the same number the panel displays rather than a second
             * implementation that can disagree with it.
             */
            'seo_analysis' => $record->seoAnalysisFor($locale),
        ];
    }

    /**
     * Resolve a cached payload, keeping the declared shape.
     *
     * Split out because DeliveryCache::remember() returns `mixed` — deliberately, since
     * it cannot thread Cache::remember()'s template through — so with the cache call
     * inline the generated spec described `data` as an untyped object. That was a
     * RULE #3 hole rather than a cosmetic one: adding a key produced no diff in
     * docs/openapi.json at all, so the in-sync gate could not have caught the change.
     *
     * @param  list<string>  $tags
     * @param  callable(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function cachedPayload(string $key, string $slug, string $locale, array $tags, callable $build): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $this->cache->key($key, $locale, ['slug' => $slug]),
            $tags,
            $build,
        );

        return $payload;
    }

    /**
     * Find a record by slug with the source-locale fallback, or 404.
     *
     * Templated to match resolveBySlug(), so each caller gets its own model type back
     * rather than a bare Model that then needs asserting at the call site.
     *
     * @template TModel of Model
     *
     * @param  \Closure(): Builder<TModel>  $query
     * @return TModel
     */
    private function findOrFail(\Closure $query, string $locale, string $slug, string $label): Model
    {
        $record = $this->resolveBySlug($query, $locale, $slug);

        if ($record === null) {
            throw new NotFoundHttpException("No published {$label} found for slug [{$slug}].");
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function respond(string $locale, array $payload): JsonResponse
    {
        return new JsonResponse(['data' => $payload, 'meta' => ['locale' => $locale]]);
    }
}
