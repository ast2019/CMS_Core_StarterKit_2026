<?php

declare(strict_types=1);

use App\Enums\ContentStatus;
use App\Filament\Widgets\ContentOverviewWidget;
use App\Filament\Widgets\SystemStatusWidget;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Models\Page;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Support\Dates\LocalizedDate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Item 18 — reporting the heartbeat where someone will read it
|--------------------------------------------------------------------------
|
| A heartbeat table nobody looks at answers nothing. What is under test here is the
| DIAGNOSIS: that each card names the right culprit, and that the one consequence an editor
| actually has — a scheduled publish that will not happen — reaches the editor rather than
| only the administrator.
|
*/

/**
 * Backdate a heartbeat so it reads as stale, without waiting.
 */
function staleHeartbeat(string $key): void
{
    SystemHeartbeat::record($key);

    SystemHeartbeat::query()
        ->where('key', $key)
        ->update(['last_seen_at' => now()->subSeconds(SystemHeartbeat::staleAfterSeconds() + 60)]);
}

it('reports both subsystems running when both have reported recently', function (): void {
    actingAs(User::factory()->admin()->create());

    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::record(SystemHeartbeat::QUEUE, ['lag_seconds' => 2]);

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.running'))
        ->assertDontSee(__('cms.system.status.stopped'));
});

it('reports the scheduler stopped and names the fix', function (): void {
    // "Stopped" on its own sends an administrator looking for a switch in the panel that
    // does not and should not exist, so the card has to say where the remedy is.
    actingAs(User::factory()->admin()->create());

    staleHeartbeat(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::record(SystemHeartbeat::QUEUE);

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.stopped'))
        ->assertSee(__('cms.system.status.scheduler_stopped_help'));
});

it('blames the queue only when the scheduler is proving it can be reached', function (): void {
    /*
     * The precise diagnosis this widget exists for. The queue stamp is written by a job the
     * SCHEDULER dispatches, so a live scheduler with a stale queue means the worker is dead
     * — and that is the only circumstance in which saying so is true.
     */
    actingAs(User::factory()->admin()->create());

    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);
    staleHeartbeat(SystemHeartbeat::QUEUE);

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.queue_stopped_help'))
        ->assertDontSee(__('cms.system.status.queue_unknown_help'));
});

it('reports the queue as unknown rather than stopped when cron is the problem', function (): void {
    /*
     * With the scheduler stopped, nothing is dispatched, so there is nothing to measure.
     * Reporting "queue stopped" here would send an operator to restart a worker that was
     * never at fault — a monitor confidently pointing at the wrong thing is worse than one
     * admitting it does not know.
     */
    actingAs(User::factory()->admin()->create());

    staleHeartbeat(SystemHeartbeat::SCHEDULER);
    staleHeartbeat(SystemHeartbeat::QUEUE);

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.queue_unknown_help'))
        ->assertDontSee(__('cms.system.status.queue_stopped_help'));
});

it('reports a fresh install with no heartbeats as not running', function (): void {
    // Exactly the deployment mistake this catches: cron was never wired up. Reading "no
    // data" as healthy would hide it behind a green card.
    actingAs(User::factory()->admin()->create());

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.stopped'))
        ->assertSee(__('cms.system.status.queue_unknown_help'));
});

it('warns when the cache store cannot do tags', function (): void {
    /*
     * A tagless store turns DeliveryCache's targeted invalidation into Cache::flush(), so
     * every editorial save discards the whole store — rate-limiter counters included. The
     * site stays correct, which is why this is a warning and not an error, and also why
     * nothing else would ever mention it.
     */
    actingAs(User::factory()->admin()->create());

    config()->set('cache.default', 'file');

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.cache_tags_missing'));
});

it('shows the queue lag the worker reported', function (): void {
    // A worker that is running but a minute behind is a capacity problem, and it looks
    // identical to a healthy one if the card only says "seen just now".
    actingAs(User::factory()->admin()->create());

    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);
    SystemHeartbeat::record(SystemHeartbeat::QUEUE, ['lag_seconds' => 47]);

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.queue_lag', [
            'seconds' => LocalizedDate::number(47),
        ]));
});

it('keeps infrastructure status out of a non-admin dashboard', function (): void {
    /*
     * Every remedy here is a server-side change. A dashboard of red warnings an editor
     * cannot act on is noise that teaches them to ignore the cards that do concern them.
     */
    actingAs(User::factory()->editor()->create());

    expect(SystemStatusWidget::canView())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The editor-facing half
|--------------------------------------------------------------------------
*/

it('stops promising a publish date the scheduler cannot keep', function (): void {
    /*
     * With cron stopped, this card was the most misleading thing on the dashboard: it named
     * a date, in the editor's own calendar, for a publish that would never happen, and
     * nothing anywhere contradicted it.
     */
    actingAs(User::factory()->editor()->create());

    Content::factory()->scheduled()->create();
    staleHeartbeat(SystemHeartbeat::SCHEDULER);

    Livewire::test(ContentOverviewWidget::class)
        ->assertSee(__('cms.dashboard.scheduler_stopped'))
        ->assertDontSee(__('cms.dashboard.nothing_scheduled'));
});

it('does not warn an editor about a consequence they do not have', function (): void {
    // An empty schedule with a stopped scheduler is an administrator's problem. Raising it
    // on an editor's dashboard is a warning with no action attached.
    actingAs(User::factory()->editor()->create());

    staleHeartbeat(SystemHeartbeat::SCHEDULER);

    Livewire::test(ContentOverviewWidget::class)
        ->assertSee(__('cms.dashboard.nothing_scheduled'))
        ->assertDontSee(__('cms.dashboard.scheduler_stopped'));
});

it('names the next publish date while the scheduler is running', function (): void {
    actingAs(User::factory()->editor()->create());

    Content::factory()->scheduled()->create();
    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER);

    Livewire::test(ContentOverviewWidget::class)
        ->assertDontSee(__('cms.dashboard.scheduler_stopped'));
});

it('warns about a publish that has ALREADY silently failed', function (): void {
    /*
     * The inversion the first version of this warning had, and the case that matters most.
     *
     * `scheduled()` means "publish_date still in the future", so a record LEAVES that set at
     * the exact instant its embargo elapses — which is the instant cms:publish-due should have
     * invalidated the Delivery cache and, with cron stopped, did not. Gating only on
     * `scheduled()` therefore warned while nothing was wrong and fell silent once something
     * was, reverting to "Nothing scheduled" about an article that is not on the site.
     */
    actingAs(User::factory()->editor()->create());

    staleHeartbeat(SystemHeartbeat::SCHEDULER);

    // Due five minutes ago — after the scheduler's last sighting, so within the outage.
    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subMinutes(5),
    ]);

    // Nothing is waiting: the old condition would have found zero and said nothing at all.
    expect(Content::query()->scheduled()->count())->toBe(0);

    Livewire::test(ContentOverviewWidget::class)
        ->assertSee(__('cms.dashboard.scheduler_missed', [
            'count' => LocalizedDate::number(1),
        ]))
        ->assertDontSee(__('cms.dashboard.nothing_scheduled'));
});

it('does not blame the scheduler for records that went live before it stopped', function (): void {
    // Measured from the last heartbeat, so work the scheduler actually completed is not counted
    // against it — otherwise every long-running site would show a permanent alarming number.
    actingAs(User::factory()->editor()->create());

    Content::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->subDays(30),
    ]);

    staleHeartbeat(SystemHeartbeat::SCHEDULER);

    Livewire::test(ContentOverviewWidget::class)
        ->assertSee(__('cms.dashboard.nothing_scheduled'));
});

it('warns an editor whose only scheduled work is a page or a gallery', function (): void {
    /*
     * cms:publish-due schedules Content, Page AND Gallery. The first version of this warning
     * counted Content only, so an editor with a scheduled Page was told "Nothing scheduled"
     * while the command that would publish it was not running. Both now read the same list
     * (App\Support\ScheduledPublishing).
     */
    actingAs(User::factory()->editor()->create());

    Page::factory()->create([
        'status' => ContentStatus::Published,
        'publish_date' => now()->addDay(),
    ]);

    staleHeartbeat(SystemHeartbeat::SCHEDULER);

    Livewire::test(ContentOverviewWidget::class)
        ->assertSee(__('cms.dashboard.scheduler_stopped'));
});

/*
|--------------------------------------------------------------------------
| Configuration the panel is the only place that could reveal
|--------------------------------------------------------------------------
*/

it('reports a web and cron process that disagree about the cache store', function (): void {
    /*
     * A containerised deployment handed two different environments. Each process writes cache
     * entries the other never reads, so an editor's publish clears a store the API does not
     * consult and the site serves stale pages indefinitely — with nothing, anywhere, reporting
     * a fault. The heartbeat stamps the CLI's view precisely so this card can compare them.
     */
    actingAs(User::factory()->admin()->create());

    SystemHeartbeat::record(SystemHeartbeat::SCHEDULER, ['cache_store' => 'redis']);
    config()->set('cache.default', 'array');

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.cache_store_mismatch', ['store' => 'redis']));
});

it('reports that the contact form stopped sending its honeypot', function (): void {
    /*
     * The honeypot only signals when the field arrives, and the field is rendered by a frontend
     * in a different repository. Rename it here without deploying there and the site's main
     * spam defence is off, with a clean-looking inbox as the only symptom.
     */
    actingAs(User::factory()->admin()->create());

    // A submission arrived, and no sighting of the decoy was ever recorded alongside it.
    ContactSubmission::factory()->create();

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.contact_honeypot_missing'));
});

it('is satisfied once a submission arrives carrying the honeypot', function (): void {
    actingAs(User::factory()->admin()->create());

    ContactSubmission::factory()->create(['created_at' => now()->subMinute()]);
    SystemHeartbeat::record(SystemHeartbeat::CONTACT_HONEYPOT);

    Livewire::test(SystemStatusWidget::class)
        ->assertDontSee(__('cms.system.status.contact_honeypot_missing'));
});

it('claims nothing about the honeypot before any message has arrived', function (): void {
    // A quiet form is not a broken one, and a fresh site has not PROVED its honeypot works
    // either — so the card says neither. Compared against the newest submission rather than
    // against the clock, so a form receiving one enquiry a month is never falsely accused.
    actingAs(User::factory()->admin()->create());

    Livewire::test(SystemStatusWidget::class)
        ->assertSee(__('cms.system.status.contact_no_submissions'));
});

it('drops the contact card when the module is switched off', function (): void {
    // Requirement 1.1 — a disabled module contributes nothing. A card reporting on an endpoint
    // that answers 404 would be a permanent warning nobody could act on.
    actingAs(User::factory()->admin()->create());

    config()->set('cms.modules.contact', false);

    Livewire::test(SystemStatusWidget::class)
        ->assertDontSee(__('cms.system.status.contact_protection'));
});
