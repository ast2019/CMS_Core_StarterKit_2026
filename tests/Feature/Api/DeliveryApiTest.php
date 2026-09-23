<?php

declare(strict_types=1);

use App\Enums\TranslationStatus;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Setting;
use App\Models\Slide;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Requirements 8.1-8.4, 8.7, 5.5, 3.6, 7.6.
 */
it('lists only live articles', function (): void {
    $live = Content::factory()->published()->create();
    $draft = Content::factory()->create();
    $scheduled = Content::factory()->scheduled()->create();
    $archived = Content::factory()->archived()->create();

    $response = getJson('/api/v1/news')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($live->id)
        ->and($ids)->not->toContain($draft->id)
        ->and($ids)->not->toContain($archived->id)
        // Requirement 3.6 — a published record with a FUTURE publish_date is
        // scheduled, not live. Status alone is not the gate.
        ->and($ids)->not->toContain($scheduled->id);
});

it('fetches an article by its per-locale slug', function (): void {
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'گزارش ویژه'],
    ]);

    $slug = $content->getTranslation('slug', 'fa');

    getJson('/api/v1/news/'.urlencode($slug))
        ->assertOk()
        ->assertJsonPath('data.title', 'گزارش ویژه')
        ->assertJsonPath('data.meta.locale', 'fa')
        ->assertJsonPath('data.meta.is_fallback', false);
});

it('returns 404 for a slug that exists in no locale', function (): void {
    Content::factory()->published()->create();

    // A slug that exists in NO locale is a 404. (A slug that exists in the source
    // locale resolves with is_fallback set — see the fallback test below.)
    getJson('/api/v1/news/does-not-exist?locale=en')->assertNotFound();
});

it('marks a response as a fallback when the locale has no reviewed translation', function (): void {
    // Requirement 5.5 — silent fallback is forbidden, because a frontend cannot
    // otherwise distinguish real English from Persian served under an English URL.
    $content = Content::factory()->published()->create([
        'title' => ['fa' => 'عنوان فارسی'],
    ]);

    $slug = $content->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/news/{$slug}?locale=en")->assertOk();

    expect($response->json('data.meta.is_fallback'))->toBeTrue()
        ->and($response->json('data.meta.fallback_locale'))->toBe('fa')
        ->and($response->json('data.meta.translation_status'))->toBe(TranslationStatus::NotTranslated->value)
        // The content itself still comes back, so the frontend can choose to show,
        // hide or label it.
        ->and($response->json('data.title'))->toBe('عنوان فارسی');
});

it('does not mark reviewed translations as fallbacks', function (): void {
    $content = Content::factory()->published()->multilingual()->create();
    $content->markTranslationReviewed('en');

    $slug = $content->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/news/{$slug}?locale=en")->assertOk();

    expect($response->json('data.meta.is_fallback'))->toBeFalse()
        ->and($response->json('data.title'))->toBe('Sample article title');
});

it('rejects an unsupported locale instead of silently falling back', function (): void {
    /*
     * A quiet fallback would mean a frontend requesting ?locale=de receives Persian
     * with a 200 and caches it, and the bug shows up weeks later as "the German
     * site is in Persian".
     */
    getJson('/api/v1/news?locale=de')
        ->assertStatus(400)
        ->assertJsonPath('errors.locale.0', 'Supported locales: fa, en, ar.');
});

it('ignores Accept-Language by default and serves the source locale', function (): void {
    Content::factory()->published()->create();

    /*
     * Symfony's test client sends 'en-us,en;q=0.5' by default, which is exactly the
     * situation this default protects against: a Persian-only site must not serve
     * every English-speaking browser a fallback-flagged payload just because the
     * browser asked.
     */
    getJson('/api/v1/news', ['Accept-Language' => 'en-US,en;q=0.9'])
        ->assertOk()
        ->assertHeader('Content-Language', 'fa')
        // No Vary when the header cannot influence the response, or every shared
        // cache fragments by browser for no benefit.
        ->assertHeaderMissing('Vary');
});

it('negotiates from Accept-Language when a site opts in, including regional tags', function (): void {
    config()->set('cms.locales.negotiate_from_header', true);

    Content::factory()->published()->create();

    // 'ar-SA' must match the supported 'ar'; comparing full tags would not.
    getJson('/api/v1/news', ['Accept-Language' => 'ar-SA,ar;q=0.9'])
        ->assertOk()
        ->assertHeader('Content-Language', 'ar')
        ->assertHeader('Vary', 'Accept-Language');
});

it('filters articles by category slug', function (): void {
    $category = Category::factory()->create(['name' => ['fa' => 'اقتصاد']]);
    $inCategory = Content::factory()->published()->create();
    $inCategory->categories()->attach($category);
    Content::factory()->published()->create();

    $slug = $category->getTranslation('slug', 'fa');

    $response = getJson("/api/v1/news?category={$slug}")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($inCategory->id);
});

it('bounds the page size so a public endpoint cannot be asked for everything', function (): void {
    Content::factory()->published()->count(5)->create();

    $response = getJson('/api/v1/news?per_page=100000')->assertOk();

    expect($response->json('meta.per_page'))->toBe(100);
});

it('caps slides and carries the performance contract in the payload', function (): void {
    // Requirement 7.6.
    $asset = MediaAsset::factory()->create();

    foreach (range(0, 4) as $i) {
        $slide = Slide::factory()->create(['position' => $i]);
        $slide->setFeaturedImage($asset);
    }

    $response = getJson('/api/v1/slides')->assertOk();

    expect($response->json('data'))->toHaveCount(5)
        // The first slide is the one to preload; a frontend cannot infer that.
        ->and($response->json('data.0.should_preload'))->toBeTrue()
        ->and($response->json('data.1.should_preload'))->toBeFalse()
        // Explicit dimensions, so the browser can reserve space (no CLS).
        ->and($response->json('data.0.image.width'))->toBe(1920)
        ->and($response->json('data.0.image.height'))->toBe(800)
        // Blueprint §6 forbids autoplay video in the slideshow.
        ->and($response->json('data.0.autoplay_video'))->toBeFalse();
});

it('omits related content unless it is explicitly requested', function (): void {
    // Requirement 4.8 — computing it costs a scored query per article, which must
    // not run for every row of a listing.
    $category = Category::factory()->create();
    $content = Content::factory()->published()->create();
    $content->categories()->attach($category);
    Content::factory()->published()->count(2)->create()
        ->each(fn (Content $c) => $c->categories()->attach($category));

    $slug = $content->getTranslation('slug', 'fa');

    getJson("/api/v1/news/{$slug}")
        ->assertOk()
        ->assertJsonMissingPath('data.related');

    getJson("/api/v1/news/{$slug}?include=related")
        ->assertOk()
        ->assertJsonCount(2, 'data.related');
});

it('serves the site settings and never leaks an unset analytics code as a string', function (): void {
    $response = getJson('/api/v1/settings')->assertOk();

    expect($response->json('data.locales.supported'))->toBe(['fa', 'en', 'ar'])
        ->and($response->json('data.analytics.ga_measurement_id'))->toBeNull();
});

it('returns 503 with Retry-After when maintenance mode is on', function (): void {
    Setting::put(Setting::MAINTENANCE_MODE, true);

    // 503 rather than 404 or an empty 200: it tells crawlers to come back instead
    // of inviting them to de-index the site.
    getJson('/api/v1/news')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '3600');
});

it('accepts a contact submission and records the server-side IP', function (): void {
    postJson('/api/v1/contact', [
        'name' => 'نام آزمایشی',
        'email' => 'someone@example.test',
        'message' => 'این یک پیام آزمایشی برای فرم تماس است.',
        // A client-supplied IP must be ignored, or the abuse trail is forgeable.
        'ip_address' => '203.0.113.9',
    ])->assertCreated();

    $submission = ContactSubmission::query()->firstOrFail();

    expect($submission->name)->toBe('نام آزمایشی')
        ->and($submission->ip_address)->not->toBe('203.0.113.9');
});

it('requires either an email address or a phone number on the contact form', function (): void {
    // Demanding email would exclude users who would rather be called back;
    // demanding phone would exclude everyone else.
    postJson('/api/v1/contact', [
        'name' => 'نام',
        'message' => 'یک پیام کافی طولانی برای عبور از اعتبارسنجی.',
    ])->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);

    postJson('/api/v1/contact', [
        'name' => 'نام',
        'phone' => '09120000000',
        'message' => 'یک پیام کافی طولانی برای عبور از اعتبارسنجی.',
    ])->assertCreated();
});

it('enforces the delivery API key only when enabled', function (): void {
    // Decision D-9 — public by default.
    getJson('/api/v1/settings')->assertOk();

    config()->set('cms.api.delivery.require_key', true);
    config()->set('cms.api.delivery.key', 'test-secret-key');

    getJson('/api/v1/settings')->assertUnauthorized();
    getJson('/api/v1/settings', ['X-API-Key' => 'wrong'])->assertUnauthorized();
    getJson('/api/v1/settings', ['X-API-Key' => 'test-secret-key'])->assertOk();
});

it('fails closed when key enforcement is on but no key is configured', function (): void {
    /*
     * Treating a blank expected key as "allow" would mean a typo'd env var silently
     * reopens an API the operator believes is locked.
     */
    config()->set('cms.api.delivery.require_key', true);
    config()->set('cms.api.delivery.key', '');

    getJson('/api/v1/settings', ['X-API-Key' => 'anything'])->assertStatus(503);
});
