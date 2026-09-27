<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\TranslationState;
use App\Services\Content\UsageInspector;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
|--------------------------------------------------------------------------
| What a PERMANENT delete leaves behind
|--------------------------------------------------------------------------
|
| A soft delete is judged on what it breaks now; a force delete has to be judged on what it
| breaks on any future restore, because by then there is nothing left to put back.
|
| Every test here covers a case where the first version of this feature did real, silent damage —
| or made a record impossible to delete for ever. `media_attachments`, `content_versions` and
| `translation_states` are all polymorphic tables with NO foreign key, so nothing at the database
| level cascades and every one of them needed a hook.
|
*/

it('releases attachment rows when their owner is destroyed', function (): void {
    /*
     * THE DEAD END. With the attachment row left behind, MediaAsset's featured-image guard counted
     * a reference to a record that no longer existed — so the asset could never be soft-deleted OR
     * force-deleted again by any path, and the refusal told the editor to change the featured image
     * on records that were gone. No exit through the product at all.
     */
    $asset = MediaAsset::factory()->create();
    $article = Content::factory()->published()->create();
    $article->setFeaturedImage($asset);

    $article->forceDelete();

    expect(app(UsageInspector::class)->blockedReason($asset))->toBeNull();

    $asset->delete();

    expect($asset->refresh()->trashed())->toBeTrue();
});

it('keeps attachment rows through a soft delete', function (): void {
    // The other half, and the reason the hook is on `forceDeleted` alone: a trashed article must
    // come back with its image, and the guard must know it still needs one.
    $asset = MediaAsset::factory()->create();
    $article = Content::factory()->published()->create();
    $article->setFeaturedImage($asset);

    $article->delete();

    expect(app(UsageInspector::class)->blockedReason($asset))->not->toBeNull();
});

it('destroys version snapshots and translation states with their owner', function (): void {
    // Both are morphs() tables with no foreign key, so a permanent delete used to leave rows keyed
    // to an id that no longer resolves — in a table whose growth cms:prune-trash exists to bound.
    $article = Content::factory()->create();
    $article->update(['title' => ['fa' => 'عنوان تازه']]);

    expect($article->versions()->count())->toBeGreaterThan(0)
        ->and($article->translationStates()->count())->toBeGreaterThan(0);

    $id = $article->getKey();
    $article->forceDelete();

    expect(ContentVersion::query()->where('versionable_id', $id)->count())->toBe(0)
        ->and(TranslationState::query()->where('translatable_id', $id)->count())->toBe(0);
});

it('refuses to destroy a tag a trashed article still carries', function (): void {
    /*
     * The incoherence this closes. usage() deliberately counts trashed articles, "so restoring one
     * does not find its tag gone" — while permanent deletion was unguarded and cascaded exactly
     * those pivot rows away. The old justification was circular: the cascade removes them BECAUSE
     * the tag is destroyed.
     */
    $tag = Tag::factory()->create();
    $article = Content::factory()->create();
    $article->tags()->attach($tag);
    $article->delete();

    $tag->delete();

    $blocked = app(UsageInspector::class)->blockedFromPermanentDeletion($tag);

    expect($blocked)->not->toBeNull()
        ->and($blocked['key'])->toBe('cms.usage.blocked.restorable_dependents')
        // …and the reversible delete was fine, which is the distinction.
        ->and(app(UsageInspector::class)->blockedReason($tag))->toBeNull();
});

it('refuses to destroy a menu item that still has a live descendant', function (): void {
    /*
     * `menu_items.parent_id` IS a real cascadeOnDelete — the one place in this feature where the
     * database will hard-delete a row nobody asked about. A child restored on its own sits under a
     * still-trashed parent, and destroying that parent took the LIVE child's row with it, including
     * thirty days later via cms:prune-trash.
     */
    $parent = MenuItem::factory()->create(['menu_key' => 'header']);
    $child = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);

    $parent->delete();

    // Restoring the child alone now restores its ancestors too, so reach the old state directly.
    MenuItem::withoutEvents(fn () => $child->restore());

    expect(app(UsageInspector::class)->blockedFromPermanentDeletion($parent))->not->toBeNull();
});

it('restores the ancestors of an item restored on its own', function (): void {
    /*
     * The state above must not be reachable through the panel in the first place. A live child under
     * a trashed parent is invisible: topLevel() excludes it (it has a parent) and the parent's
     * children is unreachable (the parent is hidden), so it vanishes from the menu endpoint with no
     * error — failure #3 arriving from the restore direction. RestoreAction is visible on any
     * trashed row, so this was one click away.
     */
    $grandparent = MenuItem::factory()->create(['menu_key' => 'header']);
    $parent = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $grandparent->getKey()]);
    $child = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);

    $grandparent->delete();
    $child->restore();

    expect($parent->refresh()->trashed())->toBeFalse()
        ->and($grandparent->refresh()->trashed())->toBeFalse();
});

it('refuses to trash the site logo', function (): void {
    /*
     * The logo is an id INSIDE a Setting document, not a `media_attachments` row, so the pivot count
     * is blind to it — and OrganisationProfile::logo() resolves through a scoped query. Trashing the
     * asset dropped `logo` from the Organization JSON-LD in a 200 response, which is what Google
     * reads for article markup, with nothing blocking it and nothing counting it.
     */
    $asset = MediaAsset::factory()->create();
    Setting::put('logo_media_asset_id', $asset->getKey());

    expect(fn () => $asset->delete())->toThrow(ValidationException::class);

    expect($asset->refresh()->trashed())->toBeFalse();
});

it('keeps the asset files until the delete is actually permanent', function (): void {
    /*
     * Media Library registers its own `deleting` listener in bootInteractsWithMedia(), and Eloquent
     * runs bootTraits() BEFORE booted() — so the featured guard used to be the SECOND listener on
     * the force path: the files were removed from disk and then the guard refused, leaving a
     * surviving row pointing at nothing. Hooking `forceDeleting` puts the guard ahead of it by API
     * rather than by boot order.
     */
    $asset = MediaAsset::factory()->withFile()->create();
    Content::factory()->published()->create()->setFeaturedImage($asset);

    expect($asset->getMedia('file'))->toHaveCount(1);

    try {
        $asset->forceDelete();
    } catch (ValidationException) {
        // The refusal is the point; what matters is what survived it.
    }

    expect($asset->refresh()->getMedia('file'))->toHaveCount(1);
});

it('records a permanent deletion as its own audit event', function (): void {
    /*
     * forceDelete() fires `deleted` as well, so a move-to-trash and a destruction used to write an
     * identical row — while cms:prune-trash's docblock and docs/deployment.md both lean on the trail
     * recording that a record WAS destroyed. An auditor could see that something was deleted and had
     * no way to know whether it could still be recovered.
     */
    $tag = Tag::factory()->create();
    $tag->delete();
    $tag->forceDelete();

    expect(Activity::query()->where('event', 'destroyed')->exists())->toBeTrue();
});

it('records a restore', function (): void {
    // Without it the log showed a record being deleted and never showed it coming back, so an audit
    // reader would conclude it was gone. Spatie adds this event itself for a soft-deleting model —
    // the explicit $recordEvents list was silently opting every model out of it.
    $tag = Tag::factory()->create();
    $tag->delete();
    $tag->restore();

    expect(Activity::query()->where('event', 'restored')->exists())->toBeTrue();
});

it('refuses to trash a primary category from outside the panel', function (): void {
    /*
     * This rule used to live only in GuardedDeleteActions, so a seeder, an import, the Management API
     * or any edit page still wired to a plain DeleteAction went straight past it — while three
     * docblocks claimed the model enforced it. UsageInspector returns a value; it refuses nothing.
     */
    $category = Category::factory()->create();
    Content::factory()->published()->create(['primary_category_id' => $category->getKey()]);

    expect(fn () => $category->delete())->toThrow(ValidationException::class);

    expect($category->refresh()->trashed())->toBeFalse();
});

it('counts a trashed article against its primary category', function (): void {
    /*
     * The live-only count left a real hole: the category could be trashed while a TRASHED article
     * still recorded it as primary, and restoring that article produced a live record with
     * `primary_category_id` set and `primaryCategory` resolving to null — a null canonical segment
     * on a published page. Actionable now that the article list has a Deleted filter.
     */
    $category = Category::factory()->create();
    $article = Content::factory()->published()->create(['primary_category_id' => $category->getKey()]);
    $article->delete();

    expect(app(UsageInspector::class)->blockedReason($category))->not->toBeNull();
});

it('leaves an unused record freely destroyable', function (): void {
    // The permissive half, so none of the guards above can be quietly widened into refusing
    // everything and turning the trash into a place records cannot leave.
    $tag = Tag::factory()->create();
    $tag->delete();

    expect(app(UsageInspector::class)->blockedFromPermanentDeletion($tag))->toBeNull();

    $tag->forceDelete();

    expect(Tag::withTrashed()->whereKey($tag->getKey())->exists())->toBeFalse();
});
