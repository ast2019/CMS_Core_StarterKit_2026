<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\TranslationStatus;
use App\Models\TranslationState;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Per-locale translation lifecycle:
 * not_translated -> ai_translated -> reviewed -> outdated
 *
 * Requirements 5.3, 5.4, 5.6.
 *
 * Staleness is detected by hashing the source locale's translatable fields
 * rather than by comparing timestamps. Timestamps would mark every translation
 * outdated on any save of the record — including a save that only touched
 * publish_date or a non-translatable column — and editors would learn to ignore
 * the flag. A hash only fires when the text a translator actually worked from
 * has changed.
 */
trait HasTranslationStatus
{
    use InteractsWithLocales;

    public static function bootHasTranslationStatus(): void
    {
        static::saved(function (self $model): void {
            $model->syncTranslationStatuses();
        });
    }

    /**
     * @return MorphMany<TranslationState, $this>
     */
    public function translationStates(): MorphMany
    {
        return $this->morphMany(TranslationState::class, 'translatable');
    }

    /**
     * Hash of the source-locale translatable content.
     *
     * Only translatable attributes are included — a change to `publish_date` or
     * `status` does not invalidate a translation.
     */
    public function sourceContentHash(): string
    {
        $source = $this->sourceLocale();
        $payload = [];

        foreach ($this->getTranslatableAttributes() as $attribute) {
            // The slug is excluded on purpose: it is per-locale and editorially
            // owned, so changing the Persian slug says nothing about whether the
            // English body text is still an accurate translation.
            if ($attribute === 'slug') {
                continue;
            }

            $payload[$attribute] = $this->getTranslation($attribute, $source, useFallbackLocale: false);
        }

        ksort($payload);

        return hash('xxh128', (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Create missing status rows and flag reviewed translations as outdated when
     * the source text has moved on.
     */
    public function syncTranslationStatuses(): void
    {
        $source = $this->sourceLocale();
        $hash = $this->sourceContentHash();

        foreach ($this->configuredLocales() as $locale) {
            if ($locale === $source) {
                // The source locale is authoritative; it is never "translated".
                $this->translationStates()->updateOrCreate(
                    ['locale' => $locale],
                    ['status' => TranslationStatus::Reviewed, 'source_hash' => $hash],
                );

                continue;
            }

            /** @var TranslationState|null $state */
            $state = $this->translationStates()->firstWhere('locale', $locale);

            if ($state === null) {
                $this->translationStates()->create([
                    'locale' => $locale,
                    'status' => $this->hasAnyTranslationFor($locale)
                        ? TranslationStatus::AiTranslated
                        : TranslationStatus::NotTranslated,
                    'source_hash' => null,
                ]);

                continue;
            }

            // Only a translation somebody signed off on can go stale. An
            // unreviewed one is already flagged as needing work, and moving it
            // to `outdated` would wrongly imply it had once been verified.
            if ($state->status === TranslationStatus::Reviewed && $state->source_hash !== $hash) {
                $state->update(['status' => TranslationStatus::Outdated]);
            }
        }
    }

    /**
     * Record that a locale now holds machine-translated text awaiting review.
     *
     * Used by the AI translator after it writes translations. syncTranslationStatuses()
     * only assigns ai_translated to a brand-new locale row; an existing
     * not_translated row is left untouched, so this makes the transition explicit.
     *
     * Only `reviewed` is refused. Re-running the machine over signed-off work
     * must not silently revert the sign-off, and AiTranslator::translate() refuses
     * that locale before it makes any request, so the two layers agree.
     *
     * `outdated` IS accepted, and that is the whole point of this method. An
     * outdated locale is the natural case for re-translating: the Persian source
     * moved on and the old English text no longer matches it. But the row must
     * genuinely LEAVE the reviewed world when it does, because `outdated` is
     * sitemap-eligible under Decision D-5 while `ai_translated` is not. An earlier
     * version guarded on wasReviewed(), which is true for `outdated` too, so an AI
     * run over an outdated locale rewrote the text and left the status alone —
     * publishing raw machine output to that locale's sitemap, the exact thing
     * D-5 exists to prevent. Three columns are therefore cleared alongside the
     * status:
     *
     *  - source_hash: pinned at the moment of a human review, to detect staleness
     *    against. The text it described has just been overwritten, so it now
     *    describes nothing.
     *  - reviewed_by / reviewed_at: they credited a named person for text that a
     *    machine has since replaced. Leaving them makes the review queue attribute
     *    machine output to that person.
     *
     * A human re-reviewing the locale re-pins all three through
     * markTranslationReviewed().
     */
    public function markTranslationAiTranslated(string $locale): TranslationState
    {
        /** @var TranslationState $state */
        $state = $this->translationStates()->firstWhere('locale', $locale)
            ?? $this->translationStates()->make(['locale' => $locale]);

        if (! $state->exists || $state->status !== TranslationStatus::Reviewed) {
            $state = $this->translationStates()->updateOrCreate(
                ['locale' => $locale],
                [
                    'status' => TranslationStatus::AiTranslated,
                    'source_hash' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ],
            );
        }

        $this->unsetRelation('translationStates');

        return $state;
    }

    /**
     * Mark a locale reviewed, pinning the source hash it was reviewed against.
     */
    public function markTranslationReviewed(string $locale, ?int $userId = null): TranslationState
    {
        /** @var TranslationState $state */
        $state = $this->translationStates()->updateOrCreate(
            ['locale' => $locale],
            [
                'status' => TranslationStatus::Reviewed,
                'source_hash' => $this->sourceContentHash(),
                'reviewed_by' => $userId ?? auth()->id(),
                'reviewed_at' => now(),
            ],
        );

        // Any already-loaded collection is now stale. Without this, a caller that
        // touched the relation before the review would keep reading the old
        // status and conclude the review had not taken effect.
        $this->unsetRelation('translationStates');

        return $state;
    }

    public function translationStatusFor(string $locale): TranslationStatus
    {
        /*
         * Use the loaded relation only when it is actually loaded — this is what
         * keeps a listing page from issuing one query per row per locale. When it
         * is not loaded, query directly rather than triggering lazy-loading of
         * the whole collection.
         *
         * The earlier version chained `$this->translationStates->firstWhere(...)
         * ?? $this->translationStates()->firstWhere(...)`, which looks like a
         * fallback but is not: a stale loaded collection returns a real row, so
         * the null-coalesce never fires and the fresh query is dead code.
         */
        /** @var TranslationState|null $state */
        $state = $this->relationLoaded('translationStates')
            ? $this->translationStates->firstWhere('locale', $locale)
            : $this->translationStates()->firstWhere('locale', $locale);

        if ($state === null) {
            return TranslationStatus::NotTranslated;
        }

        return $state->status;
    }

    /**
     * Whether this record may appear in a locale's sitemap (Decision D-5).
     */
    public function isSitemapEligibleFor(string $locale): bool
    {
        return $this->translationStatusFor($locale)->isSitemapEligible();
    }

    /**
     * Whether a locale has any translated text at all, used to decide whether a
     * brand-new status row starts as machine-translated or untranslated.
     */
    public function hasAnyTranslationFor(string $locale): bool
    {
        foreach ($this->getTranslatableAttributes() as $attribute) {
            if (filled($this->getTranslation($attribute, $locale, useFallbackLocale: false))) {
                return true;
            }
        }

        return false;
    }
}
