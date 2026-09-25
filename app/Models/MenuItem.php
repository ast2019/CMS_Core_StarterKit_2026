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
