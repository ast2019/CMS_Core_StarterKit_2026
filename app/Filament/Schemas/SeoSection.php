<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Contracts\HasSeoMetadata;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The per-locale SEO block shared by Content, Page, Gallery and Category.
 *
 * Requirements 7.1, 7.3.
 *
 * ---------------------------------------------------------------------------
 * Why this replaced four hand-rolled sections
 * ---------------------------------------------------------------------------
 * HasSeoMeta has computed advisory warnings and carried the 60/155 advisory
 * limits since the beginning, and its own docblock says they exist "so an editor
 * sees the problem while writing rather than in a crawl report weeks later".
 * Nothing in the panel called them: the only consumer was the Delivery
 * SeoController, i.e. the crawl report. The editor got three bare inputs whose
 * maxLength values (255/320) were not the numbers that matter, and the advisory
 * limits appeared only as prose in a helper string.
 *
 * The four forms had also drifted apart in what they offered at all: a Page had
 * no robots directive, so a static page could not be set noindex from the panel;
 * a Gallery had no SEO fields whatsoever even though it appears in the sitemap.
 * One component per concern means they cannot drift again.
 *
 * ---------------------------------------------------------------------------
 * How the warnings stay honest
 * ---------------------------------------------------------------------------
 * The warnings are not reimplemented here. A detached instance of the model is
 * filled from the CURRENT form state and asked for seoWarningsFor(), so what the
 * editor sees is what the API will report, and the rules exist once. Filling a
 * throwaway instance rather than the record is what makes it work before the
 * first save, and for edits made but not yet saved.
 */
final class SeoSection
{
    /**
     * @param  class-string<Model&HasSeoMetadata>  $model
     */
    public static function make(string $model, string $locale, bool $withRobots = true): Section
    {
        $components = [
            self::warnings($model, $locale),

            TextInput::make("meta_title.{$locale}")
                ->label(__('cms.field.meta_title'))
                /*
                 * maxLength is the hard ceiling the column can hold; the advisory
                 * limit is the number Google actually truncates at, and it is an
                 * order of magnitude more useful. Both are shown, which is why the
                 * counter is a helper text rather than a replacement for the rule.
                 */
                ->maxLength(255)
                ->live(onBlur: true)
                ->helperText(fn (Get $get): HtmlString => self::counter(
                    (string) $get("meta_title.{$locale}"),
                    HasSeoMetadata::META_TITLE_ADVISORY_LIMIT,
                    __('cms.field.meta_title_help'),
                ))
                ->extraInputAttributes(self::directionFor($locale)),

            Textarea::make("meta_description.{$locale}")
                ->label(__('cms.field.meta_description'))
                ->rows(2)
                ->maxLength(320)
                ->live(onBlur: true)
                ->helperText(fn (Get $get): HtmlString => self::counter(
                    (string) $get("meta_description.{$locale}"),
                    HasSeoMetadata::META_DESCRIPTION_ADVISORY_LIMIT,
                    __('cms.field.meta_description_help'),
                ))
                ->extraInputAttributes(self::directionFor($locale)),
        ];

        if ($withRobots) {
            $components[] = Select::make("robots_meta.{$locale}")
                ->label(__('cms.field.robots_meta'))
                ->options(self::robotsOptions())
                ->helperText(__('cms.field.robots_meta_help'));
        }

        return Section::make(__('cms.section.seo'))
            ->collapsed()
            ->schema($components);
    }

    /**
     * The four directives, kept in one place so the Page and the Gallery cannot
     * end up offering different ones.
     *
     * Values are the literal directive strings because that is what the column
     * stores and what robotsMetaFor() returns verbatim to the frontend — mapping
     * them through friendlier labels would only add a translation layer between
     * the editor and the thing that ends up in the <meta> tag.
     *
     * @return array<string, string>
     */
    public static function robotsOptions(): array
    {
        return [
            'index, follow' => 'index, follow',
            'noindex, follow' => 'noindex, follow',
            'index, nofollow' => 'index, nofollow',
            'noindex, nofollow' => 'noindex, nofollow',
        ];
    }

    /**
     * Advisory warnings for this locale, read from the model's own rules.
     *
     * @param  class-string<Model&HasSeoMetadata>  $model
     */
    private static function warnings(string $model, string $locale): Placeholder
    {
        return Placeholder::make("seo_warnings_{$locale}")
            ->label(__('cms.seo.warnings'))
            ->columnSpanFull()
            ->content(function (Get $get) use ($model, $locale): HtmlString {
                $warnings = self::warningsFor($model, $locale, $get);

                if ($warnings === []) {
                    return new HtmlString(
                        '<span class="cms-seo-ok">'.e(__('cms.seo.no_warnings')).'</span>',
                    );
                }

                $items = array_map(
                    static fn (string $warning): string => '<li>'.e(__("cms.seo.warning.{$warning}", [
                        'title_limit' => HasSeoMetadata::META_TITLE_ADVISORY_LIMIT,
                        'description_limit' => HasSeoMetadata::META_DESCRIPTION_ADVISORY_LIMIT,
                    ])).'</li>',
                    $warnings,
                );

                // A plain list, so it inherits the panel's RTL direction and needs
                // no stylesheet of its own (RULE #4 — nothing is fetched remotely).
                return new HtmlString(
                    '<ul class="cms-seo-warnings">'.implode('', $items).'</ul>',
                );
            });
    }

    /**
     * Ask the model's own trait, against unsaved form state.
     *
     * A detached instance is filled with whatever translatable attributes the
     * model declares AND the form is currently holding, then queried. Only
     * declared attributes are touched: spatie throws AttributeIsNotTranslatable
     * for anything else, which is the same trap that used to make
     * metaDescriptionFor() a 500 on a Page.
     *
     * @param  class-string<Model&HasSeoMetadata>  $model
     * @return list<string>
     */
    private static function warningsFor(string $model, string $locale, Get $get): array
    {
        $instance = new $model;

        /** @var list<string> $translatable */
        $translatable = $instance->getTranslatableAttributes();

        foreach ($translatable as $attribute) {
            $value = $get("{$attribute}.{$locale}");

            // Only strings: `body` and `blocks` are translatable too and hold
            // TipTap documents, which no SEO getter reads.
            if (is_string($value) && $value !== '') {
                $instance->setTranslation($attribute, $locale, $value);
            }
        }

        return $instance->seoWarningsFor($locale);
    }

    /**
     * "34 / 60" plus the field's own guidance.
     *
     * mb_strlen, not strlen: Persian is multibyte, and byte length would report a
     * 30-character Persian title as 55 and warn about a limit it has not reached.
     */
    private static function counter(string $value, int $limit, string $guidance): HtmlString
    {
        $length = mb_strlen($value);

        $count = __('cms.seo.character_count', ['count' => $length, 'limit' => $limit]);

        $class = $length > $limit ? 'cms-seo-over-limit' : 'cms-seo-count';

        return new HtmlString(
            '<span class="'.$class.'" dir="ltr">'.e($count).'</span> — '.e($guidance),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function directionFor(string $locale): array
    {
        return [
            'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
            'lang' => $locale,
        ];
    }
}
