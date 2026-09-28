<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RedirectType;
use App\Support\Digits;
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

    // v2: entries carry the stored from_path and keys are decoded paths. A new key so a
    // map cached forever in the old shape is not read after deploy.
    public const CACHE_KEY = 'cms:redirects:map:v2';

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

        /*
         * Digits too: slugs — and so every URL this site generates — carry ASCII digits,
         * while an editor pasting /fa/news/خبر-۱۴۰۳ typed Persian ones. Stored as typed,
         * that row could never match the real URL.
         */
        $path = Digits::toAscii(self::decodePercentEscapes($path));

        $path = '/'.ltrim($path, '/');

        // Collapse duplicate slashes, then drop a trailing slash (but keep root).
        $path = (string) preg_replace('#/+#', '/', $path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * One spelling per path: `/fa/news/%D8%B9...` and `/fa/news/ع...` are the same URL.
     *
     * A browser always sends a Persian path percent-encoded, and the request's path info
     * keeps it that way, while the slug-change offer stores it as Unicode — so a stored
     * Persian redirect never matched a real visitor. Editors pasting from the address
     * bar produce the encoded form too. Decoding both the stored and the requested path
     * makes them compare equal.
     *
     * `%2F` stays encoded: decoding it would turn one segment into two. And a sequence
     * that does not decode to valid UTF-8 is left exactly as it arrived rather than
     * stored as broken bytes.
     */
    private static function decodePercentEscapes(string $path): string
    {
        if (! str_contains($path, '%')) {
            return $path;
        }

        $decoded = (string) preg_replace_callback(
            '/%(?!2[fF])([0-9A-Fa-f]{2})/',
            static fn (array $match): string => chr((int) hexdec($match[1])),
            $path,
        );

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $path;
    }

    public function setFromPathAttribute(string $value): void
    {
        $this->attributes['from_path'] = static::normalisePath($value);
    }

    public function recordHit(): void
    {
        /*
         * Avoids a model event and a full save on a redirect hit, which happens on a hot path.
         *
         * Through the BASE query builder, deliberately. The Eloquent builder's update() adds
         * `updated_at` to every UPDATE, so each public hit used to rewrite the redirect's "last
         * modified" time — making it mean "last visited" instead, and making the panel's
         * concurrent-edit guard (item 35) see a change underneath the form on every request while
         * anybody was following the link. A hit is a statistic, not an edit.
         *
         * increment() rather than `hits + 1` from the loaded model: two hits in flight would both
         * read the same count and one of them would be lost. The database does the arithmetic.
         */
        static::query()->whereKey($this->getKey())->toBase()->increment('hits', 1, [
            'last_hit_at' => now(),
        ]);
    }
}
