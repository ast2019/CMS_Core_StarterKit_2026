<?php

declare(strict_types=1);

namespace App\Filament\RichContent\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\HtmlString;

/**
 * RULE #6 — Filament RichEditor with Custom Blocks, JSON storage.
 *
 * Blueprint §4, Requirements 4.4, 4.5.
 *
 * A highlighted aside: note, warning, tip. Stored as a structured node in the
 * TipTap document, so the frontend decides how to render it. That is the point
 * of blocks over pasted HTML — the same callout can be a coloured box on the web
 * and a pull-quote in a feed without the editor rewriting anything.
 */
class CalloutBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'callout';
    }

    public static function getLabel(): string
    {
        return __('cms.blocks.callout.label');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription(__('cms.blocks.callout.description'))
            ->schema([
                Select::make('tone')
                    ->label(__('cms.blocks.callout.tone'))
                    ->options([
                        'info' => __('cms.blocks.callout.tone_info'),
                        'success' => __('cms.blocks.callout.tone_success'),
                        'warning' => __('cms.blocks.callout.tone_warning'),
                        'danger' => __('cms.blocks.callout.tone_danger'),
                    ])
                    ->default('info')
                    ->required(),

                TextInput::make('title')
                    ->label(__('cms.blocks.callout.title'))
                    ->maxLength(120),

                Textarea::make('body')
                    ->label(__('cms.blocks.callout.body'))
                    ->rows(4)
                    ->required()
                    ->maxLength(1000),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $title = trim((string) ($config['title'] ?? ''));

        return $title !== ''
            ? __('cms.blocks.callout.label').': '.$title
            : __('cms.blocks.callout.label');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): ?string
    {
        return view('filament.rich-content.callout', [
            'tone' => (string) ($config['tone'] ?? 'info'),
            'title' => (string) ($config['title'] ?? ''),
            'body' => (string) ($config['body'] ?? ''),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        /*
         * Every value is escaped here rather than trusted. The editor content is
         * authored by staff, but "authored by staff" is not the same as "safe":
         * content is routinely pasted in from Word, email and other CMSes, and a
         * stored XSS in a shared Core would propagate to every client site built
         * from it.
         */
        return (new HtmlString(
            view('filament.rich-content.callout', [
                'tone' => (string) ($config['tone'] ?? 'info'),
                'title' => (string) ($config['title'] ?? ''),
                'body' => (string) ($config['body'] ?? ''),
            ])->render(),
        ))->toHtml();
    }
}
