<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\MenuItemResource;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Site chrome: slides, navigation, settings and contact details.
 *
 * Requirements 3.1, 7.6, 8.3, 8.4.
 */
class SiteController extends Controller
{
    /*
     * The trait replaces a private locale() copy that predated it, and brings the
     * module gate the other Delivery controllers already use.
     */
    use ResolvesDeliveryRequest;

    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * Active homepage slides, ordered, capped at the configured maximum.
     */
    public function slides(Request $request): AnonymousResourceCollection
    {
        /*
         * Requirement 1.1, and before the cache lookup so switching the module off
         * takes the endpoint out of service immediately rather than at the end of the
         * TTL. Stage 1 flagged this endpoint as ungated: a site with no slideshow still
         * served its slides, which makes the toggle a lie in the one place a consumer
         * can see it.
         */
        $this->ensureModuleEnabled('slide');

        $locale = $this->locale($request);

        return $this->cache->remember(
            $this->cache->key('site.slides', $locale),
            [DeliveryCache::TAG_CONTENT, DeliveryCache::TAG_MEDIA],
            function (): AnonymousResourceCollection {
                $slides = Slide::query()
                    ->active()
                    // The link target because a slide's destination is now resolved
                    // from its target's per-locale slug, and the target's translation
                    // states because the payload reports whether that slug was a
                    // fallback. Without both, every slide costs several queries on a
                    // public cached endpoint fetched for the homepage of every visit.
                    ->with(['mediaAssets', Slide::LINK_TARGET_EAGER_LOAD])
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
     *
     * Each item carries `id`, `label`, `url`, `opens_in_new_tab`, `children` and a
     * `meta` object with `is_fallback`, `fallback_locale` and `translation_status`
     * — the same fallback reporting the content endpoints use, because an item's URL
     * is built from its target's slug and may resolve through the source locale.
     *
     * `url` is root-relative, since the frontend renders navigation on its own host.
     * An item whose target is missing, unpublished, or owned by a disabled module is
     * omitted, unless it still has children, in which case it is a section heading
     * with a null `url`. The tree is capped at three levels.
     *
     * Answers 404 when the menu module is disabled OR when the location is not one
     * this deployment declares, and 200 with an empty list for a declared location
     * that simply has no items.
     */
    public function menu(Request $request, string $key = MenuItem::DEFAULT_MENU_KEY): JsonResponse
    {
        /*
         * Requirement 1.1, and before the cache lookup so switching the module off
         * takes the endpoint out of service immediately rather than at the end of the
         * cache TTL. The check was missing entirely: the route is registered
         * unconditionally, so a site with navigation switched off still served its
         * menus — the toggle was a lie in the one place a consumer could see it.
         */
        $this->ensureModuleEnabled('menu');

        /*
         * An UNDECLARED location is a 404, which is the whole point of locations being
         * a configured set (`cms.menus.locations`). Until now any alphanumeric key
         * answered 200 with an empty array, so a frontend typo — `?menu=headr`, a
         * mis-copied constant — was indistinguishable from a menu the editor had not
         * filled in yet, and the frontend rendered no navigation with nothing anywhere
         * to explain why. A DECLARED location with no items still answers 200 and an
         * empty list, because that is a content state rather than a mistake.
         */
        if (! MenuItem::isKnownMenuKey($key)) {
            throw new NotFoundHttpException(
                "No menu location [{$key}] is declared on this site.",
            );
        }

        $locale = $this->locale($request);

        $tree = $this->cache->remember(
            $this->cache->key('site.menu', $locale, ['key' => $key]),
            [DeliveryCache::TAG_NAVIGATION, DeliveryCache::TAG_CONTENT],
            function () use ($key, $request): array {
                $items = MenuItem::query()
                    ->inMenu($key)
                    ->topLevel()
                    // Bounded, and bounded by the same constant the renderer stops
                    // at. The old two-level eager load with an unbounded recursion
                    // meant a third-level item cost a query for its children and
                    // another for its target, on a public cached endpoint.
                    ->with(MenuItem::treeEagerLoads())
                    ->get();

                return MenuItemResource::tree($items, $request);
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
        // Requirement 1.1 — stage 1 flagged this endpoint as ungated.
        $this->ensureModuleEnabled('settings');

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
        // Requirement 1.1 — stage 1 flagged this endpoint as ungated.
        $this->ensureModuleEnabled('contact');

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
        /*
         * Requirement 1.1 — the 404 page is a Page record, so it belongs to the page
         * module. Stage 1 flagged this endpoint as ungated.
         */
        $this->ensureModuleEnabled('page');

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
     * The page rendered at the locale root, e.g. /fa.
     *
     * A DEDICATED endpoint rather than a field on `settings`, deliberately, even though
     * "which page is the homepage" sounds like a setting. Three reasons, in order of
     * how expensive getting it wrong would be:
     *
     *  1. Cache tags. `settings` is cached under TAG_SETTINGS and a Page is content
     *     under TAG_CONTENT. Embedding the page body in the settings payload would mean
     *     either serving a stale homepage until the settings cache expired, or
     *     invalidating settings — read on every single request — on every content
     *     write. Neither is acceptable and there is no third option.
     *  2. Consistency. `not-found-page` is the existing precedent for "a Page the
     *     application resolves by system key rather than by slug", and it is served
     *     exactly this way. A homepage served differently would be an inconsistency a
     *     frontend developer has to memorise.
     *  3. Payload size. The homepage carries a full TipTap document and a featured
     *     image; `settings` is fetched by every page of the frontend, including the
     *     ones that will never render the homepage.
     *
     * 404 when no page has been designated, or when the designated page is not live —
     * the frontend then renders its own root, which is what a site that has not adopted
     * the homepage concept keeps doing.
     */
    public function homePage(Request $request): JsonResponse
    {
        $this->ensureModuleEnabled('page');

        $page = Page::homePage();

        /*
         * `isLive()` here and NOT in Page::homePage(). The model answers "which record
         * owns the URL /fa", which governs URL shape and must not flicker while the
         * page is unpublished for an edit; whether it may be SERVED is this endpoint's
         * question. Without the check, unpublishing the homepage would keep publishing
         * it.
         */
        if ($page === null || ! $page->isLive()) {
            return new JsonResponse(['message' => 'No homepage is designated.'], 404);
        }

        return new JsonResponse([
            'data' => PageResource::make($page->load(['mediaAssets', 'translationStates']))->toArray($request),
            'meta' => ['locale' => $this->locale($request)],
        ]);
    }
}
