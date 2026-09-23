<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Services\Api\DeliveryCache;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\SchemaBuilder;
use App\Services\Seo\UrlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-locale SEO payload for one article: meta, canonical, hreflang and JSON-LD.
 *
 * Requirements 7.1, 7.2, 7.3.
 *
 * Delivered as data rather than rendered HTML because the frontend is a separate
 * deployment (blueprint §1) and the Core cannot know its templating. The JSON-LD
 * arrives as a ready `@graph` so the frontend only has to serialise it into a script
 * tag — leaving schema assembly to each client site would mean re-implementing
 * Requirement 7.3 per project, which is exactly what a reusable Core should prevent.
 */
class SeoController extends Controller
{
    public function __construct(
        private readonly SchemaBuilder $schema,
        private readonly HreflangBuilder $hreflang,
        private readonly UrlBuilder $urls,
        private readonly DeliveryCache $cache,
    ) {}

    public function forArticle(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locale($request);

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
                    'open_graph' => $this->openGraph($content, $locale),
                    'hreflang' => $this->hreflang->for($content),
                    // A @graph rather than separate keys: one script tag, and search
                    // engines resolve cross-references between the nodes.
                    'json_ld' => [
                        '@context' => 'https://schema.org',
                        '@graph' => $this->schema->forArticle($content, $locale),
                    ],
                    'seo_warnings' => $content->seoWarningsFor($locale),
                ];
            },
        );

        return new JsonResponse(['data' => $payload, 'meta' => ['locale' => $locale]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openGraph(Content $content, string $locale): array
    {
        // socialShareImage() falls back to the featured image, so a shared link never
        // renders without a preview just because a second field was left blank.
        $image = $content->socialShareImage();

        return [
            'og:type' => 'article',
            'og:title' => $content->metaTitleFor($locale),
            'og:description' => $content->metaDescriptionFor($locale),
            'og:url' => $this->urls->canonicalFor($content, $locale),
            'og:locale' => $locale,
            'og:image' => $image?->getFirstMedia('file')?->getFullUrl(),
            'og:image:alt' => $image?->altTextFor($locale),
            'article:published_time' => $content->publish_date?->toIso8601String(),
            'article:modified_time' => $content->updated_at?->toIso8601String(),
        ];
    }

    private function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) ? $locale : app()->getLocale();
    }
}
