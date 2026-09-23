<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Builds one form tab per configured locale.
 *
 * Requirements 4.1, 5.1, 5.2.
 *
 * All three locales appear from day one even though only Persian ships at launch
 * (blueprint §2). The source locale is first and marked required; the others are
 * optional, because forcing a translation at authoring time is exactly the
 * re-engineering-later trap the translation lifecycle exists to avoid.
 */
class TranslatableTabs
{
    /**
     * @param  callable(string $locale, bool $isSource): array<Component>  $fields
     */
    public static function make(callable $fields, ?string $label = null): Tabs
    {
        $locales = (array) config('cms.locales.supported', ['fa']);
        $source = (string) config('cms.locales.source', 'fa');

        // Source locale first: it is the authoring language, and it is what every
        // other locale's translation status is measured against.
        usort($locales, static fn (string $a, string $b): int => match (true) {
            $a === $source => -1,
            $b === $source => 1,
            default => strcmp($a, $b),
        });

        $tabs = [];

        foreach ($locales as $locale) {
            $isSource = $locale === $source;

            $tabs[] = Tab::make(self::localeLabel($locale))
                ->badge($isSource ? __('cms.locale.source_badge') : null)
                ->schema($fields($locale, $isSource));
        }

        return Tabs::make($label ?? __('cms.locale.tabs'))
            ->tabs($tabs)
            ->columnSpanFull();
    }

    /**
     * Native-name labels. A Persian-speaking editor should not have to map
     * "fa"/"en"/"ar" onto languages on every form.
     */
    public static function localeLabel(string $locale): string
    {
        return match ($locale) {
            'fa' => 'فارسی',
            'en' => 'English',
            'ar' => 'العربية',
            default => strtoupper($locale),
        };
    }

    /**
     * Whether a locale is written right-to-left, for per-field direction hints.
     *
     * An English field inside an RTL panel must render LTR or the text aligns to
     * the wrong edge and punctuation jumps to the wrong side.
     */
    public static function isRtl(string $locale): bool
    {
        return in_array($locale, (array) config('cms.locales.rtl', ['fa', 'ar']), true);
    }
}
