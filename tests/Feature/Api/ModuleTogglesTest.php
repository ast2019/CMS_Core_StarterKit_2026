<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use App\Models\Slide;
use App\Services\Seo\UrlBuilder;
use App\Services\Sitemap\SitemapGenerator;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Requirement 1.1 — "a disabled module registers no routes, no Filament resource, and no
 * sitemap entries".
 *
 * The half that was never enforced. config/cms.php made the promise; the sitemap
 * generator never consulted the toggles, and four Delivery endpoints had no gate at all.
 * A toggle that a consumer can see through is worse than no toggle, because the config
 * file reads as a guarantee.
 *
 * `audit` is deliberately absent from all of this: RULE #8 says there is no opt-out, and
 * it is the one entry in `cms.modules` that is not an env var.
 */
it('leaves no sitemap entry for a disabled module', function (): void {
    $content = Content::factory()->published()->create();
    $gallery = Gallery::factory()->published()->create();
    $category = Category::factory()->create();
    $page = Page::factory()->create();

    $urls = app(UrlBuilder::class);

    $pathOf = fn ($record): string => (string) $urls->pathFor($record, 'fa');

    $xml = fn (): string => app(SitemapGenerator::class)->forLocale('fa')->render();

    // All four present to begin with, or the assertions below would pass vacuously.
    expect($xml())->toContain($pathOf($content))
        ->and($xml())->toContain($pathOf($gallery))
        ->and($xml())->toContain($pathOf($category))
        ->and($xml())->toContain($pathOf($page));

    config()->set('cms.modules.gallery', false);
    config()->set('cms.modules.category', false);

    $after = $xml();

    /*
     * The consequence of getting this wrong is specific and expensive: a site with the
     * gallery module off answers 404 for every gallery, so every gallery URL submitted to
     * Search Console is a crawl error against the frontend's domain.
     */
    expect($after)->not->toContain($pathOf($gallery))
        ->and($after)->not->toContain($pathOf($category))
        ->and($after)->toContain($pathOf($content))
        ->and($after)->toContain($pathOf($page));
});

it('empties the image and video sitemaps when the content module is off', function (): void {
    /*
     * Both hang images and videos off the ARTICLE URL that shows them — Google's image
     * sitemap format associates each image with its host page — so with articles
     * unreachable there is no host page for anything to be indexed against.
     */
    Content::factory()->published()->create();

    config()->set('cms.modules.content', false);

    $generator = app(SitemapGenerator::class);

    expect($generator->eligibleArticles('fa'))->toBeEmpty()
        ->and($generator->images()->getTags())->toBeEmpty()
        ->and($generator->videos()->getTags())->toBeEmpty();
});

it('still lists the locale root when every content module is off', function (): void {
    // A sitemap with no URLs at all would be an invalid document, and the locale root is
    // the one entry that belongs to the site rather than to a module.
    foreach (['content', 'page', 'category', 'gallery'] as $module) {
        config()->set("cms.modules.{$module}", false);
    }

    expect(app(SitemapGenerator::class)->forLocale('fa')->render())
        ->toContain('<loc>'.app(UrlBuilder::class)->localeHome('fa').'</loc>');
});

it('gates the four site endpoints stage 1 left open', function (): void {
    /*
     * slides, settings, contact and not-found-page. Each answers 404 rather than 403: the
     * module does not exist on this site, and "forbidden" would advertise that there is
     * something to get access to.
     */
    Page::factory()->notFoundPage()->create();
    Slide::factory()->create();

    $endpoints = [
        'slide' => '/api/v1/slides',
        'settings' => '/api/v1/settings',
        'contact' => '/api/v1/contact',
        // The 404 page is a Page record, so it belongs to the page module.
        'page' => '/api/v1/not-found-page',
    ];

    foreach ($endpoints as $module => $uri) {
        getJson($uri)->assertOk();

        config()->set("cms.modules.{$module}", false);
        getJson($uri)->assertNotFound();
        config()->set("cms.modules.{$module}", true);
    }
});

it('refuses a contact submission when the contact module is off', function (): void {
    /*
     * The read endpoint and the write are gated together. With the module off no form is
     * rendered, so a submission could only come from a stale cached page or a script — and
     * writing it to a table nobody in the panel is looking at is worse than refusing.
     */
    $payload = ['name' => 'آزمون', 'email' => 'a@b.test', 'message' => 'سلام دنیا، این یک پیام آزمایشی است.'];

    postJson('/api/v1/contact', $payload)->assertCreated();

    config()->set('cms.modules.contact', false);

    postJson('/api/v1/contact', $payload)->assertNotFound();

    expect(ContactSubmission::query()->count())->toBe(1);
});

it('keeps the sitemap routes serving a valid document with modules off', function (): void {
    // The routes stay registered so the OpenAPI/route surface is identical on every
    // deployment; what changes is what they contain.
    foreach (['content', 'page', 'category', 'gallery'] as $module) {
        config()->set("cms.modules.{$module}", false);
    }

    get('/sitemap.xml')->assertOk();
    get('/sitemap-fa.xml')->assertOk();
    get('/sitemap-images.xml')->assertOk();
    get('/sitemap-videos.xml')->assertOk();
});

it('does not make audit logging gateable', function (): void {
    // RULE #8 — no opt-out. It is the one module entry with a literal `true` instead of an
    // env() call, and a test is the only thing that stops a future "consistency" tidy-up
    // from turning it into CMS_MODULE_AUDIT.
    $source = (string) file_get_contents(projectPath('config/cms.php'));

    expect($source)->toContain("'audit' => true")
        ->and($source)->not->toContain('CMS_MODULE_AUDIT')
        ->and(config('cms.modules.audit'))->toBeTrue();
});
