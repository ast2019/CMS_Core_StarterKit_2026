<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Services\Api\DeliveryCache;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\SchemaBuilder;
use App\Services\Seo\SocialTagBuilder;
use App\Services\Seo\UrlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-locale SEO payload for one article: meta, canonical, hreflang, social cards
 * and JSON-LD.
 *
 * Requirements 7.1, 7.2, 7.3.
 *
 * Delivered as data rather than rendered HTML because the frontend is a separate
 * deployment (blueprint §1) and the Core cannot know its templating. The JSON-LD
 * arrives as a ready `@graph` so the frontend only has to serialise it into a script
 * tag — leaving schema assembly to each client site would mean re-implementing
 * Requirement 7.3 per project, which is exactly what a reusable Core should prevent.
 *
 * The social tags are built by SocialTagBuilder rather than here. They used to be a
 * private method on this controller, which is why there were no Twitter tags and no
 * per-record overrides: a private controller method is reachable from one endpoint and
 * is not somewhere a feature gets added.
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly SchemaBuilder $schema,
        private readonly HreflangBuilder $hreflang,
        private readonly UrlBuilder $urls,
        private readonly SocialTagBuilder $social,
        private readonly DeliveryCache $cache,
    ) {}

    public function forArticle(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locale($request);

        return new JsonResponse([
            'data' => $this->payloadFor($slug, $locale),
            'meta' => ['locale' => $locale],
        ]);
    }

    /**
     * The cached payload.
     *
     * Split out of forArticle() so the shape can be DECLARED. DeliveryCache::remember()
     * returns `mixed` — deliberately, because it cannot thread Cache::remember()'s own
     * template through — so with the cache call inline the generated spec described this
     * endpoint's `data` as an untyped object. That was a RULE #3 hole rather than a
     * cosmetic one: adding `twitter` and `seo_analysis` produced no diff in
     * docs/openapi.json at all, so the in-sync gate could not have caught the change.
     * A declared return type on a method the response builds from is what makes the
     * spec name the keys a frontend can rely on.
     *
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
    private function payloadFor(string $slug, string $locale): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->cache->remember(
            $this->cache->key('seo.article', $locale, ['slug' => $slug]),
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_SETTINGS, DeliveryCache::TAG_MEDIA],
            function () use ($slug, $locale): array {
                $content = Content::query()
                    ->live()
                    ->with(['author:id,name', 'primaryCategory', 'mediaAssets', 'translationStates'])
                    ->whereJsonContainsLocale('slug', $locale, $slug)
                    ->first();

                if ($content === null) {
                    throw new NotFoundHttpException("No published article found for slug [{$slug}].");
                }

                return [
                    'canonical' => $this->urls->canonicalFor($content, $locale),
                    'meta' => [
                        'title' => $content->metaTitleFor($locale),
                        'description' => $content->metaDescriptionFor($locale),
                        'robots' => $content->robotsMetaFor($locale),
                    ],
                    'open_graph' => $this->social->openGraph($content, $locale),
                    /*
                     * NEW in this payload. X/Twitter falls back to og:* when twitter:* is
                     * absent, so a frontend that ignores this key keeps rendering exactly
                     * as before; one that emits it gets the card type decided from the
                     * real image dimensions instead of left to the crawler.
                     */
                    'twitter' => $this->social->twitter($content, $locale),
                    'hreflang' => $this->hreflang->for($content),
                    // A @graph rather than separate keys: one script tag, and search
                    // engines resolve cross-references between the nodes.
                    'json_ld' => [
                        '@context' => 'https://schema.org',
                        '@graph' => $this->schema->forArticle($content, $locale),
                    ],
                    'seo_warnings' => $content->seoWarningsFor($locale),
                    /*
                     * Also new. The keyphrase analysis is served for the same reason the
                     * warnings already are: this Core is headless, so anything wanting to
                     * show an editor — or a content audit, or a CI check on a build — how
                     * a page scores has no other way to ask. Computed by the model, so it
                     * is the same number the panel displays rather than a second
                     * implementation that can disagree with it.
                     */
                    'seo_analysis' => $content->seoAnalysisFor($locale),
                ];
            },
        );

        /**
         * The cache hands back `mixed`, so the declared shape is asserted here rather
         * than pretended at the boundary. Everything that reaches this line was built by
         * the closure above or read from a cache entry it wrote.
         *
         * @var array{
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
         * } $payload
         */
        return $payload;
    }

    private function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) ? $locale : app()->getLocale();
    }
}
