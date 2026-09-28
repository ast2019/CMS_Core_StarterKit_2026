<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Resources\Contents\Pages\CreateContent;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Jobs\SyncSearchIndexes;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\User;
use App\Services\Api\DeliveryCache;
use App\Services\Seo\HreflangBuilder;
use App\Services\Seo\UrlBuilder;
use App\Services\Sitemap\SitemapGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

/**
 * Second logic-bug audit. Each case failed on main before the matching fix.
 */
it('does not serve an unpublished branded 404 page', function (): void {
    /*
     * homePage() refuses a draft; notFoundPage() used to return the full TipTap body
     * of a draft/scheduled/archived 404 page to anyone who asked.
     */
    Page::factory()->notFoundPage()->create([
        'status' => ContentStatus::Draft,
        'title' => ['fa' => 'پیش‌نویس صفحهٔ ۴۰۴'],
        'blocks' => ['fa' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'secret draft']]]]],
    ]);

    getJson('/api/v1/not-found-page')
        ->assertNotFound()
        ->assertJsonMissing(['secret draft']);
});

it('refuses to delete a system page even for an admin', function (): void {
    /*
     * PagePolicy::delete() returns false for system pages, but Gate::before answered
     * true for admins before the policy ran — so an admin DeleteAction could soft-delete
     * the branded 404 and leave the site with none.
     */
    $admin = User::factory()->admin()->create();
    $page = Page::factory()->notFoundPage()->create();

    expect(Gate::forUser($admin)->allows('delete', $page))->toBeFalse();

    expect(fn () => $page->delete())
        ->toThrow(ValidationException::class);

    expect(Page::notFoundPage()?->getKey())->toBe($page->getKey());
});

it('forbids an author from pulling a scheduled article live via publish_date alone', function (): void {
    /*
     * status changes need content.publish; publish_date did not. An Author with
     * update.own could PATCH a future date into the past and go live without the ability.
     */
    $author = User::factory()->author()->create();
    Sanctum::actingAs($author, ['manage']);

    $article = Content::factory()->for($author, 'author')->scheduled()->create();

    patchJson('/api/v1/manage/news/'.$article->getKey(), [
        'publish_date' => now()->subMinute()->toIso8601String(),
    ])->assertForbidden();

    expect($article->fresh()->isLive())->toBeFalse();
});

it('forbids an author from taking a live article offline via publish_date alone', function (): void {
    $author = User::factory()->author()->create();
    Sanctum::actingAs($author, ['manage']);

    $article = Content::factory()->for($author, 'author')->published()->create();

    patchJson('/api/v1/manage/news/'.$article->getKey(), [
        'publish_date' => now()->addDay()->toIso8601String(),
    ])->assertForbidden();

    expect($article->fresh()->isLive())->toBeTrue();
});

it('queues a search sync and busts Delivery cache when a translation is reviewed', function (): void {
    /*
     * markTranslationReviewed() only wrote TranslationState. SearchIndexObserver and
     * DeliveryCacheObserver watch the parent, so reviewing English never indexed it and
     * left SEO payloads cached as noindex until TTL.
     */
    $content = Content::factory()->published()->multilingual()->create();

    Queue::fake();
    Cache::flush();

    $cache = app(DeliveryCache::class);
    $key = $cache->key('probe', 'fa', ['id' => $content->getKey()]);
    $cache->remember($key, [DeliveryCache::TAG_CONTENT], fn (): string => 'stale');

    $content->markTranslationReviewed('en');

    Queue::assertPushed(SyncSearchIndexes::class);

    expect($cache->remember($key, [DeliveryCache::TAG_CONTENT], fn (): string => 'fresh'))
        ->toBe('fresh');
});

it('404s the news Delivery endpoints when the content module is off', function (): void {
    Content::factory()->published()->create(['slug' => ['fa' => 'خبر-آزمایشی']]);

    getJson('/api/v1/news')->assertOk();
    getJson('/api/v1/news/خبر-آزمایشی')->assertOk();

    config()->set('cms.modules.content', false);

    getJson('/api/v1/news')->assertNotFound();
    getJson('/api/v1/news/خبر-آزمایشی')->assertNotFound();
});

it('omits a noindex locale from the hreflang cluster', function (): void {
    /*
     * Sitemap already drops noindex URLs; hreflang still advertised them, so crawlers
     * followed annotations to pages Search Console then flagged as marked noindex.
     * Arabic stays in so the cluster still has two locales after English is dropped —
     * a single leftover locale would empty the whole set by design.
     */
    $content = Content::factory()->published()->multilingual()->create([
        'title' => [
            'fa' => 'عنوان فارسی',
            'en' => 'English title',
            'ar' => 'عنوان عربي',
        ],
        'robots_meta' => [
            'fa' => 'index, follow',
            'en' => 'noindex, follow',
            'ar' => 'index, follow',
        ],
    ]);
    $content->markTranslationReviewed('en');
    $content->markTranslationReviewed('ar');

    $links = app(HreflangBuilder::class)->for($content);

    expect($links)->toHaveKey('fa')
        ->and($links)->toHaveKey('ar')
        ->and($links)->not->toHaveKey('en');
});

it('does not synthesise a locale root when the homepage is live but noindex', function (): void {
    /*
     * homePageCoversLocaleRoot() used isEligible(), which fails on noindex, so the
     * generator fell through to a synthetic /{locale} entry — the opposite of what the
     * editor asked for.
     */
    Page::factory()->homePage()->create([
        'robots_meta' => ['fa' => 'noindex, follow'],
    ]);

    $xml = app(SitemapGenerator::class)->forLocale('fa')->render();
    $root = app(UrlBuilder::class)->localeHome('fa');

    expect($xml)->not->toContain('<loc>'.$root.'</loc>');
});

it('refuses an author publishing through the panel status dropdown', function (): void {
    /*
     * Management API was fixed to route status through transitionTo + publish ability.
     * The Filament form still mass-assigned status, so an Author could create live.
     */
    $author = User::factory()->author()->withMfaEnrolled()->create();
    actingAs($author);

    $asset = MediaAsset::factory()->create();

    Livewire::test(CreateContent::class)
        ->fillForm([
            'title.fa' => 'انتشار از فرم',
            'status' => ContentStatus::Published->value,
            'featured_media_asset_id' => $asset->getKey(),
        ])
        ->call('create')
        ->assertForbidden();

    expect(Content::query()->count())->toBe(0);
});

it('publishes through the panel via the workflow so the audit event is written', function (): void {
    $editor = User::factory()->editor()->withMfaEnrolled()->create();
    actingAs($editor);

    $asset = MediaAsset::factory()->create();

    Livewire::test(CreateContent::class)
        ->fillForm([
            'title.fa' => 'خبر فوری از پنل',
            'status' => ContentStatus::Published->value,
            'featured_media_asset_id' => $asset->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $content = Content::query()->firstOrFail();

    expect($content->status)->toBe(ContentStatus::Published)
        ->and($content->publish_date)->not->toBeNull()
        ->and($content->activitiesAsSubject()->where('event', 'published')->count())->toBe(1);
});

it('refuses an illegal archived-to-published jump on the panel edit form', function (): void {
    $editor = User::factory()->editor()->withMfaEnrolled()->create();
    actingAs($editor);

    $content = Content::factory()->create([
        'status' => ContentStatus::Archived,
        'author_id' => $editor->getKey(),
    ]);
    $content->setFeaturedImage(MediaAsset::factory()->create());

    Livewire::test(EditContent::class, ['record' => $content->getRouteKey()])
        ->fillForm([
            'status' => ContentStatus::Published->value,
        ])
        ->call('save')
        ->assertHasErrors(['status']);

    expect($content->fresh()->status)->toBe(ContentStatus::Archived);
});
