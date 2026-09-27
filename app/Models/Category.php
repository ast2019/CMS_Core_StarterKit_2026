<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSeoMeta;
use App\Concerns\HasSlug;
use App\Concerns\HasTranslationStatus;
use App\Concerns\IsAuditable;
use App\Contracts\HasSeoMetadata;
use App\Contracts\TracksTranslationStatus;
use App\Services\Content\UsageInspector;
use App\Support\Plural;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

/**
 * Hierarchical taxonomy. Requirement 3.1.
 *
 * Item 11 — soft-deleted. A category holds a per-locale name, description and SEO metadata,
 * sits in a tree, and decides the canonical URL of every article whose primary category it
 * is. Deleting one used to take all of that at once, irreversibly.
 *
 * TWO CONSEQUENCES OF THE TRASH, both handled elsewhere and noted here because they are not
 * visible from this class:
 *
 *  - A trashed category that is some article's PRIMARY category would silently change that
 *    article's canonical URL and degrade its BreadcrumbList to "Home > Article", with
 *    `articleSection` disappearing — output that still validates, so nothing complains. Refused
 *    by guardPrimaryCategoryUse() below, on the model rather than in the panel, so a seeder, an
 *    import and the Management API are covered too.
 *  - Children are NOT carried down with the parent. Unlike a menu item, a trashed category's
 *    children remain serviceable: they keep their own slugs, their own articles and their own
 *    URLs (category URLs are flat, so nothing about a child's address depends on its parent).
 *    Cascading would destroy a working subtree to tidy up its label, so the delete reports the
 *    child count (UsageInspector::usage) and leaves the decision to the editor.
 *
 *    What DOES change is the BreadcrumbList of articles below the trashed node: ancestors() walks
 *    the scoped `parent` relation, so the trail ends at the trashed level and comes out shorter.
 *    That is correct rather than degraded — a category that is no longer on the site should not
 *    appear in a trail claiming it is — and it is why the trail is not resolved withTrashed(),
 *    which would advertise a link to an archive page that 404s.
 *
 * @property-read Collection<int, self> $children
 */
class Category extends Model implements HasSeoMetadata, TracksTranslationStatus
{
    use HasFactory;
    use HasSeoMeta;
    use HasSlug;
    use HasTranslations;
    use HasTranslationStatus;
    use IsAuditable;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    public array $translatable = [
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'robots_meta',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'robots_meta',
        'parent_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Refuse to delete a category that decides some article's canonical URL (Decision D-2).
         *
         * ON THE MODEL, not only in the panel. This rule previously existed solely inside
         * GuardedDeleteActions, so `$category->delete()` from a seeder, an import, the Management
         * API or any edit page still wired to a plain DeleteAction succeeded — and three docblocks
         * (including this class's) claimed otherwise. UsageInspector::blockedReason() returns a
         * value; it refuses nothing on its own.
         *
         * The consequence is quiet by construction: the article's BreadcrumbList degrades to
         * "Home > Article", `articleSection` disappears, and both still validate, so no consumer
         * reports anything.
         *
         * Both paths, `deleting` and `forceDeleting`, for the reason MediaAsset documents: on a
         * force delete the guard has to run before any other listener gets to destroy something.
         */
        static::forceDeleting(function (self $category): void {
            $category->guardPrimaryCategoryUse();
        });

        static::deleting(function (self $category): void {
            $category->guardPrimaryCategoryUse();
        });
    }

    /**
     * @throws ValidationException when an article still records this as its primary category
     */
    protected function guardPrimaryCategoryUse(): void
    {
        $blocked = app(UsageInspector::class)->blockedReason($this);

        if ($blocked === null) {
            return;
        }

        throw ValidationException::withMessages([
            'primary_category_id' => Plural::trans($blocked['key'], $blocked['parameters']),
        ]);
    }

    /**
     * Categories use `name`, not `title`, as their slug source.
     */
    public function slugSourceAttribute(): string
    {
        return 'name';
    }

    /**
     * HasSeoMeta::metaTitleFor() falls back to `title`, which this model does not
     * have. Aliasing keeps the shared SEO logic working without giving the trait
     * per-model special cases.
     */
    public function getAttribute($key): mixed
    {
        if ($key === 'title') {
            return parent::getAttribute('name');
        }

        return parent::getAttribute($key);
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
     * @return BelongsToMany<Content, $this>
     */
    public function contents(): BelongsToMany
    {
        /*
         * withPivot('is_primary'), matching Content::categories().
         *
         * The two sides describe the same pivot row, and only one of them selected the
         * flag — so reading `$content->pivot->is_primary` from the CATEGORY side silently
         * gave null, and any caller deciding something on it would decide it wrongly. The
         * panel's category→articles list is the first such caller: it marks the primary
         * article and protects that row from being detached, both of which need the flag
         * to actually arrive.
         */
        return $this->belongsToMany(Content::class)->withPivot('is_primary');
    }

    /**
     * Ancestors, nearest first. Used to build BreadcrumbList JSON-LD
     * (Requirement 7.3).
     *
     * Walks upward with a depth guard rather than recursing freely: a category
     * tree corrupted into a cycle by a bad import would otherwise hang the
     * request that renders breadcrumbs.
     *
     * @return list<self>
     */
    public function ancestors(int $maxDepth = 10): array
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
     * @param  Builder<$this>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id')->orderBy('position');
    }
}
