<?php

declare(strict_types=1);

use App\Enums\MediaRole;
use App\Enums\UserRole;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Tables\GuardedDeleteActions;
use App\Models\Category;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Tag;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use App\Support\Plural;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 10 — the trash, as something a person can actually use
|--------------------------------------------------------------------------
|
| Content, Page and Gallery had soft-deleted since the first migration, and their edit pages
| each carried a RestoreAction and a ForceDeleteAction. But NO table had a trashed filter, so a
| deleted record left the only list that links to its edit page — and ContentResource had no
| route-binding override either, so even a hand-typed URL 404'd. Restoring an article was
| impossible from the panel by any route. The feature was in the codebase and not in the
| product.
|
*/

it('hides deleted records by default and reaches them through the filter', function (): void {
    actingAs(User::factory()->admin()->create());

    $live = Tag::factory()->create();
    $trashed = Tag::factory()->create();
    $trashed->delete();

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$trashed]);

    /*
     * Filament's TrashedFilter maps `false` to "only trashed" — the tri-state is
     * all / without-trashed / only-trashed, and the default is the middle one. Asserted
     * explicitly because the polarity is easy to invert and an inverted default would expose
     * the trash on every list.
     */
    Livewire::test(ListTags::class)
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$live]);
});

it('restores a record from the trash view', function (): void {
    actingAs(User::factory()->admin()->create());

    $tag = Tag::factory()->create();
    $tag->delete();

    Livewire::test(ListTags::class)
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('restore')->table($tag));

    expect($tag->refresh()->trashed())->toBeFalse();
});

it('destroys a record permanently from the trash view', function (): void {
    actingAs(User::factory()->admin()->create());

    $tag = Tag::factory()->create();
    $tag->delete();

    Livewire::test(ListTags::class)
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('forceDelete')->table($tag));

    expect(Tag::withTrashed()->whereKey($tag->getKey())->exists())->toBeFalse();
});

it('opens a trashed record edit page so its restore button is reachable', function (): void {
    /*
     * The missing override that made the whole feature dead for articles. Filament resolves the
     * route binding through the model's default scope, so without withoutGlobalScopes() a
     * trashed record 404s — and the page holding the only buttons that could recover it cannot
     * be opened.
     */
    actingAs(User::factory()->admin()->create());

    $article = Content::factory()->create();
    $article->delete();

    Livewire::test(EditContent::class, ['record' => $article->getKey()])
        ->assertOk()
        ->callAction('restore');

    expect($article->refresh()->trashed())->toBeFalse();
});

it('keeps permanent deletion admin-only', function (): void {
    /*
     * AuthorizesCmsAbilities::forceDelete() is admin-only regardless of role abilities, because
     * it destroys the audit subject along with the record. An Editor may fill the trash and
     * empty it back out, but not past the point of no return.
     */
    actingAs(User::factory()->editor()->create());

    $category = Category::factory()->create();
    $category->delete();

    Livewire::test(EditCategory::class, ['record' => $category->getKey()])
        ->assertActionHidden('forceDelete')
        ->assertActionVisible('restore');
});

it('lets an editor restore a media asset', function (): void {
    /*
     * `media.restore` was missing from the ability matrix while MediaAssetPolicy already
     * inherited a restore() that checks for it — so the ability no role could hold made the
     * action permanently invisible. A trash nobody could recover from.
     */
    $editor = User::factory()->editor()->create();

    expect(UserRole::Editor->hasAbility('media.restore'))->toBeTrue()
        ->and(UserRole::Author->hasAbility('media.restore'))->toBeFalse();

    actingAs($editor);

    $asset = MediaAsset::factory()->create();
    $asset->delete();

    Livewire::test(ListMediaAssets::class)
        ->filterTable('trashed', false)
        ->callAction(TestAction::make('restore')->table($asset));

    expect($asset->refresh()->trashed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Item 11 — the guarded delete, through the panel
|--------------------------------------------------------------------------
*/

it('refuses a delete that would strip a published article of its image', function (): void {
    /*
     * Refused with a sentence rather than allowed to reach the model guard and surface as a
     * validation error on a page with no form. Nothing is half-done: the action halts before the
     * delete.
     */
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->create();
    Content::factory()->published()->create()->setFeaturedImage($asset);

    Livewire::test(ListMediaAssets::class)
        ->callAction(TestAction::make('delete')->table($asset))
        ->assertNotified();

    expect($asset->refresh()->trashed())->toBeFalse();
});

it('deletes what it can and keeps back what it must in a bulk delete', function (): void {
    /*
     * The stock DeleteBulkAction issues one delete per record, so with a model-level guard in
     * place the first blocked record aborts the batch — and the editor cannot tell which of forty
     * rows caused it, or how many were already gone.
     */
    actingAs(User::factory()->admin()->create());

    $blocked = MediaAsset::factory()->create();
    Content::factory()->published()->create()->setFeaturedImage($blocked);

    $free = MediaAsset::factory()->count(2)->create();

    Livewire::test(ListMediaAssets::class)
        ->callTableBulkAction('delete', [$blocked, ...$free])
        ->assertNotified();

    expect($blocked->refresh()->trashed())->toBeFalse();

    foreach ($free as $asset) {
        expect($asset->refresh()->trashed())->toBeTrue();
    }
});

it('allows a delete that only costs an inline attachment', function (): void {
    // The permissive half, so the guard cannot be quietly widened into blocking everything.
    actingAs(User::factory()->admin()->create());

    $asset = MediaAsset::factory()->create();
    Content::factory()->published()->create()->attachMediaAsset($asset, MediaRole::Inline);

    Livewire::test(ListMediaAssets::class)
        ->callAction(TestAction::make('delete')->table($asset));

    expect($asset->refresh()->trashed())->toBeTrue();
});

it('names what a category delete would cost, with a number', function (): void {
    // "Are you sure?" with no number attached is a question nobody can answer.
    $category = Category::factory()->create();
    Content::factory()->count(3)->create()->each(
        fn (Content $article) => $article->categories()->attach($category),
    );

    $description = GuardedDeleteActions::describeConsequence($category);

    // The label carries its own number and plural form, in the reader's own digits.
    expect($description)->toContain(Plural::choice('cms.usage.label.articles', 3))
        ->and($description)->toContain(LocalizedDate::number(3));
});

it('says how many menu children a delete carries with it', function (): void {
    // A cascade is a different warning from a usage count: those items go too, and come back
    // together on a restore.
    $parent = MenuItem::factory()->create(['menu_key' => 'header']);
    MenuItem::factory()->count(2)->create(['menu_key' => 'header', 'parent_id' => $parent->getKey()]);

    expect(GuardedDeleteActions::describeConsequence($parent))
        ->toContain(Plural::choice('cms.trash.cascade', 2));
});

it('does not pad a harmless delete with a reassuring paragraph', function (): void {
    /*
     * Null matters as much as the sentence. If every confirmation carried a consequence note,
     * the note would stop meaning anything on the delete where it does.
     */
    expect(GuardedDeleteActions::describeConsequence(Content::factory()->create()))->toBeNull()
        ->and(GuardedDeleteActions::describeConsequence(Tag::factory()->create()))->toBeNull();
});
