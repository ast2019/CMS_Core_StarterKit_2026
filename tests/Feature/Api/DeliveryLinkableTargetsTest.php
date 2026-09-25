<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Enums\TranslationStatus;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;

use function Pest\Laravel\getJson;

/**
 * Delivery endpoints for the three linkable menu targets that had none:
 * Page, Category and Gallery.
 *
 * Requirements 1.1, 3.1, 3.6, 5.5, 8.1, 8.3, 8.4.
 *
 * All three were already advertised to frontends — they are menu targets and they
 * appear in the sitemaps — while the API offered no way to fetch them, so a header
 * menu built in the panel produced links nothing could resolve.
 */
it('fetches a published page by its per-locale slug', function (): void {
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);

    $slug = $page->getTranslation('slug', 'fa');

    getJson('/api/v1/pages/'.urlencode($slug))
        ->assertOk()
        ->assertJsonPath('data.title', 'درباره ما')
        ->assertJsonPath('data.meta.locale', 'fa')
        ->assertJsonPath('data.meta.is_fallback', false)
        // The TipTap document, not HTML (RULE #6) — named `blocks` on a page.
        ->assertJsonPath('data.blocks.type', 'doc');
});

it('does not serve a draft, scheduled or unknown page', function (): void {
    // Requirement 3.6 — status alone is not the gate; a published record with a
    // future publish_date is scheduled, not live.
    $draft = Page::factory()->create(['status' => ContentStatus::Draft]);
    $scheduled = Page::factory()->create(['publish_date' => now()->addWeek()]);

    getJson('/api/v1/pages/'.urlencode($draft->getTranslation('slug', 'fa')))->assertNotFound();
    getJson('/api/v1/pages/'.urlencode($scheduled->getTranslation('slug', 'fa')))->assertNotFound();
    getJson('/api/v1/pages/no-such-page')->assertNotFound();
});

it('flags a page served in a locale with no reviewed translation as a fallback', function (): void {
    /*
     * Requirement 5.5 — silent fallback is forbidden. A frontend that cannot tell
     * real English from Persian served under an English URL will publish duplicate
     * content across locales.
     */
    $page = Page::factory()->create(['title' => ['fa' => 'تماس با ما']]);

    $slug = $page->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/pages/{$slug}?locale=en")->assertOk();

    expect($response->json('data.meta.is_fallback'))->toBeTrue()
        ->and($response->json('data.meta.fallback_locale'))->toBe('fa')
        ->and($response->json('data.meta.translation_status'))->toBe(TranslationStatus::NotTranslated->value)
        // The content still comes back, so the frontend can show, hide or label it.
        ->and($response->json('data.title'))->toBe('تماس با ما');
});

it('returns a category with its live article archive', function (): void {
    $category = Category::factory()->create([
        'name' => ['fa' => 'اقتصاد'],
        'description' => ['fa' => 'اخبار اقتصادی'],
    ]);
    $child = Category::factory()->childOf($category)->create(['name' => ['fa' => 'بازار سرمایه']]);

    $live = Content::factory()->published()->create();
    $draft = Content::factory()->create();
    $scheduled = Content::factory()->scheduled()->create();

    foreach ([$live, $draft, $scheduled] as $article) {
        $article->categories()->attach($category);
    }

    // In no category, so it must not appear in this archive.
    Content::factory()->published()->create();

    $slug = $category->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/categories/{$slug}")->assertOk();

    expect($response->json('data.category.name'))->toBe('اقتصاد')
        ->and($response->json('data.category.description'))->toBe('اخبار اقتصادی')
        // Children come back too: an archive page needs its sub-navigation, and
        // fetching it separately would be a second round trip per view.
        ->and($response->json('data.category.children.0.id'))->toBe($child->id)
        ->and($response->json('data.contents'))->toHaveCount(1)
        ->and($response->json('data.contents.0.id'))->toBe($live->id)
        ->and($response->json('meta.total'))->toBe(1)
        ->and($response->json('meta.locale'))->toBe('fa');
});

it('distinguishes an empty category from one that does not exist', function (): void {
    /*
     * The reason `news?category=slug` was not enough: it answers with an empty list
     * either way, so a frontend cannot tell a real but empty archive (render the
     * heading) from a bad URL (render a 404).
     */
    $category = Category::factory()->create(['name' => ['fa' => 'بدون مطلب']]);

    getJson('/api/v1/categories/'.urlencode($category->getTranslation('slug', 'fa')))
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data.contents', []);

    getJson('/api/v1/categories/no-such-category')->assertNotFound();
});

it('bounds the category archive page size', function (): void {
    $category = Category::factory()->create();

    Content::factory()->published()->count(3)->create()
        ->each(fn (Content $content) => $content->categories()->attach($category));

    getJson('/api/v1/categories/'.urlencode($category->getTranslation('slug', 'fa')).'?per_page=100000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

it('returns a gallery with its cover and ordered items', function (): void {
    // Decision D-4 — the cover is the single `featured` attachment and the items are
    // uncapped `gallery`-role attachments; the cover may also be an item.
    $gallery = Gallery::factory()->published()->create(['title' => ['fa' => 'گزارش تصویری']]);

    $cover = MediaAsset::factory()->create();
    $first = MediaAsset::factory()->create();
    $second = MediaAsset::factory()->create();

    $gallery->setFeaturedImage($cover);
    $gallery->attachMediaAsset($first, MediaRole::Gallery, position: 0);
    $gallery->attachMediaAsset($second, MediaRole::Gallery, position: 1);

    $slug = $gallery->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/galleries/{$slug}")->assertOk();

    expect($response->json('data.title'))->toBe('گزارش تصویری')
        ->and($response->json('data.cover.id'))->toBe($cover->id)
        ->and($response->json('data.item_count'))->toBe(2)
        // Editor-chosen order survives into the payload.
        ->and(array_column($response->json('data.items'), 'id'))->toBe([$first->id, $second->id])
        // Requirement 7.6 — intrinsic dimensions so the frontend can reserve space.
        ->and($response->json('data.items.0.width'))->toBe(1920);
});

it('does not serve a draft or unknown gallery', function (): void {
    // The factory's default state is Draft, which is the common case for a gallery
    // still being assembled.
    $draft = Gallery::factory()->create();

    getJson('/api/v1/galleries/'.urlencode($draft->getTranslation('slug', 'fa')))->assertNotFound();
    getJson('/api/v1/galleries/no-such-gallery')->assertNotFound();
});

it('serves nothing for a module that is switched off', function (): void {
    /*
     * Requirement 1.1 — config/cms.php promises that a disabled module "registers no
     * routes, no Filament resource, and contributes no sitemap entries". Until these
     * endpoints existed nothing in routes/api.php consulted cms.modules at all, so a
     * site with galleries turned off still served them over the API.
     */
    $page = Page::factory()->create();
    $category = Category::factory()->create();
    $gallery = Gallery::factory()->published()->create();

    $pageUrl = '/api/v1/pages/'.urlencode($page->getTranslation('slug', 'fa'));
    $categoryUrl = '/api/v1/categories/'.urlencode($category->getTranslation('slug', 'fa'));
    $galleryUrl = '/api/v1/galleries/'.urlencode($gallery->getTranslation('slug', 'fa'));

    // All three are reachable while their modules are on.
    getJson($pageUrl)->assertOk();
    getJson($categoryUrl)->assertOk();
    getJson($galleryUrl)->assertOk();

    config()->set('cms.modules.page', false);
    config()->set('cms.modules.category', false);
    config()->set('cms.modules.gallery', false);

    // 404, not 403: the module does not exist on this site, and "forbidden" would
    // advertise that there is something to get access to.
    getJson($pageUrl)->assertNotFound();
    getJson($categoryUrl)->assertNotFound();
    getJson($galleryUrl)->assertNotFound();
});

it('serves a fresh payload after the record changes', function (): void {
    /*
     * Requirement 8.4 — the cache is invalidated by tag when the underlying content
     * changes. Without this an editor fixes a typo, reloads the site, sees the old
     * text and fixes it again.
     */
    $page = Page::factory()->create(['title' => ['fa' => 'عنوان اول']]);
    $slug = $page->getTranslation('slug', 'fa');

    getJson("/api/v1/pages/{$slug}")->assertJsonPath('data.title', 'عنوان اول');

    $page->setTranslation('title', 'fa', 'عنوان دوم');
    $page->save();

    getJson("/api/v1/pages/{$slug}")->assertJsonPath('data.title', 'عنوان دوم');
});

it('does not cache a miss, so a page published a moment later is reachable', function (): void {
    /*
     * The 404 is raised INSIDE the cache callback on purpose. Caching the miss would
     * keep a just-published page unreachable for the rest of the TTL, which looks
     * exactly like a broken publish button.
     */
    $page = Page::factory()->create(['status' => ContentStatus::Draft]);
    $slug = $page->getTranslation('slug', 'fa');

    getJson("/api/v1/pages/{$slug}")->assertNotFound();

    $page->publish();

    getJson("/api/v1/pages/{$slug}")->assertOk();
});
