<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\TranslationStatus;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\TranslationState;
use App\Support\Dates\LocalizedDate;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How far each non-source locale has actually been REVIEWED.
 *
 * The percentage counts `reviewed` only, and excludes `ai_translated`, on purpose.
 * Machine output that nobody has checked is the one state the product treats as
 * not-yet-translated — Decision D-5 keeps it out of sitemaps for exactly that
 * reason — so a progress figure that counted it would report a locale as finished
 * while the Delivery API was still serving Persian fallback under an English URL.
 * `outdated` is excluded for the same reason: it was reviewed once, and the source
 * has since changed.
 *
 * The source locale gets no card. It is authored, not translated, and a permanent
 * 100% would be noise.
 */
class TranslationProgressWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 30;

    /**
     * Polling OFF — see ContentOverviewWidget. This one groups over
     * `translation_states`, which has a row per record per locale and is the
     * table most likely to grow with the archive.
     */
    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        if (! (auth()->user()?->can('translation.view') ?? false)) {
            return false;
        }

        // A single-locale installation has nothing to report. The schema is
        // three-locale from day one (steering/product.md), but a site may switch
        // the others off.
        return self::targetLocales() !== [];
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        // One grouped query for every locale and status, rather than two per
        // locale. TranslationState has a row per record per locale, so this is the
        // widget most likely to grow with the archive.
        $counts = TranslationState::query()
            ->toBase()
            ->selectRaw('locale, status, count(*) as total')
            ->groupBy('locale', 'status')
            ->get()
            ->groupBy('locale');

        $stats = [];

        foreach (self::targetLocales() as $locale) {
            $rows = $counts->get($locale);

            $total = $rows === null ? 0 : (int) $rows->sum('total');

            $reviewed = $rows === null ? 0 : (int) $rows
                ->where('status', TranslationStatus::Reviewed->value)
                ->sum('total');

            $stats[] = $this->localeStat($locale, $reviewed, $total);
        }

        return $stats;
    }

    protected function localeStat(string $locale, int $reviewed, int $total): Stat
    {
        if ($total === 0) {
            return Stat::make(TranslatableTabs::localeLabel($locale), '—')
                ->description(__('cms.dashboard.no_translation_records'))
                ->icon(Heroicon::OutlinedLanguage)
                ->color('gray');
        }

        $percentage = (int) round($reviewed / $total * 100);

        return Stat::make(
            TranslatableTabs::localeLabel($locale),
            // Formatted for the PANEL's locale, not the locale being reported: a
            // Persian editor reading about the English locale still reads Persian
            // numerals everywhere else on the page. ICU supplies the percent sign,
            // because «٪» is right for fa/ar and wrong for an English panel.
            LocalizedDate::percent($percentage / 100),
        )
            ->description(__('cms.dashboard.reviewed_ratio', [
                'reviewed' => LocalizedDate::number($reviewed),
                'total' => LocalizedDate::number($total),
            ]))
            ->icon(Heroicon::OutlinedLanguage)
            ->color(match (true) {
                $percentage >= 90 => 'success',
                $percentage >= 40 => 'warning',
                default => 'danger',
            });
    }

    /**
     * Supported locales minus the authoring locale.
     *
     * @return list<string>
     */
    protected static function targetLocales(): array
    {
        $source = (string) config('cms.locales.source', 'fa');

        /** @var list<string> $supported */
        $supported = (array) config('cms.locales.supported', []);

        return array_values(array_filter(
            $supported,
            fn (string $locale): bool => $locale !== $source,
        ));
    }
}
