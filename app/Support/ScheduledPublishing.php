<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Which record types are published on a timer, and what the scheduler has missed.
 *
 * The LIST existed already, privately, inside PublishDueContentCommand. It is here because
 * a second caller appeared — the dashboard's warning that scheduled publishing is not
 * happening — and the first version of that warning counted Content only. So the panel
 * told an editor whose pending work was a Page or a Gallery that nothing was scheduled,
 * while the command that publishes them was not running. One list, read by both, is the
 * only way that cannot drift again.
 *
 * Slide is absent deliberately: it has no `publish_date`. Neither do the taxonomies or
 * menu items.
 */
class ScheduledPublishing
{
    /**
     * The publishable, cacheable models that carry a `publish_date`.
     *
     * @return list<class-string<Model>>
     */
    public static function models(): array
    {
        return [
            Content::class,
            Page::class,
            Gallery::class,
        ];
    }

    /**
     * How many records are published-but-not-yet-due, across every scheduled type.
     */
    public static function pendingCount(): int
    {
        $now = now();
        $count = 0;

        foreach (self::models() as $model) {
            /*
             * The conditions are written out rather than calling HasPublishStatus::scopeScheduled(),
             * which is where this rule is defined. Not a preference: a scope cannot be resolved
             * by static analysis on a VARIABLE model class, so `$model::query()->scheduled()`
             * reports as an undefined method. PublishDueContentCommand writes its window out for
             * the same reason, and this file was extracted from it.
             *
             * Kept identical to the scope on purpose — `status = published` and a publish_date
             * still in the future. If that definition ever changes, HasPublishStatus is the
             * canonical one and these two loops must follow it.
             */
            $count += $model::query()
                ->where('status', ContentStatus::Published)
                ->whereNotNull('publish_date')
                ->where('publish_date', '>', $now)
                ->count();
        }

        return $count;
    }

    /**
     * How many records fell due at or after $since and are therefore AT RISK of being
     * invisible on the public site.
     *
     * THIS IS THE HALF THE FIRST VERSION OF THE WARNING MISSED, and it is the half that
     * matters. `scheduled()` means "publish_date still in the future", so the moment an
     * embargo elapses a record LEAVES that set — which is the same moment
     * `cms:publish-due` was supposed to invalidate the Delivery cache and, with the
     * scheduler stopped, did not. Gating a warning on `scheduled()` alone therefore warns
     * during the window in which nothing has gone wrong and falls silent the instant
     * something has, with the dashboard reverting to "nothing scheduled" — actively
     * reassuring an editor about an article that is not on the site.
     *
     * "At risk" rather than "broken": the record is live by the `live()` scope, so a write
     * to anything sharing its cache tag would publish it by accident, and a
     * `cms:publish-due --lookback=<long>` sweep fixes it deliberately. What cannot be
     * determined from here is whether either has happened, and claiming certainty either
     * way would be the same mistake in the other direction.
     *
     * $since is normally the scheduler's last heartbeat. Records that fell due BEFORE the
     * scheduler stopped were handled by it, so counting those would inflate the figure
     * with work that completed.
     */
    public static function dueSince(Carbon $since): int
    {
        $now = now();

        if ($since->greaterThanOrEqualTo($now)) {
            return 0;
        }

        $count = 0;

        foreach (self::models() as $model) {
            /*
             * Not the `live()` scope: that also matches a record with a null publish_date,
             * which was never scheduled and whose visibility never depended on a cron tick.
             * The bounds mirror PublishDueContentCommand's own window — half-open at the
             * `since` end, inclusive at `now` — so the two agree about which records a run
             * would have covered.
             */
            $count += $model::query()
                ->where('status', ContentStatus::Published)
                ->where('publish_date', '>', $since)
                ->where('publish_date', '<=', $now)
                ->count();
        }

        return $count;
    }
}
