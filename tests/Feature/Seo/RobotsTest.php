<?php

declare(strict_types=1);

use function Pest\Laravel\get;

/**
 * robots.txt for the API/panel host.
 *
 * Requirements 1.1, 7.4, and Requirement 1.2 (no client-specific value in code).
 *
 * `public/robots.txt` was the untouched Laravel stub — `User-agent: *` and an empty
 * `Disallow:` — which invites crawlers into the admin panel and says nothing about the
 * sitemaps this host serves. It is now a route, because the two things it has to say are
 * both configuration and a static file can express neither.
 */
it('serves robots.txt as plain text', function (): void {
    get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

it('keeps crawlers out of the admin panel at whatever path this client uses', function (): void {
    /*
     * The first reason this is a route. A committed file would hardcode `/admin`, so a
     * client setting CMS_PANEL_PATH=modiriat would ship a robots.txt disallowing a path
     * that does not exist while leaving the real login page crawlable.
     */
    get('/robots.txt')->assertSee('Disallow: /admin', escape: false);

    config()->set('cms.brand.panel_path', 'modiriat');

    $body = get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Disallow: /modiriat')
        ->and($body)->not->toContain('Disallow: /admin');
});

it('points at the sitemap index on this host, absolutely', function (): void {
    /*
     * The second reason. `Sitemap:` must be an absolute URL, and it must point HERE —
     * the sitemap suite is generated from the content database and served by
     * SitemapController, not by the frontend. A file could only get a hostname by
     * hardcoding one, which Requirement 1.2 forbids and which would silently point
     * staging at production.
     */
    $body = get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Sitemap: '.url('/sitemap.xml'))
        ->and($body)->toContain('://');

    // And the URL it advertises actually resolves.
    get('/sitemap.xml')->assertOk();
});

it('advertises no sitemap when the sitemap module is off', function (): void {
    // Requirement 1.1 — a disabled module advertises nothing, and a Sitemap: line
    // pointing at a 404 is a crawl error reported for as long as it stands.
    config()->set('cms.modules.sitemap', false);

    expect(get('/robots.txt')->assertOk()->getContent())->not->toContain('Sitemap:');
});

it('disallows the API, previews and panel plumbing as well as the panel', function (): void {
    $body = get('/robots.txt')->assertOk()->getContent();

    foreach (['/api/', '/preview/', '/livewire/'] as $path) {
        expect($body)->toContain('Disallow: '.$path);
    }
});

/*
|--------------------------------------------------------------------------
| Media has to be crawlable when it is served from here
|--------------------------------------------------------------------------
|
| This file used to assert `Disallow: /storage/` unconditionally, which pinned a real
| bug rather than a decision. Disallow does not make an image a lesser kind of result —
| it stops the crawler fetching the file — so the rule silently broke every image
| signal the application emits: the <image:loc> entries in sitemap-images.xml, the
| Article JSON-LD `image` that Google must fetch for rich results, og:image and
| twitter:image, the video sitemap thumbnails, and the publisher logo.
|
| So the rule is now conditional on where media actually lives.
|
*/

it('keeps media crawlable when it is served from this host', function (): void {
    // The default: no CMS_MEDIA_URL, so the public disk resolves to APP_URL/storage.
    config()->set('filesystems.disks.public.url', rtrim((string) config('app.url'), '/').'/storage');

    $body = get('/robots.txt')->assertOk()->getContent();

    expect($body)->not->toContain('Disallow: /storage/');
});

it('disallows media once it is served from another origin', function (): void {
    // CMS_MEDIA_URL pointing at the frontend, which rewrites /media back to this host.
    // Nothing here needs crawling any more, so closing the path costs nothing.
    config()->set('filesystems.disks.public.url', 'https://www.example.test/media');

    $body = get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Disallow: /storage/');
});

it('advertises image URLs it does not also forbid', function (): void {
    /*
     * The invariant behind the whole change, asserted end to end rather than argued:
     * whatever host the image sitemap points at, robots.txt on that host must not
     * forbid it. A sitemap that argues with its own robots.txt indexes nothing.
     */
    config()->set('filesystems.disks.public.url', rtrim((string) config('app.url'), '/').'/storage');

    $robots = get('/robots.txt')->assertOk()->getContent();

    $disallowed = [];

    foreach (explode("\n", (string) $robots) as $line) {
        if (str_starts_with($line, 'Disallow: ')) {
            $disallowed[] = trim(substr($line, strlen('Disallow: ')));
        }
    }

    $mediaPath = parse_url((string) config('filesystems.disks.public.url'), PHP_URL_PATH);

    expect($mediaPath)->toBeString();

    foreach ($disallowed as $rule) {
        expect(str_starts_with((string) $mediaPath.'/', $rule))->toBeFalse(
            "robots.txt forbids [{$rule}], which covers the media path the sitemaps advertise.",
        );
    }
});

it('says which host it describes, because the public site is a separate deployment', function (): void {
    /*
     * The easy mistake here is writing the FRONTEND's robots.txt by accident. This Core
     * is headless: the public site has its own, and nothing in this file speaks for it.
     * An operator reading this file has to be able to tell which is which.
     */
    expect(get('/robots.txt')->assertOk()->getContent())
        ->toContain('SEPARATE frontend deployment');
});

it('is not shadowed by a static file in public', function (): void {
    // A file in public/ is served by the web server before Laravel routes anything, so
    // leaving the stub in place would make this whole endpoint look mysteriously broken.
    expect(file_exists(projectPath('public/robots.txt')))->toBeFalse();
});
