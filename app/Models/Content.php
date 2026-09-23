<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasContentVersions;
use App\Concerns\HasFeaturedImage;
use App\Concerns\HasPublishStatus;
use App\Concerns\HasSeoMeta;
use App\Concerns\HasSlug;
use App\Concerns\HasTranslationStatus;
use App\Concerns\IsAuditable;
use App\Contracts\HasFeaturedMedia;
use App\Contracts\Publishable;
use App\Contracts\TracksTranslationStatus;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * News / articles.
 *
 * Carries every content-bearing concern, which is what makes the nine rules
 * uniform across the CMS rather than re-implemented per model:
 *  - HasFeaturedImage      RULE #7
 *  - IsAuditable           RULE #8
 *  - HasTranslationStatus  Requirements 5.3-5.6
 *  - HasSlug               Decision D-1
 *  - HasSeoMeta            Requirement 7.1
 *  - HasPublishStatus      Requirement 3.6
 *  - HasContentVersions    Requirement 3.7
 *
 * @property ContentStatus $status
 * @property Carbon|null $publish_date
 * @property int|null $author_id
 * @property int|null $primary_category_id
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Tag> $tags
 */
class Content extends Model implements HasFeaturedMedia, Publishable, TracksTranslationStatus
{
    use HasContentVersions;
    use HasFactory;
    use HasFeaturedImage;
    use HasPublishStatus;
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
        'title',
        'slug',
        'excerpt',
        'body',
        'answer_paragraph',
        'meta_title',
        'meta_description',
        'robots_meta',
    ];

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'answer_paragraph',
        'meta_title',
        'meta_description',
        'robots_meta',
        'status',
        'publish_date',
        'author_id',
        'primary_category_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'publish_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Decision D-2 — the canonical category, driving the article URL and the
     * BreadcrumbList JSON-LD, both of which need a single path.
     *
     * @return BelongsTo<Category, $this>
     */
    public function primaryCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'primary_category_id');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withPivot('is_primary');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Keep the pivot's `is_primary` flag in step with primary_category_id, and
     * guarantee the primary category is also a member of the set. Without this
     * an editor could set a primary category that the article is not filed
     * under, and category archive pages would omit their own lead article.
     */
    public function syncPrimaryCategory(): void
    {
        if ($this->primary_category_id === null) {
            $this->categories()->newPivotStatement()
                ->where('content_id', $this->getKey())
                ->update(['is_primary' => false]);

            return;
        }

        if (! $this->categories()->whereKey($this->primary_category_id)->exists()) {
            $this->categories()->attach($this->primary_category_id);
        }

        $this->categories()->newPivotStatement()
            ->where('content_id', $this->getKey())
            ->update(['is_primary' => false]);

        $this->categories()->updateExistingPivot($this->primary_category_id, ['is_primary' => true]);
    }

    /**
     * Auto-suggested related articles (Requirement 4.8).
     *
     * Scored by shared taxonomy: a shared category counts for more than a shared
     * tag, because categories are curated and tags are freeform. Restricted to
     * live records so a draft never leaks through a "related" list — an easy
     * place for unpublished content to escape.
     *
     * @return Collection<int, static>
     */
    public function relatedContent(?int $limit = null): Collection
    {
        $limit ??= (int) config('cms.related.limit', 6);
        $categoryWeight = (int) config('cms.related.weight_category', 2);
        $tagWeight = (int) config('cms.related.weight_tag', 1);

        $categoryIds = $this->categories->pluck('id');
        $tagIds = $this->tags->pluck('id');

        if ($categoryIds->isEmpty() && $tagIds->isEmpty()) {
            return new Collection;
        }

        return static::query()
            ->live()
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $query) use ($categoryIds, $tagIds): void {
                if ($categoryIds->isNotEmpty()) {
                    $query->orWhereHas(
                        'categories',
                        fn (Builder $inner) => $inner->whereIn('categories.id', $categoryIds),
                    );
                }

                if ($tagIds->isNotEmpty()) {
                    $query->orWhereHas(
                        'tags',
                        fn (Builder $inner) => $inner->whereIn('tags.id', $tagIds),
                    );
                }
            })
            ->withCount([
                'categories as shared_categories' => fn (Builder $q) => $q->whereIn('categories.id', $categoryIds),
                'tags as shared_tags' => fn (Builder $q) => $q->whereIn('tags.id', $tagIds),
            ])
            ->orderByRaw(
                "(shared_categories * {$categoryWeight} + shared_tags * {$tagWeight}) desc",
            )
            ->orderByDesc('publish_date')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInCategory(Builder $query, int $categoryId): void
    {
        $query->whereHas('categories', fn (Builder $inner) => $inner->whereKey($categoryId));
    }
}
