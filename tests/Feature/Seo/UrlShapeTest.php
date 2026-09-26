<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MenuItem;
use App\Models\Page;
use App\Services\Content\RedirectSuggestionService;
use App\Services\Seo\UrlBuilder;

/**
 * One URL shape, one implementation.
 *
 * Requirements 7.2, 7.4, 7.5.
 *
 * UrlBuilder's own docblock says the redirect engine, the sitemap, the hreflang
 * builder and the navigation resolver "must all agree — a generated 301 that points
 * somewhere the sitemap does not list is a silently broken link, and three
 * near-identical path builders is how that happens". There were three: MenuItem and
 * RedirectSuggestionService each carried their own copy of the segment map, and the
 * redirect service's docblock even described itself as "mirroring" the others.
 *
 * These tests assert the agreement rather than the delegation, so they keep holding
 * if the implementation is refactored again — and they fail the moment a fifth
 * linkable type is added to only one of the three places.
 */
it('gives the navigation, the redirect engine and the canonical the same path', function (): void {
    config()->set('cms.frontend_url', 'https://frontend.test');

    $records = [
        Content::factory()->published()->create(),
        Page::factory()->create(),
        Category::factory()->create(),
        Gallery::factory()->published()->create(),
    ];

    $urls = app(UrlBuilder::class);
    $redirects = app(RedirectSuggestionService::class);

    foreach ($records as $record) {
        $slug = (string) $record->getTranslation('slug', 'fa', useFallbackLocale: false);
        $path = $urls->pathFor($record, 'fa');

        $menuUrl = MenuItem::factory()->pointingAt($record)->create()->resolveUrl('fa');

        expect($path)->not->toBeNull()
            // The menu resolves through UrlBuilder, so navigation cannot drift from
            // the sitemap's idea of where a record lives.
            ->and($menuUrl)->toBe($path)
            // So does the redirect engine, which has to build the path from a slug
            // because the OLD slug is no longer on the record.
            ->and($redirects->pathFor($record, 'fa', $slug))->toBe($path)
            /*
             * The one difference that is deliberate: a canonical is absolute,
             * because a crawler needs the authoritative host, while a menu URL is
             * root-relative, because the frontend renders it on its own host.
             */
            ->and($urls->canonicalFor($record, 'fa'))->toBe('https://frontend.test'.$path)
            ->and($menuUrl)->toStartWith('/fa/');
    }
});

it('keeps a renamed slug and its redirect on the same shape', function (): void {
    $content = Content::factory()->published()->create(['title' => ['fa' => 'عنوان اول']]);

    $before = $content->getTranslations('slug');

    $content->setTranslation('slug', 'fa', 'title-renamed');
    $content->save();

    $pending = app(RedirectSuggestionService::class)->pendingFor($content, $before);
    $urls = app(UrlBuilder::class);

    expect($pending['fa']['from'])->toBe($urls->pathForSlug(Content::class, 'fa', (string) $before['fa']))
        ->and($pending['fa']['to'])->toBe($urls->pathFor($content, 'fa'));
});

it('treats a record in a disabled module as having no public URL', function (): void {
    // Requirement 1.1. The shape still exists — the site simply does not serve it,
    // so nothing should advertise it.
    $urls = app(UrlBuilder::class);

    expect($urls->isRoutable(Gallery::class))->toBeTrue()
        ->and($urls->isPubliclyRoutable(Gallery::class))->toBeTrue()
        ->and($urls->isPubliclyRoutable(MenuItem::class))->toBeFalse();

    config()->set('cms.modules.gallery', false);

    expect($urls->isRoutable(Gallery::class))->toBeTrue()
        ->and($urls->isPubliclyRoutable(Gallery::class))->toBeFalse();
});

it('keeps the slug-change rule in one place', function (): void {
    /*
     * HasSlug::slugChanges() was a second implementation of what
     * RedirectSuggestionService::pendingFor() does, minus the check for an existing
     * redirect, and nothing called it. Two implementations of one rule with only one
     * of them maintained is how the two drift apart.
     */
    expect(method_exists(Content::class, 'slugChanges'))->toBeFalse()
        ->and(method_exists(Content::class, 'captureOriginalSlugs'))->toBeFalse();
});
