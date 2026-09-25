<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('cms.field.name'))
                    ->required()
                    ->maxLength(191),

                TextInput::make('email')
                    ->label(__('cms.field.email'))
                    ->required()
                    ->email()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true)
                    // Addresses are LTR even in the Persian panel, like paths.
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

                TextInput::make('password')
                    ->label(__('cms.field.password'))
                    ->password()
                    ->revealable()
                    ->maxLength(191)
                    // Required only when creating; on edit an empty field means
                    // "leave the current password". The 'hashed' cast on the model
                    // turns the plaintext into a hash, so nothing is hashed here.
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(__('cms.user.password_help')),

                Select::make('role')
                    ->label(__('cms.field.role'))
                    ->options(fn (): array => collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $role): array => [$role->value => $role->label()])
                        ->all())
                    ->default(UserRole::Viewer->value)
                    ->required()
                    ->live(),

                Placeholder::make('role_guidance')
                    ->label(__('cms.user.role_guidance'))
                    ->columnSpanFull()
                    // The guidance is derived from UserRole::abilities() so it can
                    // never drift from the real matrix. When a role is selected we
                    // show that role's abilities; otherwise a summary of all four.
                    ->content(function (Get $get): HtmlString {
                        $selected = UserRole::tryFrom((string) $get('role'));

                        $roles = $selected !== null ? [$selected] : UserRole::cases();

                        $blocks = array_map(
                            static fn (UserRole $role): string => self::guidanceFor($role),
                            $roles,
                        );

                        return new HtmlString(implode('', $blocks));
                    }),

                Toggle::make('is_active')
                    ->label(__('cms.field.is_active'))
                    ->default(true),
            ]);
    }

    /**
     * A human-readable HTML summary of one role's abilities, built from the
     * enum matrix and translated per ability. `panel.access` is skipped because
     * every panel role holds it — listing it adds noise, not information.
     */
    private static function guidanceFor(UserRole $role): string
    {
        $items = collect($role->abilities())
            ->reject(fn (string $ability): bool => $ability === 'panel.access')
            ->map(fn (string $ability): string => __('cms.user.ability.'.$ability))
            ->map(fn (string $label): string => '<li>'.e($label).'</li>')
            ->implode('');

        return '<div class="mb-3">'
            .'<div class="font-semibold">'.e($role->label()).'</div>'
            .'<ul class="list-disc ms-5 text-sm">'.$items.'</ul>'
            .'</div>';
    }
}
