<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Tag;
use App\Support\Plural;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| Item 10 — emptying the trash on a timer
|--------------------------------------------------------------------------
|
| The requirement behind this, in the user's own words: a deleted record has to actually be
| deleted eventually. Without a retention sweep "delete" means "hide for ever", the tables grow
| without bound and — for media assets — so does the disk, while an editor believes they have
| cleaned up.
|
| The interesting behaviour is not the deleting. It is what the command REFUSES to delete, and
| the fact that it says so: a force delete is irreversible, and a scheduled task doing harm
| quietly, at night, to records nobody is looking at is the worst possible place for it.
|
*/

it('destroys a record that has been in the trash past the retention window', function (): void {
    $tag = Tag::factory()->create();
    $tag->delete();

    $this->travel(31)->days();

    artisan('cms:prune-trash')->assertSuccessful();

    expect(Tag::withTrashed()->whereKey($tag->getKey())->exists())->toBeFalse();
});

it('leaves a recently deleted record alone', function (): void {
    // Thirty days is chosen against how the mistake is actually discovered: either at once, or
    // when somebody follows a link that used to work — weeks rather than months.
    $tag = Tag::factory()->create();
    $tag->delete();

    $this->travel(5)->days();

    artisan('cms:prune-trash')->assertSuccessful();

    expect(Tag::onlyTrashed()->whereKey($tag->getKey())->exists())->toBeTrue();
});

it('never touches a record that is not in the trash at all', function (): void {
    $tag = Tag::factory()->create();

    $this->travel(400)->days();

    artisan('cms:prune-trash')->assertSuccessful();

    expect($tag->refresh()->trashed())->toBeFalse();
});

it('keeps back a category a trashed article still depends on', function (): void {
    /*
     * The strictest of the guards, and it only applies here. blockedReason() counts LIVE
     * articles, because an editor can act on those and cannot act on one in the trash. A force
     * delete is judged differently: destroying this category would leave the trashed article
     * restorable but WRONG — a null canonical URL segment — and nothing would report it, because
     * the damage is done to a row nobody looks at until the day it comes back.
     */
    $category = Category::factory()->create();
    $article = Content::factory()->create();

    /*
     * A NON-PRIMARY filing. Trashing a category that is somebody's primary one is refused outright
     * by the model now, so the case the permanent-deletion guard exists for is this one: the
     * category is freely deletable, and destroying it would still cascade the pivot row away from a
     * trashed article — which would then come back filed under one fewer category, silently.
     */
    $article->categories()->attach($category);

    // The category goes first and ages past the window; the article is deleted only just now, so it
    // is NOT eligible and cannot be pruned out of the way. That is the situation where the guard is
    // the only thing between a scheduled task and a silently incomplete restore.
    $category->delete();

    $this->travel(60)->days();

    $article->delete();

    Artisan::call('cms:prune-trash');

    expect(Category::onlyTrashed()->whereKey($category->getKey())->exists())->toBeTrue()
        // And it says so, rather than skipping quietly: a record that keeps being kept is a
        // dangling reference somebody should look at.
        ->and(Artisan::output())->toContain(Plural::choice('cms.trash.prune_blocked', 1));
});

it('destroys the article first, which frees the category in the same run', function (): void {
    /*
     * Why the order in PRUNABLE is load-bearing rather than tidy. Content, Page and Gallery hold
     * the references, so pruning them first releases what would otherwise make the taxonomy
     * unprunable — and one scheduled run cleans up a coherent set instead of needing another
     * thirty days to reach the category.
     */
    $category = Category::factory()->create();
    $article = Content::factory()->create();
    $article->categories()->attach($category);

    $article->delete();
    $category->delete();

    // Both past the window, unlike the test above where only the category was eligible.
    $this->travel(60)->days();

    artisan('cms:prune-trash')->assertSuccessful();

    expect(Content::withTrashed()->whereKey($article->getKey())->exists())->toBeFalse()
        ->and(Category::withTrashed()->whereKey($category->getKey())->exists())->toBeFalse();
});

it('keeps back an asset that is still somebody featured image', function (): void {
    /*
     * RULE #7 survives the retention sweep. An asset cannot normally be trashed while it is a
     * featured image, but an install that predates that guard can have one — and destroying it
     * would take the file off disk and leave a published record with no image.
     */
    $asset = MediaAsset::factory()->create();
    $article = Content::factory()->published()->create();
    $article->setFeaturedImage($asset);

    // Forced past the guard, standing in for a row written before it existed.
    MediaAsset::withoutEvents(fn () => $asset->delete());

    $this->travel(60)->days();

    artisan('cms:prune-trash')->assertSuccessful();

    expect(MediaAsset::onlyTrashed()->whereKey($asset->getKey())->exists())->toBeTrue();
});

it('reports what it would do without doing it', function (): void {
    $tag = Tag::factory()->create();
    $tag->delete();

    $this->travel(60)->days();

    artisan('cms:prune-trash', ['--dry-run' => true])->assertSuccessful();

    expect(Tag::onlyTrashed()->whereKey($tag->getKey())->exists())->toBeTrue();
});

it('refuses a retention window of zero days', function (): void {
    /*
     * Zero would destroy a record in the same run that deleted it, which is not a trash at all —
     * and it is the sort of value somebody sets while testing and forgets to put back. Floored
     * at one day.
     */
    config()->set('cms.trash.keep_days', 0);

    $tag = Tag::factory()->create();
    $tag->delete();

    artisan('cms:prune-trash')->assertSuccessful();

    expect(Tag::onlyTrashed()->whereKey($tag->getKey())->exists())->toBeTrue();
});

it('says plainly when there was nothing to do', function (): void {
    // An empty run has to be distinguishable from a run that failed to start, or an operator
    // reading cron output learns to ignore it.
    Artisan::call('cms:prune-trash');

    expect(Artisan::output())->toContain(__('cms.trash.nothing_pruned'));
});
