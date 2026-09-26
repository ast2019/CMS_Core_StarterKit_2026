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
     * Scheme tag on every hash this trait produces.
     *
     * The hash is stored, so changing how it is COMPUTED makes every pinned value
     * mismatch — and a mismatch is read as "the source text moved on", which would
     * flip every reviewed locale in the database to `outdated` on its record's next
     * save. That is precisely the false alarm hash-based detection exists to avoid,
     * only delivered all at once on a deploy.
     *
     * Tagging the scheme makes "computed differently" distinguishable from "content
     * changed", so the two can get different answers — see syncTranslationStatuses().
     * Bump this whenever sourceContentHash() changes what it hashes or how.
     *
     * v1 = top-level ksort only (nested TipTap arrays hashed as-is).
     * v2 = recursive key normalisation.
     */
    private const HASH_SCHEME = 'v2';

    /**
     * Hash of the source-locale translatable content.
     *
     * Only translatable attributes are included — a change to `publish_date` or
     * `status` does not invalidate a translation.
     *
     * The payload is normalised RECURSIVELY, which the earlier version was not: it
     * ksort()ed the top level and then json_encode()d `body` — a nested TipTap
     * document — exactly as it arrived. So a structurally irrelevant reordering of a
     * node's keys by the editor produced a different hash and marked every reviewed
     * locale `outdated`, which is the false alarm this method's whole design is meant
     * to prevent ("a no-op save does not invalidate good translations"). TipTap nodes
     * are objects; nothing about a document changes when `type` is serialised before
     * or after `content`.
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

        $canonical = json_encode($this->normaliseForHash($payload), JSON_UNESCAPED_UNICODE);

        return self::HASH_SCHEME.':'.hash('xxh128', (string) $canonical);
    }

    /**
     * Sort every MAP's keys, recursively, and leave every LIST's order alone.
     *
     * The distinction is the whole point, and getting it backwards would be much
     * worse than the bug being fixed:
     *
     *  - a map (a TipTap node, a marks entry, a block's config) has no meaningful key
     *    order. `{"type":"paragraph","content":[…]}` and
     *    `{"content":[…],"type":"paragraph"}` are the same paragraph, and an editor
     *    save that reorders them is a no-op that must not invalidate a translation.
     *  - a list IS ordered, and its order is content. Swapping two paragraphs, or two
     *    items in a bullet list, changes what the document says — so sorting list
     *    elements would make a genuine rewrite hash identically to the original and
     *    leave a stale translation advertised as reviewed. Silent staleness is far
     *    more expensive than a spurious re-review.
     */
    private function normaliseForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        // array_map over a single array preserves keys, so the ksort above survives.
        return array_map(fn (mixed $item): mixed => $this->normaliseForHash($item), $value);
    }

    /**
     * Whether a mismatching hash mismatches only because the SCHEME changed.
     *
     * True for a hash that exists and was written by a different scheme — the one case
     * that deserves a silent re-pin rather than an alarm.
     *
     * A null hash is deliberately excluded. A reviewed row with nothing pinned never
     * had a verified baseline at all, so re-pinning it would assert a freshness nobody
     * ever established; the conservative answer — ask for the re-review — is the
     * pre-existing behaviour and the right one.
     */
    private static function hashNeedsRepinning(?string $hash): bool
    {
        return $hash !== null && ! str_starts_with($hash, self::HASH_SCHEME.':');
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
            if ($state->status !== TranslationStatus::Reviewed || $state->source_hash === $hash) {
                continue;
            }

            /*
             * A hash from an OLDER scheme mismatches for a reason that has nothing to
             * do with the content: we changed how the hash is computed. Re-pin it
             * instead of raising an alarm.
             *
             * This is safe, and it is worth saying exactly why rather than asserting
             * it. syncTranslationStatuses() runs on EVERY save, so a row that is still
             * `reviewed` is one whose pinned hash matched at its last save — if the
             * Persian source had moved on, that save would already have flipped it to
             * `outdated` under the old scheme. Recomputing under the new scheme
             * therefore describes the same content the reviewer signed off on, and
             * pinning it changes the stored VALUE without changing its MEANING.
             *
             * The alternative was a one-off data migration re-pinning every row, or
             * accepting a one-time flip of every reviewed locale in the database to
             * `outdated` on deploy. The second is a review backlog full of work nobody
             * needs to do, which teaches translators to clear the flag without reading
             * — and the flag is the only signal the feature has. The first needs a
             * migration that loads and re-hashes every translatable record, and would
             * have to be repeated by hand the next time the hash changes. Tagging the
             * scheme makes this and every future change free, and it is a migration
             * that cannot half-apply: a record nobody saves keeps its old hash, and is
             * re-pinned the moment anyone touches it.
             */
            if (self::hashNeedsRepinning($state->source_hash)) {
                $state->update(['source_hash' => $hash]);

                continue;
            }

            $state->update(['status' => TranslationStatus::Outdated]);
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
        $state = $this->translationStateFor($locale);

        // A locale with no row has never been translated, which is the same answer
        // freshTranslationStatusFor() gives — the two must not disagree about a record
        // that predates the status row.
        if ($state === null) {
            return TranslationStatus::NotTranslated;
        }

        return $state->status;
    }

    /**
     * The status ROW for a locale, or null when the locale has none.
     *
     * Use the loaded relation only when it is actually loaded — this is what keeps a
     * listing page from issuing one query per row per locale. When it is not loaded,
     * query directly rather than triggering lazy-loading of the whole collection.
     *
     * The earlier version of translationStatusFor() chained
     * `$this->translationStates->firstWhere(...) ?? $this->translationStates()
     * ->firstWhere(...)`, which looks like a fallback but is not: a stale loaded
     * collection returns a real row, so the null-coalesce never fires and the fresh
     * query is dead code.
     *
     * Exposed as the row rather than only as the status because the row carries
     * `updated_at`, and SitemapGenerator needs it: a translator signing off the
     * English text writes this row and not the article, yet /en/... is precisely the
     * URL whose lastmod just moved.
     */
    public function translationStateFor(string $locale): ?TranslationState
    {
        /** @var TranslationState|null $state */
        $state = $this->relationLoaded('translationStates')
            ? $this->translationStates->firstWhere('locale', $locale)
            : $this->translationStates()->firstWhere('locale', $locale);

        return $state;
    }

    /**
     * The locale's status read straight from the database, ignoring any loaded
     * relation.
     *
     * translationStatusFor() deliberately PREFERS the eager-loaded collection so a
     * listing page does not issue a query per row per locale, and many call sites
     * eager-load it (ContentsTable, ManagementContentController, the Delivery
     * ContentController, SearchController, SeoController, SitemapGenerator,
     * SyncSearchIndexes). That is right for rendering and wrong for a decision that
     * must be authoritative at the moment it is taken: a queued AI translation is
     * handed a record whose relation was loaded before ~3 minutes of HTTP calls, so
     * a human sign-off that happened in between is invisible to it. Reading the row
     * again is the only way to see the current state.
     *
     * $lockForUpdate takes a row lock so a concurrent review cannot commit between
     * this read and the write that follows it inside the same transaction. SQLite's
     * grammar compiles the lock clause to nothing (it serialises writes anyway), so
     * this is a MySQL-only guarantee and harmless locally.
     */
    public function freshTranslationStatusFor(string $locale, bool $lockForUpdate = false): TranslationStatus
    {
        $query = $this->translationStates()->where('locale', $locale);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var TranslationState|null $state */
        $state = $query->first();

        // A locale with no row has never been translated, which is the same answer
        // translationStatusFor() gives — the two must not disagree about a record
        // that predates the status row.
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
