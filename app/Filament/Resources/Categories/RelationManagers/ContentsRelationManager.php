<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\RelationManagers;

use App\Enums\ContentStatus;
use App\Filament\Resources\Contents\ContentResource;
use App\Models\Category;
use App\Models\Content;
use App\Support\Dates\LocalizedDate;
use App\Support\Plural;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The articles in a category, on the category's own page.
 *
 * Item 26. No resource in this panel declared a single relation manager, so the only way
 * to answer "what is in this category?" was to leave, open the content list, and filter
 * by it — with the category's name to remember on the way. The relationship was already
 * there; nothing surfaced it.
 *
 * ATTACH RATHER THAN CREATE. An article needs a title, a body, a featured image and a
 * source locale before it is worth saving (RULE #7 among others), so offering "new
 * article" in a modal here would either duplicate the whole content form or produce a
 * record the real form would reject. Attaching an existing one is the operation that
 * belongs on this screen; writing an article belongs on the article's.
 *
 * DETACH, NOT DELETE. Removing an article from a category must not delete the article —
 * the destructive reading of the same gesture — so only detach is offered, and
 * DeleteBulkAction is deliberately absent.
 */
class ContentsRelationManager extends RelationManager
{
    protected static string $relationship = 'contents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('cms.resource.contents');
    }

    /**
     * Hidden when the content module is off, matching ContentResource: a disabled module
     * shows nothing anywhere (Requirement 1.1).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) config('cms.modules.content', true);
    }

    /**
     * Whether THIS category is the article's primary one, read from the pivot.
     *
     * One reader for the badge, the disabled detach and the bulk skip, so the three
     * cannot disagree about which row is protected.
     */
    private static function isPrimaryFor(Content $record): bool
    {
        return (bool) ($record->pivot->is_primary ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('cms.field.title'))
                    ->getStateUsing(fn (Content $record): string => $record->getTranslation(
                        'title',
                        app()->getLocale(),
                    ))
                    ->wrap()
                    ->limit(60),

                TextColumn::make('status')
                    ->label(__('cms.field.status'))
                    ->badge()
                    ->formatStateUsing(fn (ContentStatus $state): string => $state->label())
                    ->color(fn (ContentStatus $state): string => $state->color()),

                TextColumn::make('is_primary')
                    ->label(__('cms.field.primary_category'))
                    ->badge()
                    /*
                     * Whether THIS category is the article's primary one, read from the
                     * pivot. It decides the canonical URL and the breadcrumb trail
                     * (Decision D-2), so an article sitting in five categories behaves
                     * differently in one of them — and that is worth seeing here.
                     */
                    ->getStateUsing(fn (Content $record): ?string => self::isPrimaryFor($record)
                        ? __('cms.table.primary')
                        : null)
                    ->color('success'),

                TextColumn::make('publish_date')
                    ->label(__('cms.field.publish_date'))
                    ->formatStateUsing(fn (?CarbonInterface $state): ?string => LocalizedDate::format($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cms.field.status'))
                    ->options(fn (): array => collect(ContentStatus::cases())
                        ->mapWithKeys(fn (ContentStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->headerActions([
                AttachAction::make()
                    /*
                     * No preloadRecordSelect(): this table is expected to hold thousands
                     * of articles, and preloading would put every one of them in the
                     * select on every render.
                     *
                     * Searched by the TRANSLATED TITLE, not the id — an editor does not
                     * know the id. The `title->fa` arrow is how Filament addresses a JSON
                     * column: it runs the name through the same
                     * generate_search_column_expression() the tables use, which builds the
                     * json_extract and keeps the search case-insensitive on MySQL and
                     * Postgres.
                     */
                    ->recordSelectSearchColumns([
                        'title->'.app()->getLocale(),
                    ])
                    ->recordTitle(fn (Content $record): string => $record->getTranslation(
                        'title',
                        app()->getLocale(),
                    ))
                    ->after(fn (Content $record) => $record->syncPrimaryCategory()),
            ])
            ->recordActions([
                // Straight to the real editor rather than a modal: see the class
                // docblock on why an article is not editable in a side panel.
                Action::make('edit')
                    ->label(__('cms.action.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Content $record): string => ContentResource::getUrl(
                        'edit',
                        ['record' => $record],
                    )),

                /*
                 * DETACHING THE PRIMARY CATEGORY IS REFUSED, not silently repaired.
                 *
                 * `primary_category_id` decides the canonical URL and the breadcrumb
                 * trail (D-2), and syncPrimaryCategory() guarantees the primary category
                 * is also a member of the set. Detaching writes the pivot directly, so
                 * plain detach left the article with a canonical path through a category
                 * whose archive no longer listed it.
                 *
                 * Two repairs were possible and both are worse than refusing. Calling
                 * syncPrimaryCategory() afterwards RE-ATTACHES the category — that is
                 * precisely its job — so the detach appears to do nothing. Clearing
                 * `primary_category_id` instead would change the article's canonical URL
                 * as a side effect of tidying a category listing, which is not a decision
                 * this screen should make on an editor's behalf.
                 *
                 * So it is disabled with the reason attached: change the primary category
                 * on the article, then detach here.
                 */
                DetachAction::make()
                    ->disabled(fn (Content $record): bool => self::isPrimaryFor($record))
                    ->tooltip(fn (Content $record): ?string => self::isPrimaryFor($record)
                        ? __('cms.category.cannot_detach_primary')
                        : null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    /*
                     * Detach only. Deleting the article from here would be the destructive
                     * reading of "remove from this category".
                     *
                     * Hand-rolled rather than DetachBulkAction so a row whose PRIMARY
                     * category this is can be skipped and reported, for the reason on the
                     * single detach above. DetachBulkAction would take the whole selection
                     * including those.
                     */
                    BulkAction::make('detachSelected')
                        ->label(__('cms.action.detach_selected'))
                        ->icon('heroicon-o-link-slash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records, RelationManager $livewire): void {
                            /** @var Category $category */
                            $category = $livewire->getOwnerRecord();

                            $detached = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                /** @var Content $record */
                                if (self::isPrimaryFor($record)) {
                                    $skipped++;

                                    continue;
                                }

                                // Through the owner's typed relation rather than
                                // getRelationship(), which is Builder|Relation and carries
                                // no detach().
                                $category->contents()->detach($record->getKey());
                                $detached++;
                            }

                            $notification = Notification::make()
                                ->title(Plural::choice('cms.action.detach_selected_done', $detached));

                            $skipped > 0
                                ? $notification->body(Plural::choice('cms.category.primary_skipped', $skipped))->warning()
                                : $notification->success();

                            $notification->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('publish_date', 'desc');
    }
}
