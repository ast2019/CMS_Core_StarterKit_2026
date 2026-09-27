<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasLinkTarget;
use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

/**
 * Navigation. Requirement 3.1.
 *
 * An item points either at a raw URL or at a CMS record. The relational form is
 * the one worth having: a linked record's slug is per-locale, so one item resolves
 * to a different path in each language, and renaming the record's slug moves the
 * menu with it instead of breaking it. That behaviour is shared with Slide and lives
 * in HasLinkTarget.
 *
 * Three invariants are enforced on the write path rather than only in the panel, so
 * a seed, an import or a future Management API cannot produce navigation that
 * silently disappears from the API:
 *  - exactly one of `link` / `linkable_type` is set (HasLinkTarget::normaliseTarget());
 *  - `menu_key` names a location this deployment declares (guardMenuLocation());
 *  - the tree stays acyclic and no deeper than MAX_DEPTH (the form validates it;
 *    the read path caps traversal regardless).
 *
 * @property-read Collection<int, self> $children
 * @property Carbon|null $deleted_at
 */
class MenuItem extends Model
{
    use HasFactory;

    /*
     * The raw-URL-or-record target, shared with Slide. It used to live here; Slide
     * needed the identical rules, and two copies of "which target wins, and when is a
     * target unreachable" would drift the first time one of them was fixed.
     */
    use HasLinkTarget;
    use HasTranslations;
    use InteractsWithLocales;
    use IsAuditable;

    /*
     * Item 11. The subtree is carried down and back up explicitly — see booted(). The
     * `cascadeOnDelete` on `parent_id` does not fire on a soft delete, and an orphaned branch
     * disappears from the menu endpoint without an error.
     */
    use SoftDeletes;

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
     * The location every site has, and the column default.
     *
     * Kept as a constant rather than read from config because it is also the `menu_key`
     * column's default in the migration and the fallback when the configured list is
     * empty — a value the schema depends on should not be able to drift with an
     * environment variable.
     */
    public const DEFAULT_MENU_KEY = 'header';

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
            $item->guardMenuLocation();
        });

        /*
         * Item 11 — carry the subtree down, because the database will not.
         *
         * `menu_items.parent_id` is declared cascadeOnDelete, and that constraint does not
         * fire on a soft delete: a soft delete is an UPDATE. So trashing a parent used to
         * leave its children in place with a `parent_id` pointing at a row the default scope
         * hides — which means `topLevel()` excludes them (they still have a parent) and the
         * parent's `children` is unreachable (the parent is hidden). The entire branch
         * disappears from GET /api/v1/menus/{key} with no error anywhere, which is exactly
         * the failure this class's docblock describes for cycles.
         *
         * Only on a soft delete. A force delete lets the real cascade do its job, and doing
         * both would soft-delete the children and then hard-delete them a moment later.
         */
        /*
         * In `deleted`, not `deleting`, and that is a correctness fix rather than a tidy-up.
         *
         * Stamping the children in `deleting` meant reading the clock a second time: the parent is
         * stamped a moment later by runSoftDelete() from its own freshTimestamp(), and `deleted_at`
         * is stored to the second. Cross a second boundary between the two writes and the children
         * carry T while the parent carries T+1 — at which point restoreDescendants(), which pairs
         * them on an exact match, finds nothing and the restore cascade silently does nothing. The
         * same failure, with the same silence, as the getOriginal() bug this code already replaced,
         * and invisible to every test because travel() freezes the clock.
         *
         * `deleted` fires after performDeleteOnModel() has run runSoftDelete(), so `$this->deleted_at`
         * is the value actually in the row and can simply be copied. One clock read, no race.
         *
         * (Not `trashed`: SoftDeletes has no such MODEL EVENT — `trashed()` is an instance method
         * returning a bool, so registering a listener for it fails at boot with "cannot be called
         * statically". The soft-delete events are restoring/restored/forceDeleting/forceDeleted.)
         */
        static::deleted(function (self $item): void {
            if ($item->isForceDeleting()) {
                return;
            }

            $item->trashDescendants();
        });

        /*
         * And bring it back up. Without this, restoring a parent returns an item whose
         * children are still in the trash — so the menu comes back visibly shorter than it
         * was and an editor has to find and restore each child by hand, in the right order.
         */
        /*
         * The timestamp has to be read BEFORE the restore, not after.
         *
         * Eloquent's restore() nulls `deleted_at`, saves, and only then fires `restored` — and
         * the save has already called syncOriginal(), so by the time the `restored` handler runs
         * both the attribute and getOriginal('deleted_at') are null. Reading it there found
         * nothing and the cascade silently did nothing at all: the parent came back and its
         * children stayed in the trash, which is the failure this pair of hooks exists to
         * prevent. Caught by the test that asserts a whole branch returns.
         */
        static::restoring(function (self $item): void {
            $item->deletedAtBeforeRestore = $item->deleted_at?->toDateTimeString();

            /*
             * Bring the ANCESTORS back first, or the restore produces a live item nobody can see.
             *
             * A child restored on its own keeps a `parent_id` pointing at a row the default scope
             * hides: topLevel() excludes it (it has a parent) and the parent's `children` is
             * unreachable (the parent is trashed), so the item vanishes from
             * GET /api/v1/menus/{key} — failure #3 again, arriving from the restore direction
             * instead of the delete direction. RestoreAction is visible on any trashed row, so this
             * was one click away in the trash view.
             *
             * It also closes the sharper edge behind it: `menu_items.parent_id` IS a real
             * cascadeOnDelete, so once a live child sat under a trashed parent, permanently deleting
             * that parent — by hand, or by cms:prune-trash thirty days later — hard-deleted the live
             * child's row.
             *
             * Restoring an ancestor is not a decision being made for the editor: an item cannot
             * exist in a menu without its parents, so "restore this item" can only mean "restore the
             * path to it".
             */
            $item->restoreAncestors();
        });

        static::restored(function (self $item): void {
            $item->restoreDescendants();
        });
    }

    /**
     * The `deleted_at` this item carried a moment before it was restored.
     *
     * Transient, and deliberately not an attribute: it is bookkeeping for one operation, not a
     * column, and putting it in $attributes would make every restored item look dirty and
     * invite Eloquent to try to persist it. Same reasoning as Slide::$preloadDecision.
     */
    private ?string $deletedAtBeforeRestore = null;

    /**
     * Soft-delete every item below this one.
     *
     * Marked with the SAME `deleted_at` instant as the parent, which is what makes the
     * restore side possible: restoreDescendants() can then identify precisely the items that
     * went down with this parent, rather than sweeping up children an editor had deleted
     * separately and deliberately earlier.
     */
    protected function trashDescendants(): void
    {
        $keys = array_values(array_diff($this->descendantKeys(), [(int) $this->getKey()]));

        if ($keys === []) {
            return;
        }

        /*
         * A single UPDATE rather than iterating and calling delete(). The alternative would
         * re-enter this same `deleting` hook once per node and walk the tree again from each
         * one — quadratic on a branch, and it would stamp a different timestamp per level,
         * breaking the restore pairing above.
         *
         * The trade is that no per-child audit row is written. That is the right call: the
         * audited action is "the editor deleted this menu item", and one row per node would
         * describe a decision nobody made about each child.
         */
        static::query()->whereKey($keys)->update(['deleted_at' => $this->deleted_at]);
    }

    /**
     * Restore every trashed item on the path from this one up to the top level.
     *
     * Walked with withTrashed(), because the whole problem is that the scoped `parent` relation
     * cannot see a trashed parent. Bounded by WALK_LIMIT like every other walk in this class, so a
     * tree corrupted into a cycle breaks the loop rather than hanging the request.
     *
     * Each ancestor is restored through restore(), so its own hooks run — which means a partially
     * trashed path is repaired from the top down and the `restored` cascade below does not then
     * fight it: an ancestor that is already live is skipped, and one that is not is restored with
     * the branch it took down.
     */
    protected function restoreAncestors(): void
    {
        $parentId = $this->parent_id;
        $seen = [(int) $this->getKey() => true];

        for ($step = 0; $step < self::WALK_LIMIT && $parentId !== null; $step++) {
            if (isset($seen[(int) $parentId])) {
                break;
            }

            $seen[(int) $parentId] = true;

            $parent = static::withTrashed()->find($parentId);

            if ($parent === null) {
                break;
            }

            if ($parent->trashed()) {
                $parent->restore();

                // restore() on the ancestor walks the rest of the path itself, so continuing here
                // would repeat the work for every level.
                return;
            }

            $parentId = $parent->parent_id;
        }
    }

    /**
     * Restore the items that were trashed together with this one.
     *
     * Matched on the shared `deleted_at` instant rather than on `parent_id` alone, so a child
     * the editor had deleted on its own last week stays deleted. Restoring it too would be a
     * decision the editor never made, and it would silently put an item back into live
     * navigation.
     */
    protected function restoreDescendants(): void
    {
        $deletedAt = $this->deletedAtBeforeRestore;
        $this->deletedAtBeforeRestore = null;

        if ($deletedAt === null) {
            return;
        }

        static::withTrashed()
            ->where('parent_id', $this->getKey())
            ->where('deleted_at', $deletedAt)
            ->get()
            // Through restore() per item, not one UPDATE: each child may have a subtree of
            // its own, and recursing through the `restored` event is what brings a
            // three-level branch back whole.
            ->each(fn (self $child) => $child->restore());
    }

    /**
     * Keys of the menu locations this deployment declares. Requirement 3.1.
     *
     * Reads `cms.menus.locations`, so a client site declares its own navigation
     * regions in config instead of editing this class — the previous hardcoded list
     * meant a site with a "utility" bar or no sidebar had to patch the Core, which is
     * the thing a reusable Core exists to avoid (Requirement 1.2).
     *
     * @return list<string>
     */
    public static function menuKeys(): array
    {
        /** @var array<array-key, mixed> $configured */
        $configured = (array) config('cms.menus.locations', []);

        $keys = array_values(array_filter(
            array_map(fn (mixed $key): string => is_string($key) ? $key : '', $configured),
            fn (string $key): bool => $key !== '',
        ));

        /*
         * Never an empty set. A deployment that mis-configures the list to nothing
         * would otherwise be unable to save a menu item at all AND would 404 every
         * menu endpoint — a config typo taking the whole navigation subsystem down. The
         * default location is the one every site has.
         */
        return $keys === [] ? [self::DEFAULT_MENU_KEY] : $keys;
    }

    /**
     * Location key => human label, for the panel's Select and table filter.
     *
     * The label is a TRANSLATION, resolved by convention from the key, rather than a
     * string stored beside the key in config. Two reasons: the panel is trilingual, so
     * a literal label in config would force a client to pick one language for a field
     * the rest of the panel translates; and config is loaded before (and cached
     * independently of) the locale, so a translated value there could not follow the
     * active locale anyway. A location with no translation falls back to its own key,
     * so a client can add "utility" to config and ship without touching lang files.
     *
     * @return array<string, string>
     */
    public static function menuLocations(): array
    {
        $locations = [];

        foreach (self::menuKeys() as $key) {
            $translationKey = "cms.menu.location.{$key}";
            $label = __($translationKey);

            $locations[$key] = is_string($label) && $label !== $translationKey ? $label : $key;
        }

        return $locations;
    }

    public static function isKnownMenuKey(string $key): bool
    {
        return in_array($key, self::menuKeys(), strict: true);
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
     * The target's `translationStates` are loaded with it, not just the target. The
     * payload reports `meta.translation_status` per item (Requirement 5.5), which reads
     * that relation — so loading the morph and not its states traded one N+1 for
     * another, and the second one was invisible because the first had been fixed.
     *
     * The nested string form works because every linkable type tracks translation
     * status; a MorphTo merges nested eager loads across all of its types, so a future
     * linkable type WITHOUT `translationStates` would need morphWith() here. It would
     * fail loudly rather than silently, which is why the simpler form is acceptable.
     *
     * @return list<string>
     */
    public static function treeEagerLoads(): array
    {
        $loads = [self::LINK_TARGET_EAGER_LOAD];
        $path = '';

        for ($level = 1; $level < self::MAX_DEPTH; $level++) {
            $path .= 'children.';
            $loads[] = $path.self::LINK_TARGET_EAGER_LOAD;
        }

        return $loads;
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
     * The message for an item with no destination at all.
     *
     * Overrides HasLinkTarget's generic wording because the menu form names the two
     * options the editor was actually offered, and a message that does not match the
     * fields on screen reads as a bug in the panel.
     */
    protected function linkTargetRequiredMessage(): string
    {
        return __('cms.validation.menu_target_required');
    }

    /**
     * Refuse a `menu_key` this deployment does not declare.
     *
     * The column was a free string with no validation anywhere, so a typo in a seed
     * or an import produced a menu nobody could find: the items existed, the panel
     * filter did not offer the key, and `GET /api/v1/menus/{key}` answered 200 with an
     * empty list for both a typo and a genuinely empty menu. Validating the write and
     * 404ing the unknown read makes those two cases distinguishable — which is the
     * whole point of locations being a declared set.
     */
    protected function guardMenuLocation(): void
    {
        $key = (string) $this->menu_key;

        if ($key === '') {
            $this->menu_key = self::DEFAULT_MENU_KEY;

            return;
        }

        if (self::isKnownMenuKey($key)) {
            return;
        }

        throw ValidationException::withMessages([
            'menu_key' => __('cms.validation.menu_key_unknown', [
                'key' => $key,
                'locations' => implode('، ', self::menuKeys()),
            ]),
        ]);
    }
}
