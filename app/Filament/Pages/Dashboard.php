<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Dates\LocalizedDate;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The first page of the panel.
 *
 * It replaces Filament's stock Dashboard, which registered exactly one widget —
 * the version/changelog card — so signing in landed an editor on a page that told
 * them which release they were running and nothing about their own work.
 *
 * What it shows is chosen to answer "is anything waiting for me?" rather than to
 * fill space:
 *
 *  - ContentOverviewWidget — what is live, what is unfinished, what is queued to
 *    go out, and whether anyone has written in.
 *  - PublishingActivityWidget — output per month, bucketed on the locale's own
 *    calendar.
 *  - TranslationProgressWidget — how far each non-source locale has actually been
 *    reviewed, which is the number the translation lifecycle exists to move.
 *  - RecentActivityWidget — the last few audited writes.
 *  - VersionWidget — kept, at the bottom (RULE #2).
 *
 * Every widget answers canView() from the D-10 ability matrix, so an Author sees a
 * content summary and no audit trail, and a Viewer sees no editorial counts they
 * cannot act on. A dashboard that renders panels a role may not open is a
 * permissions bug that looks like a design choice.
 */
class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        return __('cms.dashboard.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('cms.dashboard.title');
    }

    /**
     * Today's date, in the panel locale's calendar.
     *
     * Small, but it is the reason a Persian editor can trust the rest of the page:
     * every other date on the dashboard is Jalali, and stating today's date in the
     * same calendar makes "3 days ago" and "next: 5 Mehr" immediately legible
     * instead of requiring a conversion.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $today = LocalizedDate::format(now(), 'weekday');

        return $today === null
            ? null
            : __('cms.dashboard.today', ['date' => $today]);
    }
}
