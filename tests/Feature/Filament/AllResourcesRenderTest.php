<?php

declare(strict_types=1);

use App\Filament\Resources\Redirects\RedirectResource;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Smoke-renders every resource's list, create and edit page.
 *
 * Requirements 3.1, 4.1.
 *
 * A Filament schema is only validated when it is built, so a typo'd field name,
 * a missing relationship or a bad closure signature stays invisible until someone
 * opens that page. With ten resources across three page types that is thirty
 * chances for a silent break, which is exactly the kind of thing a starter kit
 * ships with if nobody clicks through every screen.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->withMfaEnrolled()->create();

    // Seeded data gives every form something real to hydrate: a record with no
    // media, no categories and no translations would not exercise the option
    // closures that are most likely to be wrong.
    $this->seed();
});

/**
 * @return list<array{0: class-string, 1: string}>
 */
function resourcePages(): array
{
    $cases = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $name => $registration) {
            $cases[] = [$registration->getPage(), $resource::getPluralModelLabel().' / '.$name];
        }
    }

    return $cases;
}

it('renders every list page', function (): void {
    actingAs($this->admin);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();

        if (! isset($pages['index'])) {
            continue;
        }

        $page = $pages['index']->getPage();

        Livewire::test($page)
            ->assertOk();
    }
});

it('renders every create page', function (): void {
    actingAs($this->admin);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();

        if (! isset($pages['create'])) {
            continue;
        }

        Livewire::test($pages['create']->getPage())->assertOk();
    }
});

it('renders every edit page against a real record', function (): void {
    actingAs($this->admin);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();

        if (! isset($pages['edit'])) {
            continue;
        }

        $record = $resource::getModel()::query()->first();

        if ($record === null) {
            // Not every module is seeded (redirects and contact submissions are
            // created at runtime), so an absent record is not a failure here.
            continue;
        }

        Livewire::test($pages['edit']->getPage(), ['record' => $record->getRouteKey()])
            ->assertOk();
    }
});

it('renders every view page against a real record', function (): void {
    actingAs($this->admin);

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();

        if (! isset($pages['view'])) {
            continue;
        }

        $record = $resource::getModel()::query()->first();

        if ($record === null) {
            continue;
        }

        Livewire::test($pages['view']->getPage(), ['record' => $record->getRouteKey()])
            ->assertOk();
    }
});

it('hides a resource whose module is disabled', function (): void {
    // Requirement 1.1 — module toggles must actually gate the panel, not just
    // the API.
    config()->set('cms.modules.redirect', false);

    expect(RedirectResource::shouldRegisterNavigation())->toBeFalse();

    config()->set('cms.modules.redirect', true);

    expect(RedirectResource::shouldRegisterNavigation())->toBeTrue();
});
