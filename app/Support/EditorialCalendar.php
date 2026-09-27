<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ContentStatus;
use App\Support\Dates\CalendarMonth;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Item 43 — what goes out when, for one month of the panel's calendar.
 *
 * The record types are ScheduledPublishing::models(), the list `cms:publish-due` publishes, so a
 * type that gains a publish_date and joins the command appears here without anyone remembering
 * this page exists.
 *
 * Three kinds of entry, because an editor planning a week needs to tell them apart at a glance:
 *
 *  - SCHEDULED: published, with a publish_date still ahead. This goes live on its own.
 *  - PUBLISHED: published, publish_date passed. Already out.
 *  - UNAPPROVED: a draft or in-review record whose publish_date is still ahead. This is the one that
 *    matters most and the one nothing else in the panel surfaces — the date says Tuesday, but the
 *    record will NOT go live on Tuesday unless somebody publishes it first. Only future ones are
 *    shown: a draft carrying a past date (an unpublished article keeps its old date) is history,
 *    not a plan, and putting it in the grid would only be noise.
 *  - MISSED: a draft or in-review record dated EARLIER TODAY. Its slot has passed and it is not on
 *    the site. Cutting unapproved entries off at "now" would make this one vanish at the exact
 *    minute it became a problem — the trap ScheduledPublishing::dueSince() records — so today is
 *    kept, in red. Earlier days are the history case above.
 *
 * Archived records are left out: they are neither planned nor on the site.
 */
class EditorialCalendar
{
    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_PUBLISHED = 'published';

    public const STATE_UNAPPROVED = 'unapproved';

    public const STATE_MISSED = 'missed';

    /**
     * The month's entries grouped by the epoch day they fall on, each day in publish order.
     *
     * One query per record type whatever the month holds — the page is held to the same budget as
     * every list (PanelQueryBudgetTest). Only the columns the grid reads are selected: a month of a
     * daily newsroom is hundreds of articles, and the body of each is a rich-text document in three
     * locales that the calendar never shows.
     *
     * @return array<int, list<array{type: string, title: string, time: string, state: string, color: string, url: ?string}>>
     */
    public static function entriesFor(CalendarMonth $month): array
    {
        $start = $month->start();
        $end = $month->end();
        $now = now();
        $startOfToday = CalendarMonth::startOfEpochDay(CalendarMonth::epochDayOf($now));
        $locale = app()->getLocale();

        $entries = [];

        foreach (ScheduledPublishing::models() as $model) {
            /** @var class-string<FilamentResource>|null $resource */
            $resource = Filament::getModelResource($model);

            /** @var Model $instance */
            $instance = new $model;

            $columns = [$instance->getKeyName(), 'title', 'status', 'publish_date'];

            /*
             * The owner column is needed by the policy, not the grid: an Author may edit only their
             * own articles, and deciding whether to link a title needs it. Asked of the policy, so
             * the column selected is the one it will compare.
             */
            $policy = Gate::getPolicyFor($model);
            $owner = is_object($policy) && method_exists($policy, 'ownerColumn') ? $policy->ownerColumn() : null;

            if (is_string($owner) && $owner !== '') {
                $columns[] = $owner;
            }

            /** @var Builder<Model> $query */
            $query = $model::query();

            $records = $query
                ->select($columns)
                ->where('publish_date', '>=', $start)
                ->where('publish_date', '<', $end)
                ->where(function (Builder $query) use ($startOfToday): void {
                    /*
                     * Written out rather than through HasPublishStatus's scopes, which static
                     * analysis cannot resolve on a variable model class — the same constraint
                     * ScheduledPublishing documents. The published half is live() and scheduled()
                     * together, restricted to records that carry a date.
                     */
                    $query->where('status', ContentStatus::Published)
                        ->orWhere(function (Builder $query) use ($startOfToday): void {
                            $query->whereIn('status', [ContentStatus::Draft, ContentStatus::Review])
                                ->where('publish_date', '>=', $startOfToday);
                        });
                })
                ->orderBy('publish_date')
                ->get();

            foreach ($records as $record) {
                /** @var CarbonInterface $date */
                $date = $record->getAttribute('publish_date');

                $state = match (true) {
                    $record->getAttribute('status') !== ContentStatus::Published => $date->greaterThan($now)
                        ? self::STATE_UNAPPROVED
                        : self::STATE_MISSED,
                    $date->greaterThan($now) => self::STATE_SCHEDULED,
                    default => self::STATE_PUBLISHED,
                };

                $entries[CalendarMonth::epochDayOf($date)][] = [
                    'sort' => $date->getTimestamp(),
                    'type' => $resource !== null ? $resource::getModelLabel() : class_basename($model),
                    'title' => self::titleOf($record, $locale),
                    'time' => LocalizedDate::format($date, 'time') ?? '',
                    'state' => $state,
                    'color' => self::colorOf($state),
                    // Linked only where following the link would work: Filament's edit page
                    // authorises `update`, so a Viewer would be sent to a 403. Asked quietly, or
                    // every colleague's article an Author can see would be audited as a refused
                    // edit — one write per entry per page load.
                    'url' => $resource !== null
                        && AuthorisationProbe::quietly(fn (): bool => $resource::canEdit($record))
                            ? $resource::getUrl('edit', ['record' => $record])
                            : null,
                ];
            }
        }

        // Types were read one after another; within a day the editor wants clock order.
        foreach ($entries as $day => $dayEntries) {
            usort($dayEntries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $entries[$day] = array_map(function (array $entry): array {
                unset($entry['sort']);

                return $entry;
            }, $dayEntries);
        }

        return $entries;
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_SCHEDULED => __('cms.editorial_calendar.state.scheduled'),
            self::STATE_PUBLISHED => __('cms.editorial_calendar.state.published'),
            self::STATE_MISSED => __('cms.editorial_calendar.state.missed'),
            default => __('cms.editorial_calendar.state.unapproved'),
        };
    }

    public static function colorOf(string $state): string
    {
        return match ($state) {
            self::STATE_SCHEDULED => 'info',
            self::STATE_PUBLISHED => 'success',
            self::STATE_MISSED => 'danger',
            default => 'warning',
        };
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [self::STATE_SCHEDULED, self::STATE_UNAPPROVED, self::STATE_MISSED, self::STATE_PUBLISHED];
    }

    /**
     * The title in the panel's locale, falling back to the source locale — a scheduled English
     * translation still has a Persian original, and a blank cell tells the editor nothing.
     */
    private static function titleOf(Model $record, string $locale): string
    {
        if (! method_exists($record, 'getTranslation')) {
            return (string) ($record->getAttribute('title') ?: '—');
        }

        $title = (string) $record->getTranslation('title', $locale, false);

        if ($title === '') {
            $title = (string) $record->getTranslation('title', (string) config('cms.locales.source', 'fa'), false);
        }

        return $title !== '' ? $title : '—';
    }
}
