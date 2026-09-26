<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AiProvider;
use App\Models\Setting as SettingModel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Site settings, edited from the panel and stored in the Setting singleton.
 *
 * Admin-only: SettingPolicy already backs `settings.manage`, which is granted to
 * Admin alone in the D-10 matrix. This is the first Settings page in the panel;
 * its required scope is the AI-translation configuration (Requirement 5.3), which
 * the user asked to live in the database rather than in .env so it can be set per
 * client without a redeploy.
 *
 * Every API key is written through Setting::putSecret(), which encrypts it at
 * rest. That is what keeps the plaintext key out of the append-only audit trail
 * (RULE #8): what IsAuditable captures is ciphertext, never the credential.
 *
 * WHY EACH PROVIDER HAS ITS OWN KEY FIELD:
 *
 * The three services (App\Enums\AiProvider) each issue their own credential. One
 * shared field would be overwritten the moment an admin tried a second provider,
 * so switching back would mean fetching the first key from a third-party
 * dashboard again. A field per provider — only the selected one shown, so the form
 * stays as short as it was — makes the provider choice a switch rather than a
 * re-setup.
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
        // No key is ever prefilled. Showing even a decrypted secret in a form is a
        // leak vector (browser autofill, shoulder-surfing, a copied page source);
        // leaving it blank means "unchanged" and a value means "replace". Whether
        // one is stored is surfaced by each field's helper text instead.
        $perProvider = [];

        foreach (AiProvider::cases() as $provider) {
            $perProvider[$provider->apiKeySettingKey()] = null;
            $perProvider[$provider->modelSettingKey()] = $this->storedModelFor($provider);
        }

        $this->form->fill([
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
                Section::make(__('cms.settings.ai.section'))
                    ->description(__('cms.settings.ai.section_help'))
                    ->schema([
                        Toggle::make('ai_translation_enabled')
                            ->label(__('cms.settings.ai.enabled'))
                            ->helperText(__('cms.settings.ai.enabled_help')),

                        /*
                         * A Radio rather than a Select, for the same reason
                         * ContentForm uses one for schema_type: Filament supports
                         * per-option descriptions on a Radio only, and here the
                         * descriptions ARE the feature. "GapGPT" and "ChatQT" mean
                         * nothing to an administrator without the sentence saying
                         * what each one is and how it differs; a bare three-item
                         * dropdown would be picked at random, and the wrong provider
                         * is an unreachable endpoint or a rejected key.
                         *
                         * Live, because both the key field and the model placeholder
                         * below depend on which provider is selected.
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
                         * The model and the key for each provider, shown only while
                         * that provider is selected so the form stays as short as it
                         * was. Both are stored per provider, so switching service
                         * neither loses a credential nor carries the previous
                         * service's model id across — a model id is not portable, and
                         * sending one catalogue's id to another answers 404.
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

    public function save(): void
    {
        $data = $this->form->getState();

        $provider = AiProvider::fromValue($data['ai_translation_provider'] ?? null);

        SettingModel::put(SettingModel::AI_TRANSLATION_ENABLED, (bool) ($data['ai_translation_enabled'] ?? false));
        SettingModel::put(SettingModel::AI_TRANSLATION_PROVIDER, $provider->value);

        foreach (AiProvider::cases() as $case) {
            SettingModel::put($case->modelSettingKey(), trim((string) ($data[$case->modelSettingKey()] ?? '')));

            $settingKey = $case->apiKeySettingKey();

            /** @var mixed $submitted */
            $submitted = $data[$settingKey] ?? null;

            /*
             * A blank key field means "leave the stored key alone", so a save that
             * only flips the toggle or switches provider does not wipe a credential.
             * Removing one is an explicit action on the field instead — see
             * clearApiKeyAction().
             *
             * A key typed for a provider the admin then switched AWAY from is still
             * saved, because the field declares dehydratedWhenHidden(): Filament
             * prunes hidden fields from the submitted state by default, which would
             * silently discard that credential while reporting success.
             */
            if (is_string($submitted) && trim($submitted) !== '') {
                SettingModel::putSecret($settingKey, $submitted);
            }

            // Never keep a plaintext key in the Livewire component state after save.
            $this->data[$settingKey] = null;
        }

        Notification::make()
            ->title(__('cms.settings.saved'))
            ->success()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('cms.settings.save'))
                ->submit('save'),
        ];
    }

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
            // Keep a model typed before the admin switched provider; see save().
            ->dehydratedWhenHidden()
            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']);
    }

    /**
     * The key field for one provider, shown only while that provider is selected.
     */
    private function apiKeyField(AiProvider $provider): TextInput
    {
        return TextInput::make($provider->apiKeySettingKey())
            ->label(__('cms.settings.ai.api_key', ['provider' => $provider->label()]))
            ->password()
            ->revealable()
            ->autocomplete(false)
            ->visible(fn (Get $get): bool => $get('ai_translation_provider') === $provider->value)
            /*
             * Without this, a key typed while one provider was selected is pruned
             * from the submitted state the moment the admin changes the radio, so the
             * credential is discarded and the page still reports success. Filament
             * drops hidden fields by default; here that default loses data silently.
             */
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
     * switched provider needs a key from a dashboard they may never have visited,
     * and the provider's own quickstart is where it is issued. Returned as an
     * HtmlString so the anchor renders as a link — Filament escapes a plain string,
     * which would leave the admin a URL to retype by hand.
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

    /**
     * The model stored for a provider, honouring the legacy shared setting.
     *
     * Mirrors AiTranslator::model() so the form shows the value that will actually
     * be sent: an upgraded install has its model in the old shared
     * `ai_translation_model` key, and that value belongs to OpenRouter alone.
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
