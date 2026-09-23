<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSeoMeta;
use App\Concerns\HasSlug;
use App\Concerns\HasTranslationStatus;
use App\Concerns\IsAuditable;
use App\Contracts\TracksTranslationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * Hierarchical taxonomy. Requirement 3.1.
 *
 * @property-read Collection<int, self> $children
 */
class Category extends Model implements TracksTranslationStatus
{
    use HasFactory;
    use HasSeoMeta;
    use HasSlug;
    use HasTranslations;
    use HasTranslationStatus;
    use IsAuditable;

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
        return $this->belongsToMany(Content::class);
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
