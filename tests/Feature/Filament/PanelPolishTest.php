<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use App\Enums\PanelNavigationGroup;
use App\Filament\Pages\AuditLog;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\RelationManagers\ContentsRelationManager;
use App\Filament\Resources\ContactSubmissions\ContactSubmissionResource;
use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Filament\Resources\Galleries\GalleryResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Batch 4 — panel polish (items 36, 48, 50, 55, 58, 59)
|--------------------------------------------------------------------------
*/

it('warns before leaving a form with unsaved changes', function (): void {
    // Item 36. Closing a tab mid-article used to lose the work without a word.
    expect(Filament::getPanel('admin')->hasUnsavedChangesAlerts())->toBeTrue();
});

it('remembers a list filter when the editor comes back to the list', function (): void {
    /*
     * Item 48. Every table reset on each visit, so an editor who filtered the list, opened one record
     * and came back had to rebuild the view — once per record.
     */
    actingAs(User::factory()->admin()->create());

    $live = Tag::factory()->create();
    $trashed = Tag::factory()->create();
    $trashed->delete();

    Livewire::test(ListTags::class)->filterTable('trashed', false);

    // A fresh mount stands in for navigating away and back.
    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$trashed])
        ->assertCanNotSeeTableRecords([$live]);
});

it('draws the article list thumbnails without a query per row', function (): void {
    /*
     * Item 50. Without the eager load each thumbnail costs two queries (the attachment, then its
     * file), so fifty articles would be a hundred extra queries to draw fifty small images.
     */
    actingAs(User::factory()->admin()->create());

    foreach (range(1, 6) as $i) {
        Content::factory()->create()->setFeaturedImage(MediaAsset::factory()->create());
    }

    /*
     * Counted on the two tables the thumbnail reads, not on the page total. The page total also
     * carries item 37's per-row translation queries, and a test measuring both would pass or fail
     * for reasons unrelated to the thumbnail.
     */
    $thumbnailQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListContents::class)->assertOk();
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'media_attachments')
                || str_contains($q['query'], '"media"'))
            ->count();
        DB::disableQueryLog();

        return $queries;
    };

    $few = $thumbnailQueries();

    foreach (range(1, 6) as $i) {
        Content::factory()->create()->setFeaturedImage(MediaAsset::factory()->create());
    }

    // Doubling the rows adds no thumbnail query: they are loaded once for the whole page.
    expect($thumbnailQueries())->toBe($few);
});

it('does not let a constrained thumbnail load hide other media roles', function (): void {
    /*
     * The trap the separate relation exists for: HasFeaturedImage trusts `mediaAssets` being loaded
     * to mean EVERY attachment is present. Eager-loading it constrained to the featured role would
     * make a gallery's items vanish for the rest of the request.
     */
    $article = Content::factory()->create();
    $article->setFeaturedImage(MediaAsset::factory()->create());
    $article->attachMediaAsset(MediaAsset::factory()->create(), MediaRole::Inline);

    $loaded = Content::query()->with('featuredImageAssets')->findOrFail($article->getKey());

    expect($loaded->relationLoaded('mediaAssets'))->toBeFalse()
        ->and($loaded->loadedAssetsInRole(MediaRole::Inline))->toHaveCount(1);
});

it('names media types and sizes in the reader language', function (): void {
    // Item 55. The panel showed `image`, `document` and a bare "KB" column in a Persian interface.
    expect(MediaAsset::typeOptions()['image'])->toBe(__('cms.media.type.image'))
        ->and(MediaAsset::typeOptions()['image'])->not->toBe('image');

    $asset = MediaAsset::factory()->make(['size' => 3 * 1024 * 1024]);

    expect($asset->humanSize())->toBe(__('cms.media.size_mb', [
        'size' => LocalizedDate::number(3.0),
    ]));
});

it('offers the trash events as audit filters, in words', function (): void {
    /*
     * Item 55. The filter listed five events by raw English name and knew nothing of `restored` or
     * `destroyed`, so "what was permanently deleted?" — the one question the trash makes worth
     * asking — could not be filtered for.
     */
    $labels = AuditLog::eventLabels();

    expect($labels)->toHaveKeys(['restored', 'destroyed', 'denied'])
        ->and($labels['destroyed'])->not->toBe('destroyed')
        // An unknown event still shows something true rather than nothing.
        ->and(AuditLog::eventLabel('some_package_event'))->toBe('some_package_event');
});

it('puts the inbox under the dashboard with an unread badge', function (): void {
    // Item 58. It sat under "System" next to Redirects, where editors do not look for readers' mail.
    expect(ContactSubmissionResource::getNavigationGroup())->toBeNull();

    ContactSubmission::factory()->count(3)->create();
    ContactSubmission::factory()->spam()->create();

    // Spam is excluded: a flagged message stays unread for ever, so counting it would show a number
    // nobody can clear.
    expect(ContactSubmissionResource::getNavigationBadge())
        ->toBe(LocalizedDate::number(3));
});

it('keeps the cached inbox badge exact when a message is read', function (): void {
    // Cached because it renders on every page for every user; exact because model events clear it.
    $message = ContactSubmission::factory()->create();

    expect(ContactSubmissionResource::getNavigationBadge())->not->toBeNull();

    $message->markRead();

    expect(ContactSubmissionResource::getNavigationBadge())->toBeNull();
});

it('declares the order of the navigation groups', function (): void {
    // Item 59. The order used to be a side effect of $navigationSort values on individual resources.
    expect(PanelNavigationGroup::cases())->toBe([
        PanelNavigationGroup::Content,
        PanelNavigationGroup::Taxonomy,
        PanelNavigationGroup::Media,
        PanelNavigationGroup::Appearance,
        PanelNavigationGroup::System,
    ]);
});

it('gives the media library and galleries icons that can be told apart', function (): void {
    // Media used a folder next to the categories' open folder; galleries had the photo icon.
    expect(MediaAssetResource::getNavigationIcon())->not->toBe(GalleryResource::getNavigationIcon());
});

it('starts a relation manager clean for each owner record', function (): void {
    /*
     * Filament keys persisted table state by component class alone, so a relation manager had one
     * slot for every owner: a "draft" filter set on category A's articles was already applied on
     * category B's, reading convincingly as "this category has nothing published".
     */
    actingAs(User::factory()->admin()->create());

    $a = Category::factory()->create();
    $b = Category::factory()->create();
    $published = Content::factory()->published()->create();
    $published->categories()->attach($b);

    Livewire::test(ContentsRelationManager::class, [
        'ownerRecord' => $a,
        'pageClass' => EditCategory::class,
    ])->filterTable('status', ContentStatus::Draft->value);

    Livewire::test(ContentsRelationManager::class, [
        'ownerRecord' => $b,
        'pageClass' => EditCategory::class,
    ])->assertCanSeeTableRecords([$published]);
});

it('opens the inbox as the inbox every time', function (): void {
    /*
     * The spam filter DEFAULTS to hiding spam, and that default is the feature. Persisted, a "spam
     * only" choice outlived the visit, so the unread badge could lead to a list of nothing but spam.
     */
    actingAs(User::factory()->admin()->create());

    $real = ContactSubmission::factory()->create();
    $spam = ContactSubmission::factory()->spam()->create();

    Livewire::test(ListContactSubmissions::class)
        ->filterTable('is_spam', true);

    Livewire::test(ListContactSubmissions::class)
        ->assertCanSeeTableRecords([$real])
        ->assertCanNotSeeTableRecords([$spam]);
});

it('does not treat a redirect hit as an edit', function (): void {
    /*
     * Eloquent's builder update() adds updated_at to every UPDATE, so each public hit rewrote the
     * redirect's last-modified time — and made the concurrent-edit guard see a change underneath the
     * form on every request while anybody followed the link.
     */
    $redirect = Redirect::factory()->create();
    $before = $redirect->getRawOriginal('updated_at');

    $this->travel(5)->minutes();
    $redirect->recordHit();

    $redirect->refresh();

    expect($redirect->getRawOriginal('updated_at'))->toBe($before)
        ->and($redirect->hits)->toBe(1);
});
