<?php

declare(strict_types=1);

namespace App\Filament\RichContent\Blocks;

use App\Models\Gallery;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * RULE #6 — Requirements 4.4, 4.5.
 *
 * Embeds an existing Gallery by reference.
 *
 * By reference, not by copying the image list into the document: a gallery edited
 * later must stay correct in every article that embeds it. Copying would leave
 * stale duplicates scattered through old content with no way to find them.
 */
class GalleryEmbedBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'gallery_embed';
    }

    public static function getLabel(): string
    {
        return __('cms.blocks.gallery_embed.label');
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription(__('cms.blocks.gallery_embed.description'))
            ->schema([
                Select::make('gallery_id')
                    ->label(__('cms.blocks.gallery_embed.gallery'))
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        return Gallery::query()
                            ->whereJsonContainsLocale('title', app()->getLocale(), "%{$search}%", 'like')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Gallery $gallery): array => [
                                $gallery->getKey() => $gallery->getTranslation('title', app()->getLocale()),
                            ])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => Gallery::find($value)
                        ?->getTranslation('title', app()->getLocale())),

                Select::make('layout')
                    ->label(__('cms.blocks.gallery_embed.layout'))
                    ->options([
                        'grid' => __('cms.blocks.gallery_embed.layout_grid'),
                        'carousel' => __('cms.blocks.gallery_embed.layout_carousel'),
                        'masonry' => __('cms.blocks.gallery_embed.layout_masonry'),
                    ])
                    ->default('grid')
                    ->required(),

                TextInput::make('max_items')
                    ->label(__('cms.blocks.gallery_embed.max_items'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(60)
                    ->helperText(__('cms.blocks.gallery_embed.max_items_help')),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        $gallery = filled($config['gallery_id'] ?? null)
            ? Gallery::find($config['gallery_id'])
            : null;

        if ($gallery === null) {
            // A referenced gallery can be deleted after the article was written.
            // Saying so in the editor is better than rendering an empty box the
            // author cannot explain.
            return __('cms.blocks.gallery_embed.missing');
        }

        return __('cms.blocks.gallery_embed.label').': '
            .$gallery->getTranslation('title', app()->getLocale());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): ?string
    {
        return self::render($config, isPreview: true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return self::render($config, isPreview: false);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function render(array $config, bool $isPreview): string
    {
        /*
         * whereKey()->first() rather than find(): find() accepts an array and so
         * its return type widens to Model|Collection, which makes every later
         * ->items access ambiguous.
         */
        $gallery = filled($config['gallery_id'] ?? null)
            ? Gallery::query()->with('items')->whereKey($config['gallery_id'])->first()
            : null;

        $maxItems = (int) ($config['max_items'] ?? 0);

        return view('filament.rich-content.gallery-embed', [
            'gallery' => $gallery,
            'layout' => (string) ($config['layout'] ?? 'grid'),
            'items' => $gallery === null
                ? collect()
                : ($maxItems > 0 ? $gallery->items->take($maxItems) : $gallery->items),
            'isPreview' => $isPreview,
        ])->render();
    }
}
