<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasFeaturedImage;
use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use App\Contracts\HasFeaturedMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Homepage slideshow slide.
 *
 * Requirements 3.4, 3.5 (max 5 active), 7.6 (no CLS, no autoplay video).
 */
class Slide extends Model implements HasFeaturedMedia
{
    use HasFactory;
    use HasFeaturedImage;
    use HasTranslations;
    use InteractsWithLocales;
    use IsAuditable;

    /**
     * @var list<string>
     */
    public array $translatable = ['title', 'subtitle', 'cta_label'];

    protected $fillable = [
        'title',
        'subtitle',
        'cta_label',
        'link',
        'position',
        'is_active',
        'image_width',
        'image_height',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
            'image_width' => 'integer',
            'image_height' => 'integer',
        ];
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('position');
    }

    public static function maxSlides(): int
    {
        return (int) config('cms.slides.max', 5);
    }

    /**
     * Whether another active slide may be added.
     *
     * The cap applies to ACTIVE slides only. Counting inactive ones would stop
     * an editor from preparing next month's campaign alongside this month's,
     * which is a normal way to work and not what the performance cap protects
     * against — the cap exists because each slide is a large hero image.
     */
    public static function canAddActiveSlide(): bool
    {
        return static::query()->where('is_active', true)->count() < static::maxSlides();
    }

    /**
     * Requirement 7.6 — the first slide's image is preloaded, so the frontend
     * needs to know which one it is.
     */
    public function isFirstActive(): bool
    {
        $first = static::query()->active()->first();

        return $first !== null && $first->getKey() === $this->getKey();
    }
}
