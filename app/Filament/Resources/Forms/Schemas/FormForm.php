<?php

declare(strict_types=1);

namespace App\Filament\Resources\Forms\Schemas;

use App\Enums\FormFieldType;
use App\Filament\Resources\Forms\Pages\EditForm;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Form;
use App\Services\Forms\FormSchema;
use App\Support\Dates\LocalizedDate;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * Item 15 — the form builder's editor: the form's identity, then its fields as a repeater.
 *
 * Validation here is for the editor's benefit — it names the problem next to the input. The
 * model normalises the schema again on save (FormSchema::normalise) and re-imposes the contact
 * form's fixed structure (ContactFormStructure), because both must hold for writes that never
 * pass through this page.
 */
class FormForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('cms.forms.section_details'))
                ->description(fn (Component $livewire): ?string => self::isContactForm($livewire)
                    ? __('cms.forms.contact_locked')
                    : null)
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->label(__('cms.forms.key'))
                        ->helperText(__('cms.forms.key_help'))
                        ->required()
                        ->maxLength(FormSchema::FORM_KEY_MAX_LENGTH)
                        ->regex(FormSchema::FORM_KEY_PATTERN)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (Component $livewire): bool => self::isContactForm($livewire))
                        ->dehydrated()
                        // A key is an identifier in the frontend's code: always LTR.
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                    Toggle::make('is_active')
                        ->label(__('cms.forms.is_active'))
                        ->helperText(__('cms.forms.is_active_help'))
                        ->default(true)
                        ->hidden(fn (Component $livewire): bool => self::isContactForm($livewire)),

                    TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                        TextInput::make("title.{$locale}")
                            ->label(__('cms.forms.title'))
                            ->required($isSource)
                            ->maxLength(190)
                            ->extraInputAttributes(self::directionFor($locale)),
                    ]),
                ]),

            Section::make(__('cms.forms.section_fields'))
                ->schema([
                    Repeater::make('fields')
                        ->hiddenLabel()
                        ->schema(self::fieldSchema())
                        ->itemLabel(fn (array $state): ?string => FormSchema::localised($state['label'] ?? null, app()->getLocale())
                            ?? (is_string($state['key'] ?? null) ? $state['key'] : null))
                        ->addActionLabel(__('cms.forms.add_field'))
                        ->collapsible()
                        ->reorderable()
                        ->minItems(1)
                        ->maxItems(FormSchema::MAX_FIELDS)
                        ->defaultItems(1)
                        // The contact form's field SET is the fixed contract of POST /api/v1/contact.
                        ->addable(fn (Component $livewire): bool => ! self::isContactForm($livewire))
                        ->deletable(fn (Component $livewire): bool => ! self::isContactForm($livewire)),
                ]),
        ]);
    }

    /**
     * @return array<\Filament\Schemas\Components\Component>
     */
    private static function fieldSchema(): array
    {
        $locked = fn (Component $livewire): bool => self::isContactForm($livewire);

        return [
            Grid::make(4)->schema([
                TextInput::make('key')
                    ->label(__('cms.forms.field_key'))
                    ->helperText(__('cms.forms.field_key_help'))
                    ->required()
                    ->maxLength(FormSchema::FIELD_KEY_MAX_LENGTH)
                    ->regex(FormSchema::FIELD_KEY_PATTERN)
                    ->distinct()
                    // The spam checks read these names off the same request.
                    ->notIn(fn (): array => FormSchema::reservedFieldKeys())
                    ->validationMessages(['not_in' => __('cms.forms.key_reserved')])
                    ->disabled($locked)
                    ->dehydrated()
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                Select::make('type')
                    ->label(__('cms.forms.field_type'))
                    ->options(FormFieldType::options())
                    ->default(FormFieldType::Text->value)
                    ->required()
                    ->native(false)
                    ->live()
                    ->disabled($locked)
                    ->dehydrated(),

                TextInput::make('max_length')
                    ->label(__('cms.forms.max_length'))
                    ->helperText(fn (Get $get): ?string => self::maxLengthHelp($get('type')))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(fn (Get $get): ?int => FormFieldType::tryFrom((string) $get('type'))?->maxLengthCeiling())
                    ->visible(fn (Get $get): bool => FormFieldType::tryFrom((string) $get('type'))?->maxLengthCeiling() !== null)
                    ->disabled($locked)
                    ->dehydrated(),

                Toggle::make('required')
                    ->label(__('cms.forms.required'))
                    ->inline(false)
                    ->disabled($locked)
                    ->dehydrated(),
            ]),

            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("label.{$locale}")
                    ->label(__('cms.forms.label'))
                    ->required($isSource)
                    ->maxLength(FormSchema::TEXT_MAX_LENGTH)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("placeholder.{$locale}")
                    ->label(__('cms.forms.placeholder'))
                    ->maxLength(FormSchema::TEXT_MAX_LENGTH)
                    ->extraInputAttributes(self::directionFor($locale)),

                TextInput::make("help.{$locale}")
                    ->label(__('cms.forms.help'))
                    ->maxLength(FormSchema::TEXT_MAX_LENGTH)
                    ->extraInputAttributes(self::directionFor($locale)),
            ]),

            Repeater::make('options')
                ->label(__('cms.forms.options'))
                ->visible(fn (Get $get): bool => FormFieldType::tryFrom((string) $get('type'))?->hasOptions() ?? false)
                ->required(fn (Get $get): bool => FormFieldType::tryFrom((string) $get('type'))?->hasOptions() ?? false)
                ->minItems(1)
                ->maxItems(FormSchema::MAX_OPTIONS)
                ->addActionLabel(__('cms.forms.add_option'))
                ->reorderable()
                ->schema([
                    /*
                     * The option labels are one input per locale in a row rather than a second
                     * level of locale tabs: an option is a word or two, and tabs inside tabs inside
                     * a repeater hide more than they organise. Same conventions otherwise — the
                     * source locale first and the only one required, direction per locale.
                     */
                    Grid::make(1 + count(self::locales()))->schema([
                        TextInput::make('value')
                            ->label(__('cms.forms.option_value'))
                            ->helperText(__('cms.forms.option_value_help'))
                            ->required()
                            ->distinct()
                            ->maxLength(FormSchema::OPTION_VALUE_MAX_LENGTH)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        ...array_map(
                            fn (string $locale): TextInput => TextInput::make("label.{$locale}")
                                ->label(__('cms.forms.option_label', ['locale' => TranslatableTabs::localeLabel($locale)]))
                                ->required($locale === self::sourceLocale())
                                ->maxLength(FormSchema::TEXT_MAX_LENGTH)
                                ->extraInputAttributes(self::directionFor($locale)),
                            self::locales(),
                        ),
                    ]),
                ]),
        ];
    }

    /**
     * Whether the page is editing the built-in contact form, whose structure is fixed.
     *
     * Asked of the Livewire page rather than of the injected `$record`: inside a repeater item
     * `$record` is the item's container, not the Form.
     */
    private static function isContactForm(Component $livewire): bool
    {
        if (! $livewire instanceof EditForm) {
            return false;
        }

        $record = $livewire->getRecord();

        return $record instanceof Form && $record->isContactForm();
    }

    private static function maxLengthHelp(mixed $type): ?string
    {
        $type = FormFieldType::tryFrom(is_string($type) ? $type : '');

        if ($type?->defaultMaxLength() === null) {
            return null;
        }

        return __('cms.forms.max_length_help', [
            'default' => LocalizedDate::number($type->defaultMaxLength()),
            'ceiling' => LocalizedDate::number((int) $type->maxLengthCeiling()),
        ]);
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

    /**
     * Source locale first, as TranslatableTabs orders its tabs.
     *
     * @return list<string>
     */
    private static function locales(): array
    {
        $locales = array_values(array_map('strval', (array) config('cms.locales.supported', ['fa'])));
        $source = self::sourceLocale();

        usort($locales, static fn (string $a, string $b): int => match (true) {
            $a === $source => -1,
            $b === $source => 1,
            default => strcmp($a, $b),
        });

        return $locales;
    }

    private static function sourceLocale(): string
    {
        return (string) config('cms.locales.source', 'fa');
    }
}
