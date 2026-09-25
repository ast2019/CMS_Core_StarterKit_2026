<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use App\Contracts\Publishable;
use App\Services\Seo\UrlBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

/**
 * Navigation. Requirement 3.1.
 *
 * An item points either at a raw URL or at a CMS record. The relational form is
 * the one worth having: a linked record's slug is per-locale, so one item resolves
 * to a different path in each language, and renaming the record's slug moves the
 * menu with it instead of breaking it.
 *
 * Two invariants are enforced on the write path rather than only in the panel, so
 * a seed, an import or a future Management API cannot produce navigation that
 * silently disappears from the API:
 *  - exactly one of `link` / `linkable_type` is set (see normaliseTarget());
 *  - the tree stays acyclic and no deeper than MAX_DEPTH (the form validates it;
 *    the read path caps traversal regardless).
 *
 * @property-read Collection<int, self> $children
 */
class MenuItem extends Model
{
    use HasFactory;
    use HasTranslations;
    use InteractsWithLocales;
    use IsAuditable;

    /**
     * Deepest nesting the tree may reach, counting the top level as 1.
     *
     * Three levels is the practical ceiling for site chrome: a header can render
     * a bar, a dropdown and one flyout column. Deeper than that is a sitemap page,
     * not a menu — and every extra level is another eager-loaded relation on a
     * public, cached endpoint. The number is shared by the form's validation and
     * the API's traversal so the two cannot disagree.
     */
    public const MAX_DEPTH = 3;

    /**
     * Upper bound on any single upward or downward walk of the tree.
     *
     * Larger than MAX_DEPTH on purpose: these walks exist to *detect* a tree that
     * is already too deep or already cyclic (a bad import, a row written before
     * this validation existed), so they must be able to step past the legal limit
     * without hanging.
     */
    private const WALK_LIMIT = 25;

    /**
     * @var list<string>
     */
    public array $translatable = ['label'];

    protected $fillable = [
        'label',
        'menu_key',
        'link',
        'linkable_type',
        'linkable_id',
        'parent_id',
        'position',
        'opens_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'opens_in_new_tab' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->normaliseTarget();
        });
    }

    /**
     * The menus a site has. Requirement 3.1.
     *
     * Centralised because the value was already hardcoded in the form and in the
     * table filter, and this is the third reader. Still a flat list of keys rather
     * than a location concept with names and constraints — that is a separate piece
     * of work, and duplicating the array a third time now would make it harder.
     *
     * @return list<string>
     */
    public static function menuKeys(): array
    {
        return ['header', 'footer', 'sidebar'];
    }

    /**
     * The CMS record this item points at, when it is not a raw URL.
     *
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInMenu(Builder $query, string $menuKey): void
    {
        $query->where('menu_key', $menuKey)->orderBy('position');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Relations to eager-load to render a whole menu tree in a fixed number of
     * queries.
     *
     * Built from MAX_DEPTH instead of written out. The previous
     * `['children.linkable', 'linkable']` covered two levels while the renderer
     * recursed without a bound, so every third-level item cost one query for its
     * children and one for its target — an N+1 that only appears on the sites that
     * actually use sub-navigation.
     *
     * @return list<string>
     */
    public static function treeEagerLoads(): array
    {
        $loads = ['linkable'];
        $path = '';

        for ($level = 1; $level < self::MAX_DEPTH; $level++) {
            $path .= 'children.';
            $loads[] = $path.'linkable';
        }

        return $loads;
    }

    /**
     * Resolve the destination for a locale, root-relative.
     *
     * Root-relative, not absolute: navigation is rendered by the frontend on its
     * own host, so a base URL here would hardcode one deployment into every link.
     * Canonical and sitemap URLs are absolute for the opposite reason — they are
     * consumed by crawlers that need the authoritative host — which is why
     * UrlBuilder exposes both `pathForSlug()` and `absolute()`.
     *
     * Returns null when the item resolves to nothing, so the frontend omits it
     * rather than rendering a link into a 404.
     *
     * Locale note: the slug is read WITH fallback, unlike UrlBuilder::pathFor(),
     * which refuses to fall back. The asymmetry is deliberate and is the one place
     * navigation and the SEO layer are allowed to differ:
     *  - a canonical that points at another locale's content is worse than no
     *    canonical, so pathFor() returns null;
     *  - a menu that empties itself in every locale but Persian is a broken site,
     *    and the Delivery API already resolves a source-locale slug under any
     *    locale (ResolvesDeliveryRequest::resolveBySlug), so the path does resolve.
     * What must not happen is the fallback being SILENT (Requirement 5.5), so
     * MenuItemResource reports `meta.is_fallback`, `fallback_locale` and
     * `translation_status` per item exactly as the content resources do, and a
     * frontend that wants a strictly-translated menu can drop those items itself.
     */
    public function resolveUrl(string $locale): ?string
    {
        if (blank($this->linkable_type)) {
            return filled($this->link) ? (string) $this->link : null;
        }

        /*
         * The relation wins when a row somehow carries both (a legacy row, a seed,
         * an import). normaliseTarget() clears the loser on write, so this is a
         * read-path backstop — but the precedence still has to be stated, because
         * the old order checked `link` first and therefore ignored the relation the
         * editor had just chosen.
         */
        $target = $this->resolvedTarget();

        if ($target === null) {
            return null;
        }

        $slug = $target->getTranslation('slug', $locale, useFallbackLocale: true);

        if (blank($slug)) {
            return null;
        }

        // UrlBuilder is the single source of truth for the site's URL shape. It is
        // resolved from the container rather than injected because this is a model:
        // Eloquent controls construction, and HasSlug::fillMissingSlugs() already
        // reaches SlugGenerator the same way. The service is stateless, so there is
        // nothing to share and nothing to mock around.
        return app(UrlBuilder::class)->pathForSlug($target::class, $locale, (string) $slug);
    }

    /**
     * The linked record, if this item has one that is actually reachable.
     *
     * Null for a raw-URL item, a dangling morph (the target was deleted), a target
     * whose type has no public URL, a target whose MODULE is switched off, and a
     * target that is not live. The module check is the reason a menu cannot outlive
     * a disabled feature: with `cms.modules.gallery` off the Delivery API answers
     * 404 for every gallery (Requirement 1.1), so a gallery link would be a link to
     * nothing, and the item is dropped instead.
     */
    public function resolvedTarget(): ?Model
    {
        if (blank($this->linkable_type)) {
            return null;
        }

        $target = $this->linkable;

        if ($target === null) {
            return null;
        }

        if (! app(UrlBuilder::class)->isPubliclyRoutable($target::class)) {
            return null;
        }

        /*
         * Publishable rather than a method_exists() probe: the contract exists
         * precisely so "is this live?" is a typed question, and a probe's silent
         * default is what let an unpublishable type be treated as live. A Category
         * has no publish workflow and correctly does not implement it — a category
         * exists or it does not.
         */
        if ($target instanceof Publishable && ! $target->isLive()) {
            return null;
        }

        return $target;
    }

    /**
     * Ancestors, nearest first, with a cycle guard.
     *
     * Same shape as Category::ancestors() and for the same reason: a tree corrupted
     * into a cycle must break the walk, not hang the request that renders it.
     *
     * @return list<self>
     */
    public function ancestors(int $maxDepth = self::WALK_LIMIT): array
    {
        $ancestors = [];
        $node = $this->parent;
        $seen = [$this->getKey() => true];

        while ($node !== null && count($ancestors) < $maxDepth) {
            if (isset($seen[$node->getKey()])) {
                break;
            }

            $seen[$node->getKey()] = true;
            $ancestors[] = $node;
            $node = $node->parent;
        }

        return $ancestors;
    }

    /**
     * This item's level in its menu, counting the top level as 1.
     */
    public function level(): int
    {
        return count($this->ancestors()) + 1;
    }

    /**
     * How many levels this item's own subtree occupies, itself included.
     *
     * Needed because moving a node moves its children with it: re-parenting a
     * two-level branch under a level-2 item would put grandchildren at level 4,
     * which the read path would then silently truncate.
     */
    public function subtreeHeight(int $limit = self::WALK_LIMIT): int
    {
        if ($limit <= 1) {
            return 1;
        }

        $height = 1;

        foreach ($this->children as $child) {
            $height = max($height, 1 + $child->subtreeHeight($limit - 1));
        }

        return $height;
    }

    /**
     * Keys of every item below this one, self included.
     *
     * Used by the form to keep an item's own subtree out of its parent options,
     * which is the half of the cycle problem that excluding only self missed: A→B
     * plus B→A was savable, and the whole branch then vanished from the API because
     * neither row is topLevel() any more.
     *
     * @return list<int>
     */
    public function descendantKeys(int $limit = self::WALK_LIMIT): array
    {
        $keys = [(int) $this->getKey()];

        if ($limit <= 1) {
            return $keys;
        }

        foreach ($this->children as $child) {
            foreach ($child->descendantKeys($limit - 1) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Whether this item may legally sit under the given parent.
     *
     * Two failures, reported separately by the form so the editor is told which
     * rule they hit: the parent is inside this item's own subtree (a cycle), or the
     * resulting branch would be deeper than MAX_DEPTH.
     *
     * @return array{ok: bool, reason: 'cycle'|'depth'|null}
     */
    public function canNestUnder(self $parent): array
    {
        if ($this->exists && in_array((int) $parent->getKey(), $this->descendantKeys(), strict: true)) {
            return ['ok' => false, 'reason' => 'cycle'];
        }

        $height = $this->exists ? $this->subtreeHeight() : 1;

        if ($parent->level() + $height > self::MAX_DEPTH) {
            return ['ok' => false, 'reason' => 'depth'];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Force "exactly one of link / linkable" on every write.
     *
     * Three things were wrong before, and all three are cheap to fix here rather
     * than in the one form that happens to be the only writer today:
     *  - an item with NEITHER set was savable and simply never appeared in the API,
     *    which looks like a caching bug from the editor's side;
     *  - an item with BOTH set ignored its relation, because the raw link was
     *    checked first — so re-pointing a raw-URL item at a page appeared to do
     *    nothing (Filament does not dehydrate a hidden field, so the stale `link`
     *    column survived the save);
     *  - a `linkable_type` with no id (or the reverse) is a half-written morph that
     *    resolves to nothing.
     *
     * The relation is the winner when both arrive: it is the form the editor was
     * offered second, it is per-locale, and it is the one this model can validate.
     */
    public function normaliseTarget(): void
    {
        $hasRelation = filled($this->linkable_type) && filled($this->linkable_id);

        if ($hasRelation) {
            $this->link = null;

            return;
        }

        // A half-written morph is not a target; drop both halves so the row does
        // not carry a type pointing at nothing.
        $this->linkable_type = null;
        $this->linkable_id = null;

        if (filled($this->link)) {
            return;
        }

        throw ValidationException::withMessages([
            'link' => __('cms.validation.menu_target_required'),
        ]);
    }
}
