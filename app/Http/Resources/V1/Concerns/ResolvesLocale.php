<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Concerns;

use App\Contracts\TracksTranslationStatus;
use App\Support\Dates\LocalizedDate;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Locale resolution and explicit fallback reporting for API resources.
 *
 * Requirement 5.5 — "return the source-locale content and INDICATE the fallback".
 *
 * Silent fallback is forbidden by the design for a concrete reason: a frontend that
 * cannot tell a real English translation from Persian text served under an English
 * URL will publish duplicate content across locales, and the client will see a
 * ranking problem they have no way to diagnose. So every translated payload states
 * whether it is a fallback and which locale it actually came from.
 */
trait ResolvesLocale
{
    protected function locale(Request $request): string
    {
        $locale = $request->attributes->get('cms_locale');

        return is_string($locale) && $locale !== ''
            ? $locale
            : app()->getLocale();
    }

    protected function sourceLocale(): string
    {
        return (string) config('cms.locales.source', 'fa');
    }

    /**
     * A translated value, falling back to the source locale.
     */
    protected function translated(Model $model, string $attribute, string $locale): mixed
    {
        return $model->getTranslation($attribute, $locale, useFallbackLocale: true);
    }

    /**
     * Whether this record is being served in a locale it has no real translation
     * for.
     *
     * Determined from the translation LIFECYCLE, not merely from whether a string
     * exists. A record can hold machine-translated text that has never been
     * reviewed; presenting that as a genuine translation is what Decision D-5
     * excludes from sitemaps, and the same judgement belongs in the API payload.
     */
    protected function isFallback(Model $model, string $locale): bool
    {
        if ($locale === $this->sourceLocale()) {
            return false;
        }

        if (! $model instanceof TracksTranslationStatus) {
            // No lifecycle tracking, so fall back to the weaker signal: does a
            // value exist in this locale at all?
            return ! $this->hasAnyTranslation($model, $locale);
        }

        return ! $model->translationStatusFor($locale)->wasReviewed();
    }

    /**
     * A date rendered for a human, in the calendar of the resolved locale.
     *
     * Always emitted NEXT TO the ISO-8601 value, never instead of it. The ISO
     * string is the contract — sortable, diffable, unambiguous — and this is the
     * presentation of it, so a frontend in any technology can print a Persian
     * date without owning a Persian calendar. See `cms.dates.api`.
     */
    protected function displayDate(DateTimeInterface|string|null $value, string $locale): ?string
    {
        return LocalizedDate::format(
            $value,
            (string) config('cms.dates.api.pattern', 'long'),
            $locale,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function localeMeta(Model $model, string $locale): array
    {
        $isFallback = $this->isFallback($model, $locale);

        return [
            'locale' => $locale,
            'is_fallback' => $isFallback,
            'fallback_locale' => $isFallback ? $this->sourceLocale() : null,
            'translation_status' => $model instanceof TracksTranslationStatus
                ? $model->translationStatusFor($locale)->value
                : null,

            /*
             * How to read the `*_display` strings in this payload.
             *
             * Reported rather than assumed, because both are configurable per
             * site (`cms.dates`) and a frontend that renders a date the CMS
             * already formatted still needs to know which calendar it is in — to
             * label it, to pick a font, or to decide whether its own relative-time
             * ("3 days ago") rendering is consistent with it.
             */
            'calendar' => LocalizedDate::calendar($locale),
            'timezone' => LocalizedDate::timezone()->getName(),
        ];
    }

    private function hasAnyTranslation(Model $model, string $locale): bool
    {
        foreach ($model->getTranslatableAttributes() as $attribute) {
            if (filled($model->getTranslation($attribute, $locale, useFallbackLocale: false))) {
                return true;
            }
        }

        return false;
    }
}
