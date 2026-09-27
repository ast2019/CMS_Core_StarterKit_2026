<?php

declare(strict_types=1);

namespace App\Filament\Tables\Columns;

use App\Contracts\HasFeaturedMedia;
use App\Models\MediaAsset;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Item 50 — a thumbnail of the featured image in a list.
 *
 * Articles, slides and galleries were lists of text, so an editor looking for "the one with the
 * stadium photo" had to open records one by one. RULE #7 guarantees every one of them HAS a
 * featured image, which makes it the most reliable way to recognise a record at a glance.
 *
 * Two parts that have to be used together, which is why they live in one class:
 *
 *  - make() — the column itself.
 *  - eagerLoad() — the relations it reads, for the table's modifyQueryUsing(). Without it every row
 *    costs two queries (the attachment, then its file), which on a list of fifty articles is a
 *    hundred queries to draw fifty thumbnails — the same class of problem as item 37.
 *
 * It reads `featuredImageAssets`, not `mediaAssets`: see HasFeaturedImage::featuredImageAssets() for
 * why eager-loading a CONSTRAINED `mediaAssets` would quietly break every other role for the
 * request.
 */
class FeaturedImageColumn
{
    public static function make(): ImageColumn
    {
        return ImageColumn::make('featured_image_thumbnail')
            ->label(__('cms.section.featured_image'))
            ->getStateUsing(function (Model $record): ?string {
                if (! $record instanceof HasFeaturedMedia || ! method_exists($record, 'featuredImageAssets')) {
                    return null;
                }

                // Read from the eager load when the table provided one, and query only when it did
                // not — so the column is correct everywhere and cheap where it matters.
                $asset = $record->relationLoaded('featuredImageAssets')
                    ? $record->getRelation('featuredImageAssets')->first()
                    : $record->featuredImageAssets()->first();

                /*
                 * previewUrl() rather than getUrl('thumb'): conversions are queued, so the thumb of a
                 * just-uploaded image does not exist yet and Media Library would return its URL
                 * anyway — a broken image in the list for the first minute of every new record.
                 */
                return $asset instanceof MediaAsset ? $asset->previewUrl('thumb') : null;
            })
            ->square()
            ->imageSize(48)
            ->toggleable();
    }

    /**
     * The relations the column reads, to pass to Builder::with().
     *
     * @return list<string>
     */
    public static function eagerLoad(): array
    {
        return ['featuredImageAssets.media'];
    }

    /**
     * Convenience for a table's modifyQueryUsing().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withEagerLoad(Builder $query): Builder
    {
        return $query->with(self::eagerLoad());
    }
}
