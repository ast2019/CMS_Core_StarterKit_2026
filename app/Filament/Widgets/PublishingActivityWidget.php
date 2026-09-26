<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Support\Dates\LocalizedDate;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Articles published per month, on the panel calendar's own month boundaries.
 *
 * This is the widget the whole date layer earns its keep in. Mehr 1405 runs from
 * 23 September to 22 October; a chart bucketed by Gregorian month would split
 * every Persian month across two bars and label them with names that do not match
 * any month the newsroom plans in. So the buckets come from
 * LocalizedDate::recentMonths(), which walks ICU's calendar.
 *
 * Counted on `publish_date`, not `created_at`: this is a chart of what went out,
 * not of what was typed. A published record with no publish_date is therefore not
 * counted, which is correct — it has no publication month to be counted in.
 */
class PublishingActivityWidget extends ChartWidget
{
    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '14rem';

    /**
     * Polling OFF — Filament's default is every 5 seconds, and this widget's
     * query is the most expensive on the page: a twelve-bucket conditional sum
     * over a year of content. A chart of monthly output does not change between
     * two ticks of a five-second timer.
     */
    protected ?string $pollingInterval = null;

    /**
     * How many months of history to show.
     *
     * Twelve, so a full year of the panel's calendar is visible and seasonal
     * shape is readable. It is also the number of buckets the query below stays
     * cheap at — see the note there.
     */
    protected const MONTHS = 12;

    public static function canView(): bool
    {
        return auth()->user()?->can('content.view') ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): ?string
    {
        return __('cms.dashboard.publishing_activity');
    }

    public function getDescription(): ?string
    {
        return __('cms.dashboard.publishing_activity_description');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $months = LocalizedDate::recentMonths(self::MONTHS);

        if ($months === []) {
            return ['datasets' => [], 'labels' => []];
        }

        return [
            'datasets' => [
                [
                    'label' => __('cms.dashboard.published_count'),
                    'data' => $this->countsPerMonth($months),
                ],
            ],
            'labels' => array_column($months, 'label'),
        ];
    }

    /**
     * One row, one aggregate per bucket.
     *
     * Deliberately NOT `group by date(publish_date)`: the column stores UTC, and a
     * Persian month turns over at 20:30 UTC, so a UTC day can fall on both sides of
     * a month boundary and anything published late in the evening would land in the
     * wrong bar. Conditional sums over the exact instants are the only way to bucket
     * on a calendar the database does not know about and still be right at the edges.
     *
     * It is also one query rather than twelve, and rather than reading twelve months
     * of timestamps into PHP to bucket them there — which for a daily newsroom is
     * tens of thousands of rows transferred to produce twelve integers. The
     * ['status', 'publish_date'] index covers it.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $months
     * @return list<int>
     */
    protected function countsPerMonth(array $months): array
    {
        $selects = [];
        $bindings = [];

        foreach ($months as $index => $month) {
            $selects[] = "sum(case when publish_date >= ? and publish_date < ? then 1 else 0 end) as bucket_{$index}";
            $bindings[] = $month['start']->toDateTimeString();
            $bindings[] = $month['end']->toDateTimeString();
        }

        $row = Content::query()
            ->where('status', ContentStatus::Published)
            ->toBase()
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        return array_map(
            fn (int $index): int => (int) ($row->{"bucket_{$index}"} ?? 0),
            array_keys($months),
        );
    }
}
