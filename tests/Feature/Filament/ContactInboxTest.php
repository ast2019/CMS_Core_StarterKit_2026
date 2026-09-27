<?php

declare(strict_types=1);

use App\Filament\Resources\ContactSubmissions\Pages\ListContactSubmissions;
use App\Filament\Resources\ContactSubmissions\Pages\ViewContactSubmission;
use App\Filament\Widgets\ContentOverviewWidget;
use App\Models\ContactSubmission;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 16 — the inbox side of spam flagging
|--------------------------------------------------------------------------
|
| Flagging is only half the feature. The other half is that a flagged row leaves the
| editor's working view WITHOUT leaving the system — hidden behind a filter rather than
| excluded by the resource's base query, so a false positive is recoverable.
|
| These tests exist because "hidden" and "gone" look identical until someone needs the row
| back.
|
*/

it('hides flagged submissions from the inbox by default', function (): void {
    actingAs(User::factory()->admin()->create());

    $real = ContactSubmission::factory()->create();
    $spam = ContactSubmission::factory()->spam()->create();

    Livewire::test(ListContactSubmissions::class)
        ->assertCanSeeTableRecords([$real])
        ->assertCanNotSeeTableRecords([$spam]);
});

it('can still reach flagged submissions through the filter', function (): void {
    /*
     * The point of a default on the FILTER rather than a scope on the resource: the
     * checks are heuristics, so the rows they catch have to stay reachable. A base-query
     * scope would make this impossible from the panel at all.
     */
    actingAs(User::factory()->admin()->create());

    $real = ContactSubmission::factory()->create();
    $spam = ContactSubmission::factory()->spam()->create();

    Livewire::test(ListContactSubmissions::class)
        ->filterTable('is_spam', true)
        ->assertCanSeeTableRecords([$spam])
        ->assertCanNotSeeTableRecords([$real]);

    // …and "all messages" means all of them, not a third list.
    Livewire::test(ListContactSubmissions::class)
        ->filterTable('is_spam', null)
        ->assertCanSeeTableRecords([$real, $spam]);
});

it('clears the flag when an editor says a message is not spam', function (): void {
    actingAs(User::factory()->editor()->create());

    $spam = ContactSubmission::factory()->spam('missing_timing')->create();

    Livewire::test(ListContactSubmissions::class)
        ->filterTable('is_spam', true)
        ->callAction(TestAction::make('markNotSpam')->table($spam));

    $spam->refresh();

    expect($spam->is_spam)->toBeFalse()
        // The reason goes with the flag. A row that is not spam while still naming the
        // check it failed leaves the next reader guessing which field to believe.
        ->and($spam->spam_reason)->toBeNull();
});

it('lets an editor flag a message the checks missed', function (): void {
    /*
     * The manual direction matters as much as the automatic one. Without it, an editor's
     * only way to clear obvious junk out of the inbox is deletion — which is admin-only,
     * and destroys the record.
     */
    actingAs(User::factory()->editor()->create());

    $real = ContactSubmission::factory()->create();

    Livewire::test(ListContactSubmissions::class)
        ->callAction(TestAction::make('markSpam')->table($real));

    $real->refresh();

    expect($real->is_spam)->toBeTrue()
        // 'manual' rather than a heuristic's name: a person's judgement is a different and
        // stronger answer than any of the checks.
        ->and($real->spam_reason)->toBe('manual');
});

it('does not let a role without inbox access triage spam', function (): void {
    // An Author holds no `contact.view`, so the whole inbox is out of reach — the triage
    // actions must not be an exception that reaches into it.
    $author = User::factory()->author()->create();
    $spam = ContactSubmission::factory()->spam()->create();

    expect($author->can('triageSpam', $spam))->toBeFalse();
});

it('keeps flagged submissions out of the unread count on the dashboard', function (): void {
    /*
     * A flagged submission is unread and stays unread, because nobody opens it. Counting
     * it would show the editor a number they cannot reduce by reading the inbox — an
     * "inbox zero" that is unreachable by design.
     */
    actingAs(User::factory()->admin()->create());

    ContactSubmission::factory()->count(2)->create();
    ContactSubmission::factory()->count(5)->spam()->create();

    Livewire::test(ContentOverviewWidget::class)
        ->assertSee(LocalizedDate::number(2))
        ->assertDontSee(LocalizedDate::number(7));
});

it('offers triage on the page where the message is actually read', function (): void {
    /*
     * The recovery path this feature is built around is "filter to spam, open it, read it,
     * decide". Without these actions on the view page that path ended on a screen with no
     * indication the record was flagged and no way to clear it, sending the editor back to the
     * list to act on a row they had just finished reading.
     */
    actingAs(User::factory()->editor()->create());

    $spam = ContactSubmission::factory()->spam()->create();

    Livewire::test(ViewContactSubmission::class, ['record' => $spam->getKey()])
        ->callAction('markNotSpam');

    expect($spam->refresh()->is_spam)->toBeFalse();
});

it('does not mark a flagged message read just for being opened', function (): void {
    /*
     * The unread count already excludes spam (scopeNotSpam), so marking a flagged row read
     * achieves nothing an editor can see — while a triage pass through the spam list would
     * quietly rewrite `read_at` on every row it touched. A write with no visible effect,
     * performed by a page view, is worth not doing.
     */
    actingAs(User::factory()->admin()->create());

    $spam = ContactSubmission::factory()->spam()->create();

    Livewire::test(ViewContactSubmission::class, ['record' => $spam->getKey()]);

    expect($spam->refresh()->isRead())->toBeFalse();
});

it('still marks a real message read when it is opened', function (): void {
    // The behaviour the spam exception must not have broken: opening a message is what marks
    // it read, and a second click on every item is what that avoids.
    actingAs(User::factory()->admin()->create());

    $real = ContactSubmission::factory()->create();

    Livewire::test(ViewContactSubmission::class, ['record' => $real->getKey()]);

    expect($real->refresh()->isRead())->toBeTrue();
});

/*
 * There is deliberately no test asserting the triage actions are HIDDEN in the table for an
 * unauthorised role, because no such role can reach the table: `triageSpam` is gated on
 * `contact.view`, which is exactly the ability ContactSubmissionResource::canAccess() requires.
 * A role that may not triage cannot open the inbox at all, so mounting the table to inspect its
 * actions 403s first. The policy assertion above covers the rule; a test that had to be skipped
 * to exist would only look like coverage.
 */

it('renders the spam reason as prose rather than as its storage key', function (): void {
    // The column stores a key because the row outlives the active locale. An editor
    // looking for a mis-flagged enquiry needs the sentence, not `missing_timing`.
    $spam = ContactSubmission::factory()->spam('missing_timing')->create();

    expect($spam->spamReasonLabel())->toBe(__('cms.contact.spam_reason.missing_timing'))
        ->and($spam->spamReasonLabel())->not->toBe('missing_timing');
});

it('falls back to the raw reason when no translation exists yet', function (): void {
    // A check added later must not render as an empty badge before anyone writes its
    // translation — an empty cell reads as "no reason", which is a different claim.
    $spam = ContactSubmission::factory()->spam('some_future_check')->create();

    expect($spam->spamReasonLabel())->toBe('some_future_check');
});
