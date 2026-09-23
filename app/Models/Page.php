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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Static pages, including the brandable 404 (Requirements 3.1, 3.8).
 *
 * @property ContentStatus $status
 * @property Carbon|null $publish_date
 * @property int $position
 * @property string|null $system_key
 */
class Page extends Model implements HasFeaturedMedia, Publishable, TracksTranslationStatus
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
     * Pages the application resolves by name rather than slug.
     */
    public const SYSTEM_NOT_FOUND = '404';

    public const SYSTEM_MAINTENANCE = 'maintenance';

    /**
     * @var list<string>
     */
    public array $translatable = [
        'title',
        'slug',
        'blocks',
        'meta_title',
        'meta_description',
        'robots_meta',
    ];

    protected $fillable = [
        'title',
        'slug',
        'blocks',
        'meta_title',
        'meta_description',
        'robots_meta',
        'status',
        'publish_date',
        'position',
        'system_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'publish_date' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * Requirement 3.8 — resolve the branded 404 page.
     *
     * Returns null rather than throwing when absent so the error handler can
     * fall back to a plain response: a missing 404 page must not turn a 404 into
     * a 500.
     */
    public static function notFoundPage(): ?self
    {
        return static::query()->where('system_key', self::SYSTEM_NOT_FOUND)->first();
    }

    /**
     * System pages are protected from deletion by PagePolicy. Deleting the 404
     * page would leave the site with no error page, and an editor tidying up
     * would have no reason to expect that consequence.
     */
    public function isSystemPage(): bool
    {
        return filled($this->system_key);
    }
}
