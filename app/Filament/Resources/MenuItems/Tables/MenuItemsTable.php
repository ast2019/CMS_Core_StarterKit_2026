<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Tables;

use App\Filament\Tables\GuardedDeleteActions;
use App\Filament\Tables\TrashControls;
use App\Models\MenuItem;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Both columns below reach off the row — the label shows its parent's
             * label, and the link column resolves the morph — so without this the
             * list costs two extra queries per item.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['linkable', 'parent']))
            ->columns([
                TextColumn::make('label')
                    ->label(__('cms.field.name'))
                    /*
                     * Item 31 — the hierarchy, shown as one.
                     *
                     * The list was flat and a child's only clue to its place was its
                     * parent's name repeated underneath, so reading the shape of a menu
                     * meant reconstructing it from a column of names. A child is now
                     * indented under its parent, which is the whole point of a menu
                     * screen: you are arranging a tree, so you should be looking at one.
                     *
                     * An em-space rather than CSS padding because the panel is RTL and
                     * LTR depending on locale, and a character indents correctly in both
                     * without a direction-aware stylesheet.
                     */
                    ->getStateUsing(function (MenuItem $record): string {
                        $label = $record->getTranslation('label', app()->getLocale());

                        // level() is 1 for a root item, so the indent starts at zero.
                        $depth = max(0, $record->level() - 1);

                        return $depth === 0
                            ? $label
                            // An ideographic space rather than CSS padding: the panel is
                            // RTL or LTR depending on locale, and a character indents
                            // correctly in both without a direction-aware stylesheet.
                            : str_repeat('　', $depth).'└ '.$label;
                    })
                    /*
                     * The parent's name stays as the description alongside the indent.
                     * Rows are ordered by `position`, which does not guarantee a child
                     * renders directly under its parent, so the indent alone can point at
                     * nothing — naming the parent is what makes a detached row readable.
                     */
                    ->description(fn (MenuItem $record): ?string => $record->parent?->getTranslation('label', app()->getLocale())),

                TextColumn::make('menu_key')
                    ->label(__('cms.field.menu_key'))
                    ->badge(),

                TextColumn::make('resolved')
                    ->label(__('cms.field.link'))
                    // Shows what the item actually resolves to for the current
                    // locale, so a broken or unpublished target is visible here
                    // rather than only on the live site.
                    ->getStateUsing(fn (MenuItem $record): string => $record->resolveUrl(app()->getLocale()) ?? '—')
                    ->extraAttributes(['class' => 'cms-ltr']),

                IconColumn::make('opens_in_new_tab')
                    ->label(__('cms.field.opens_in_new_tab'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('position')
                    ->label(__('cms.field.position'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('menu_key')
                    ->label(__('cms.field.menu_key'))
                    // The list lives on the model: it was already written out here
                    // and in the form, and this was about to be a third copy.
                    ->options(fn (): array => array_combine(MenuItem::menuKeys(), MenuItem::menuKeys())),

                /*
                 * Item 10 — without this the trash is unreachable: a deleted record leaves the
                 * only list that links to its edit page, so the restore action there has no route
                 * to it. Defaults to excluding deleted rows, which is what this table already did
                 * implicitly.
                 */
                TrashControls::filter(),
            ])
            ->recordActions([
                EditAction::make(),
                GuardedDeleteActions::record(),
                ...TrashControls::recordActions(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GuardedDeleteActions::bulk(),
                    ...TrashControls::bulkActions(),
                ]),
            ])
            /*
             * Grouping by menu is OFFERED but not the default, deliberately.
             *
             * A site has a header, a footer and a sidebar interleaved in one list, so
             * grouping them reads much better — but Filament's reorder assigns positions
             * 1..N across every VISIBLE row, group boundaries included. Making it the
             * default therefore produced a trap: the rows looked like per-menu lists, and
             * one drag inside `header` silently renumbered a `footer` item. Relative order
             * inside each menu survived, so nothing broke on the site; the numbers an
             * editor saw simply drifted for menus they never touched.
             *
             * So: group when you want to read the shape, filter by menu_key when you want
             * to reorder one. Nesting by drag would need a tree component this kit has no
             * dependency for — see the item 31 note in the PR.
             */
            ->groups([
                Group::make('menu_key')
                    ->label(__('cms.field.menu_key'))
                    ->collapsible(),
            ])
            ->reorderable('position')
            ->defaultSort('position');
    }
}
