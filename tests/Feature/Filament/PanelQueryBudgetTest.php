<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Enums\TranslationStatus;
use App\Filament\Pages\EditorialCalendar;
use App\Filament\Pages\TranslationReview;
use App\Filament\Resources\Contents\ContentResource;
use App\Filament\Resources\Galleries\Pages\ListGalleries;
use App\Filament\Resources\Slides\Pages\ListSlides;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Slide;
use App\Models\User;
use App\Support\TranslationBacklog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../../Support/panel-lists.php';

/*
|--------------------------------------------------------------------------
| Items 37, 38, 39 — a list costs the same number of queries at any length
|--------------------------------------------------------------------------
|
| Measured rather than inspected. The article list issued one query per row per non-source locale
| for its translations column, the gallery list one per row for its item count, the slide list one per
| row to ask which slide is first, and the category list one per child row for its parent's name.
| None of them was wrong, and none of them showed up with the ten rows a developer tests with. At
| fifty rows the article list alone was a hundred extra queries.
|
| The test is the same for every list: render it, add rows, render it again, and require that the
| query count did not grow with the rows. It covers EVERY resource list, not only the four that were
| fixed, so the next column somebody adds with a lazy relation fails here instead of in production.
|
*/

function queriesToRender(string $page): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test($page)->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('renders every list in a number of queries that does not grow with its rows', function (string $name): void {
    actingAs(User::factory()->admin()->create());

    [$page, $makeRow] = listPagesWithRowFactories()[$name];

    foreach (range(1, 3) as $i) {
        $makeRow();
    }

    $few = queriesToRender($page);

    foreach (range(1, 6) as $i) {
        $makeRow();
    }

    // Twice as many rows on the page and not one more query.
    expect(queriesToRender($page))->toBe($few, "the {$name} list issues a query per row");
})->with(array_keys(listPagesWithRowFactories()));

it('renders the editorial calendar in a number of queries that does not grow with its entries', function (): void {
    // Item 43. Not a table, but a month of a daily newsroom is hundreds of entries, each with a
    // type label, an edit link and an authorisation check — the same per-row trap as any list.
    $this->travelTo(CarbonImmutable::parse('2026-09-27 09:00:00', 'UTC'));
    actingAs(User::factory()->author()->create());

    $makeRows = function (): void {
        $date = fn (): CarbonImmutable => CarbonImmutable::parse('2026-10-0'.random_int(1, 9).' 08:00:00', 'UTC');

        Content::factory()->create(['status' => ContentStatus::Published, 'publish_date' => $date()]);
        Content::factory()->create(['status' => ContentStatus::Draft, 'publish_date' => $date()]);
        Page::factory()->create(['status' => ContentStatus::Published, 'publish_date' => $date()]);
        Gallery::factory()->create(['status' => ContentStatus::Published, 'publish_date' => $date()]);
    };

    $makeRows();
    $few = queriesToRender(EditorialCalendar::class);

    foreach (range(1, 4) as $i) {
        $makeRows();
    }

    expect(queriesToRender(EditorialCalendar::class))->toBe($few, 'the editorial calendar issues a query per entry');
});

it('names the first active slide without asking the database per row', function (): void {
    // Item 37 family: the answer is the same for every row, so it is computed once per render.
    actingAs(User::factory()->admin()->create());

    $first = Slide::factory()->create(['is_active' => true, 'position' => 1]);
    Slide::factory()->create(['is_active' => true, 'position' => 2]);

    Livewire::test(ListSlides::class)
        ->assertSee(__('cms.slide.preloaded'))
        ->assertTableColumnFormattedStateSet('title', $first->getTranslation('title', app()->getLocale()), $first);
});

it('counts gallery items with the same rules as the gallery itself', function (): void {
    // Item 39: withCount('items') uses the same relation as Gallery::itemCount(), so a trashed asset
    // is not counted in the list either.
    actingAs(User::factory()->admin()->create());

    $gallery = Gallery::factory()->create();
    $gallery->attachMediaAsset(MediaAsset::factory()->create(), MediaRole::Gallery);
    $trashed = MediaAsset::factory()->create();
    $gallery->attachMediaAsset($trashed, MediaRole::Gallery);
    $trashed->delete();

    expect($gallery->itemCount())->toBe(1);

    Livewire::test(ListGalleries::class)
        ->assertTableColumnStateSet('items_count', 1, $gallery);
});

/*
|--------------------------------------------------------------------------
| Item 38 — the navigation badges
|--------------------------------------------------------------------------
*/

it('does not recount the translation backlog on every page', function (): void {
    /*
     * The badges render on every page for every user. Computed once, they must not query again until
     * something changes.
     */
    Content::factory()->create();

    ContentResource::getNavigationBadge();
    TranslationReview::getNavigationBadge();

    DB::flushQueryLog();
    DB::enableQueryLog();
    ContentResource::getNavigationBadge();
    TranslationReview::getNavigationBadge();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('moves the backlog badges the moment a translation is reviewed', function (): void {
    /*
     * Cached but exact: the cache is cleared by the writes that move the number, so a translator
     * finishing an article sees the badge drop on their next click rather than ten minutes later.
     */
    $article = Content::factory()->create();

    expect(TranslationBacklog::statesNeedingWork())->toBe(2)
        ->and(TranslationBacklog::articlesNeedingWork())->toBe(1);

    foreach ($article->translationStates as $state) {
        $state->update(['status' => TranslationStatus::Reviewed]);
    }

    expect(TranslationBacklog::statesNeedingWork())->toBe(0)
        ->and(TranslationBacklog::articlesNeedingWork())->toBe(0);
});

it('drops an article from the badge when it goes to the trash, and back when restored', function (): void {
    // The article badge counts only articles outside the trash, so a trash move changes it without
    // any translation state being written — which is why the trash lifecycle clears it too.
    $article = Content::factory()->create();

    expect(TranslationBacklog::articlesNeedingWork())->toBe(1);

    $article->delete();
    expect(TranslationBacklog::articlesNeedingWork())->toBe(0);

    $article->restore();
    expect(TranslationBacklog::articlesNeedingWork())->toBe(1);
});

it('drops a destroyed article from the review badge', function (): void {
    // The states of a destroyed record go in one query-builder delete, which fires no model events —
    // so the badge is told directly.
    $article = Content::factory()->create();

    expect(TranslationBacklog::statesNeedingWork())->toBe(2);

    $article->forceDelete();

    expect(TranslationBacklog::statesNeedingWork())->toBe(0);
});
