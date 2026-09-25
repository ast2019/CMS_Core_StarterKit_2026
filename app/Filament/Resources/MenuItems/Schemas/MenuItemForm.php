<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Schemas;

use App\Filament\Schemas\LinkTargetFields;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\MenuItem;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class MenuItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TranslatableTabs::make(fn (string $locale, bool $isSource): array => [
                TextInput::make("label.{$locale}")
                    ->label(__('cms.field.name'))
                    ->required($isSource)
                    ->maxLength(120)
                    ->extraInputAttributes([
                        'dir' => TranslatableTabs::isRtl($locale) ? 'rtl' : 'ltr',
                        'lang' => $locale,
                    ]),
            ]),

            Section::make(__('cms.section.link'))
                ->columns(2)
                ->schema([
                    Select::make('menu_key')
                        ->label(__('cms.field.menu_key'))
                        ->helperText(__('cms.field.menu_key_help'))
                        /*
                         * The locations this deployment declares in `cms.menus.locations`,
                         * labelled from the lang files. The list is no longer written out
                         * here: the same set validates the column on save and decides
                         * whether GET /api/v1/menus/{key} is a 404, so three readers of
                         * one config entry is the only arrangement in which they cannot
                         * disagree.
                         */
                        ->options(fn (): array => MenuItem::menuLocations())
                        ->default(MenuItem::DEFAULT_MENU_KEY)
                        ->required()
                        // Live because the parent options are scoped to the same
                        // location: a parent in another menu is not reachable from this
                        // one's tree, so the child would vanish from both.
                        ->live(),

                    Select::make('parent_id')
                        ->label(__('cms.field.parent'))
                        ->helperText(__('cms.field.parent_help', ['depth' => MenuItem::MAX_DEPTH]))
                        /*
                         * Query modifier is relationship()'s third argument; Select
                         * has no ->modifyQueryUsing().
                         *
                         * Excluding only self was not enough. A→B plus B→A was
                         * savable, and the branch then disappeared from the API
                         * entirely because neither row is topLevel() any more — a
                         * whole menu silently gone, with nothing in the panel to
                         * show why. So the options also exclude this item's own
                         * descendants, items in other menus, and items already deep
                         * enough that nesting under them would breach MAX_DEPTH.
                         */
                        ->relationship(
                            'parent',
                            'id',
                            fn (Builder $query, ?MenuItem $record, Get $get): Builder => self::parentOptions(
                                $query,
                                $record,
                                is_string($get('menu_key')) ? $get('menu_key') : null,
                            ),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (MenuItem $record): string => $record->getTranslation('label', app()->getLocale()),
                        )
                        ->searchable()
                        /*
                         * Validated as well as filtered. The options list is a
                         * courtesy; the rule is the guarantee, and it is the rule
                         * that also catches a stale live()-filtered form and a tree
                         * that was already cyclic before this validation existed.
                         */
                        ->rules([
                            fn (?MenuItem $record): Closure => static function (
                                string $attribute,
                                mixed $value,
                                Closure $fail,
                            ) use ($record): void {
                                self::validateParent($record, $value, $fail);
                            },
                        ]),

                    /*
                     * The raw-URL-or-record picker, shared with SlideForm. A menu item
                     * must have one of the two; a slide need not.
                     */
                    ...LinkTargetFields::make(targetRequired: true),

                    TextInput::make('position')
                        ->label(__('cms.field.position'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Toggle::make('opens_in_new_tab')
                        ->label(__('cms.field.opens_in_new_tab')),
                ]),
        ]);
    }

    /**
     * Items that may legally be this item's parent.
     *
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    private static function parentOptions(Builder $query, ?MenuItem $record, ?string $menuKey): Builder
    {
        if ($menuKey !== null) {
            $query->where('menu_key', $menuKey);
        }

        if ($record !== null) {
            $query->whereNotIn('id', $record->descendantKeys());
        }

        $height = $record?->subtreeHeight() ?? 1;

        /*
         * Keep only candidates shallow enough to take this item's whole subtree.
         * Done in PHP rather than SQL because depth is a recursive property and
         * MySQL/SQLite parity rules out a recursive CTE here; a navigation menu is
         * a handful of rows, so the cost is a non-issue.
         */
        $allowed = MenuItem::query()
            ->when($menuKey !== null, fn (Builder $inner): Builder => $inner->where('menu_key', $menuKey))
            ->get()
            ->filter(fn (MenuItem $candidate): bool => $candidate->level() + $height <= MenuItem::MAX_DEPTH)
            ->modelKeys();

        return $query->whereIn('id', $allowed);
    }

    /**
     * @param  Closure(string): void  $fail
     */
    private static function validateParent(?MenuItem $record, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $parent = MenuItem::find($value);

        if ($parent === null) {
            $fail(__('cms.validation.menu_parent_missing'));

            return;
        }

        if ($record !== null && (int) $parent->getKey() === (int) $record->getKey()) {
            $fail(__('cms.validation.menu_parent_cycle'));

            return;
        }

        $verdict = ($record ?? new MenuItem)->canNestUnder($parent);

        if ($verdict['ok']) {
            return;
        }

        $fail($verdict['reason'] === 'cycle'
            ? __('cms.validation.menu_parent_cycle')
            : __('cms.validation.menu_depth', ['depth' => MenuItem::MAX_DEPTH]));
    }
}
