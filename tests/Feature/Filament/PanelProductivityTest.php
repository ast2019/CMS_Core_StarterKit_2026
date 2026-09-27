<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\RelationManagers\ContentsRelationManager;
use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\Contents\ContentResource;
use App\Filament\Resources\Contents\Pages\ListContents;
use App\Filament\Resources\Galleries\GalleryResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Category;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\MediaAsset;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use App\Services\Api\DeliveryCache;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 21 — global search
|--------------------------------------------------------------------------
|
| The search box at the top of the panel found NOTHING: no resource declared
| getGloballySearchableAttributes(), so it rendered and returned empty for every query.
| That is worse than not offering it — an editor tries it, gets nothing, and concludes
| the article is missing.
|
*/

it('finds a record by its title in the current locale', function (): void {
    actingAs(User::factory()->admin()->create());

    // Excerpts pinned: search covers them too, and the factory's random Persian prose can contain
    // the searched word, which made this test fail about once in a hundred runs.
    Content::factory()->published()->create(['title' => ['fa' => 'انتخابات مجلس', 'en' => 'Parliament election'], 'excerpt' => ['fa' => 'خلاصه']]);
    Content::factory()->published()->create(['title' => ['fa' => 'قیمت مسکن'], 'excerpt' => ['fa' => 'خلاصه']]);

    $results = ContentResource::getGlobalSearchResults('انتخابات');

    expect($results)->toHaveCount(1)
        // The heading is the TRANSLATED title. Filament's default reads the attribute
        // straight off the model, which for a JSON column renders `{"fa":"…"}`.
        ->and($results->first()->title)->toBe('انتخابات مجلس');
});

it('does not match every record on a JSON key', function (): void {
    actingAs(User::factory()->admin()->create());

    Content::factory()->published()->create(['title' => ['fa' => 'قیمت مسکن', 'en' => 'Housing prices']]);

    /*
     * The reason Filament's default constraint could not be used. `where(title, 'like',
     * '%en%')` matches the serialised JSON — every record contains the string `"en":` —
     * so searching a locale code would return the whole table. Routing through
     * whereJsonContainsLocale searches the TEXT of one locale.
     */
    expect(ContentResource::getGlobalSearchResults('en'))->toHaveCount(0);
});

it('searches the locale the panel is in, not every translation', function (): void {
    actingAs(User::factory()->admin()->create());

    Content::factory()->published()->create(['title' => ['fa' => 'قیمت مسکن', 'en' => 'Housing prices']]);

    // In Persian, the English title is not what the editor is looking at, so it is not
    // what gets searched.
    app()->setLocale('fa');
    expect(ContentResource::getGlobalSearchResults('Housing'))->toHaveCount(0);

    app()->setLocale('en');
    expect(ContentResource::getGlobalSearchResults('Housing'))->toHaveCount(1);
});

it('makes every content-bearing resource searchable', function (string $resource): void {
    // The whole point is that an editor can find anything from one box; a resource left
    // out is a gap they discover by not finding something.
    expect($resource::getGloballySearchableAttributes())->not->toBeEmpty();
})->with([
    ContentResource::class,
    CategoryResource::class,
    MediaAssetResource::class,
    PageResource::class,
    TagResource::class,
    GalleryResource::class,
]);

/*
|--------------------------------------------------------------------------
| Item 22 — media library search
|--------------------------------------------------------------------------
*/

it('searches the media library by alt text', function (): void {
    actingAs(User::factory()->admin()->create());

    $wanted = MediaAsset::factory()->create(['alt_text' => ['fa' => 'نمای ساختمان مجلس']]);
    $other = MediaAsset::factory()->create(['alt_text' => ['fa' => 'گل آفتابگردان']]);

    /*
     * The library had no searchable column at all, so finding one image among thousands
     * meant paging. Alt text is the right thing to search: Requirement 2.7 makes it
     * mandatory, so every asset has one and it describes the picture in the editor's own
     * words.
     */
    Livewire::test(ListMediaAssets::class)
        ->searchTable('مجلس')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

/*
|--------------------------------------------------------------------------
| Item 23 — bulk publish / unpublish
|--------------------------------------------------------------------------
|
| The `content.publish` ability and ContentPolicy::publish()/unpublish() already existed
| with nothing in the panel wired to them, so publishing was one record at a time through
| the status dropdown inside the form.
|
*/

it('publishes several records at once and gives them a date', function (): void {
    actingAs(User::factory()->admin()->create());

    $drafts = Content::factory()->count(3)->create([
        'status' => ContentStatus::Draft,
        'publish_date' => null,
    ]);

    Livewire::test(ListContents::class)
        ->callTableBulkAction('publish', $drafts);

    foreach ($drafts as $draft) {
        $draft->refresh();

        expect($draft->status)->toBe(ContentStatus::Published)
            /*
             * live() needs BOTH a published status and a past date, so setting the status
             * alone would report success and leave the record invisible — the most
             * confusing outcome this action could produce.
             */
            ->and($draft->publish_date)->not->toBeNull()
            ->and($draft->isLive())->toBeTrue();
    }
});

it('keeps a future publish date when bulk publishing', function (): void {
    actingAs(User::factory()->admin()->create());

    $scheduled = Content::factory()->create([
        'status' => ContentStatus::Draft,
        'publish_date' => now()->addWeek(),
    ]);

    Livewire::test(ListContents::class)
        ->callTableBulkAction('publish', [$scheduled]);

    // An editor who scheduled something and then bulk-published it meant to approve the
    // schedule, not to cancel it.
    expect($scheduled->refresh()->isScheduled())->toBeTrue()
        ->and($scheduled->isLive())->toBeFalse();
});

it('unpublishes back to draft', function (): void {
    actingAs(User::factory()->admin()->create());

    $live = Content::factory()->published()->count(2)->create();

    Livewire::test(ListContents::class)
        ->callTableBulkAction('unpublish', $live);

    foreach ($live as $record) {
        expect($record->refresh()->status)->toBe(ContentStatus::Draft)
            ->and($record->isLive())->toBeFalse();
    }
});

it('leaves records a viewer may not touch alone', function (): void {
    // A Viewer holds no publish ability, so the action must change nothing rather than
    // quietly succeeding.
    actingAs(User::factory()->viewer()->create());

    $draft = Content::factory()->create(['status' => ContentStatus::Draft]);

    Livewire::test(ListContents::class)
        ->callTableBulkAction('publish', [$draft]);

    expect($draft->refresh()->status)->toBe(ContentStatus::Draft);
});

/*
|--------------------------------------------------------------------------
| Item 32 — bulk mark as read
|--------------------------------------------------------------------------
*/

it('marks several contact messages read at once', function (): void {
    actingAs(User::factory()->admin()->create());

    $messages = ContactSubmission::factory()->count(3)->create(['read_at' => null]);

    Livewire::test(ListContactSubmissions::class)
        ->callTableBulkAction('markRead', $messages);

    foreach ($messages as $message) {
        expect($message->refresh()->isRead())->toBeTrue();
    }
});

/*
|--------------------------------------------------------------------------
| Item 33 — two-factor reset
|--------------------------------------------------------------------------
*/

it('resets a locked-out user two-factor enrolment', function (): void {
    actingAs(User::factory()->admin()->create());

    $locked = User::factory()->editor()->create();
    $locked->forceFill([
        'app_authentication_secret' => 'SOMESECRET',
        'app_authentication_recovery_codes' => ['code-one', 'code-two'],
    ])->save();

    expect($locked->hasCompletedMfaEnrolment())->toBeTrue();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('resetTwoFactor')->table($locked));

    $locked->refresh();

    /*
     * BOTH halves, or the account is not actually reset: a recovery code left behind is
     * still a working second factor, so the button would look like it worked while
     * changing nothing for somebody who lost the phone AND the codes.
     */
    expect($locked->hasCompletedMfaEnrolment())->toBeFalse()
        ->and($locked->app_authentication_secret)->toBeNull()
        ->and($locked->app_authentication_recovery_codes)->toBeNull();
});

it('refuses to reset your own two-factor', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->forceFill(['app_authentication_secret' => 'MYSECRET'])->save();

    actingAs($admin);

    // Resetting your own second factor while signed in is how an administrator locks
    // themselves out of the panel that holds the button.
    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('resetTwoFactor')->table($admin));
});

it('deactivates users in bulk but never your own account', function (): void {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $editor = User::factory()->editor()->create(['is_active' => true]);

    Livewire::test(ListUsers::class)
        ->callTableBulkAction('deactivate', [$editor, $admin]);

    expect($editor->refresh()->is_active)->toBeFalse()
        // Deactivating yourself signs you out of the screen holding the button, and there
        // may be no other admin.
        ->and($admin->refresh()->is_active)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Items 26, 30, 31 — relations, reordering, the menu tree
|--------------------------------------------------------------------------
*/

it('lists a category own articles on the category page', function (): void {
    actingAs(User::factory()->admin()->create());

    $category = Category::factory()->create();
    $inside = Content::factory()->published()->create();
    $outside = Content::factory()->published()->create();

    $category->contents()->attach($inside);

    // No resource declared a relation manager before, so answering "what is in this
    // category?" meant leaving for the content list and filtering by it.
    Livewire::test(ContentsRelationManager::class, [
        'ownerRecord' => $category,
        'pageClass' => EditCategory::class,
    ])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('lets pages be reordered by dragging', function (): void {
    actingAs(User::factory()->admin()->create());

    $first = Page::factory()->create(['position' => 1]);
    $second = Page::factory()->create(['position' => 2]);

    // The column existed and was sorted by; ordering meant typing numbers into a form.
    Livewire::test(ListPages::class)
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->refresh()->position)->toBe(2)
        ->and($second->refresh()->position)->toBe(1);
});

it('groups menu items by menu and indents a child under its parent', function (): void {
    actingAs(User::factory()->admin()->create());

    $parent = MenuItem::factory()->create([
        'menu_key' => 'header',
        'label' => ['fa' => 'خدمات'],
        'position' => 1,
    ]);

    $child = MenuItem::factory()->create([
        'menu_key' => 'header',
        'label' => ['fa' => 'مشاوره'],
        'parent_id' => $parent->getKey(),
        'position' => 2,
    ]);

    /*
     * The list was flat and a child's only clue to its place was its parent's name
     * repeated underneath, so reading the shape of a menu meant reconstructing it from a
     * column of names.
     */
    expect($child->level())->toBe(2);

    Livewire::test(ListMenuItems::class)
        ->assertCanSeeTableRecords([$parent, $child])
        // Reordering is what a menu screen is for, so it has to actually apply.
        ->call('reorderTable', [$child->getKey(), $parent->getKey()]);

    expect($parent->refresh()->position)->toBe(2)
        ->and($child->refresh()->position)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The failure modes, not just the happy paths
|--------------------------------------------------------------------------
|
| Every test below covers something a review found actually broken in the first cut of
| this batch. They are the reason the batch changed shape.
|
*/

it('refuses to bulk publish an archived record', function (): void {
    actingAs(User::factory()->admin()->create());

    $archived = Content::factory()->create(['status' => ContentStatus::Archived]);

    Livewire::test(ListContents::class)
        ->callTableBulkAction('publish', [$archived]);

    /*
     * ContentStatus::Archived may only go to Draft, and the Management API enforces it.
     * The first version of this action wrote the `status` column by hand, so the panel
     * became a way around the transition map: an archived article went straight back to
     * live. It goes through transitionTo() now, which refuses.
     */
    expect($archived->refresh()->status)->toBe(ContentStatus::Archived);
});

it('emits the published audit event, not just a generic update', function (): void {
    actingAs(User::factory()->admin()->create());

    $draft = Content::factory()->create(['status' => ContentStatus::Draft, 'publish_date' => null]);

    Livewire::test(ListContents::class)
        ->callTableBulkAction('publish', [$draft]);

    /*
     * "Who put this live" is the question an audit trail on a CMS exists to answer, and
     * writing the column by hand buried it inside a generic `updated` row among every
     * other change. transitionTo() logs the dedicated event.
     */
    expect(Activity::query()->where('event', 'published')->exists())->toBeTrue();
});

it('lets an editor unpublish a page, not only an admin', function (): void {
    actingAs(User::factory()->editor()->create());

    $page = Page::factory()->create(['status' => ContentStatus::Published]);

    Livewire::test(ListPages::class)
        ->callTableBulkAction('unpublish', [$page]);

    /*
     * PagePolicy and GalleryPolicy declared publish() and not unpublish(). Laravel
     * resolves a missing policy method to a DENIAL, and Gate::before only rescues
     * admins — so an Editor who could publish the very same page was told "0 changed,
     * 1 skipped", and each refusal wrote an authorisation-denial row into the audit
     * trail.
     */
    expect($page->refresh()->status)->toBe(ContentStatus::Draft);
});

it('finds a record by two words in either order', function (): void {
    actingAs(User::factory()->admin()->create());

    // Excerpt pinned: the random one sometimes contains «استعفا», the word asserted absent below.
    Content::factory()->published()->create(['title' => ['fa' => 'نتایج انتخابات مجلس ۱۴۰۵'], 'excerpt' => ['fa' => 'خلاصه']]);

    /*
     * Filament splits a search term on whitespace and requires every word. The first
     * version of this trait replaced the method that does the splitting, so a two-word
     * search only matched as an exact phrase — and an editor hunting an article by two
     * remembered words in the wrong order found nothing, which is the failure the item
     * set out to fix.
     */
    expect(ContentResource::getGlobalSearchResults('انتخابات نتایج'))->toHaveCount(1)
        ->and(ContentResource::getGlobalSearchResults('نتایج ۱۴۰۵'))->toHaveCount(1)
        // …and a word that is not there still excludes the record.
        ->and(ContentResource::getGlobalSearchResults('نتایج استعفا'))->toHaveCount(0)
        // The excerpt is searched too, and each word may match a different column.
        ->and(ContentResource::getGlobalSearchResults('خلاصه'))->toHaveCount(1)
        ->and(ContentResource::getGlobalSearchResults('نتایج خلاصه'))->toHaveCount(1);
});

it('searches a non-translatable column too', function (): void {
    actingAs(User::factory()->admin()->create());

    // The branch the trait's translatable check exists for: a plain column must still go
    // through Filament's normal path rather than being pointed at a JSON locale.
    $submission = ContactSubmission::factory()->create(['name' => 'زهرا محمدی']);

    Livewire::test(ListContactSubmissions::class)
        ->searchTable('زهرا')
        ->assertCanSeeTableRecords([$submission]);
});

it('refuses to detach an article from its primary category', function (): void {
    actingAs(User::factory()->admin()->create());

    $category = Category::factory()->create();
    $content = Content::factory()->published()->create(['primary_category_id' => $category->getKey()]);
    $content->syncPrimaryCategory();

    /*
     * `primary_category_id` decides the canonical URL and the breadcrumb trail (D-2), and
     * syncPrimaryCategory() guarantees the primary category is a member of the set.
     *
     * Plain detach broke that invariant, and neither repair was acceptable: calling
     * syncPrimaryCategory() afterwards RE-ATTACHES the category (that is its job), and
     * clearing primary_category_id would change the article's canonical URL as a side
     * effect of tidying a category listing. So it is refused, with the reason attached.
     */
    Livewire::test(ContentsRelationManager::class, [
        'ownerRecord' => $category,
        'pageClass' => EditCategory::class,
    ])->assertTableActionDisabled('detach', $content);

    expect($content->refresh()->categories)->toHaveCount(1);
});

it('skips the primary category when detaching in bulk, and says so', function (): void {
    actingAs(User::factory()->admin()->create());

    $category = Category::factory()->create();

    $primary = Content::factory()->published()->create(['primary_category_id' => $category->getKey()]);
    $primary->syncPrimaryCategory();

    $secondary = Content::factory()->published()->create();
    $category->contents()->attach($secondary);

    Livewire::test(ContentsRelationManager::class, [
        'ownerRecord' => $category,
        'pageClass' => EditCategory::class,
    ])->callTableBulkAction('detachSelected', [$primary, $secondary]);

    // The one that could go, went; the protected one stayed and was reported.
    expect($secondary->refresh()->categories)->toHaveCount(0)
        ->and($primary->refresh()->categories)->toHaveCount(1);
});

it('records a two-factor reset in the audit trail', function (): void {
    $admin = User::factory()->admin()->create();
    actingAs($admin);

    $locked = User::factory()->editor()->create();
    $locked->forceFill(['app_authentication_secret' => 'SECRET'])->save();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('resetTwoFactor')->table($locked));

    /*
     * User does NOT use IsAuditable, so the model write logs nothing by itself — an
     * earlier comment here claimed otherwise. Stripping another account's second factor
     * is the most attack-relevant operation this panel offers: a compromised admin
     * session could clear MFA across every account, and without this row there would be
     * no trace of it.
     */
    $entry = Activity::query()->where('event', 'two_factor_reset')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($admin->getKey())
        ->and($entry->subject_id)->toBe($locked->getKey());
});

it('records a deactivation in the audit trail', function (): void {
    actingAs(User::factory()->admin()->create());

    $editor = User::factory()->editor()->create(['is_active' => true]);

    Livewire::test(ListUsers::class)
        ->callTableBulkAction('deactivate', [$editor]);

    // Deactivation is how access is revoked, so it must leave a trail.
    expect(Activity::query()->where('event', 'deactivated')->exists())->toBeTrue();
});

it('says so when a bulk action changed nothing', function (): void {
    actingAs(User::factory()->admin()->create());

    $live = Content::factory()->published()->create();

    // Publishing something already published is neither a change nor a refusal, and
    // reporting "0 published" with no explanation is how somebody concludes the button
    // is broken.
    Livewire::test(ListContents::class)
        ->callTableBulkAction('publish', [$live])
        ->assertNotified();

    expect($live->refresh()->status)->toBe(ContentStatus::Published);
});

it('invalidates the Delivery cache when pages are reordered', function (): void {
    actingAs(User::factory()->admin()->create());

    config()->set('cache.default', 'array');
    config()->set('cms.api.delivery.cache_ttl', 300);

    $first = Page::factory()->create(['position' => 1]);
    $second = Page::factory()->create(['position' => 2]);

    $cache = app(DeliveryCache::class);
    $cache->remember($cache->key('pages.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['stale']);

    /*
     * Reordering is a single UPDATE on the query builder, so no model is instantiated and
     * no observer fires — yet `position` is part of the public page payload. Without the
     * afterReordering() hook the new order was served stale until the TTL expired.
     */
    Livewire::test(ListPages::class)
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($cache->remember($cache->key('pages.index', 'fa'), [DeliveryCache::TAG_CONTENT], fn (): array => ['fresh']))
        ->toBe(['fresh']);
});
