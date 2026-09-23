<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Translatable\HasTranslations;

/**
 * Navigation. Requirement 3.1.
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
     * Resolve the destination for a locale.
     *
     * A linked record's slug is per-locale, so the same menu item points at a
     * different path in each language. Returns null when the target is missing
     * or not live, so the frontend can omit the item rather than render a link
     * to a 404.
     */
    public function resolveUrl(string $locale): ?string
    {
        if (filled($this->link)) {
            return $this->link;
        }

        $target = $this->linkable;

        if ($target === null) {
            return null;
        }

        if (method_exists($target, 'isLive') && ! $target->isLive()) {
            return null;
        }

        $slug = $target->getTranslation('slug', $locale, useFallbackLocale: true);

        if (blank($slug)) {
            return null;
        }

        $segment = match ($target::class) {
            Content::class => 'news',
            Category::class => 'category',
            Gallery::class => 'gallery',
            default => null,
        };

        return $segment === null
            ? "/{$locale}/{$slug}"
            : "/{$locale}/{$segment}/{$slug}";
    }
}
