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
use App\Contracts\HasSeoMetadata;
use App\Contracts\Publishable;
use App\Contracts\TracksTranslationStatus;
use App\Contracts\Versionable;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Translatable\HasTranslations;

/**
 * Static pages, including the brandable 404 (Requirements 3.1, 3.8).
 *
 * @property ContentStatus $status
 * @property Carbon|null $publish_date
 * @property int $position
 * @property string|null $system_key
 */
class Page extends Model implements HasFeaturedMedia, HasSeoMetadata, Publishable, TracksTranslationStatus, Versionable
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
     * The page rendered at the locale root, e.g. /fa.
     *
     * Modelled as a system key rather than an `is_home` boolean, because the key is
     * the mechanism this table already has for "the page the application resolves by
     * name instead of by slug" — which is exactly what a homepage is. It also comes
     * with the guarantee the feature needs for free: `system_key` carries a UNIQUE
     * index, so a second homepage cannot exist at the storage level. A boolean would
     * have needed a partial index (not portable across MySQL and SQLite, Decision
     * D-1's lesson) or an application-only rule, and two pages silently competing for
     * `/fa` is the failure mode worth designing out rather than validating for.
     */
    public const SYSTEM_HOME = 'home';

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
        /*
         * A page carries prose (`blocks`), so the keyphrase analysis has something
         * real to measure on it — unlike a Gallery or a Category. Translatable for the
         * same reason as on Content: the English page targets an English phrase.
         */
        'focus_keyphrase',
    ];

    protected $fillable = [
        'title',
        'slug',
        'blocks',
        'meta_title',
        'meta_description',
        'robots_meta',
        'focus_keyphrase',
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

    protected static function booted(): void
    {
        /*
         * The unique index on `system_key` is the real guarantee, but it fails as a
         * database integrity error — an opaque 500 in the panel and an unhandled
         * exception in a seeder or import. Checking on the write path turns the same
         * refusal into a message that names the page already holding the key, which
         * is the only thing the writer can act on. Same reasoning as
         * MenuItem::normaliseTarget(): the invariant belongs to the model, not to the
         * one form that happens to be the only writer today.
         */
        static::saving(function (self $page): void {
            $page->guardSystemKeyIsUnique();
        });
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
     * The page designated as the site's homepage, if one has been designated.
     *
     * Deliberately NOT filtered by `live()`, unlike the Delivery endpoint that serves
     * it. The two questions are different: "which record owns the URL /fa" governs
     * URL SHAPE, and the answer must not change when the page is unpublished for an
     * hour — otherwise the same record would canonicalise to /fa while published and
     * to /fa/{slug} while draft, and the sitemap and the menu would disagree with
     * each other mid-edit. Whether the homepage may be SERVED is a separate check,
     * made where it is served.
     */
    public static function homePage(): ?self
    {
        return static::query()->where('system_key', self::SYSTEM_HOME)->first();
    }

    /**
     * Whether this record is the one rendered at the locale root.
     *
     * Reads the already-loaded column rather than querying, because UrlBuilder calls
     * it for every record it builds a URL for — including every row of a sitemap.
     */
    public function isHomePage(): bool
    {
        return $this->system_key === self::SYSTEM_HOME;
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

    /**
     * Another page already holding the same system key, if any.
     *
     * Includes SOFT-DELETED rows, because the unique index does. A page moved to the
     * trash still occupies its key, so without `withTrashed()` the panel would accept
     * the designation and the save would then fail as an integrity error the editor
     * cannot act on — the competing page is not visible to them anywhere.
     */
    public static function otherPageWithSystemKey(string $systemKey, ?int $exceptId = null): ?self
    {
        return static::withTrashed()
            ->where('system_key', $systemKey)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    /**
     * Refuse a system key another page already owns.
     *
     * Only runs when the key is actually being written or changed, so re-saving the
     * existing homepage for any other reason costs no query.
     */
    protected function guardSystemKeyIsUnique(): void
    {
        if (blank($this->system_key) || ! $this->isDirty('system_key')) {
            return;
        }

        $existing = static::otherPageWithSystemKey(
            (string) $this->system_key,
            $this->exists ? (int) $this->getKey() : null,
        );

        if ($existing === null) {
            return;
        }

        throw ValidationException::withMessages([
            'system_key' => __('cms.validation.system_key_taken', [
                'key' => (string) $this->system_key,
                'title' => $existing->getTranslation('title', config('cms.locales.source', 'fa')) ?: '#'.$existing->getKey(),
            ]),
        ]);
    }
}
