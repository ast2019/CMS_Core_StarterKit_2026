<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Contracts\HasSeoMetadata;
use App\Services\Seo\SerpPreviewBuilder;
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
 * How everything here stays honest
 * ---------------------------------------------------------------------------
 * Nothing in this class computes an SEO answer. A detached instance of the model
 * is filled from the CURRENT form state and then ASKED — for its warnings, its
 * search-result preview, its keyphrase score. So what the editor sees is what the
 * API will report, and each rule exists once. Filling a throwaway instance rather
 * than the record is what makes it work before the first save, and for edits made
 * but not yet saved.
 *
 * ---------------------------------------------------------------------------
 * Why the optional blocks are decided by asking the model
 * ---------------------------------------------------------------------------
 * The keyphrase and the social-card overrides exist on some SEO-bearing models and
 * not others (HasSeoMeta::OPTIONAL_SEO_TRANSLATABLE_ATTRIBUTES explains which and
 * why). This class does not keep a list of which — it asks whether the model
 * declares the attribute as translatable. A list here would be the fifth place the
 * same fact is written down, and the one nobody updates: adding a keyphrase to
 * Gallery later is then a migration plus one line in $translatable, and the form
 * grows the field on its own.
 */
final class SeoSection
{
    /**
     * Non-translatable attributes that change what the preview should say, picked up
     * from live form state so switching a draft to published updates the preview
     * before the save rather than after it.
     *
     * Deliberately a short, explicit list rather than "everything in the form": these
     * are the three the SEO layer actually reads (UrlBuilder::hasLocaleRootUrl() reads
     * `system_key`; Publishable::isLive() reads the other two), and copying arbitrary
     * form state onto a model instance is how a preview starts triggering model
     * behaviour nobody asked for.
     */
    private const URL_AND_VISIBILITY_ATTRIBUTES = ['system_key', 'status', 'publish_date'];

    /**
     * @param  class-string<Model&HasSeoMetadata>  $model
     */
    public static function make(string $model, string $locale, bool $withRobots = true): Section
    {
        $probe = new $model;

        $components = [
            self::serpPreview($model, $locale),

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

        if ($probe->seoSupportsFocusKeyphrase()) {
            $components[] = TextInput::make("focus_keyphrase.{$locale}")
                ->label(__('cms.field.focus_keyphrase'))
                ->maxLength(120)
                ->live(onBlur: true)
                ->helperText(__('cms.field.focus_keyphrase_help'))
                ->extraInputAttributes(self::directionFor($locale));

            $components[] = self::analysis($model, $locale);
        }

        if ($withRobots) {
            $components[] = Select::make("robots_meta.{$locale}")
                ->label(__('cms.field.robots_meta'))
                ->options(self::robotsOptions())
                ->helperText(__('cms.field.robots_meta_help'));
        }

        if (self::declaresTranslatable($probe, 'og_title')) {
            $components[] = self::socialOverrides($locale);
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
     * The Google-style search-result preview.
     *
     * First in the section on purpose: it is the only component here that shows the
     * editor the OUTCOME rather than an input. The counters tell them a title is 68
     * characters; this tells them which three words a searcher will never read.
     *
     * @param  class-string<Model&HasSeoMetadata>  $model
     */
    private static function serpPreview(string $model, string $locale): Placeholder
    {
        return Placeholder::make("serp_preview_{$locale}")
            ->label(__('cms.seo.preview.label'))
            ->columnSpanFull()
            ->content(function (Get $get, ?Model $record) use ($model, $locale): HtmlString {
                $preview = app(SerpPreviewBuilder::class)->for(
                    self::instanceFrom($model, $locale, $get, $record),
                    $locale,
                );

                return new HtmlString(view('filament.seo.serp-preview', [
                    'preview' => $preview,
                    'rtl' => TranslatableTabs::isRtl($locale),
                ])->render());
            });
    }

    /**
     * The keyphrase score and its checks.
     *
     * @param  class-string<Model&HasSeoMetadata>  $model
     */
    private static function analysis(string $model, string $locale): Placeholder
    {
        return Placeholder::make("seo_analysis_{$locale}")
            ->label(__('cms.seo.analysis.label'))
            ->columnSpanFull()
            ->content(function (Get $get, ?Model $record) use ($model, $locale): HtmlString {
                $analysis = self::instanceFrom($model, $locale, $get, $record)
                    ->seoAnalysisFor($locale);

                return new HtmlString(view('filament.seo.keyphrase-analysis', [
                    'analysis' => $analysis,
                ])->render());
            });
    }

    /**
     * Per-record Open Graph overrides.
     *
     * Nested and collapsed, because they are the exception rather than the routine:
     * blank means "use the meta values", which is what the API has always served.
     * Surfacing them at the same level as the meta fields would suggest four fields
     * need filling in where two do.
     */
    private static function socialOverrides(string $locale): Section
    {
        return Section::make(__('cms.section.social_card'))
            ->description(__('cms.section.social_card_help'))
            ->collapsed()
            ->columnSpanFull()
            ->schema([
                TextInput::make("og_title.{$locale}")
                    ->label(__('cms.field.og_title'))
                    ->maxLength(255)
                    ->helperText(__('cms.field.og_title_help'))
                    ->extraInputAttributes(self::directionFor($locale)),

                Textarea::make("og_description.{$locale}")
                    ->label(__('cms.field.og_description'))
                    ->rows(2)
                    ->maxLength(320)
                    ->helperText(__('cms.field.og_description_help'))
                    ->extraInputAttributes(self::directionFor($locale)),
            ]);
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
            ->content(function (Get $get, ?Model $record) use ($model, $locale): HtmlString {
                $warnings = self::instanceFrom($model, $locale, $get, $record)
                    ->seoWarningsFor($locale);

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
     * An instance of the model carrying UNSAVED form state, safe to interrogate.
     *
     * The one helper behind the warnings, the preview and the keyphrase score, so
     * all three are computed against the same picture of the record.
     *
     * Two things it has to get right:
     *
     * 1. It CLONES the saved record when there is one, rather than always starting
     *    from `new $model`. The URL shape and the robots directive depend on columns
     *    that are not translatable and are not in this section — `system_key` decides
     *    whether a Page is the site's homepage, and `status` and `publish_date`
     *    decide whether anything is indexable at all. A blank instance answers "not
     *    the homepage, not published" for every record, which would have previewed
     *    the homepage at /fa/{slug} (a URL stage 2 deliberately stopped serving) and
     *    labelled every published article noindex. The clone is never saved; Filament
     *    writes the form array, not this object.
     *
     * 2. It only touches attributes the model DECLARES as translatable. Asking
     *    spatie for anything else throws AttributeIsNotTranslatable — the same trap
     *    that once turned a missing meta description on a Page into a 500.
     *
     * @param  class-string<Model&HasSeoMetadata>  $model
     * @return Model&HasSeoMetadata
     */
    private static function instanceFrom(string $model, string $locale, Get $get, ?Model $record): Model
    {
        // `$record instanceof $model` is enough: $model is class-string<Model&HasSeoMetadata>,
        // so a record of that class satisfies both halves.
        $instance = $record instanceof $model ? clone $record : new $model;

        /** @var list<string> $translatable */
        $translatable = $instance->getTranslatableAttributes();

        foreach ($translatable as $attribute) {
            $value = $get("{$attribute}.{$locale}");

            if (is_string($value) && $value !== '') {
                $instance->setTranslation($attribute, $locale, $value);

                continue;
            }

            /*
             * The body is an array, not a string: RichEditor::json() holds a TipTap
             * document. It is skipped by the string branch above and has to be set
             * explicitly, because the keyphrase checks that matter most — is the phrase
             * in a heading, in the opening, how dense is it — read the body and nothing
             * else. The editor is NOT live (re-rendering the whole form on every
             * keystroke inside a rich editor is not affordable), so these checks reflect
             * the last save; cms.seo.analysis.caveat says so in the panel.
             */
            if (is_array($value) && $value !== []) {
                $instance->setTranslation($attribute, $locale, $value);
            }
        }

        foreach (self::URL_AND_VISIBILITY_ATTRIBUTES as $attribute) {
            $value = $get($attribute);

            if ($value !== null && $value !== '' && in_array($attribute, $instance->getFillable(), true)) {
                $instance->setAttribute($attribute, $value);
            }
        }

        return $instance;
    }

    /**
     * @param  Model&HasSeoMetadata  $instance
     */
    private static function declaresTranslatable(Model $instance, string $attribute): bool
    {
        return in_array($attribute, $instance->getTranslatableAttributes(), true);
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
