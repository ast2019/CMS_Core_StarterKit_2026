<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Models\Category;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Slide;
use App\Models\Tag;
use App\Models\User;
use App\Services\Content\UsageInspector;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Item 11 — the seven ways a trashed record used to produce wrong output
|--------------------------------------------------------------------------
|
| Adding SoftDeletes to a model is one line. The reason this file exists is that Eloquent
| applies the RELATED model's global scopes, so the moment one of these five became
| soft-deletable every relation pointing at it began resolving to null or to a shorter
| collection — while the foreign keys and pivot rows stayed exactly where they were. A
| database-level cascade does not help either: a soft delete is an UPDATE, so nothing fires.
|
| Every test below is a regression test for a specific WRONG ANSWER rather than for an error.
| That is the whole problem with this change: none of these failures threw anything.
|
*/

it('refuses to trash the featured image of a published record', function (): void {
    /*
     * FAILURE 1, and the worst of them. RULE #7 was enforced only by a form component
     * (MediaAssetPicker::featured) — no model guard, no policy check, no database constraint —
     * so a soft-deleted asset left a published article serving `featured_image: null` inside a
     * 200 response. Nobody would notice until a reader did.
     */
    $asset = MediaAsset::factory()->create();
    $article = Content::factory()->published()->create();
    $article->setFeaturedImage($asset);

    expect(fn () => $asset->delete())->toThrow(ValidationException::class);

    expect($asset->refresh()->trashed())->toBeFalse();
});

it('refuses to destroy the featured image permanently too', function (): void {
    // Permanence makes the problem worse, not exempt — and it would take the file off disk.
    $asset = MediaAsset::factory()->create();
    Content::factory()->published()->create()->setFeaturedImage($asset);

    expect(fn () => $asset->forceDelete())->toThrow(ValidationException::class);

    expect(MediaAsset::withTrashed()->whereKey($asset->getKey())->exists())->toBeTrue();
});

it('allows an asset used only inline to be trashed', function (): void {
    /*
     * The intended asymmetry, asserted so nobody "fixes" the guard into blocking everything. A
     * missing inline image leaves a gap in a paragraph; a missing featured image breaks the
     * card, the Open Graph tag, the JSON-LD and the image sitemap entry at once.
     */
    $asset = MediaAsset::factory()->create();
    Content::factory()->published()->create()->attachMediaAsset($asset, MediaRole::Inline);

    $asset->delete();

    expect($asset->refresh()->trashed())->toBeTrue();
});

it('refuses to trash the primary category of an article', function (): void {
    /*
     * FAILURE 2. The primary category decides the canonical URL and the BreadcrumbList
     * (Decision D-2). Trashed, the trail degrades to "Home > Article" and `articleSection`
     * disappears — output that still VALIDATES, so no consumer would report a thing.
     */
    $category = Category::factory()->create();
    Content::factory()->published()->create(['primary_category_id' => $category->getKey()]);

    $reason = app(UsageInspector::class)->blockedReason($category);

    expect($reason)->not->toBeNull()
        ->and($reason['key'])->toBe('cms.usage.blocked.category_primary')
        ->and(__($reason['key'], $reason['parameters']))->not->toContain(':count');
});

it('carries a menu item subtree into the trash with its parent', function (): void {
    /*
     * FAILURE 3. `menu_items.parent_id` is declared cascadeOnDelete, and that does not fire on
     * a soft delete. So the children kept a parent_id pointing at a hidden row: topLevel()
     * excluded them (they have a parent) and the parent's children was unreachable (the parent
     * is hidden). The whole branch vanished from the menu endpoint with no error.
     */
    $parent = MenuItem::factory()->create(['menu_key' => 'header']);
    $child = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);
    $grandchild = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $child->getKey()]);

    $parent->delete();

    expect($child->refresh()->trashed())->toBeTrue()
        ->and($grandchild->refresh()->trashed())->toBeTrue();
});

it('brings the whole branch back when the parent is restored', function (): void {
    // Otherwise the menu returns visibly shorter than it was and an editor has to find and
    // restore each child by hand, in the right order.
    $parent = MenuItem::factory()->create(['menu_key' => 'header']);
    $child = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);
    $grandchild = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $child->getKey()]);

    $parent->delete();
    $parent->restore();

    expect($child->refresh()->trashed())->toBeFalse()
        ->and($grandchild->refresh()->trashed())->toBeFalse();
});

it('leaves a separately deleted child in the trash when its parent is restored', function (): void {
    /*
     * The pairing that makes the cascade safe to have. A child the editor deleted on its own
     * last week must stay deleted: restoring it would be a decision nobody made, and it would
     * silently put an item back into live navigation. Matched on the shared `deleted_at`
     * instant rather than on parent_id.
     */
    $parent = MenuItem::factory()->create(['menu_key' => 'header']);
    $deliberate = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);
    $incidental = MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);

    $deliberate->delete();

    // A clear gap, so the two deletions cannot share a timestamp by accident.
    $this->travel(5)->minutes();

    $parent->delete();
    $parent->restore();

    expect($incidental->refresh()->trashed())->toBeFalse()
        ->and($deliberate->refresh()->trashed())->toBeTrue();
});

it('keeps a trashed menu branch out of the public menu payload', function (): void {
    // The end-to-end version of failure 3: what a frontend actually receives.
    $parent = MenuItem::factory()->create(['menu_key' => 'header']);
    MenuItem::factory()->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);
    $survivor = MenuItem::factory()->create(['menu_key' => 'header']);

    $parent->delete();

    $response = getJson('/api/v1/menus/header')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($survivor->getKey());
});

it('treats a slug held by a trashed record as taken', function (): void {
    /*
     * FAILURE 4, and the one that produced an unactionable error rather than silence. A trashed
     * row still occupies its slug: the MySQL unique index sits on a generated column extracting
     * the JSON path and knows nothing about `deleted_at`. Checking the default scope meant the
     * application accepted a slug the database would then reject, naming a record the editor
     * cannot see.
     */
    $trashed = Tag::factory()->create(['name' => ['fa' => 'برچسب آزمایشی'], 'slug' => []]);
    $generated = (string) $trashed->getTranslation('slug', 'fa');

    expect($generated)->not->toBe('');

    $trashed->delete();

    // The same name, so the generator proposes the same slug and has to resolve the collision.
    $fresh = Tag::factory()->create(['name' => ['fa' => 'برچسب آزمایشی'], 'slug' => []]);

    expect($fresh->getTranslation('slug', 'fa'))->not->toBe($generated)
        // Suffixed rather than reused: the trashed record may come back, and two records cannot
        // share a URL segment within one locale.
        ->and($fresh->getTranslation('slug', 'fa'))->toBe($generated.'-2');
});

it('refuses a trashed category through the management API', function (): void {
    /*
     * FAILURE 5. `exists:` runs on the query builder, not Eloquent, so no global scope applies
     * and a trashed id satisfied it. The panel's Selects read relations and excluded the trash
     * for free, which left the Management API as the one door open.
     */
    $category = Category::factory()->create();
    $category->delete();

    $user = User::factory()->admin()->create();
    $token = $user->createToken('test', ['manage'])->plainTextToken;

    postJson('/api/v1/manage/news', [
        'title' => ['fa' => 'یک مطلب آزمایشی'],
        'primary_category_id' => $category->getKey(),
    ], ['Authorization' => "Bearer {$token}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['primary_category_id']);
});

it('brings a restored slide back inactive', function (): void {
    /*
     * FAILURE 6 (Requirement 3.5). The five-active cap is enforced on creation and in
     * validation, and a restore reached neither — so deleting an active slide, activating a
     * replacement and restoring the original produced six preloaded hero images on the
     * homepage, which is the performance problem the cap exists to prevent.
     *
     * Forced inactive rather than refused, so the editor keeps the title, subtitle and
     * call-to-action in three languages and decides when it goes back on screen.
     */
    $slide = Slide::factory()->create(['is_active' => true]);
    $slide->delete();

    Slide::factory()->count(Slide::maxSlides())->create(['is_active' => true]);

    $slide->restore();

    expect($slide->refresh()->is_active)->toBeFalse()
        ->and(Slide::query()->where('is_active', true)->count())->toBe(Slide::maxSlides());
});

it('does not throw when an article is filed under a trashed category', function (): void {
    /*
     * FAILURE 7. `$this->categories()` applies Category's global scope, so the membership probe
     * in syncPrimaryCategory() answered "not a member" for a pivot row that was sitting right
     * there — and attach() then hit the composite primary key and threw a raw QueryException,
     * reaching the editor as a database error on save.
     */
    $category = Category::factory()->create();
    $article = Content::factory()->create(['primary_category_id' => $category->getKey()]);
    $article->syncPrimaryCategory();

    /*
     * Forced past Category's own guard, which now refuses this delete outright — so the state below
     * can only arise on an install whose rows predate that guard. Worth keeping a test for exactly
     * because of that: the guard stops NEW occurrences and does nothing for existing ones.
     */
    Category::withoutEvents(fn () => $category->delete());

    // The save re-runs syncPrimaryCategory through the observer chain.
    $article->status = ContentStatus::Draft;

    expect(fn () => $article->save())->not->toThrow(Exception::class);
});

it('counts a trashed article as still depending on its tag', function (): void {
    /*
     * Counts include soft-deleted dependents on purpose. A trashed article keeps its pivot row,
     * so restoring it must not find the tag gone — and a usage figure that ignored the trash
     * would tell an editor a tag was unused moments before it was needed again.
     */
    $tag = Tag::factory()->create();
    $article = Content::factory()->create();
    $article->tags()->attach($tag);
    $article->delete();

    $usage = app(UsageInspector::class)->usage($tag);

    expect($usage)->toBe(['cms.usage.label.articles' => 1]);
});

it('does not claim a leaf menu item is taking children with it', function (): void {
    // descendantKeys() includes the record itself — it exists to keep a node out of its own
    // parent options — so an off-by-one here made every leaf item announce one phantom child.
    $leaf = MenuItem::factory()->create(['menu_key' => 'header']);

    expect(app(UsageInspector::class)->cascadingDescendants($leaf))->toBe([])
        ->and(app(UsageInspector::class)->usage($leaf))->toBe([]);
});
