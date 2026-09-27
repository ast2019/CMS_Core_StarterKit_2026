<?php

declare(strict_types=1);

use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\MediaAsset;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 35 — a save no longer silently erases someone else's save
|--------------------------------------------------------------------------
|
| Editor A opens a record; editor B changes it and saves; A saves later from the form they opened
| earlier. Before this, A's save wrote the old text straight back over B's and nothing said so.
|
| Every test opens the form FIRST and then changes the record behind it, because that ordering is
| the whole bug: a form holding a stale copy.
|
*/

it('refuses to save over a change another editor made meanwhile', function (): void {
    $tag = Tag::factory()->create(['name' => ['fa' => 'نسخهٔ اول']]);

    $editorA = User::factory()->editor()->create();
    $editorB = User::factory()->editor()->create();

    actingAs($editorA);
    $page = Livewire::test(EditTag::class, ['record' => $tag->getKey()]);

    // B saves while A's form is open. travel() moves updated_at into a new second.
    $this->travel(5)->seconds();
    actingAs($editorB);
    $tag->refresh()->update(['name' => ['fa' => 'اصلاح ویراستار دوم']]);

    actingAs($editorA);
    $page->fillForm(['name' => ['fa' => 'نسخهٔ ویراستار اول']])
        ->call('save')
        ->assertNotified(__('cms.concurrency.title'));

    // B's change survived: that is the entire point.
    expect($tag->refresh()->getTranslation('name', 'fa'))->toBe('اصلاح ویراستار دوم');
});

it('saves over it when the editor deliberately chooses to', function (): void {
    // The escape hatch has to work, or the warning becomes a wall.
    $tag = Tag::factory()->create(['name' => ['fa' => 'نسخهٔ اول']]);
    $editorA = User::factory()->editor()->create();

    actingAs($editorA);
    $page = Livewire::test(EditTag::class, ['record' => $tag->getKey()]);

    $this->travel(5)->seconds();
    actingAs(User::factory()->editor()->create());
    $tag->refresh()->update(['name' => ['fa' => 'اصلاح ویراستار دوم']]);

    actingAs($editorA);
    $page->fillForm(['name' => ['fa' => 'نسخهٔ ویراستار اول']])
        ->call('saveOverwritingConcurrentEdit');

    expect($tag->refresh()->getTranslation('name', 'fa'))->toBe('نسخهٔ ویراستار اول');
});

it('treats a queued job writing the record as someone else', function (): void {
    /*
     * The AI translation case. TranslateRecordJob runs with no authenticated user and writes the
     * target-locale fields minutes after the click; an editor saving the open form afterwards would
     * write the old, empty translation back over the result they were waiting for.
     */
    $tag = Tag::factory()->create(['name' => ['fa' => 'برچسب']]);
    $editor = User::factory()->editor()->create();

    actingAs($editor);
    $page = Livewire::test(EditTag::class, ['record' => $tag->getKey()]);

    $this->travel(5)->seconds();
    auth()->logout();
    $tag->refresh()->update(['name' => ['fa' => 'برچسب', 'en' => 'Tag']]);

    actingAs($editor);
    $page->call('save')->assertNotified(__('cms.concurrency.title'));

    expect($tag->refresh()->getTranslation('name', 'en', useFallbackLocale: false))->toBe('Tag');
});

it('does not warn an editor about their own earlier save from the same form', function (): void {
    // Re-baselined after each successful save; otherwise the second save of the day would report
    // the first one as a conflict.
    $tag = Tag::factory()->create(['name' => ['fa' => 'یک']]);
    actingAs(User::factory()->editor()->create());

    $page = Livewire::test(EditTag::class, ['record' => $tag->getKey()]);

    $page->fillForm(['name' => ['fa' => 'دو']])->call('save')->assertHasNoFormErrors();

    $this->travel(5)->seconds();

    $page->fillForm(['name' => ['fa' => 'سه']])->call('save');

    expect($tag->refresh()->getTranslation('name', 'fa'))->toBe('سه');
});

it('does not treat a write made by this page itself as a conflict', function (): void {
    /*
     * A version restore, a trash restore, a publish action, the page's own after-save hooks: all
     * move updated_at, all from inside one of this page's own requests. The request boundary is what
     * separates these from a colleague's save — anything written during a request from this page is
     * this page's own.
     */
    $tag = Tag::factory()->create(['name' => ['fa' => 'یک']]);
    $tag->delete();

    actingAs(User::factory()->admin()->create());
    $page = Livewire::test(EditTag::class, ['record' => $tag->getKey()]);

    $this->travel(5)->seconds();

    // Restoring from the trash writes updated_at — through the page, in the page's own request.
    $page->callAction('restore');

    $this->travel(5)->seconds();

    $page->fillForm(['name' => ['fa' => 'دو']])
        ->call('save')
        ->assertNotNotified(__('cms.concurrency.title'));

    expect($tag->refresh()->getTranslation('name', 'fa'))->toBe('دو');
});

it('protects records whose model keeps no audit trail', function (): void {
    /*
     * The first version decided conflicts from the activity log, so on EditUser — User is not audited
     * — it found nobody and let every conflict through. Admin B renamed a user, admin A saved the
     * email from an older form, and the name silently reverted.
     */
    $target = User::factory()->editor()->create(['name' => 'نام اول']);
    $adminA = User::factory()->admin()->create();

    actingAs($adminA);
    $page = Livewire::test(EditUser::class, ['record' => $target->getKey()]);

    $this->travel(5)->seconds();
    $target->refresh()->update(['name' => 'نام تازه از مدیر دوم']);

    actingAs($adminA);
    $page->call('save')->assertNotified(__('cms.concurrency.title'));

    expect($target->refresh()->name)->toBe('نام تازه از مدیر دوم');
});

it('catches a quiet background write that leaves no audit row', function (): void {
    /*
     * ExtractVideoMetadata saveQuietly()s the extracted duration. No activity row is written, so the
     * log-based rule read it as "nobody else" and the editor's next save wrote null over 125.
     */
    $asset = MediaAsset::factory()->create(['duration_seconds' => null]);
    actingAs(User::factory()->admin()->create());

    $page = Livewire::test(EditMediaAsset::class, ['record' => $asset->getKey()]);

    $this->travel(5)->seconds();
    $asset->refresh()->forceFill(['duration_seconds' => 125])->saveQuietly();

    $page->call('save')->assertNotified(__('cms.concurrency.title'));

    expect($asset->refresh()->duration_seconds)->toBe(125);
});

it('is not fooled by an authorisation denial on the record', function (): void {
    /*
     * `denied` audit rows are someone being refused, not an edit. The log-based rule counted them as
     * writes and blocked a legitimate save, naming the refused user as the editor.
     */
    $tag = Tag::factory()->create(['name' => ['fa' => 'یک']]);
    $editor = User::factory()->editor()->create();

    actingAs($editor);
    $page = Livewire::test(EditTag::class, ['record' => $tag->getKey()]);

    activity('cms')->causedBy(User::factory()->author()->create())->performedOn($tag)->event('denied')->log('denied:update');

    $page->fillForm(['name' => ['fa' => 'دو']])
        ->call('save')
        ->assertNotNotified(__('cms.concurrency.title'));

    expect($tag->refresh()->getTranslation('name', 'fa'))->toBe('دو');
});

it('saves normally when nobody else has touched the record', function (): void {
    // The common case must cost nothing but one lookup and must never nag.
    $tag = Tag::factory()->create(['name' => ['fa' => 'یک']]);
    actingAs(User::factory()->editor()->create());

    Livewire::test(EditTag::class, ['record' => $tag->getKey()])
        ->fillForm(['name' => ['fa' => 'دو']])
        ->call('save')
        ->assertNotNotified(__('cms.concurrency.title'));

    expect($tag->refresh()->getTranslation('name', 'fa'))->toBe('دو');
});
