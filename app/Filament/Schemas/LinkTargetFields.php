<?php

declare(strict_types=1);

namespace App\Filament\Schemas;

use App\Contracts\Publishable;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Page;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The "point this at something" field group: a raw URL, or a CMS record.
 *
 * The panel half of App\Concerns\HasLinkTarget. Menus had this and slides did not,
 * and the two must offer the same thing for the same reason the model behaviour is
 * shared: the searchable target picker, the existence-and-type validation, and the
 * "unpublished target" annotation are the difference between an editor linking a
 * record confidently and an editor pasting a path that will rot. Duplicating ~150
 * lines of Select configuration into SlideForm would have guaranteed the two drifted.
 *
 * What a caller varies is whether a destination is MANDATORY: a menu item with none is
 * broken navigation, a slide with none is a decorative hero.
 */
class LinkTargetFields
{
    /**
     * Rows returned by one target search.
     *
     * The field used to load the first 100 records of the chosen type into a static
     * options array and let the browser filter them. On a site with 2 000 articles that
     * made 1 900 of them unlinkable, with no indication that the list was truncated —
     * the search box simply found nothing. Searching server-side means the limit only
     * ever bounds one query's result set.
     */
    private const TARGET_RESULTS = 20;

    /**
     * The three fields, in form order.
     *
     * @return list<Component>
     */
    public static function make(bool $targetRequired = true): array
    {
        return [
            /*
             * A row points either at a raw URL or at a CMS record — at most one of the
             * two, enforced on the write path by HasLinkTarget::normaliseTarget() as
             * well as by the ->required() rules below.
             *
             * Linking to a record is the better choice because its slug is per-locale,
             * so one row resolves to a different path in each language, and a later slug
             * change moves the link with it. A hardcoded URL would send every locale to
             * the Persian page and break the day someone renames the page.
             */
            Select::make('linkable_type')
                ->label(__('cms.field.type'))
                ->options(fn (): array => self::types())
                ->live()
                ->placeholder(__('cms.field.link'))
                // Choosing a type clears any previously chosen record, so a Page id
                // cannot survive a switch to Gallery and silently address a different
                // record of the new type.
                ->afterStateUpdated(fn (Set $set): mixed => $set('linkable_id', null)),

            Select::make('linkable_id')
                ->label(__('cms.field.target'))
                ->helperText(__('cms.field.target_help'))
                ->searchable()
                ->visible(fn (Get $get): bool => filled($get('linkable_type')))
                ->required(fn (Get $get): bool => filled($get('linkable_type')))
                /*
                 * Server-side search, as HeroBlock and GalleryEmbedBlock do.
                 * getOptionLabelUsing() is what makes an already-saved value render its
                 * title: the search results are only the rows the editor's query
                 * matched, and the stored id is usually not among them.
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
                 * Existence and type are validated, which a manual options array never
                 * did: linkable_id is not a ->relationship() field (the morph's type
                 * lives in a sibling), so without this a stale or fabricated id saved
                 * happily and the row then resolved to nothing.
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
                /*
                 * The other half of "exactly one", where the model asks for it. A menu
                 * item with neither a link nor a target used to save cleanly and then
                 * never appear in the API, which looks like a caching bug from the
                 * editor's side. A slide is allowed to have neither.
                 */
                ->required(fn (Get $get): bool => $targetRequired && blank($get('linkable_type')))
                // Relative internal paths are normal here, so a url() rule would be
                // wrong; this only blocks the dangerous schemes.
                ->regex('/^(?!javascript:|data:|vbscript:)/i')
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'cms-ltr']),
        ];
    }

    /**
     * The types a link may address.
     *
     * The same four types UrlBuilder::SEGMENTS knows how to build a URL for. Not
     * derived from that constant, because the panel also needs a translated label per
     * type and UrlBuilder has no business holding UI strings — but if a fifth routable
     * type is added there and not here, it simply is not offered, which is a visible
     * omission rather than a broken link.
     *
     * @return array<class-string, string>
     */
    public static function types(): array
    {
        return [
            Page::class => __('cms.resource.page'),
            Content::class => __('cms.resource.content'),
            Category::class => __('cms.resource.category'),
            Gallery::class => __('cms.resource.gallery'),
        ];
    }

    /**
     * Selectable records of one type: either the matches for a search term, or the
     * single record with a known key.
     *
     * Public because it IS the field's behaviour — the search, the option label and the
     * existence check are all this one method — and a test that reaches through
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
     * @param  class-string|null  $type
     * @param  Closure(string): void  $fail
     */
    public static function validateTarget(?string $type, mixed $value, Closure $fail): void
    {
        if ($type === null || blank($value)) {
            return;
        }

        if (self::targetOptions($type, key: is_numeric($value) ? (int) $value : null) === []) {
            $fail(__('cms.validation.menu_target_missing'));
        }
    }

    /**
     * @return class-string|null
     */
    public static function typeFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && class_exists($value) ? $value : null;
    }

    /**
     * A concrete query per linkable type.
     *
     * Written as a match over four concrete builders rather than `$type::query()`: a
     * query built from a dynamic class-string resolves to Builder<Model>, which hides
     * the model's scopes and typed relations from static analysis, and suppressing that
     * would also hide a genuine mistake. Same reasoning as
     * SitemapGenerator::indexableQueries().
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
     * this helper is generic over the four linkable models and a scope is only visible
     * to static analysis on a concrete builder. The expression is the same one that
     * scope builds.
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
     * A record's label, annotated when linking to it will not produce the URL the
     * editor expects.
     *
     * Drafts are deliberately still selectable. Site chrome is normally assembled
     * alongside the pages it points at, so a published-only list would refuse the one
     * target the editor just created and offer no explanation; and it would not prevent
     * a broken link anyway, since a published target can be unpublished afterwards.
     * What was actually missing is the editor being TOLD — the API drops a non-live
     * target, and the menu table's "link" column already shows a dash for it.
     *
     * The homepage is annotated for a different reason: it resolves to /fa rather than
     * /fa/{slug}, and an editor who cannot see that would reasonably read a link to it
     * as a link to an ordinary page.
     */
    private static function optionLabel(Category|Content|Gallery|Page $record, string $field, string $locale): string
    {
        $label = (string) $record->getTranslation($field, $locale);

        if ($label === '') {
            $label = '#'.$record->getKey();
        }

        if ($record instanceof Page && $record->isHomePage()) {
            $label .= ' — '.__('cms.page.homepage');
        }

        if ($record instanceof Publishable && ! $record->isLive()) {
            $label .= ' — '.__('cms.menu.target_not_live');
        }

        return $label;
    }
}
