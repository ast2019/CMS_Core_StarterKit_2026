<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\IsAuditable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Global settings — the singleton in behaviour, key/value in storage.
 *
 * Requirement 1.2: every client-specific value lives here rather than in
 * committed code, which is what lets one Core be copied per client.
 *
 * @property mixed $value
 */
class Setting extends Model
{
    use IsAuditable;

    public const CACHE_KEY = 'cms:settings';

    /**
     * Known keys, so the panel can render a typed form and a typo cannot create
     * a silently-ignored setting.
     */
    public const SITE_NAME = 'site_name';

    public const SOCIAL_LINKS = 'social_links';

    public const GA_MEASUREMENT_ID = 'ga_measurement_id';

    public const GTM_CONTAINER_ID = 'gtm_container_id';

    public const GSC_VERIFICATION = 'gsc_verification';

    public const BING_VERIFICATION = 'bing_verification';

    public const MAINTENANCE_MODE = 'maintenance_mode';

    public const ORGANISATION_SCHEMA = 'organisation_schema';

    /**
     * AI translation configuration (Requirement 5.3). These are edited on the
     * Settings page and stored here rather than in .env, so a client can be
     * configured from the panel without a redeploy.
     */
    public const AI_TRANSLATION_ENABLED = 'ai_translation_enabled';

    /**
     * The LEGACY shared model key.
     *
     * The model is now stored per provider — `ai_translation_model_openrouter` and
     * so on, see App\Enums\AiProvider::modelSettingKey() — because a model id is
     * not portable between services. This key is still read, for OpenRouter only,
     * so an install upgraded from the single-provider version keeps using the model
     * it had: the old Settings page pre-filled this field with `openai/gpt-4o-mini`
     * whether the admin chose it or not, and that value describes OpenRouter's
     * catalogue and nothing else.
     */
    public const AI_TRANSLATION_MODEL = 'ai_translation_model';

    /**
     * Which service translates (an App\Enums\AiProvider value). Absent on an
     * install that predates the provider choice, which resolves to the enum's
     * default — OpenRouter, the provider the feature shipped with.
     */
    public const AI_TRANSLATION_PROVIDER = 'ai_translation_provider';

    /**
     * One encrypted credential PER provider, because each service issues its own.
     *
     * A single shared key field would be destroyed the moment an admin tried a
     * second provider, and switching back would mean retrieving the first key from
     * a third-party dashboard again. Keeping them separate is what makes the
     * provider choice a switch rather than a migration.
     *
     * All three are written through putSecret() and so stored as ciphertext, which
     * is what keeps plaintext credentials out of the append-only audit trail
     * (RULE #8). App\Enums\AiProvider::apiKeySettingKey() maps a provider to its
     * key, so the mapping lives in exactly one place.
     */
    public const OPENROUTER_API_KEY = 'openrouter_api_key';

    public const GAPGPT_API_KEY = 'gapgpt_api_key';

    public const CHATQT_API_KEY = 'chatqt_api_key';

    protected $fillable = ['key', 'value', 'is_translatable'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_translatable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * All settings as a key => value map, cached indefinitely and busted on any
     * write. Settings are read on nearly every request and changed rarely.
     *
     * @return array<string, mixed>
     */
    public static function map(): array
    {
        /** @var array<string, mixed> */
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => static::query()->pluck('value', 'key')->all(),
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::map()[$key] ?? null;

        /*
         * The `value` column is a JSON array cast, so a scalar setting round-trips
         * as a single-element list. Unwrapping here means callers get back what
         * they stored rather than having to know the storage shape.
         */
        if (is_array($value) && array_keys($value) === [0]) {
            return $value[0];
        }

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value, bool $isTranslatable = false): self
    {
        /** @var self $setting */
        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) ? $value : [$value],
                'is_translatable' => $isTranslatable,
            ],
        );

        return $setting;
    }

    public static function isMaintenanceMode(): bool
    {
        return (bool) static::get(self::MAINTENANCE_MODE, false);
    }

    /**
     * Store a secret setting, encrypted at rest.
     *
     * RULE #8 — the audit trail (IsAuditable) logs the `value` column, and it is
     * append-only and never pruned. Storing the OpenRouter key as ciphertext is
     * what keeps the plaintext key out of that trail: what Spatie captures is the
     * encrypted blob, not the credential. Encryption also protects the key at
     * rest in the database. An empty string clears the secret rather than
     * encrypting nothing meaningful.
     */
    public static function putSecret(string $key, ?string $value): self
    {
        $value = $value === null ? '' : trim($value);

        return static::put($key, $value === '' ? '' : Crypt::encryptString($value));
    }

    /**
     * Read and decrypt a secret setting written with putSecret().
     *
     * Returns null when unset or empty. Decryption failure (a key rotation, a
     * value that predates encryption) is swallowed to null rather than thrown, so
     * a bad stored value surfaces as "not configured" instead of a 500 — the
     * service layer already handles the missing-key case with a Persian notice.
     */
    public static function getSecret(string $key): ?string
    {
        $stored = static::get($key);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }
}
