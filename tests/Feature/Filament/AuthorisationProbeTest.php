<?php

declare(strict_types=1);

use App\Filament\Resources\Contents\Pages\EditContent;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Models\Content;
use App\Models\User;
use App\Support\AuthorisationProbe;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/../../Support/panel-lists.php';

/*
|--------------------------------------------------------------------------
| The denial audit records attempts, not buttons that were correctly hidden
|--------------------------------------------------------------------------
|
| Filament asks the Gate about every row action while it renders a list. Before AuthorisationProbe
| looked at the render phase, a Viewer opening the media library with two files wrote fifty-one
| `denied` rows, and every list did the same in proportion to its rows. These tests hold both halves
| of the rule: rendering is silent, and doing — or trying to — is still recorded.
|
*/

function denialCount(): int
{
    return Activity::query()->where('event', 'denied')->count();
}

it('renders every list for a limited role without auditing a single refusal', function (string $role, string $name): void {
    [$page, $makeRow] = listPagesWithRowFactories()[$name];

    // Rows first, by whoever the factories choose, so the list is full of other people's records.
    $makeRow();
    $makeRow();

    actingAs(User::factory()->{$role}()->create());

    $before = denialCount();

    // A list the role may not open is refused on `viewAny`, which is a navigation check and was
    // never audited; it is rendered anyway so the assertion below covers it too.
    $test = Livewire::test($page);
    $page::getResource()::canAccess() ? $test->assertOk() : $test->assertForbidden();

    expect(denialCount() - $before)->toBe(0, "{$role} rendering the {$name} list was audited as refused");
})->with(['author', 'viewer'])->with(fn (): array => array_keys(listPagesWithRowFactories()));

it('still audits a request for an action the list did not offer', function (): void {
    $other = Content::factory()->create();
    $author = User::factory()->author()->create();

    actingAs($author);

    $list = Livewire::test(ListContents::class);
    $before = denialCount();

    // What a hand-crafted Livewire request for the hidden delete button does.
    $list->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $other->getKey()]);

    $denials = Activity::query()->where('event', 'denied')->latest('id')->limit(denialCount() - $before)->get();

    expect($denials)->toHaveCount(1)
        ->and($denials->first()->properties['ability'] ?? null)->toBe('delete')
        ->and($denials->first()->causer_id)->toBe($author->getKey())
        ->and($denials->first()->subject_id)->toBe($other->getKey())
        ->and($other->fresh()?->trashed())->toBeFalse();
});

it('stays silent on the requests that follow a render, such as a search', function (): void {
    Content::factory()->count(3)->create();

    actingAs(User::factory()->author()->create());

    $list = Livewire::test(ListContents::class);
    $before = denialCount();

    $list->set('tableSearch', 'x')->set('tableSearch', '');

    expect(denialCount() - $before)->toBe(0);
});

it('writes nothing when an author opens their own article, relation panels and all', function (): void {
    $author = User::factory()->author()->create();
    $own = Content::factory()->create(['author_id' => $author->getKey()]);

    actingAs($author);

    $before = denialCount();

    Livewire::test(EditContent::class, ['record' => $own->getKey()])->assertOk();

    expect(denialCount() - $before)->toBe(0);
});

it('still audits opening the edit page of a record the user may not edit', function (): void {
    $other = Content::factory()->create();
    $author = User::factory()->author()->create();

    actingAs($author);

    $before = denialCount();

    Livewire::test(EditContent::class, ['record' => $other->getKey()])->assertForbidden();

    $denial = Activity::query()->where('event', 'denied')->latest('id')->first();

    expect(denialCount() - $before)->toBe(1)
        ->and($denial?->causer_id)->toBe($author->getKey())
        ->and($denial?->properties['ability'] ?? null)->toBe('update');
});

it('is quiet inside quietly() and not after it', function (): void {
    expect(AuthorisationProbe::isQuiet())->toBeFalse()
        ->and(AuthorisationProbe::quietly(fn (): bool => AuthorisationProbe::isQuiet()))->toBeTrue()
        ->and(AuthorisationProbe::isQuiet())->toBeFalse();
});

it('ignores a render-stack entry left behind by a render that threw', function (): void {
    /*
     * Livewire pops its render stack with tap(), not finally, so a throwing render leaves its entry
     * behind. Were that enough to make the probe quiet, every later refusal in the process would go
     * unrecorded. The component stack is popped in finally, so it is empty here and the stale entry
     * must not count.
     */
    $author = User::factory()->author()->create();
    actingAs($author);

    HandleComponents::$renderStack[] = new ListContents;

    try {
        expect(AuthorisationProbe::isQuiet())->toBeFalse();

        $before = denialCount();
        $author->can('publish', Content::factory()->create());

        expect(denialCount() - $before)->toBe(1);
    } finally {
        array_pop(HandleComponents::$renderStack);
    }
});

it('releases quietly() even when the check throws', function (): void {
    expect(fn () => AuthorisationProbe::quietly(fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(AuthorisationProbe::isQuiet())->toBeFalse();
});
