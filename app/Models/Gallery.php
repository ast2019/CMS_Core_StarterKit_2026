<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasFeaturedImage;
use App\Concerns\HasPublishStatus;
use App\Concerns\HasSeoMeta;
use App\Concerns\HasSlug;
use App\Concerns\HasTranslationStatus;
use App\Concerns\IsAuditable;
use App\Contracts\Publishable;
use App\Contracts\TracksTranslationStatus;
use App\Enums\ContentStatus;
use App\Enums\MediaRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Image galleries. Requirement 3.1.
 *
 * Decision D-4: the cover is the single `featured` attachment and is what
 * RULE #7 governs; items are `gallery`-role attachments and are uncapped. The
 * cover may also be one of the items.
 *
 * @property ContentStatus $status
 * @property Carbon|null $publish_date
 * @property-read Collection<int, MediaAsset> $items
 */
class Gallery extends Model implements Publishable, TracksTranslationStatus
{
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
        'description',
        'meta_title',
        'meta_description',
        'robots_meta',
    ];

    protected $fillable = [
        'title',
        'slug',
        'description',
        'meta_title',
        'meta_description',
        'robots_meta',
        'status',
        'publish_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'publish_date' => 'datetime',
        ];
    }

    /**
     * The ordered item list, distinct from the cover (Decision D-4).
     *
     * @return MorphToMany<MediaAsset, $this>
     */
    public function items(): MorphToMany
    {
        return $this->mediaAssetsInRole(MediaRole::Gallery);
    }

    public function cover(): ?MediaAsset
    {
        return $this->featuredImage();
    }

    public function itemCount(): int
    {
        return $this->items()->count();
    }
}
