<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Pages\AuditLog;
use App\Models\Content;
use App\Models\User;
use App\Support\AuditRedaction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/**
 * RULE #8 and Requirement 3.7.
 */
it('renders the audit log page for an administrator', function (): void {
    $admin = User::factory()->admin()->create();
    Content::factory()->create();

    actingAs($admin);

    Livewire::test(AuditLog::class)->assertOk();
});

it('restricts the audit log to administrators', function (): void {
    // Decision D-10 — `audit.view` is granted to Admin alone. An editor who could
    // read the trail could also see which of their colleagues' actions were logged.
    expect(UserRole::Admin->hasAbility('audit.view'))->toBeTrue()
        ->and(UserRole::Editor->hasAbility('audit.view'))->toBeFalse()
        ->and(UserRole::Author->hasAbility('audit.view'))->toBeFalse()
        ->and(UserRole::Viewer->hasAbility('audit.view'))->toBeFalse();

    actingAs(User::factory()->editor()->create());

    expect(AuditLog::canAccess())->toBeFalse();
});

it('records an authorisation denial with the ability and actor', function (): void {
    // Requirement 9.2.
    $author = User::factory()->author()->create();
    $other = Content::factory()->create();

    actingAs($author);

    // An Author may not publish, and may not edit someone else's article.
    expect($author->can('publish', $other))->toBeFalse();

    $denial = Activity::query()
        ->where('event', 'denied')
        ->latest('id')
        ->first();

    expect($denial)->not->toBeNull()
        ->and($denial->causer_id)->toBe($author->getKey())
        ->and($denial->properties['ability'] ?? null)->toBe('publish');
});

it('does not flood the trail with denials from rendering navigation', function (): void {
    /*
     * A panel renders its menu by asking whether each resource is viewable, so
     * logging view denials would emit a handful of rows on every page load by a
     * limited-role user — entries that represent nothing but a correctly hidden
     * menu item, drowning the writes the trail exists to record.
     */
    $viewer = User::factory()->viewer()->create();

    actingAs($viewer);

    $viewer->can('settings.manage');   // a real action: should be logged
    $viewer->can('content.view');      // a render-time check: should not be
    $viewer->can('panel.access');

    $denials = Activity::query()->where('event', 'denied')->get();

    expect($denials)->toHaveCount(1)
        ->and($denials->first()->properties['ability'])->toBe('settings.manage');
});

it('snapshots the previous state on update and can restore it', function (): void {
    // Requirement 3.7.
    $content = Content::factory()->create(['title' => ['fa' => 'عنوان اولیه']]);

    expect($content->versions()->count())->toBe(0);

    $content->setTranslation('title', 'fa', 'عنوان دوم');
    $content->save();

    expect($content->versions()->count())->toBe(1);

    $version = $content->versions()->firstOrFail();

    // The snapshot holds the state BEFORE the update, so it is something to roll
    // back to. Storing the new state would make restore a no-op.
    // HasContentVersions guarantees translatable values are stored as locale
    // maps, so consumers never have to handle two shapes.
    expect($version->payload['title'])->toBeArray()
        ->and($version->payload['title']['fa'])->toBe('عنوان اولیه');

    $content->restoreVersion($version);

    expect($content->fresh()->getTranslation('title', 'fa'))->toBe('عنوان اولیه');
});

it('keeps a restore reversible by versioning the state it replaced', function (): void {
    $content = Content::factory()->create(['title' => ['fa' => 'نسخه یک']]);

    $content->setTranslation('title', 'fa', 'نسخه دو');
    $content->save();

    $first = $content->versions()->firstOrFail();

    $content->restoreVersion($first);

    // The restore is itself an update, so it produced a version capturing what it
    // replaced. That property is what makes offering restore to editors safe.
    expect($content->versions()->count())->toBe(2);

    $latest = $content->versions()->orderByDesc('version_number')->firstOrFail();
    expect($latest->payload['title']['fa'])->toBe('نسخه دو');
});

it('prunes versions beyond the configured limit', function (): void {
    config()->set('cms.versions.keep', 3);

    $content = Content::factory()->create();

    foreach (range(1, 6) as $i) {
        $content->setTranslation('title', 'fa', "عنوان {$i}");
        $content->save();
    }

    // Each snapshot is a full copy of every translatable field across three
    // locales, including the TipTap body, so unbounded growth is significant.
    expect($content->versions()->count())->toBe(3);
});

it('never writes a secret into the audit trail', function (): void {
    $user = User::factory()->admin()->create();

    actingAs($user);

    $content = Content::factory()->create();
    $content->setTranslation('title', 'fa', 'تغییر');
    $content->save();

    $properties = Activity::query()->pluck('properties')->toJson();

    foreach (AuditRedaction::ALWAYS_EXCLUDED as $secret) {
        expect(str_contains($properties, $secret))->toBeFalse(
            "The audit trail must never contain [{$secret}]."
        );
    }
});
