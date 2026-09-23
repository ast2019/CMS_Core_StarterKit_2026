<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Schemas;

use App\Filament\Schemas\TranslatableTabs;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("name.{$locale}")
                    ->label(__('cms.field.name'))
                    ->required($isSource)
                    ->maxLength(120)
                    ->extraInputAttributes([
                        'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                        'lang' => $locale,
                    ]),

                TextInput::make("slug.{$locale}")
                    ->label(__('cms.field.slug'))
                    ->maxLength(150)
                    ->helperText(__('cms.field.slug_help'))
                    // Always LTR: a slug is a URL segment, and bidi reordering
                    // makes it unproofreadable against the real URL.
                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
            ]),
        ]);
    }
}
