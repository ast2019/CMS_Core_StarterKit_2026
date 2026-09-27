<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AiProvider;
use App\Enums\OrganisationType;
use App\Filament\Schemas\MediaAssetPicker;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\ContactSetting;
use App\Models\Setting as SettingModel;
use App\Support\OrganisationProfile;
use App\Support\SiteIdentity;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Site settings, edited from the panel and stored in the database.
 *
 * Admin-only: SettingPolicy backs `settings.manage`, which is granted to Admin alone
 * in the D-10 matrix.
 *
 * WHAT THESE VALUES ARE FOR, BECAUSE IT IS EASY TO GET BACKWARDS.
 *
 * This host is the backoffice: the panel, the Delivery API and the sitemaps. Almost
 * nothing on this page changes anything an admin can see here — the values are
 * published to the FRONTEND, which is a separate deployment that reads them from
 * `GET /api/v1/settings` and `GET /api/v1/contact` and renders them on the public
 * site.
 *
 * The analytics and verification tokens are the sharpest example: they are edited
 * here and deliberately NEVER loaded by this panel. Injecting a GA or GTM tag into
 * the backoffice would fetch a script from a third party on every admin page view,
 * which RULE #4 forbids and tests/Architecture/NoExternalCdnTest asserts against.
 * They are strings this page stores and the API hands over, nothing more.
 *
 * The exception is the site name, which IS used here: it names the panel in its own
 * header (see AdminPanelProvider), so an administrator can tell which client's
 * backoffice they are signed into.
 *
 * `organisation_schema` is deliberately absent. The constant exists on the Setting
 * model but nothing reads it — SchemaBuilder::organization() is built from
 * `site_name` and `social_links` instead — and a form for a value that changes no
 * output would be worse than no form at all.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.settings';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('cms.settings.title');
    }

    public function getTitle(): string
    {
        return __('cms.settings.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('cms.nav.system');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $contact = ContactSetting::current();

        // No API key is ever prefilled. Showing even a decrypted secret in a form is
        // a leak vector (browser autofill, shoulder-surfing, a copied page source);
        // blank means "unchanged" and a value means "replace". Whether one is stored
        // is surfaced by each field's helper text instead.
        $perProvider = [];

        foreach (AiProvider::cases() as $provider) {
            $perProvider[$provider->apiKeySettingKey()] = null;
            $perProvider[$provider->modelSettingKey()] = $this->storedModelFor($provider);
        }

        $this->form->fill([
            'site_name' => $this->translatableSetting(SettingModel::SITE_NAME),
            'social_links' => $this->storedSocialLinks(),

            'ga_measurement_id' => $this->stringSetting(SettingModel::GA_MEASUREMENT_ID),
            'gtm_container_id' => $this->stringSetting(SettingModel::GTM_CONTAINER_ID),
            'gsc_verification' => $this->stringSetting(SettingModel::GSC_VERIFICATION),
            'bing_verification' => $this->stringSetting(SettingModel::BING_VERIFICATION),

            'maintenance_mode' => SettingModel::isMaintenanceMode(),

            'organisation' => [
                'type' => OrganisationProfile::type()->value,
                'logo_media_asset_id' => OrganisationProfile::logo()?->getKey(),
                'legal_name' => OrganisationProfile::legalName() ?? '',
                'founding_date' => OrganisationProfile::foundingDate() ?? '',
                'alternate_name' => $this->organisationTranslations('alternate_name'),
                'description' => $this->organisationTranslations('description'),
            ],

            'contact' => [
                'address' => $contact->getTranslations('address'),
                'office_hours' => $contact->getTranslations('office_hours'),
                'form_labels' => $contact->getTranslations('form_labels'),
                'phone' => $contact->phone,
                'email' => $contact->email,
                'map_latitude' => $contact->map_latitude,
                'map_longitude' => $contact->map_longitude,
            ],

            'ai_translation_enabled' => (bool) SettingModel::get(SettingModel::AI_TRANSLATION_ENABLED, false),
            'ai_translation_provider' => AiProvider::fromValue(
                SettingModel::get(SettingModel::AI_TRANSLATION_PROVIDER),
            )->value,
            ...$perProvider,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')->tabs([
                    $this->generalTab(),
                    $this->discoveryTab(),
                    $this->contactTab(),
                    $this->aiTab(),
                    $this->maintenanceTab(),
                ]),
            ]);
    }

    /**
     * Say out loud that a save was refused, and where.
     *
     * The form is tabbed and the save is all-or-nothing: getState() validates the whole
     * schema, so a blank required site name on the General tab silently blocks an
     * analytics id being saved on another tab. Filament v5 puts no error marker on a Tab
     * and installs no default notification for a failed validation, so the only signal
     * was an inline message under a field on a tab the admin was not looking at — from
     * where they are standing, the Save button simply does nothing.
     *
     * Names the offending tabs rather than repeating the field errors, because the field
     * already carries its own message; what the admin cannot see is WHICH tab to open.
     */
    protected function onValidationError(ValidationException $exception): void
    {
        Notification::make()
            ->title(__('cms.settings.validation_failed'))
            ->body(__('cms.settings.validation_failed_body', [
                'tabs' => $this->tabsWithErrors($exception),
            ]))
            ->danger()
            ->persistent()
            ->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->saveGeneral($data);
        $this->saveOrganisation($data);
        $this->saveDiscovery($data);
        $this->saveContact($data);
        $this->saveAi($data);

        $maintenance = (bool) ($data['maintenance_mode'] ?? false);
        SettingModel::put(SettingModel::MAINTENANCE_MODE, $maintenance);

        Notification::make()
            ->title(__('cms.settings.saved'))
            ->success()
            ->send();

        /*
         * Maintenance mode answers the whole Delivery API with 503, so the public site
         * goes dark the moment this is saved. It is a legitimate thing to want, and
         * also the single most consequential switch on the page, so leaving the
         * confirmation at a green "saved" would be too quiet. A persistent warning has
         * to be dismissed, which means somebody read it.
         */
        if ($maintenance) {
            Notification::make()
                ->title(__('cms.settings.maintenance.active_title'))
                ->body(__('cms.settings.maintenance.active_body'))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    // -----------------------------------------------------------------------------
    // Tabs
    // -----------------------------------------------------------------------------

    private function generalTab(): Tab
    {
        return Tab::make(__('cms.settings.general.tab'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                Section::make(__('cms.settings.general.identity'))
                    ->description(__('cms.settings.general.identity_help'))
                    ->schema([
                        /*
                         * Per locale, and the source locale is required: site_name is
                         * the Organization's `name` in the JSON-LD, where it is a
                         * mandatory property, and it is also what titles this panel.
                         */
                        ...array_map(
                            fn (string $locale): TextInput => TextInput::make("site_name.{$locale}")
                                ->label(__('cms.settings.general.site_name', [
                                    'locale' => TranslatableTabs::localeLabel($locale),
                                ]))
                                ->required($locale === $this->sourceLocale())
                                ->maxLength(255)
                                ->extraInputAttributes([
                                    'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                                    'lang' => $locale,
                                ]),
                            $this->locales(),
                        ),
                    ]),

                Section::make(__('cms.settings.organisation.section'))
                    ->description(__('cms.settings.organisation.section_help'))
                    ->columns(2)
                    ->schema([
                        /*
                         * The type an administrator PICKS. A Select rather than a Radio
                         * despite the precedent elsewhere: seven options with
                         * descriptions would be a very tall block on a settings page,
                         * and unlike the AI provider these labels are self-explanatory
                         * once translated.
                         */
                        Select::make('organisation.type')
                            ->label(__('cms.settings.organisation.type'))
                            ->options(fn (): array => collect(OrganisationType::cases())
                                ->mapWithKeys(fn (OrganisationType $t): array => [$t->value => $t->label()])
                                ->all())
                            ->default(OrganisationType::default()->value)
                            ->selectablePlaceholder(false)
                            ->helperText(__('cms.settings.organisation.type_help')),

                        /*
                         * The logo, and the property that does the most work: it is what
                         * turns the `publisher` on every article from a name into an
                         * identified organisation.
                         */
                        MediaAssetPicker::image('organisation.logo_media_asset_id')
                            ->label(__('cms.settings.organisation.logo'))
                            ->helperText(__('cms.settings.organisation.logo_help'))
                            /*
                             * MediaAssetPicker defaults to dehydrated(false) because on a
                             * CONTENT form the attachment is a media_attachments row
                             * applied after the save, not a column on the record. Here it
                             * is neither: the chosen id is stored inside the
                             * organisation_schema setting, so this field does have to
                             * reach the submitted state.
                             */
                            ->dehydrated(),

                        TextInput::make('organisation.legal_name')
                            ->label(__('cms.settings.organisation.legal_name'))
                            ->maxLength(255)
                            ->helperText(__('cms.settings.organisation.legal_name_help')),

                        TextInput::make('organisation.founding_date')
                            ->label(__('cms.settings.organisation.founding_date'))
                            // ISO, because schema.org's foundingDate is a Date and a
                            // Jalali string would be emitted verbatim as an invalid one.
                            ->placeholder('2014-03-21')
                            ->rule('date_format:Y-m-d')
                            ->helperText(__('cms.settings.organisation.founding_date_help'))
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        ...array_map(
                            fn (string $locale): TextInput => TextInput::make("organisation.alternate_name.{$locale}")
                                ->label(__('cms.settings.organisation.alternate_name', [
                                    'locale' => TranslatableTabs::localeLabel($locale),
                                ]))
                                ->maxLength(255)
                                ->extraInputAttributes([
                                    'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                                    'lang' => $locale,
                                ]),
                            $this->locales(),
                        ),

                        ...array_map(
                            fn (string $locale): Textarea => Textarea::make("organisation.description.{$locale}")
                                ->label(__('cms.settings.organisation.description', [
                                    'locale' => TranslatableTabs::localeLabel($locale),
                                ]))
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull()
                                ->extraInputAttributes([
                                    'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                                    'lang' => $locale,
                                ]),
                            $this->locales(),
                        ),
                    ]),

                Section::make(__('cms.settings.general.social'))
                    ->description(__('cms.settings.general.social_help'))
                    ->schema([
                        /*
                         * simple() stores a FLAT list of strings rather than a list of
                         * single-key rows, which is the shape SiteIdentity and the
                         * Organization `sameAs` property already expect. A nested
                         * repeater would have changed the stored shape and silently
                         * emptied every existing install's social profiles.
                         */
                        Repeater::make('social_links')
                            ->label(__('cms.settings.general.social_links'))
                            ->simple(
                                TextInput::make('url')
                                    ->url()
                                    /*
                                     * http(s) ONLY, matching what SiteIdentity will
                                     * publish. Laravel's bare `url` rule accepts ftp://
                                     * and friends, and such a link used to save without
                                     * complaint, never appear in the API or in `sameAs`
                                     * because the reader filters on the scheme, and then
                                     * be erased by the next save — which writes back the
                                     * filtered list the form was refilled from. The
                                     * field has to refuse what the reader will discard,
                                     * so an editor finds out here instead of never.
                                     */
                                    ->rule('url:http,https')
                                    ->required()
                                    ->placeholder('https://')
                                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
                            )
                            ->addActionLabel(__('cms.settings.general.social_add'))
                            ->reorderable()
                            ->defaultItems(0),
                    ]),
            ]);
    }

    private function discoveryTab(): Tab
    {
        return Tab::make(__('cms.settings.discovery.tab'))
            ->icon(Heroicon::OutlinedChartBar)
            ->schema([
                Section::make(__('cms.settings.discovery.analytics'))
                    // Says plainly that these are for the frontend and are never
                    // loaded here, so nobody reports the panel "not tracking".
                    ->description(__('cms.settings.discovery.analytics_help'))
                    ->schema([
                        TextInput::make('ga_measurement_id')
                            ->label(__('cms.settings.discovery.ga'))
                            ->placeholder('G-XXXXXXXXXX')
                            ->maxLength(64)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        TextInput::make('gtm_container_id')
                            ->label(__('cms.settings.discovery.gtm'))
                            ->placeholder('GTM-XXXXXXX')
                            ->maxLength(64)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
                    ]),

                Section::make(__('cms.settings.discovery.verification'))
                    ->description(__('cms.settings.discovery.verification_help'))
                    ->schema([
                        TextInput::make('gsc_verification')
                            ->label(__('cms.settings.discovery.gsc'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        TextInput::make('bing_verification')
                            ->label(__('cms.settings.discovery.bing'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
                    ]),
            ]);
    }

    private function contactTab(): Tab
    {
        return Tab::make(__('cms.settings.contact.tab'))
            ->icon(Heroicon::OutlinedMapPin)
            ->schema([
                Section::make(__('cms.settings.contact.details'))
                    ->description(__('cms.settings.contact.details_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('contact.phone')
                            ->label(__('cms.settings.contact.phone'))
                            ->tel()
                            ->maxLength(64)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        TextInput::make('contact.email')
                            ->label(__('cms.settings.contact.email'))
                            ->email()
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        ...array_map(
                            fn (string $locale): Textarea => Textarea::make("contact.address.{$locale}")
                                ->label(__('cms.settings.contact.address', [
                                    'locale' => TranslatableTabs::localeLabel($locale),
                                ]))
                                ->rows(2)
                                ->maxLength(500)
                                ->extraInputAttributes([
                                    'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                                    'lang' => $locale,
                                ]),
                            $this->locales(),
                        ),

                        ...array_map(
                            fn (string $locale): TextInput => TextInput::make("contact.office_hours.{$locale}")
                                ->label(__('cms.settings.contact.office_hours', [
                                    'locale' => TranslatableTabs::localeLabel($locale),
                                ]))
                                ->maxLength(255)
                                ->extraInputAttributes([
                                    'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                                    'lang' => $locale,
                                ]),
                            $this->locales(),
                        ),
                    ]),

                Section::make(__('cms.settings.contact.map'))
                    // Both or neither: ContactSetting::hasGeoCoordinates() gates the
                    // LocalBusiness JSON-LD on having the pair, because one coordinate
                    // describes a line, not a place.
                    ->description(__('cms.settings.contact.map_help'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('contact.map_latitude')
                            ->label(__('cms.settings.contact.latitude'))
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->requiredWith('contact.map_longitude')
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        TextInput::make('contact.map_longitude')
                            ->label(__('cms.settings.contact.longitude'))
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->requiredWith('contact.map_latitude')
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
                    ]),

                Section::make(__('cms.settings.contact.form_labels'))
                    ->description(__('cms.settings.contact.form_labels_help'))
                    ->schema([
                        /*
                         * KeyValue rather than fixed inputs for name/email/message: the
                         * column is free-form JSON that the Delivery API passes through
                         * verbatim, so which labels exist is the frontend's contract,
                         * not this panel's. Hardcoding a set here would silently drop
                         * any key a frontend already relies on.
                         */
                        ...array_map(
                            fn (string $locale): KeyValue => KeyValue::make("contact.form_labels.{$locale}")
                                ->label(__('cms.settings.contact.form_labels_locale', [
                                    'locale' => TranslatableTabs::localeLabel($locale),
                                ]))
                                ->keyLabel(__('cms.settings.contact.form_labels_key'))
                                ->valueLabel(__('cms.settings.contact.form_labels_value'))
                                ->reorderable(false),
                            $this->locales(),
                        ),
                    ]),
            ]);
    }

    private function maintenanceTab(): Tab
    {
        return Tab::make(__('cms.settings.maintenance.tab'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema([
                Section::make(__('cms.settings.maintenance.section'))
                    ->description(__('cms.settings.maintenance.section_help'))
                    ->schema([
                        Toggle::make('maintenance_mode')
                            ->label(__('cms.settings.maintenance.enabled'))
                            ->helperText(__('cms.settings.maintenance.enabled_help')),
                    ]),
            ]);
    }

    private function aiTab(): Tab
    {
        return Tab::make(__('cms.settings.ai.tab'))
            ->icon(Heroicon::OutlinedLanguage)
            ->schema([
                Section::make(__('cms.settings.ai.section'))
                    ->description(__('cms.settings.ai.section_help'))
                    ->schema([
                        Toggle::make('ai_translation_enabled')
                            ->label(__('cms.settings.ai.enabled'))
                            ->helperText(__('cms.settings.ai.enabled_help')),

                        /*
                         * A Radio rather than a Select, for the same reason ContentForm
                         * uses one for schema_type: Filament supports per-option
                         * descriptions on a Radio only, and here the descriptions ARE
                         * the feature. "GapGPT" and "ChatQT" mean nothing to an
                         * administrator without the sentence saying what each one is,
                         * and the wrong provider is an unreachable endpoint.
                         */
                        Radio::make('ai_translation_provider')
                            ->label(__('cms.settings.ai.provider'))
                            ->options(fn (): array => collect(AiProvider::cases())
                                ->mapWithKeys(fn (AiProvider $p): array => [$p->value => $p->label()])
                                ->all())
                            ->descriptions(fn (): array => collect(AiProvider::cases())
                                ->mapWithKeys(fn (AiProvider $p): array => [$p->value => $p->description()])
                                ->all())
                            ->default(AiProvider::default()->value)
                            ->required()
                            ->live()
                            ->helperText(__('cms.settings.ai.provider_help')),

                        /*
                         * The model and the key for each provider, shown only while that
                         * provider is selected so the form stays short. Both are stored
                         * per provider, so switching service neither loses a credential
                         * nor carries the previous service's model id across — a model
                         * id is not portable, and one catalogue's id answers 404 on
                         * another.
                         */
                        ...array_merge(...array_map(
                            fn (AiProvider $provider): array => [
                                $this->modelField($provider),
                                $this->apiKeyField($provider),
                            ],
                            AiProvider::cases(),
                        )),
                    ]),
            ]);
    }

    // -----------------------------------------------------------------------------
    // Persistence
    // -----------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveGeneral(array $data): void
    {
        /** @var array<string, mixed> $names */
        $names = is_array($data['site_name'] ?? null) ? $data['site_name'] : [];

        /*
         * MERGED into what is stored, not rebuilt from the form.
         *
         * Setting::put() replaces the whole value, and this form only renders the
         * locales in cms.locales.supported — so rebuilding would silently delete a name
         * stored under any other locale. That is not hypothetical: a client who narrows
         * `supported` (dropping Arabic, say) would lose the Arabic site name on the next
         * unrelated settings save, as a side effect of editing an analytics id.
         * ContactSetting keeps its untouched locales because setTranslation() works per
         * key; this makes site_name behave the same way.
         */
        $siteName = $this->translatableSetting(SettingModel::SITE_NAME, onlySupportedLocales: false);

        foreach ($this->locales() as $locale) {
            $value = $names[$locale] ?? null;
            $value = is_string($value) ? trim($value) : '';

            if ($value === '') {
                unset($siteName[$locale]);

                continue;
            }

            $siteName[$locale] = $value;
        }

        // isTranslatable: true — the value is a locale-keyed map, and the flag is what
        // tells a reader (and the API) to treat it as one.
        SettingModel::put(SettingModel::SITE_NAME, $siteName, isTranslatable: true);

        /** @var array<array-key, mixed> $rows */
        $rows = is_array($data['social_links'] ?? null) ? $data['social_links'] : [];

        /*
         * A repeater's state is a list of ROWS keyed by uuid, even a simple() one —
         * simple() changes how the row is rendered, not how it is stored, so each row
         * here is ['url' => '…']. The STORED setting is a flat list of strings, which is
         * what SiteIdentity, the Organization `sameAs` property and the Delivery API all
         * expect, so the shapes are converted at this boundary rather than anywhere the
         * value is read.
         */
        $links = [];

        foreach ($rows as $row) {
            $url = is_array($row) ? ($row['url'] ?? null) : $row;

            if (is_string($url) && trim($url) !== '') {
                $links[] = trim($url);
            }
        }

        SettingModel::put(SettingModel::SOCIAL_LINKS, $links);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveOrganisation(array $data): void
    {
        /** @var array<string, mixed> $input */
        $input = is_array($data['organisation'] ?? null) ? $data['organisation'] : [];

        $profile = [
            'type' => OrganisationType::fromValue($input['type'] ?? null)->value,
        ];

        $logoId = $input['logo_media_asset_id'] ?? null;

        if (is_numeric($logoId)) {
            $profile['logo_media_asset_id'] = (int) $logoId;
        }

        foreach (['legal_name', 'founding_date'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value !== '') {
                $profile[$key] = $value;
            }
        }

        /*
         * Blank locales are dropped rather than stored as empty strings. Everything the
         * profile feeds is schema.org markup, where an empty property is worse than an
         * absent one — SchemaBuilder's "omit rather than guess" rule — and
         * OrganisationProfile reads absence as "not configured".
         */
        foreach (['alternate_name', 'description'] as $key) {
            /** @var array<string, mixed> $translations */
            $translations = is_array($input[$key] ?? null) ? $input[$key] : [];

            $values = [];

            foreach ($this->locales() as $locale) {
                $value = $translations[$locale] ?? null;
                $value = is_string($value) ? trim($value) : '';

                if ($value !== '') {
                    $values[$locale] = $value;
                }
            }

            if ($values !== []) {
                $profile[$key] = $values;
            }
        }

        SettingModel::put(SettingModel::ORGANISATION_SCHEMA, $profile);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveDiscovery(array $data): void
    {
        foreach ([
            SettingModel::GA_MEASUREMENT_ID,
            SettingModel::GTM_CONTAINER_ID,
            SettingModel::GSC_VERIFICATION,
            SettingModel::BING_VERIFICATION,
        ] as $key) {
            $value = trim((string) ($data[$key] ?? ''));

            // null rather than '' when cleared: the API publishes these verbatim, and a
            // frontend checking `if (analytics.ga_measurement_id)` should see absence,
            // not an empty string that renders an empty script tag.
            SettingModel::put($key, $value === '' ? null : $value);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveContact(array $data): void
    {
        /** @var array<string, mixed> $contactData */
        $contactData = is_array($data['contact'] ?? null) ? $data['contact'] : [];

        $contact = ContactSetting::current();

        foreach (['address', 'office_hours', 'form_labels'] as $attribute) {
            /** @var array<string, mixed> $translations */
            $translations = is_array($contactData[$attribute] ?? null) ? $contactData[$attribute] : [];

            foreach ($this->locales() as $locale) {
                $contact->setTranslation($attribute, $locale, $translations[$locale] ?? null);
            }
        }

        $phone = trim((string) ($contactData['phone'] ?? ''));
        $email = trim((string) ($contactData['email'] ?? ''));

        $contact->phone = $phone === '' ? null : $phone;
        $contact->email = $email === '' ? null : $email;

        // Blank coordinates must land as NULL, not 0.0: hasGeoCoordinates() gates the
        // LocalBusiness JSON-LD on both being set, and 0,0 is a real place in the Gulf
        // of Guinea that would be published as the client's address.
        $contact->map_latitude = $this->nullableFloat($contactData['map_latitude'] ?? null);
        $contact->map_longitude = $this->nullableFloat($contactData['map_longitude'] ?? null);

        $contact->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveAi(array $data): void
    {
        $provider = AiProvider::fromValue($data['ai_translation_provider'] ?? null);

        SettingModel::put(SettingModel::AI_TRANSLATION_ENABLED, (bool) ($data['ai_translation_enabled'] ?? false));
        SettingModel::put(SettingModel::AI_TRANSLATION_PROVIDER, $provider->value);

        foreach (AiProvider::cases() as $case) {
            SettingModel::put($case->modelSettingKey(), trim((string) ($data[$case->modelSettingKey()] ?? '')));

            $settingKey = $case->apiKeySettingKey();

            /** @var mixed $submitted */
            $submitted = $data[$settingKey] ?? null;

            /*
             * A blank key field means "leave the stored key alone", so a save that only
             * flips a toggle does not wipe a credential. Removing one is an explicit
             * action on the field instead — see clearApiKeyAction().
             *
             * A key typed for a provider the admin then switched AWAY from is still
             * saved, because the field declares dehydratedWhenHidden(): Filament prunes
             * hidden fields from the submitted state by default, which would silently
             * discard that credential while reporting success.
             */
            if (is_string($submitted) && trim($submitted) !== '') {
                SettingModel::putSecret($settingKey, $submitted);
            }

            // Never keep a plaintext key in the Livewire component state after save.
            $this->data[$settingKey] = null;
        }
    }

    // -----------------------------------------------------------------------------
    // AI provider fields
    // -----------------------------------------------------------------------------

    /**
     * The model field for one provider, shown only while it is selected.
     *
     * Blank is the useful default: it means "whatever this provider's default is",
     * which stays correct as the provider's catalogue moves, where a pinned id
     * silently rots. The default is shown as the placeholder so the admin can still
     * see which model will actually run.
     */
    private function modelField(AiProvider $provider): TextInput
    {
        return TextInput::make($provider->modelSettingKey())
            ->label(__('cms.settings.ai.model'))
            ->placeholder($provider->defaultModel())
            ->helperText(__('cms.settings.ai.model_help', [
                'provider' => $provider->label(),
                'model' => $provider->defaultModel(),
            ]))
            ->maxLength(255)
            ->visible(fn (Get $get): bool => $get('ai_translation_provider') === $provider->value)
            ->dehydratedWhenHidden()
            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']);
    }

    private function apiKeyField(AiProvider $provider): TextInput
    {
        return TextInput::make($provider->apiKeySettingKey())
            ->label(__('cms.settings.ai.api_key', ['provider' => $provider->label()]))
            ->password()
            ->revealable()
            ->autocomplete(false)
            ->visible(fn (Get $get): bool => $get('ai_translation_provider') === $provider->value)
            ->dehydratedWhenHidden()
            ->helperText($this->apiKeyHelperText($provider))
            ->suffixAction($this->clearApiKeyAction($provider))
            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']);
    }

    /**
     * Remove a stored key, for a provider that has one.
     *
     * Needed because blank means "unchanged" everywhere else, which leaves no way to
     * REVOKE a credential — and with a key per provider an install accumulates up to
     * three. Rotating a leaked key should not require database access.
     */
    private function clearApiKeyAction(AiProvider $provider): Action
    {
        return Action::make('clear_'.$provider->apiKeySettingKey())
            ->label(__('cms.settings.ai.api_key_clear'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => SettingModel::getSecret($provider->apiKeySettingKey()) !== null)
            ->action(function () use ($provider): void {
                // putSecret(null) stores an empty value, which getSecret reads back as
                // "not configured" — the same state as never having set one.
                SettingModel::putSecret($provider->apiKeySettingKey(), null);

                $this->data[$provider->apiKeySettingKey()] = null;

                Notification::make()
                    ->title(__('cms.settings.ai.api_key_cleared'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Whether a key is already stored for this provider, plus where to get one.
     *
     * The documentation link is part of the answer: an administrator who has just
     * switched provider needs a key from a dashboard they may never have visited, and
     * the provider's own quickstart is where it is issued. Returned as an HtmlString
     * so the anchor renders as a link — Filament escapes a plain string, which would
     * leave the admin a URL to retype by hand.
     */
    private function apiKeyHelperText(AiProvider $provider): string|HtmlString
    {
        $status = SettingModel::getSecret($provider->apiKeySettingKey()) !== null
            ? __('cms.settings.ai.api_key_help_set')
            : __('cms.settings.ai.api_key_help');

        $docs = $provider->docsUrl();

        if ($docs === null) {
            return $status;
        }

        $link = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" class="cms-ltr underline">%s</a>',
            e($docs),
            e($docs),
        );

        return new HtmlString(e($status).' '.__('cms.settings.ai.api_key_docs', ['url' => $link]));
    }

    // -----------------------------------------------------------------------------
    // Reading helpers
    // -----------------------------------------------------------------------------

    /**
     * Which tabs hold the fields that failed validation, as a readable list.
     *
     * Derived from the failing state paths rather than from the schema, because the map
     * from a field to its tab is static and short here, and walking the component tree
     * to rediscover it would be more code with more ways to be wrong.
     */
    private function tabsWithErrors(ValidationException $exception): string
    {
        $paths = array_keys($exception->validator->errors()->toArray());

        $tabs = [];

        foreach ($paths as $path) {
            $tab = match (true) {
                str_starts_with($path, 'data.site_name'), str_starts_with($path, 'data.social_links') => __('cms.settings.general.tab'),
                str_starts_with($path, 'data.contact') => __('cms.settings.contact.tab'),
                str_starts_with($path, 'data.ai_translation') => __('cms.settings.ai.tab'),
                str_starts_with($path, 'data.maintenance') => __('cms.settings.maintenance.tab'),
                default => __('cms.settings.discovery.tab'),
            };

            $tabs[$tab] = $tab;
        }

        return implode('، ', $tabs);
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        /** @var list<string> */
        return array_values((array) config('cms.locales.supported', ['fa']));
    }

    private function sourceLocale(): string
    {
        return (string) config('cms.locales.source', 'fa');
    }

    /**
     * A locale-keyed setting as a map, tolerating the pre-translatable scalar form.
     *
     * $onlySupportedLocales narrows the result to the locales this form renders, which
     * is what filling the form wants. Saving passes false, so it can merge its edits
     * into the FULL stored map and preserve locales the form never showed.
     *
     * @return array<string, string>
     */
    private function translatableSetting(string $key, bool $onlySupportedLocales = true): array
    {
        $stored = SettingModel::get($key);

        if (is_string($stored)) {
            // Written before the translatable flag existed: show it against the source
            // locale rather than discarding it, which is where it came from.
            return $stored === '' ? [] : [$this->sourceLocale() => $stored];
        }

        if (! is_array($stored)) {
            return [];
        }

        $values = [];

        foreach ($stored as $locale => $value) {
            if (! is_string($locale) || ! is_string($value)) {
                continue;
            }

            if ($onlySupportedLocales && ! in_array($locale, $this->locales(), true)) {
                continue;
            }

            $values[$locale] = $value;
        }

        return $values;
    }

    private function stringSetting(string $key): string
    {
        $stored = SettingModel::get($key);

        return is_string($stored) ? $stored : '';
    }

    /**
     * Social links as repeater ROWS.
     *
     * Read through SiteIdentity because a single stored link comes back from the array
     * cast as a bare string, and filling a repeater with a string yields one row per
     * character. Wrapped into ['url' => …] rows because that is a repeater's state
     * shape even when simple() renders it as a single field.
     *
     * @return list<array<string, string>>
     */
    private function storedSocialLinks(): array
    {
        return array_map(
            static fn (string $url): array => ['url' => $url],
            SiteIdentity::socialLinks(),
        );
    }

    /**
     * A per-locale organisation field, as a map the form can fill.
     *
     * @return array<string, string>
     */
    private function organisationTranslations(string $key): array
    {
        $stored = OrganisationProfile::all()[$key] ?? null;

        if (! is_array($stored)) {
            return [];
        }

        $values = [];

        foreach ($this->locales() as $locale) {
            $value = $stored[$locale] ?? null;

            if (is_string($value)) {
                $values[$locale] = $value;
            }
        }

        return $values;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    /**
     * The model stored for a provider, honouring the legacy shared setting.
     *
     * Mirrors AiTranslator::model() so the form shows the value that will actually be
     * sent: an upgraded install has its model in the old shared `ai_translation_model`
     * key, and that value belongs to OpenRouter alone.
     */
    private function storedModelFor(AiProvider $provider): string
    {
        $model = SettingModel::get($provider->modelSettingKey());

        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        if ($provider === AiProvider::OpenRouter) {
            $legacy = SettingModel::get(SettingModel::AI_TRANSLATION_MODEL);

            if (is_string($legacy) && trim($legacy) !== '') {
                return trim($legacy);
            }
        }

        return '';
    }
}
