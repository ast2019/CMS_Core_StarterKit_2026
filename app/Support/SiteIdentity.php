<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * Who this site says it is: its name per locale, and its social profiles.
 *
 * One reader for two settings that three callers need — the Delivery API
 * (`GET /api/v1/settings`), the Organization JSON-LD, and the admin panel's own
 * header. Each had (or would have had) its own copy of the same two awkward
 * unwrapping rules, and a third copy is how they start disagreeing about what the
 * site is called.
 *
 * BOTH RULES EXIST BECAUSE OF HOW `settings.value` IS STORED.
 *
 * The column is an `array` cast, so Setting::put() wraps a scalar in a list and
 * Setting::get() unwraps a list whose only key is 0. That heuristic cannot tell a
 * stored scalar from a stored one-element list, which is fine for `site_name`
 * (keyed by locale, so never a list) and wrong for `social_links`:
 *
 *     put('social_links', ['https://example.test'])   // one profile
 *     get('social_links')  =>  'https://example.test' // a STRING, not a list
 *
 * So the API returned a bare string for exactly one social link and an array for
 * zero or two — a shape the frontend cannot consume without special-casing a
 * number. socialLinks() always returns a list.
 *
 * `site_name` is translatable, which means it is normally a locale-keyed map but is
 * a plain string on an install that wrote it before the flag was set. Both are
 * read.
 */
final class SiteIdentity
{
    /**
     * The site's name in $locale, falling back to the source locale, then to
     * `app.name` so the panel header is never empty.
     */
    public static function name(?string $locale = null): string
    {
        return self::translatedName($locale) ?? (string) config('app.name');
    }

    /**
     * The site's name in $locale, or null when nothing is configured.
     *
     * Separate from name() because the JSON-LD needs to know the difference: the
     * `name` property is required on an Organization, and emitting `app.name` —
     * "Laravel" on an unconfigured install — would publish a false claim about the
     * publisher rather than omitting an object it cannot describe.
     */
    public static function translatedName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $stored = Setting::get(Setting::SITE_NAME);

        if (is_string($stored)) {
            return trim($stored) === '' ? null : trim($stored);
        }

        if (! is_array($stored)) {
            return null;
        }

        $source = (string) config('cms.locales.source', 'fa');

        foreach ([$locale, $source] as $candidate) {
            $value = $stored[$candidate] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Configured social profile URLs, always as a list.
     *
     * Filtered to http(s) values: the stored array is free-form JSON an editor
     * filled in, and `sameAs` on an Organization must hold URLs. Filtering here
     * rather than at each call site is what lets the API and the JSON-LD agree
     * about which entries count.
     *
     * @return list<string>
     */
    public static function socialLinks(): array
    {
        /** @var array<array-key, mixed> $stored */
        $stored = (array) Setting::get(Setting::SOCIAL_LINKS, []);

        return array_values(array_filter(
            array_map(
                static fn (mixed $url): string => is_string($url) ? trim($url) : '',
                $stored,
            ),
            static fn (string $url): bool => str_starts_with($url, 'http'),
        ));
    }
}
