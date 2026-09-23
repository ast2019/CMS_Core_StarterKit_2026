<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedirectType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;

/**
 * Requirement 7.5.
 *
 * @property RedirectType $type
 * @property string $from_path
 * @property string $to_path
 */
class Redirect extends Model
{
    use HasFactory;

    public const CACHE_KEY = 'cms:redirects:map';

    protected $fillable = [
        'from_path',
        'to_path',
        'type',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => RedirectType::class,
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The whole table is cached as one map, so any write invalidates it.
        // Redirects are read on potentially every 404 and written rarely, which
        // makes full-map caching the right trade.
        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Normalise to a leading-slashed, un-trailing-slashed path.
     *
     * Editors paste full URLs. Storing a host would break every redirect the
     * moment the site moved domain or ran on a staging hostname, so the host is
     * stripped here rather than trusted.
     */
    public static function normalisePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        // Strip scheme+host if a full URL was pasted.
        if (preg_match('#^https?://#i', $path) === 1) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '/');
        }

        $path = '/'.ltrim($path, '/');

        // Collapse duplicate slashes, then drop a trailing slash (but keep root).
        $path = (string) preg_replace('#/+#', '/', $path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function setFromPathAttribute(string $value): void
    {
        $this->attributes['from_path'] = static::normalisePath($value);
    }

    public function recordHit(): void
    {
        // Avoids a model event and a full save on a redirect hit, which happens
        // on a hot path.
        static::query()->whereKey($this->getKey())->update([
            'hits' => $this->hits + 1,
            'last_hit_at' => now(),
        ]);
    }
}
