<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrganisationType;
use App\Models\MediaAsset;
use App\Models\Setting;

/**
 * The publisher's own details, as an administrator configured them.
 *
 * Reads the `organisation_schema` setting, which until now was a constant on the
 * Setting model that NOTHING read — a phantom key seeded by nobody and rendered by no
 * form, while SchemaBuilder built its Organization from `site_name` and
 * `social_links` alone. This class is what makes it real.
 *
 * WHY THE EXTRA PROPERTIES MATTER, since "name plus url" already validates:
 *
 * The Organization is referenced as the `publisher` of every Article. A publisher with
 * only a name is the weakest form of that claim — SchemaBuilder's own comment said so —
 * and `logo` in particular is what Google asks for on article markup and what feeds a
 * brand's knowledge panel. So the properties here are not decoration; they are the
 * difference between a publisher node that identifies an organisation and one that
 * merely names it.
 *
 * Everything is optional and everything is omitted when blank. That is the same
 * "omit rather than guess" rule SchemaBuilder follows throughout: incomplete markup is
 * reported as incomplete, and a half-filled property is worse than an absent one.
 */
final class OrganisationProfile
{
    /**
     * The whole stored profile, normalised.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = Setting::get(Setting::ORGANISATION_SCHEMA, []);

        return is_array($stored) ? $stored : [];
    }

    public static function type(): OrganisationType
    {
        return OrganisationType::fromValue(self::all()['type'] ?? null);
    }

    /**
     * The registered legal name, when it differs from the trading name.
     */
    public static function legalName(): ?string
    {
        return self::string('legal_name');
    }

    public static function foundingDate(): ?string
    {
        return self::string('founding_date');
    }

    /**
     * An alternative name in $locale — an abbreviation, or the brand in another script.
     */
    public static function alternateName(?string $locale = null): ?string
    {
        return self::translated('alternate_name', $locale);
    }

    public static function description(?string $locale = null): ?string
    {
        return self::translated('description', $locale);
    }

    /**
     * The logo asset, or null when none is chosen or the chosen one has gone.
     *
     * Resolved to the MODEL rather than a URL so the caller can emit a full ImageObject
     * with dimensions — Google's article markup wants a logo it can measure, and a bare
     * URL string forces it to fetch the file to find out.
     *
     * A deleted asset degrades to null rather than to a broken URL: MediaAsset is not
     * soft-deleted, so an id stored here can genuinely stop existing.
     */
    public static function logo(): ?MediaAsset
    {
        $id = self::all()['logo_media_asset_id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return MediaAsset::query()->find((int) $id);
    }

    /**
     * Whether anything beyond the defaults has been configured.
     *
     * Lets the panel show an honest "not configured yet" state without each caller
     * re-deciding what counts as configured.
     */
    public static function isConfigured(): bool
    {
        return self::all() !== [];
    }

    private static function string(string $key): ?string
    {
        $value = self::all()[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function translated(string $key, ?string $locale): ?string
    {
        $locale ??= app()->getLocale();

        $values = self::all()[$key] ?? null;

        if (! is_array($values)) {
            return is_string($values) && trim($values) !== '' ? trim($values) : null;
        }

        $source = (string) config('cms.locales.source', 'fa');

        foreach ([$locale, $source] as $candidate) {
            $value = $values[$candidate] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
