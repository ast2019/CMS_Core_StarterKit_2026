<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Schemas;

use App\Enums\RedirectType;
use App\Models\Redirect;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('from_path')
                    ->label(__('cms.field.from_path'))
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true)
                    // Paths are always LTR even in a Persian panel; a mixed-script
                    // path in an RTL input renders its segments out of order.
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr'])
                    ->helperText('/fa/old-path')
                    // Normalise on the way in so a pasted full URL becomes a path.
                    // Storing a host would break every redirect on a domain change.
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : Redirect::normalisePath($state)),

                TextInput::make('to_path')
                    ->label(__('cms.field.to_path'))
                    ->required()
                    ->maxLength(500)
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr'])
                    ->helperText('/fa/news/new-path')
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : Redirect::normalisePath($state))
                    /*
                     * Requirement 7.5 — reject a self-referential redirect.
                     *
                     * A field-level rule rather than a schema-level hook: Schema has
                     * no afterValidation(). Comparison happens on NORMALISED paths,
                     * so '/fa/x' and '/fa/x/' are correctly recognised as the same
                     * target — a raw string comparison would let that loop through.
                     *
                     * HandleRedirects also guards at request time, but a stored loop
                     * is a latent outage on whichever URL it covers, so it should
                     * never be saveable.
                     */
                    ->rule(static fn (Get $get): Closure => static function (
                        string $attribute,
                        mixed $value,
                        Closure $fail,
                    ) use ($get): void {
                        $from = Redirect::normalisePath((string) $get('from_path'));
                        $to = Redirect::normalisePath((string) $value);

                        if ($from !== '' && $from === $to) {
                            $fail(__('cms.validation.redirect_loop'));
                        }
                    }),

                Select::make('type')
                    ->label(__('cms.field.redirect_type'))
                    ->options(fn (): array => collect(RedirectType::cases())
                        ->mapWithKeys(fn (RedirectType $t): array => [$t->value => $t->label()])
                        ->all())
                    ->default(RedirectType::Permanent->value)
                    ->required(),
            ]);
    }
}
