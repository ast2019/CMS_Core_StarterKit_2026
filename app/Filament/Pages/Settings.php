<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting as SettingModel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Site settings, edited from the panel and stored in the Setting singleton.
 *
 * Admin-only: SettingPolicy already backs `settings.manage`, which is granted to
 * Admin alone in the D-10 matrix. This is the first Settings page in the panel;
 * its required scope is the AI-translation configuration (Requirement 5.3), which
 * the user asked to live in the database rather than in .env so it can be set per
 * client without a redeploy.
 *
 * The OpenRouter API key is written through Setting::putSecret(), which encrypts
 * it at rest. That is what keeps the plaintext key out of the append-only audit
 * trail (RULE #8): what IsAuditable captures is ciphertext, never the credential.
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
        // The key is intentionally NOT prefilled. Showing even a decrypted secret
        // in a form is a leak vector (browser autofill, shoulder-surfing, a copied
        // page source); leaving it blank means "unchanged" and a value means
        // "replace". Whether one is stored is surfaced by the helper text instead.
        $this->form->fill([
            'ai_translation_enabled' => (bool) SettingModel::get(SettingModel::AI_TRANSLATION_ENABLED, false),
            'ai_translation_model' => SettingModel::get(
                SettingModel::AI_TRANSLATION_MODEL,
                (string) config('cms.ai.translation.default_model', 'openai/gpt-4o-mini'),
            ),
            'openrouter_api_key' => null,
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

                        TextInput::make('ai_translation_model')
                            ->label(__('cms.settings.ai.model'))
                            ->placeholder((string) config('cms.ai.translation.default_model', 'openai/gpt-4o-mini'))
                            ->helperText(__('cms.settings.ai.model_help'))
                            ->maxLength(255)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                        TextInput::make('openrouter_api_key')
                            ->label(__('cms.settings.ai.api_key'))
                            ->password()
                            ->revealable()
                            ->autocomplete(false)
                            ->helperText($this->apiKeyHelperText())
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SettingModel::put(SettingModel::AI_TRANSLATION_ENABLED, (bool) ($data['ai_translation_enabled'] ?? false));
        SettingModel::put(SettingModel::AI_TRANSLATION_MODEL, trim((string) ($data['ai_translation_model'] ?? '')));

        // A blank key field means "leave the stored key alone", so a save that
        // only flips the toggle does not wipe the credential.
        $key = $data['openrouter_api_key'] ?? null;

        if (is_string($key) && trim($key) !== '') {
            SettingModel::putSecret(SettingModel::OPENROUTER_API_KEY, $key);
        }

        // Never keep the plaintext key in the Livewire component state after save.
        $this->data['openrouter_api_key'] = null;

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

    private function apiKeyHelperText(): string
    {
        return SettingModel::getSecret(SettingModel::OPENROUTER_API_KEY) !== null
            ? __('cms.settings.ai.api_key_help_set')
            : __('cms.settings.ai.api_key_help');
    }
}
