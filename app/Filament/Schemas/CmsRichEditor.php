<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Filament\RichContent\Blocks\CalloutBlock;
use App\Filament\RichContent\Blocks\GalleryEmbedBlock;
use App\Filament\RichContent\Blocks\HeroBlock;
use App\Filament\RichContent\Blocks\QuoteBlock;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;

/**
 * RULE #6 — "Filament RichEditor with Custom Blocks, JSON storage."
 *
 * Blueprint §12.6, Requirements 4.4, 4.5.
 *
 * One factory so every editor in the CMS is configured identically. Four
 * resources each calling RichEditor::make() with their own toolbar and their own
 * ->json() would be four chances to forget the JSON call — and an editor storing
 * HTML while its neighbours store TipTap documents is the kind of divergence that
 * only surfaces when the frontend tries to render both.
 */
class CmsRichEditor
{
    /**
     * @return list<class-string<RichContentCustomBlock>>
     */
    public static function blocks(): array
    {
        return [
            CalloutBlock::class,
            HeroBlock::class,
            QuoteBlock::class,
            GalleryEmbedBlock::class,
        ];
    }

    public static function make(string $name, string $locale): RichEditor
    {
        return RichEditor::make($name)
            /*
             * RULE #6 — structured TipTap JSON, not HTML. This is the call that
             * makes the whole rule real: without it the editor serialises to an
             * HTML string and the custom blocks degrade to opaque markup that no
             * frontend can re-interpret.
             */
            ->json()
            ->customBlocks(self::blocks())
            ->toolbarButtons([
                ['bold', 'italic', 'underline', 'strike', 'link'],
                ['h2', 'h3', 'blockquote', 'codeBlock'],
                ['bulletList', 'orderedList'],
                ['table', 'attachFiles'],
                ['undo', 'redo'],
            ])
            ->extraInputAttributes([
                // Persian and Arabic need RTL; English must render LTR even inside
                // an RTL panel, or its punctuation and alignment invert.
                'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                'lang' => $locale,
            ])
            ->columnSpanFull();
    }
}
