<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasFeaturedImage;
use App\Concerns\HasLinkTarget;
use App\Concerns\InteractsWithLocales;
use App\Concerns\IsAuditable;
use App\Contracts\HasFeaturedMedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Homepage slideshow slide.
 *
 * Requirements 3.4, 3.5 (max 5 active), 7.6 (no CLS, no autoplay video).
 *
 * A slide's destination works exactly like a menu item's, and for the same reason:
 * `link` used to be a bare string, so the call-to-action on a Persian-authored hero
 * sent English and Arabic visitors to the Persian page, and renaming the target's slug
 * broke the slideshow with nothing in the panel to show why. HasLinkTarget gives it
 * the polymorphic alternative, per-locale resolution through UrlBuilder, and the same
 * graceful degradation when a target is deleted, unpublished, or owned by a module
 * that has been switched off.
 */
class Slide extends Model implements HasFeaturedMedia
{
    use HasFactory;
    use HasFeaturedImage;
    use HasLinkTarget;
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
        'linkable_type',
        'linkable_id',
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
     * A slide needs no destination at all.
     *
     * The one place Slide and MenuItem genuinely differ. A menu item with no
     * destination is not a menu item; a slide with no destination is a decorative
     * hero, which is a normal thing to publish. Requiring one here would reject
     * existing rows — every slide created before the morph existed with an empty
     * `link` — on their next save, for a field the editor never filled in.
     */
    protected function linkTargetIsRequired(): bool
    {
        return false;
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
     * The preload decision when a caller has already made it for the whole set.
     *
     * Null means nobody asked, and isFirstActive() falls back to its own query — so a
     * single slide loaded on its own (the panel's table, a test) behaves exactly as
     * before. Deliberately NOT an attribute: it is a fact about the SET this slide was
     * loaded in, not a column, and putting it in $attributes would make every slide
     * look dirty and invite Eloquent to try to persist it.
     */
    private ?bool $preloadDecision = null;

    /**
     * Decide, once for an already-loaded active set, which slide the frontend
     * preloads (Requirement 7.6).
     *
     * This exists because `should_preload` was a per-row query. SlideResource called
     * isFirstActive() on every slide, and each call re-ran `active()->first()` — so
     * the endpoint asked the database which slide is first as many times as there are
     * slides, and every answer was the same. The cap of five (Requirement 3.5) kept it
     * cheap, but it is the homepage's payload and the pattern is what spreads.
     *
     * The decision belongs to the collection rather than to a slide: "is this the
     * first one" is not a property a row can answer about itself without looking at
     * its siblings, which is precisely why the model had to re-query to answer it.
     *
     * $slides MUST be the active set in position order — which is what
     * Slide::scopeActive() produces, and what the Delivery endpoint passes. Handing it
     * an arbitrary collection would stamp a confident wrong answer, so callers go
     * through the scope.
     *
     * @param  Collection<int, static>  $slides
     */
    public static function markPreloadTarget(Collection $slides): void
    {
        $first = $slides->first();

        foreach ($slides as $slide) {
            $slide->preloadDecision = $first !== null && $first->is($slide);
        }
    }

    /**
     * Requirement 7.6 — the first slide's image is preloaded, so the frontend
     * needs to know which one it is.
     *
     * Prefers a decision already made for the whole set (see markPreloadTarget), and
     * queries only when there is none.
     */
    public function isFirstActive(): bool
    {
        if ($this->preloadDecision !== null) {
            return $this->preloadDecision;
        }

        $first = static::query()->active()->first();

        return $first !== null && $first->getKey() === $this->getKey();
    }
}
