<?php

declare(strict_types=1);

namespace App\Filament\RichContent\Blocks;

use App\Models\MediaAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * RULE #6 — Requirements 4.4, 4.5.
 *
 * A full-width banner with heading, lead text and an optional CTA.
 *
 * The image is chosen from the MediaAsset library rather than uploaded inline, so
 * RULE #9 (local disk) and Requirement 2.7 (per-locale alt text) are satisfied by
 * construction: an inline uploader would create an image with no alt text and no
 * library record, invisible to the media module.
 */
class HeroBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'hero';
    }

    public static function getLabel(): string
    {
        return __('cms.blocks.hero.label');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription(__('cms.blocks.hero.description'))
            ->schema([
                TextInput::make('heading')
                    ->label(__('cms.blocks.hero.heading'))
                    ->required()
                    ->maxLength(160),

                Textarea::make('lead')
                    ->label(__('cms.blocks.hero.lead'))
                    ->rows(3)
                    ->maxLength(400),

                Select::make('media_asset_id')
                    ->label(__('cms.blocks.hero.image'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => MediaAsset::query()
                        ->where('type', 'image')
                        ->whereJsonContainsLocale('alt_text', app()->getLocale(), null, 'like')
                        ->limit(20)
                        ->pluck('id', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => MediaAsset::find($value)
                        ?->altTextFor(app()->getLocale()))
                    ->helperText(__('cms.blocks.hero.image_help')),

                TextInput::make('cta_label')
                    ->label(__('cms.blocks.hero.cta_label'))
                    ->maxLength(80),

                TextInput::make('cta_url')
                    ->label(__('cms.blocks.hero.cta_url'))
                    // Relative paths are the norm for internal links, and a URL
                    // rule would reject them, so this only blocks the dangerous
                    // schemes.
                    ->regex('/^(?!javascript:|data:|vbscript:)/i')
                    ->maxLength(500),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $heading = trim((string) ($config['heading'] ?? ''));

        return $heading !== ''
            ? __('cms.blocks.hero.label').': '.$heading
            : __('cms.blocks.hero.label');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): ?string
    {
        return self::render($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return self::render($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function render(array $config): string
    {
        $asset = filled($config['media_asset_id'] ?? null)
            ? MediaAsset::find($config['media_asset_id'])
            : null;

        return view('filament.rich-content.hero', [
            'heading' => (string) ($config['heading'] ?? ''),
            'lead' => (string) ($config['lead'] ?? ''),
            'asset' => $asset,
            'ctaLabel' => (string) ($config['cta_label'] ?? ''),
            'ctaUrl' => (string) ($config['cta_url'] ?? ''),
        ])->render();
    }
}
