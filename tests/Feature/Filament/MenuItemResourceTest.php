<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Schemas\MenuItemForm;
use App\Models\Content;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * The menu form's guarantees. Requirement 3.1.
 *
 * Four configurations used to save cleanly in the panel and then behave as though
 * the save had not happened: an item with no destination at all, an item pointing at
 * an id that does not exist, a two-node cycle, and a branch deeper than the renderer
 * would ever walk. None of them produced an error, and all of them made items
 * disappear from the API.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();

    actingAs($this->admin);
});

it('creates a raw-link item', function (): void {
    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'label' => ['fa' => 'خانه'],
            'menu_key' => 'header',
            'link' => '/fa',
            'position' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MenuItem::query()->count())->toBe(1);
});

it('refuses an item with no destination at all', function (): void {
    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'label' => ['fa' => 'بیمقصد'],
            'menu_key' => 'header',
        ])
        ->call('create')
        ->assertHasFormErrors(['link']);

    expect(MenuItem::query()->count())->toBe(0);
});

it('requires a record once a target type is chosen', function (): void {
    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'label' => ['fa' => 'بیهدف'],
            'menu_key' => 'header',
            'linkable_type' => Page::class,
        ])
        ->call('create')
        ->assertHasFormErrors(['linkable_id']);
});

it('refuses a target id that does not exist for the chosen type', function (): void {
    /*
     * linkable_id was built from a manual options array rather than
     * ->relationship(), so nothing validated it: an id belonging to another type, or
     * to a record since deleted, saved happily and the item then resolved to nothing.
     */
    $content = Content::factory()->published()->create();

    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'label' => ['fa' => 'هدف اشتباه'],
            'menu_key' => 'header',
            'linkable_type' => Page::class,
            // A real key, but of the wrong type.
            'linkable_id' => $content->getKey() + 9_000,
        ])
        ->call('create')
        ->assertHasFormErrors(['linkable_id']);
});

it('refuses to nest an item under its own child', function (): void {
    /*
     * The parent options excluded self and nothing else, so A→B plus B→A was
     * savable — after which neither row is topLevel() and the whole branch vanishes
     * from the API with nothing in the panel to explain why.
     */
    $parent = MenuItem::factory()->create();
    $child = MenuItem::factory()->childOf($parent)->create();

    Livewire::test(EditMenuItem::class, ['record' => $parent->getRouteKey()])
        ->fillForm(['parent_id' => $child->getKey()])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);

    expect($parent->fresh()?->parent_id)->toBeNull();
});

it('refuses to nest an item under itself', function (): void {
    $item = MenuItem::factory()->create();

    Livewire::test(EditMenuItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['parent_id' => $item->getKey()])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);
});

it('refuses a branch deeper than the renderer will walk', function (): void {
    $level1 = MenuItem::factory()->create();
    $level2 = MenuItem::factory()->childOf($level1)->create();
    $level3 = MenuItem::factory()->childOf($level2)->create();

    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'label' => ['fa' => 'سطح چهارم'],
            'menu_key' => 'header',
            'link' => '/fa/too-deep',
            'parent_id' => $level3->getKey(),
        ])
        ->call('create')
        ->assertHasFormErrors(['parent_id']);

    // One level higher is fine, so the limit is a limit and not a blanket refusal.
    Livewire::test(CreateMenuItem::class)
        ->fillForm([
            'label' => ['fa' => 'سطح سوم دیگر'],
            'menu_key' => 'header',
            'link' => '/fa/deep-enough',
            'parent_id' => $level2->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

it('refuses to move a branch that would push its own children too deep', function (): void {
    // Moving a node moves its subtree: re-parenting a two-level branch under a
    // level-2 item would leave grandchildren at level 4, which the renderer truncates.
    $level1 = MenuItem::factory()->create();
    $level2 = MenuItem::factory()->childOf($level1)->create();

    $branchRoot = MenuItem::factory()->create();
    MenuItem::factory()->childOf($branchRoot)->create();

    Livewire::test(EditMenuItem::class, ['record' => $branchRoot->getRouteKey()])
        ->fillForm(['parent_id' => $level2->getKey()])
        ->call('save')
        ->assertHasFormErrors(['parent_id']);
});

it('finds a target beyond the first hundred records of its type', function (): void {
    /*
     * The field used to load `->limit(100)->get()` into a static options array and
     * let the browser filter it, so on a site with more than 100 pages the rest were
     * simply unlinkable — and the search box reported nothing rather than admitting
     * the list was truncated.
     */
    Page::factory()->count(100)->create();

    $needle = Page::factory()->create(['title' => ['fa' => 'صفحهٔ یگانه برای جستوجو']]);

    $options = MenuItemForm::targetOptions(Page::class, like: 'یگانه');

    expect($options)->toHaveKey($needle->id)
        ->and($options)->toHaveCount(1);
});

it('marks a target that will not produce a live URL', function (): void {
    // Drafts stay selectable on purpose — a menu is normally built alongside the
    // pages it points at — but the editor is told, because the API drops them.
    $draft = Content::factory()->create([
        'title' => ['fa' => 'خبر پیشنویس'],
        'status' => ContentStatus::Draft,
    ]);

    $label = MenuItemForm::targetOptions(Content::class, key: $draft->id)[$draft->id] ?? '';

    expect($label)->toContain('خبر پیشنویس')
        ->and($label)->toContain(__('cms.menu.target_not_live'));
});

it('clears the raw link when an item is re-pointed at a record', function (): void {
    /*
     * The editor-visible half of the both-set defect: `link` is hidden once a type is
     * chosen, and Filament does not dehydrate a hidden field, so the old URL survived
     * the save — and resolveUrl() checked it first, making the change look like it had
     * not been applied at all.
     */
    $item = MenuItem::factory()->create(['link' => '/fa/hardcoded']);
    $page = Page::factory()->create(['title' => ['fa' => 'درباره ما']]);

    Livewire::test(EditMenuItem::class, ['record' => $item->getRouteKey()])
        ->fillForm([
            'linkable_type' => Page::class,
            'linkable_id' => $page->getKey(),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();

    expect($item->link)->toBeNull()
        ->and($item->linkable_id)->toBe($page->getKey())
        ->and($item->resolveUrl('fa'))->toBe('/fa/'.$page->getTranslation('slug', 'fa'));
});
