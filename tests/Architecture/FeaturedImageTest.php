<?php

declare(strict_types=1);

use App\Concerns\HasFeaturedImage;
use App\Enums\MediaRole;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Slide;

/**
 * RULE #7 — FEATURED IMAGE: "shared trait on every content-bearing model."
 *
 * Blueprint §12.7, Requirements 3.2, 3.3.
 */

/**
 * The four models the blueprint requires a featured image on (§3).
 *
 * @return list<class-string>
 */
function featuredImageModels(): array
{
    return [Content::class, Page::class, Gallery::class, Slide::class];
}

it('applies the shared featured-image trait to every content-bearing model', function (): void {
    foreach (featuredImageModels() as $model) {
        expect(in_array(HasFeaturedImage::class, class_uses_recursive($model), true))
            ->toBeTrue("RULE #7 violated: {$model} must use the shared HasFeaturedImage trait.");
    }
});

it('uses one shared trait rather than per-model reimplementations', function (): void {
    // The rule says "shared trait". Four models each defining their own
    // featuredImage() would satisfy the letter and lose the point: the guarantee
    // is that all four behave identically.
    $expected = realpath((new ReflectionClass(HasFeaturedImage::class))->getFileName());

    foreach (featuredImageModels() as $model) {
        $method = new ReflectionMethod($model, 'featuredImage');

        expect(realpath((string) $method->getFileName()))->toBe(
            $expected,
            "RULE #7 violated: {$model} defines its own featuredImage() instead of "
            .'inheriting the shared behaviour.',
        );
    }
});

it('keeps the featured role singular', function (): void {
    expect(MediaRole::Featured->isSingular())->toBeTrue()
        ->and(MediaRole::OgImage->isSingular())->toBeTrue()
        // Galleries and inline images are many per record by design (D-4).
        ->and(MediaRole::Gallery->isSingular())->toBeFalse()
        ->and(MediaRole::Inline->isSingular())->toBeFalse();
});

it('replaces rather than accumulates when a second featured image is attached', function (): void {
    // This is where "exactly one" is actually enforced. A partial unique index —
    // unique on (attachable, role) only when the role is singular — is not
    // portable across MySQL and SQLite, so the write path guarantees it instead.
    $content = Content::factory()->create();
    $first = MediaAsset::factory()->create();
    $second = MediaAsset::factory()->create();

    $content->setFeaturedImage($first);
    expect($content->featuredImage()?->getKey())->toBe($first->getKey());

    $content->setFeaturedImage($second);

    expect($content->mediaAssetsInRole(MediaRole::Featured)->count())->toBe(1)
        ->and($content->featuredImage()?->getKey())->toBe($second->getKey());
});

it('allows many gallery items while the cover stays single', function (): void {
    // Decision D-4: the cover is governed by RULE #7; items are uncapped.
    $gallery = Gallery::factory()->create();
    $cover = MediaAsset::factory()->create();
    $items = MediaAsset::factory()->count(4)->create();

    $gallery->setFeaturedImage($cover);

    foreach ($items as $item) {
        $gallery->attachMediaAsset($item, MediaRole::Gallery);
    }

    expect($gallery->items()->count())->toBe(4)
        ->and($gallery->cover()?->getKey())->toBe($cover->getKey());
});

it('falls back to the featured image for social sharing', function (): void {
    // A shared link rendering with no preview because nobody filled in a second
    // field is a silent failure, so og_image falls back rather than returning null.
    $content = Content::factory()->create();
    $featured = MediaAsset::factory()->create();

    $content->setFeaturedImage($featured);

    expect($content->socialShareImage()?->getKey())->toBe($featured->getKey());

    $override = MediaAsset::factory()->create();
    $content->attachMediaAsset($override, MediaRole::OgImage);

    expect($content->socialShareImage()?->getKey())->toBe($override->getKey());
});

it('shares one media asset across several records', function (): void {
    /*
     * Decision D-3 in one assertion. Spatie Media Library alone cannot do this —
     * its media rows belong to exactly one owner — which is why MediaAsset owns
     * the Spatie media and a polymorphic attachment table does the linking. Losing
     * this would mean duplicated files on disk and divergent alt text.
     */
    $asset = MediaAsset::factory()->create();
    $article = Content::factory()->create();
    $page = Page::factory()->create();

    $article->setFeaturedImage($asset);
    $page->setFeaturedImage($asset);

    expect($article->featuredImage()?->getKey())->toBe($asset->getKey())
        ->and($page->featuredImage()?->getKey())->toBe($asset->getKey());
});
