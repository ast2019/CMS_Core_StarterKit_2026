<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pages\Schemas;

use App\Enums\ContentStatus;
use App\Filament\Forms\Components\LocalizedDateTimePicker;
use App\Filament\Schemas\CmsRichEditor;
use App\Filament\Schemas\MediaAssetPicker;
use App\Filament\Schemas\SeoSection;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Page;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("title.{$locale}")
                    ->label(__('cms.field.title'))
                    ->required($isSource)
                    ->maxLength(255)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("slug.{$locale}")
                    ->label(__('cms.field.slug'))
                    ->maxLength(255)
                    ->helperText(__('cms.field.slug_help'))
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                /*
                 * The blueprint's §3 called this `blocks[]` while News has `body`,
                 * implying two different editors. Unified on one RichEditor storing
                 * TipTap JSON: two editors would mean two sets of custom blocks to
                 * maintain and editors switching mental models between content
                 * types. The column name stays `blocks` to match the blueprint.
                 */
                CmsRichEditor::make("blocks.{$locale}", $locale)
                    ->label(__('cms.field.blocks')),

                /*
                 * Includes the robots directive, which this form never offered.
                 * `robots_meta` has always been a translatable column on Page and
                 * robotsMetaFor() has always read it, so a static page COULD be
                 * noindex — just not from the panel. Anyone wanting to keep a
                 * thank-you or a landing page out of the index had to edit the
                 * database.
                 */
                SeoSection::make(Page::class, $locale),
            ]),

            Section::make(__('cms.section.publishing'))
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label(__('cms.field.status'))
                        ->options(fn (): array => collect(ContentStatus::cases())
                            ->mapWithKeys(fn (ContentStatus $s): array => [$s->value => $s->label()])
                            ->all())
                        ->default(ContentStatus::Draft->value)
                        ->required(),

                    LocalizedDateTimePicker::make('publish_date')
                        ->label(__('cms.field.publish_date'))
                        ->seconds(false),

                    TextInput::make('position')
                        ->label(__('cms.field.position'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    /*
                     * The page's ROLE, which is what `system_key` has always been: the
                     * marker for a page the application resolves by name rather than by
                     * slug (Requirement 3.8's branded 404, and now the homepage).
                     *
                     * It used to be a disabled text input shown only on pages that
                     * already had a key, so the mechanism existed and nothing in the
                     * panel could assign it — which is why there was no way to say
                     * "this page is the homepage" at all.
                     *
                     * Only the homepage is assignable. The 404 and maintenance keys are
                     * seeded and protected: renaming one would orphan the page the error
                     * handler looks up, and the site would silently lose its branded 404.
                     * So a page already holding one of those shows its role read-only
                     * (disabled AND not dehydrated, or the save would clear the column).
                     */
                    Select::make('system_key')
                        ->label(__('cms.field.page_role'))
                        ->options(fn (?Page $record): array => self::roleOptions($record))
                        ->placeholder(__('cms.page.role_none'))
                        ->helperText(__('cms.field.page_role_help'))
                        ->disabled(fn (?Page $record): bool => self::isProtectedRole($record))
                        ->dehydrated(fn (?Page $record): bool => ! self::isProtectedRole($record))
                        /*
                         * Validated as well as enforced by the unique index on the
                         * column and by Page::guardSystemKeyIsUnique(). The rule is what
                         * turns "two pages competing for /fa" into a message naming the
                         * page that already holds the role — the model's exception would
                         * otherwise surface as a generic error, and the database's as a
                         * 500.
                         */
                        ->rules([
                            fn (?Page $record): Closure => static function (
                                string $attribute,
                                mixed $value,
                                Closure $fail,
                            ) use ($record): void {
                                self::validateRole($record, $value, $fail);
                            },
                        ]),
                ]),

            Section::make(__('cms.section.featured_image'))
                ->schema([
                    MediaAssetPicker::featured()
                        ->afterStateHydrated(function (Select $component, $state, ?Page $record): void {
                            if ($record !== null && $state === null) {
                                $component->state($record->featuredImage()?->getKey());
                            }
                        }),
                ]),
        ]);
    }

    /**
     * Roles selectable on this record.
     *
     * The homepage is always offered. A record that already holds a DIFFERENT system
     * key has that key added as its own label so the read-only field renders something
     * meaningful instead of an empty select — the options list is what a Select shows,
     * and a value with no matching option displays as blank.
     *
     * @return array<string, string>
     */
    private static function roleOptions(?Page $record): array
    {
        $options = [Page::SYSTEM_HOME => __('cms.page.homepage')];

        if ($record !== null && $record->isSystemPage() && ! $record->isHomePage()) {
            $key = (string) $record->system_key;
            $options[$key] = __('cms.page.system_role', ['key' => $key]);
        }

        return $options;
    }

    /**
     * Whether this record's role is one the panel must never change.
     */
    private static function isProtectedRole(?Page $record): bool
    {
        return $record !== null && $record->isSystemPage() && ! $record->isHomePage();
    }

    /**
     * @param  Closure(string): void  $fail
     */
    private static function validateRole(?Page $record, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $existing = Page::otherPageWithSystemKey(
            $value,
            $record?->getKey() === null ? null : (int) $record->getKey(),
        );

        if ($existing === null) {
            return;
        }

        /*
         * Naming the competing page is the whole value of this rule, and the reason it
         * also looks at TRASHED pages: the unique index counts them, so without this the
         * editor would be told nothing holds the role and the save would then fail as an
         * integrity error against a page they cannot see anywhere in the panel.
         */
        $fail(__('cms.validation.system_key_taken', [
            'key' => $value,
            'title' => $existing->getTranslation('title', config('cms.locales.source', 'fa'))
                ?: '#'.$existing->getKey(),
        ]));
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
