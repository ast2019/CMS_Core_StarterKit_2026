<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PageResource;
use App\Http\Resources\V1\SlideResource;
use App\Models\ContactSetting;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Slide;
use App\Services\Api\DeliveryCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Site chrome: slides, navigation, settings and contact details.
 *
 * Requirements 3.1, 7.6, 8.3, 8.4.
 */
class SiteController extends Controller
{
    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * Active homepage slides, ordered, capped at the configured maximum.
     */
    public function slides(Request $request): AnonymousResourceCollection
    {
        $locale = $this->locale($request);

        return $this->cache->remember(
            $this->cache->key('site.slides', $locale),
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_MEDIA],
            function (): AnonymousResourceCollection {
                $slides = Slide::query()
                    ->active()
                    ->with('mediaAssets')
                    /*
                     * Requirements 3.4/3.5 cap active slides at 5, enforced in
                     * validation and the policy. The limit is applied here as well:
                     * if data ever gets past those (a direct SQL insert, a bad
                     * import), the public payload must still honour the performance
                     * contract rather than shipping twelve hero images.
                     */
                    ->limit(Slide::maxSlides())
                    ->get();

                return SlideResource::collection($slides);
            },
        );
    }

    /**
     * A named navigation menu, resolved for the locale as a nested tree.
     */
    public function menu(Request $request, string $key = 'header'): JsonResponse
    {
        $locale = $this->locale($request);

        $tree = $this->cache->remember(
            $this->cache->key('site.menu', $locale, ['key' => $key]),
            [DeliveryCache::TAG_NAVIGATION, DeliveryCache::TAG_CONTENT],
            function () use ($key, $locale): array {
                $items = MenuItem::query()
                    ->inMenu($key)
                    ->topLevel()
                    ->with(['children.linkable', 'linkable'])
                    ->get();

                return $items
                    ->map(fn (MenuItem $item): array => $this->menuItem($item, $locale))
                    // An item whose target is missing or unpublished resolves to
                    // null; dropping it here means the frontend never renders a link
                    // into a 404.
                    ->filter(fn (array $item): bool => $item['url'] !== null || $item['children'] !== [])
                    ->values()
                    ->all();
            },
        );

        return new JsonResponse([
            'data' => $tree,
            'meta' => ['locale' => $locale, 'menu' => $key],
        ]);
    }

    /**
     * Public site settings.
     */
    public function settings(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        $payload = $this->cache->remember(
            $this->cache->key('site.settings', $locale),
            [DeliveryCache::TAG_SETTINGS],
            function () use ($locale): array {
                $siteName = Setting::get(Setting::SITE_NAME);

                return [
                    'site_name' => is_array($siteName)
                        ? ($siteName[$locale] ?? $siteName[config('cms.locales.source')] ?? null)
                        : $siteName,
                    'social_links' => Setting::get(Setting::SOCIAL_LINKS, []),

                    /*
                     * Analytics and verification tokens are emitted for the FRONTEND
                     * to render. They are deliberately never loaded in the admin
                     * panel, which would breach RULE #4's no-external-request
                     * guarantee for the backoffice.
                     */
                    'analytics' => [
                        'ga_measurement_id' => Setting::get(Setting::GA_MEASUREMENT_ID),
                        'gtm_container_id' => Setting::get(Setting::GTM_CONTAINER_ID),
                    ],
                    'verification' => [
                        'google' => Setting::get(Setting::GSC_VERIFICATION),
                        'bing' => Setting::get(Setting::BING_VERIFICATION),
                    ],

                    'locales' => [
                        'supported' => config('cms.locales.supported'),
                        'source' => config('cms.locales.source'),
                        'rtl' => config('cms.locales.rtl'),
                    ],
                ];
            },
        );

        return new JsonResponse(['data' => $payload, 'meta' => ['locale' => $locale]]);
    }

    /**
     * Contact details and form labels.
     */
    public function contact(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        $payload = $this->cache->remember(
            $this->cache->key('site.contact', $locale),
            [DeliveryCache::TAG_SETTINGS],
            function () use ($locale): array {
                $contact = ContactSetting::current();

                return [
                    'address' => $contact->getTranslation('address', $locale, true),
                    'office_hours' => $contact->getTranslation('office_hours', $locale, true),
                    'form_labels' => $contact->getTranslation('form_labels', $locale, true),
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'map' => $contact->hasGeoCoordinates() ? [
                        'latitude' => $contact->map_latitude,
                        'longitude' => $contact->map_longitude,
                    ] : null,
                ];
            },
        );

        return new JsonResponse(['data' => $payload, 'meta' => ['locale' => $locale]]);
    }

    /**
     * The brandable 404 page (Requirement 3.8).
     */
    public function notFoundPage(Request $request): JsonResponse
    {
        $page = Page::notFoundPage();

        if ($page === null) {
            /*
             * A missing 404 page must not turn a 404 into a 500, so this reports the
             * absence with a 404 of its own and lets the frontend fall back to its
             * own unbranded message.
             */
            return new JsonResponse(['message' => 'No 404 page is configured.'], 404);
        }

        return new JsonResponse([
            'data' => PageResource::make($page->load('mediaAssets'))->toArray($request),
            'meta' => ['locale' => $this->locale($request)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuItem(MenuItem $item, string $locale): array
    {
        return [
            'id' => $item->id,
            'label' => $item->getTranslation('label', $locale, true),
            'url' => $item->resolveUrl($locale),
            'opens_in_new_tab' => $item->opens_in_new_tab,
            'children' => $item->children
                ->map(fn (MenuItem $child): array => $this->menuItem($child, $locale))
                ->filter(fn (array $child): bool => $child['url'] !== null)
                ->values()
                ->all(),
        ];
    }

    private function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) ? $locale : app()->getLocale();
    }
}
