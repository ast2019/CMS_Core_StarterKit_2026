<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ContentStatus;
use App\Models\ContactSubmission;
use App\Models\Content;
use App\Support\Dates\LocalizedDate;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The state of the newsroom, in four numbers.
 *
 * Scheduling is the one worth explaining. `status = published` does not mean
 * live: HasPublishStatus treats a published row with a future publish_date as
 * scheduled (Requirement 3.6), and the counts here use the model's own `live()`
 * and `scheduled()` scopes rather than counting by status, so the dashboard cannot
 * drift from what the Delivery API actually serves. The scheduled card also names
 * the next date, because "3 scheduled" prompts the question the card should have
 * already answered.
 */
class ContentOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 10;

    /**
     * Polling OFF. Filament's default is every 5 seconds.
     *
     * Four aggregates, re-run every five seconds, for every open tab, forever —
     * to shorten the wait on numbers that change when somebody publishes an
     * article. The dashboard updates on the next page load, which for this
     * information is soon enough. Same judgement as
     * `databaseNotificationsPolling(null)` in AdminPanelProvider.
     */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $stats = [
            $this->liveStat(),
            $this->inProgressStat(),
            $this->scheduledStat(),
        ];

        // Only for roles that can open the inbox. Showing an unread count to
        // someone with no route to the messages is an invitation to ask an
        // administrator to read their mail out to them.
        if (auth()->user()?->can('contact.view') ?? false) {
            $stats[] = $this->inboxStat();
        }

        return $stats;
    }

    protected function liveStat(): Stat
    {
        $live = Content::query()->live()->count();

        /*
         * "This month" is the current month of the PANEL's calendar, so in a
         * Persian panel the figure resets at Nowruz and at the 1st of each Persian
         * month — not on the 1st of a Gregorian one, which would be an arbitrary
         * date three weeks out of step with the calendar the editor works in.
         */
        $month = LocalizedDate::recentMonths(1);

        // Half-open, matching what recentMonths() documents and returns:
        // whereBetween is inclusive at both ends, so it would count anything
        // published at the exact boundary instant in two consecutive months.
        $thisMonth = $month === []
            ? 0
            : Content::query()
                ->live()
                ->where('publish_date', '>=', $month[0]['start'])
                ->where('publish_date', '<', $month[0]['end'])
                ->count();

        return Stat::make(__('cms.dashboard.live'), LocalizedDate::number($live))
            ->description(__('cms.dashboard.live_this_month', [
                'count' => LocalizedDate::number($thisMonth),
            ]))
            ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
            ->icon(Heroicon::OutlinedNewspaper)
            ->color('success');
    }

    protected function inProgressStat(): Stat
    {
        // One grouped query rather than one per status: this widget renders on
        // every page load of the panel's landing page.
        $counts = Content::query()
            ->whereIn('status', [ContentStatus::Draft, ContentStatus::Review])
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $drafts = (int) $counts->get(ContentStatus::Draft->value, 0);
        $review = (int) $counts->get(ContentStatus::Review->value, 0);

        return Stat::make(
            __('cms.dashboard.in_progress'),
            LocalizedDate::number($drafts + $review),
        )
            ->description(__('cms.dashboard.in_progress_breakdown', [
                'drafts' => LocalizedDate::number($drafts),
                'review' => LocalizedDate::number($review),
            ]))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color($review > 0 ? 'warning' : 'gray');
    }

    protected function scheduledStat(): Stat
    {
        $count = Content::query()->scheduled()->count();

        /*
         * The record itself rather than min('publish_date'), so the value arrives
         * through the model's datetime cast instead of as whatever string the
         * driver happens to return. Both queries are served by the
         * ['status', 'publish_date'] index.
         *
         * The date is named, not just counted: "3 scheduled" prompts exactly the
         * question the card should already have answered. The long form is used
         * because this is prose, not a column.
         */
        $next = LocalizedDate::format(
            Content::query()->scheduled()->orderBy('publish_date')->first()?->publish_date,
            'long_time',
        );

        return Stat::make(__('cms.dashboard.scheduled'), LocalizedDate::number($count))
            ->description($next === null
                ? __('cms.dashboard.nothing_scheduled')
                : __('cms.dashboard.next_publish', ['date' => $next]))
            ->icon(Heroicon::OutlinedClock)
            ->color($next === null ? 'gray' : 'info');
    }

    protected function inboxStat(): Stat
    {
        $unread = ContactSubmission::query()->unread()->count();

        return Stat::make(__('cms.dashboard.unread_messages'), LocalizedDate::number($unread))
            ->description($unread === 0 ? __('cms.dashboard.inbox_clear') : null)
            ->icon(Heroicon::OutlinedInbox)
            ->color($unread > 0 ? 'danger' : 'gray');
    }
}
