<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * Locale lookups shared by the translation-aware concerns.
 *
 * Extracted because HasSlug and HasTranslationStatus both needed
 * `configuredLocales()`, and PHP raises a fatal collision when two traits used
 * by the same class define the same method. Composing this single trait into
 * both is collision-free: PHP deduplicates an identical trait reached by more
 * than one path.
 */
trait InteractsWithLocales
{
    /**
     * Locales this installation serves. All three are structural from day one
     * even though only Persian content ships at launch (Requirement 5.1).
     *
     * @return list<string>
     */
    public function configuredLocales(): array
    {
        return (array) config('cms.locales.supported', ['fa']);
    }

    /**
     * The authoring locale. It is authoritative: translations are measured
     * against it, and it is the x-default target for hreflang.
     */
    public function sourceLocale(): string
    {
        return (string) config('cms.locales.source', 'fa');
    }

    public function isRtlLocale(string $locale): bool
    {
        return in_array($locale, (array) config('cms.locales.rtl', ['fa', 'ar']), true);
    }

    /**
     * Fail loudly when a value cannot be JSON-encoded.
     *
     * Laravel's default asJson() calls json_encode() without JSON_THROW_ON_ERROR,
     * so encoding failure returns false. For a translatable attribute that false
     * is stored as the value and reaches the database as the integer 0 — the
     * title silently becomes 0, and the first visible symptom is an unrelated NOT
     * NULL violation on the slug column derived from it.
     *
     * The most likely trigger in this project is malformed UTF-8: Persian and
     * Arabic text is multi-byte throughout, so any byte-oriented string operation
     * upstream (a rtrim with a multi-byte charlist, a substr at a fixed offset, a
     * truncated upload) can split a codepoint. That must surface as an error at
     * the point of the bad write, not as corrupted content discovered later.
     *
     * @param  mixed  $value
     * @param  int  $flags
     */
    protected function asJson($value, $flags = 0)
    {
        try {
            return json_encode(
                $value,
                $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $exception) {
            throw new \JsonException(sprintf(
                'Failed to encode attribute value for %s: %s. This usually means the '
                .'value contains malformed UTF-8 — check for byte-oriented string '
                .'operations applied to Persian or Arabic text.',
                static::class,
                $exception->getMessage(),
            ), $exception->getCode(), $exception);
        }
    }
}
