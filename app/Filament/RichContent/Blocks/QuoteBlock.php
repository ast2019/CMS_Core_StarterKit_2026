<?php

declare(strict_types=1);

namespace App\Filament\RichContent\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * RULE #6 — Requirements 4.4, 4.5.
 *
 * A pull quote with attribution. Separate from TipTap's built-in blockquote
 * because attribution is structured data: it feeds the `citation` property of
 * Article JSON-LD (Requirement 7.3), which a plain blockquote cannot supply.
 */
class QuoteBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'quote';
    }

    public static function getLabel(): string
    {
        return __('cms.blocks.quote.label');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription(__('cms.blocks.quote.description'))
            ->schema([
                Textarea::make('quote')
                    ->label(__('cms.blocks.quote.quote'))
                    ->rows(3)
                    ->required()
                    ->maxLength(600),

                TextInput::make('attribution')
                    ->label(__('cms.blocks.quote.attribution'))
                    ->maxLength(160),

                TextInput::make('attribution_role')
                    ->label(__('cms.blocks.quote.attribution_role'))
                    ->maxLength(160),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $quote = trim((string) ($config['quote'] ?? ''));

        return $quote !== ''
            ? mb_strimwidth($quote, 0, 60, '…')
            : __('cms.blocks.quote.label');
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

    public static function shouldApplyProseStylingToPreview(array $config): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function render(array $config): string
    {
        return view('filament.rich-content.quote', [
            'quote' => (string) ($config['quote'] ?? ''),
            'attribution' => (string) ($config['attribution'] ?? ''),
            'attributionRole' => (string) ($config['attribution_role'] ?? ''),
        ])->render();
    }
}
