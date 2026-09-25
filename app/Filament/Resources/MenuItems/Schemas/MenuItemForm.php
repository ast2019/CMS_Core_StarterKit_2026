<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Schemas;

use App\Contracts\Publishable;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\MenuItem;
use App\Models\Page;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MenuItemForm
{
    /**
     * Rows returned by one target search.
     *
     * The field used to load the first 100 records of the chosen type into a static
     * options array and let the browser filter them. On a site with 2 000 articles
     * that made 1 900 of them unlinkable, with no indication that the list was
     * truncated — the search box simply found nothing. Searching server-side means
     * the limit only ever bounds one query's result set.
     */
    private const TARGET_RESULTS = 20;

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
                        ->options(fn (): array => array_combine(MenuItem::menuKeys(), MenuItem::menuKeys()))
                        ->default('header')
                        ->required()
                        // Live because the parent options are scoped to the same
                        // menu: a parent in another menu is not reachable from this
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
                     * A menu item points either at a raw URL or at a CMS record —
                     * exactly one of the two, enforced on the write path by
                     * MenuItem::normaliseTarget() as well as by the ->required()
                     * rules below.
                     *
                     * Linking to a record is the better choice because its slug is
                     * per-locale, so one item resolves to a different path in each
                     * language, and a later slug change moves the menu with it. A
                     * hardcoded URL would send every locale to the Persian page and
                     * break the day someone renames the page.
                     */
                    Select::make('linkable_type')
                        ->label(__('cms.field.type'))
                        ->options([
                            Page::class => __('cms.resource.page'),
                            Content::class => __('cms.resource.content'),
                            Category::class => __('cms.resource.category'),
                            Gallery::class => __('cms.resource.gallery'),
                        ])
                        ->live()
                        ->placeholder(__('cms.field.link'))
                        // Choosing a type clears any previously chosen record, so a
                        // Page id cannot survive a switch to Gallery and silently
                        // address a different record of the new type.
                        ->afterStateUpdated(fn (Set $set): mixed => $set('linkable_id', null)),

                    Select::make('linkable_id')
                        ->label(__('cms.field.target'))
                        ->helperText(__('cms.field.target_help'))
                        ->searchable()
                        ->visible(fn (Get $get): bool => filled($get('linkable_type')))
                        ->required(fn (Get $get): bool => filled($get('linkable_type')))
                        /*
                         * Server-side search, as HeroBlock and GalleryEmbedBlock do.
                         * getOptionLabelUsing() is what makes an already-saved value
                         * render its title: the search results are only the rows the
                         * editor's query matched, and the stored id is usually not
                         * among them.
                         */
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => self::targetOptions(
                            self::typeFrom($get('linkable_type')),
                            like: $search,
                        ))
                        ->getOptionLabelUsing(fn (mixed $value, Get $get): ?string => self::targetOptions(
                            self::typeFrom($get('linkable_type')),
                            key: is_numeric($value) ? (int) $value : null,
                        )[(int) $value] ?? null)
                        /*
                         * Existence and type are validated, which a manual options
                         * array never did: linkable_id is not a ->relationship()
                         * field (the morph's type lives in a sibling), so without
                         * this a stale or fabricated id saved happily and the item
                         * then resolved to nothing.
                         */
                        ->rules([
                            fn (Get $get): Closure => static function (
                                string $attribute,
                                mixed $value,
                                Closure $fail,
                            ) use ($get): void {
                                self::validateTarget(self::typeFrom($get('linkable_type')), $value, $fail);
                            },
                        ]),

                    TextInput::make('link')
                        ->label(__('cms.field.link'))
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => blank($get('linkable_type')))
                        // The other half of "exactly one". An item with neither a
                        // link nor a target used to save cleanly and then never
                        // appear in the API, which looks like a caching bug from the
                        // editor's side.
                        ->required(fn (Get $get): bool => blank($get('linkable_type')))
                        ->regex('/^(?!javascript:|data:|vbscript:)/i')
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),

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

    /**
     * @param  class-string|null  $type
     * @param  Closure(string): void  $fail
     */
    private static function validateTarget(?string $type, mixed $value, Closure $fail): void
    {
        if ($type === null || blank($value)) {
            return;
        }

        if (self::targetOptions($type, key: is_numeric($value) ? (int) $value : null) === []) {
            $fail(__('cms.validation.menu_target_missing'));
        }
    }

    /**
     * Selectable records of one type: either the matches for a search term, or the
     * single record with a known key.
     *
     * Public because it IS the field's behaviour — the search, the option label and
     * the existence check are all this one method — and a test that reaches through
     * Livewire into a Select's internals to assert it would be testing Filament, not
     * this.
     *
     * @param  class-string|null  $type
     * @return array<int, string>
     */
    public static function targetOptions(?string $type, ?string $like = null, ?int $key = null): array
    {
        if ($type === null) {
            return [];
        }

        $locale = app()->getLocale();
        $field = $type === Category::class ? 'name' : 'title';
        $options = [];

        foreach (self::findTargets($type, $field, $locale, $like, $key) as $record) {
            $options[(int) $record->getKey()] = self::optionLabel($record, $field, $locale);
        }

        return $options;
    }

    /**
     * A concrete query per linkable type.
     *
     * Written as a match over four concrete builders rather than
     * `$type::query()`: a query built from a dynamic class-string resolves to
     * Builder<Model>, which hides the model's scopes and typed relations from
     * static analysis, and suppressing that would also hide a genuine mistake. Same
     * reasoning as SitemapGenerator::indexableQueries().
     *
     * @param  class-string  $type
     * @return array<int, Category|Content|Gallery|Page>
     */
    private static function findTargets(
        string $type,
        string $field,
        string $locale,
        ?string $like,
        ?int $key,
    ): array {
        return match ($type) {
            Page::class => self::narrow(Page::query(), $field, $locale, $like, $key)->get()->all(),
            Content::class => self::narrow(Content::query(), $field, $locale, $like, $key)->get()->all(),
            Category::class => self::narrow(Category::query(), $field, $locale, $like, $key)->get()->all(),
            Gallery::class => self::narrow(Gallery::query(), $field, $locale, $like, $key)->get()->all(),
            default => [],
        };
    }

    /**
     * Narrow a target query to a search term, or to one known key.
     *
     * A raw JSON-path `where` rather than the whereJsonContainsLocale scope, because
     * this helper is generic over the four linkable models and a scope is only
     * visible to static analysis on a concrete builder. The expression is the same
     * one that scope builds.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function narrow(
        Builder $query,
        string $field,
        string $locale,
        ?string $like,
        ?int $key,
    ): Builder {
        if ($key !== null) {
            return $query->whereKey($key);
        }

        if ($like !== null && $like !== '') {
            $query->where("{$field}->{$locale}", 'like', "%{$like}%");
        }

        return $query->limit(self::TARGET_RESULTS);
    }

    /**
     * A record's label, annotated when linking to it will not produce a live URL.
     *
     * Drafts are deliberately still selectable. A menu is normally assembled
     * alongside the pages it points at, so a published-only list would refuse the
     * one target the editor just created and offer no explanation; and it would not
     * prevent a broken link anyway, since a published target can be unpublished
     * afterwards. What was actually missing is the editor being told — the API drops
     * a non-live target, and the resource table's "link" column already shows a dash
     * for it.
     */
    private static function optionLabel(Category|Content|Gallery|Page $record, string $field, string $locale): string
    {
        $label = (string) $record->getTranslation($field, $locale);

        if ($label === '') {
            $label = '#'.$record->getKey();
        }

        if ($record instanceof Publishable && ! $record->isLive()) {
            $label .= ' — '.__('cms.menu.target_not_live');
        }

        return $label;
    }

    /**
     * @return class-string|null
     */
    private static function typeFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && class_exists($value) ? $value : null;
    }
}
