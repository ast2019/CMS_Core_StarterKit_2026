<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Contracts\TracksTranslationStatus;
use App\Enums\AiProvider;
use App\Enums\TranslationStatus;
use App\Filament\Schemas\CmsRichEditor;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\Setting;
use App\Support\TipTap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Machine-translate a record's source-locale fields into a target locale
 * (Requirement 5.3 — the ai_translated stage of the lifecycle) through the
 * administrator's chosen provider: OpenRouter, GapGPT or ChatQT.
 *
 * WHY ONE CLIENT SERVES ALL THREE PROVIDERS:
 *
 * They speak the same protocol — OpenAI's chat-completions shape, a Bearer key,
 * `{"choices":[{"message":{"content":...}}]}` back — so the only differences are
 * the endpoint, the model catalogue, and whether the provider wants OpenRouter's
 * attribution headers. All three are data, held by App\Enums\AiProvider and
 * config('cms.ai.providers'). A client per provider would triple the code carrying
 * the invariants below (the 1:1 segment contract, the chunk partition, the retry
 * policy) and give them three places to drift apart; instead the provider is
 * resolved ONCE per run in translate() and threaded down to chat(), so a provider
 * cannot change under a run that is already paying for requests.
 *
 * WHY a thin Http client rather than moe-mizrak/laravel-openrouter:
 *
 * The one hard requirement is that the API key lives in the DATABASE (the
 * Settings singleton) and is read at RUNTIME, because the panel configures it
 * per client with no redeploy. That package binds its key from config/env when
 * its service provider boots, so honouring the requirement would mean rebinding
 * its config from Setting::get() before every call — fighting the package's
 * lifecycle to gain nothing here, since all this feature needs is one
 * chat-completions POST. A direct Http call reads the key at call time, is
 * trivially faked with Http::fake() in tests, and adds no dependency. Being
 * provider-agnostic, it is also what makes supporting three services a matter of
 * changing a URL rather than adopting three vendor SDKs. The decision is recorded
 * in the feature findings.
 *
 * WHY EVERYTHING IS BATCHED, INCLUDING THE PLAIN FIELDS:
 *
 * There used to be one POST per plain field — title, excerpt, answer_paragraph,
 * meta_title, meta_description, up to five — plus one batched POST for the body.
 * Six round trips for one record and locale, which meant six copies of the system
 * prompt billed, six entries against the provider's rate limit, and six sequential
 * timeouts to wait through. The argument for batching the body applies verbatim to
 * the plain fields: they are a list of strings, which is exactly translateBatch()'s
 * protocol. They now travel in ONE request, and the field↔translation mapping is
 * positional against the same array that built the request, so there is no second
 * traversal that could disagree about the order.
 *
 * WHY the body is translated by walking the TipTap tree, not serialising it:
 *
 * The RichEditor `body` is a TipTap JSON document (RULE #6). The whole record —
 * body included — is machine-translated in one go, then a human edits afterward.
 * To keep the node tree valid, the translator never sends the raw JSON to the
 * model: it walks the tree, collects only the human-readable prose leaves (text
 * nodes' `text`, plus the prose values inside a custom block's `attrs.config`),
 * translates those strings, and writes them back into the SAME node positions.
 * Node types, marks, and every structural attr (URLs, IDs, media_asset_id,
 * gallery_id, tone, level, layout, cta_url, max_items, ...) stay byte-identical,
 * so the structure cannot be corrupted. `slug` is still skipped because it is
 * per-locale and editorially owned (matching HasTranslationStatus::
 * sourceContentHash, which also skips it), and `robots_meta` is a directive token
 * ("index, follow"), not prose.
 *
 * Custom blocks are addressed through App\Support\TipTap rather than with a local
 * copy of the layout. Filament stores EVERY block as node type `customBlock` and
 * hides the block id and its field values inside `attrs`; this class previously
 * kept its own map keyed by node type, which matched nothing a real editor ever
 * saved, so block prose was silently left in Persian. One shared accessor now
 * serves both this translator and search indexing, so they cannot drift apart.
 * After a block's config is translated, the attrs Filament DERIVES from it
 * (`label`, `preview`, `shouldApplyProseStylingToPreview`) are rebuilt through the
 * block class — see refreshCachedBlockAttrs().
 *
 * ---------------------------------------------------------------------------
 * WHY A LONG BODY IS CHUNKED, AND HOW THE 1:1 CONTRACT SURVIVES IT
 * ---------------------------------------------------------------------------
 * Batching the whole body into one request has a ceiling: a long article with
 * hundreds of prose leaves exceeds the model's OUTPUT token limit, the JSON
 * truncates mid-string, json_decode fails, and the editor is told only
 * "request_failed" with nothing to act on. So the segment list is split into
 * bounded chunks (cms.ai.translation.max_segments_per_request and
 * max_characters_per_request, both applied — forty captions and four enormous
 * paragraphs are the same segment count and nothing like the same token count).
 *
 * The previous stage declined to chunk on the grounds that the whole-document 1:1
 * length contract is what keeps the collect/apply cursor aligned. That contract is
 * preserved, and now PROVED rather than assumed, by four properties:
 *
 *  1. chunkSegments() PARTITIONS the segment list: every segment appears in exactly
 *     one chunk, in document order, and no segment is ever split (a split segment
 *     could not map 1:1 back to a node, so an oversized single segment forms its
 *     own chunk instead).
 *  2. translateBatch() hard-enforces per-chunk 1:1 — a length mismatch, a
 *     non-string element or an empty-after-trim element is requestFailed(), never a
 *     best-effort merge.
 *  3. the chunk results are concatenated in chunk order, so (1) + (2) give a merged
 *     list of exactly count($segments) strings in document order. That derivation
 *     is then ASSERTED in translateSegments() rather than left as an argument.
 *  4. applyProseTranslations() checks array_key_exists BEFORE consuming the cursor,
 *     and translateBodyDocument() checks afterwards that the cursor consumed the
 *     whole list. Those two close the overrun hole that was raised and never
 *     addressed: `$translations[$cursor++]` on a cursor past the end used to warn
 *     and write NULL into a text node's `text`, which is an invalid TipTap leaf
 *     (RULE #6) — the one thing the hard-fail policy exists to prevent.
 *
 * A body needing more chunks than cms.ai.translation.max_requests_per_record is
 * refused up front with sourceTooLong(), before any request is issued, because
 * collection is local and free. An actionable refusal beats twenty minutes of
 * spending followed by a generic failure.
 *
 * WHY re-translation cannot duplicate work:
 *
 * A locale a human already marked Reviewed is refused up front (before any HTTP
 * work) so signed-off text is never overwritten or re-translated. This closes a
 * gap: markTranslationAiTranslated() already refused to downgrade a reviewed
 * STATUS row, but translate() used to overwrite the field values regardless.
 * Re-running over an unreviewed locale REPLACES the target text via
 * setTranslation() (it never appends), so nothing is duplicated.
 *
 * WHY a body with nothing to translate is left UNWRITTEN:
 *
 * A body can be perfectly valid and still contain no translatable prose — one
 * that is only a gallery_embed block, only images, or only structurally-empty
 * paragraphs. The walk then collects zero segments, and an earlier version still
 * wrote that UNTRANSLATED source document into the target locale (it only skipped
 * the bookkeeping). The target locale ended up holding a verbatim Persian body,
 * hasAnyTranslationFor() reported the locale as populated, and the review queue
 * showed the work as done — a silent false success, and worse than a failure
 * because nobody goes looking for it. So the body is written ONLY when prose was
 * actually collected AND translated. A record with no translatable prose ANYWHERE
 * (no plain fields either) reports emptySource() instead of "succeeding" with
 * nothing, which is decided before any HTTP work because segment collection is
 * purely local.
 *
 * WHY this is never called from a web request:
 *
 * Batching cut the floor from six requests to two, but it did not remove the
 * ceiling: a long article is several body chunks, each with its own timeout and its
 * own retry budget, so the worst case a configuration permits is still many
 * minutes of wall clock. PHP's max_execution_time and nginx's
 * fastcgi_read_timeout both fire long before that, killing the request AFTER the
 * API calls were paid for and losing every translation — while pinning a PHP-FPM
 * worker for the duration. App\Jobs\TranslateRecordJob is the only caller;
 * everything here assumes it is running on a worker with a generous timeout, and
 * the guard/write ordering below is written for the minutes-long gap that implies.
 */
class AiTranslator
{
    /**
     * Plain-text translatable attributes that must never be sent to the model.
     *
     * `body` is NOT here: it is a TipTap document handled by a structure-aware
     * path (translateBodyDocument), not as plain text. `slug` is per-locale and
     * editorially owned; `robots_meta` is a directive token, not prose.
     *
     * @var list<string>
     */
    private const SKIP_ATTRIBUTES = ['slug', 'body', 'robots_meta'];

    /**
     * What a batch of strings IS, told to the model so it can translate them well.
     *
     * A meta description is a standalone piece of metadata with a length budget; a
     * prose leaf is a fragment lifted mid-sentence out of a rich-text document and
     * must come back as a fragment, not as a tidied-up sentence. Same protocol, two
     * different jobs, and saying which one it is costs nothing.
     */
    private const KIND_FIELDS = 'standalone editorial metadata fields (for example a headline, a summary, or a meta description)';

    private const KIND_PROSE = 'prose fragments taken in document order from a single rich-text document; each element may be a partial sentence and must be returned as a fragment, not expanded or re-punctuated';

    /**
     * Translate a record's source-locale text into $targetLocale and persist it.
     *
     * On success the record is saved, which fires HasTranslationStatus::saved()
     * and lands the (record, target-locale) TranslationState at ai_translated.
     *
     * @template TModel of Model&TracksTranslationStatus
     *
     * @param  TModel  $record
     *
     * @throws AiTranslationException on disabled feature, missing key, an
     *                                already-reviewed target locale, empty
     *                                source, an over-long source, or a
     *                                provider failure.
     */
    public function translate(Model&TracksTranslationStatus $record, string $targetLocale): AiTranslationResult
    {
        if (! self::isEnabled()) {
            throw AiTranslationException::disabled();
        }

        /*
         * Resolved once, here, and passed down rather than re-read per request.
         * A run makes several sequential calls over minutes; re-reading the setting
         * inside chat() would let an admin switching provider mid-run send the
         * second half of one article to a different service, with the first
         * provider's key. One provider per run, decided before anything is spent.
         */
        $provider = self::provider();
        $apiKey = self::apiKeyFor($provider);

        if ($apiKey === null) {
            throw AiTranslationException::missingKey();
        }

        /*
         * Refuse a locale a human already signed off on, BEFORE any HTTP work.
         * Overwriting reviewed text would silently revert the sign-off and could
         * re-translate content the editor already finalised. This closes the gap
         * where translate() overwrote field values regardless of status while
         * markTranslationAiTranslated() only protected the status row afterward.
         *
         * not_translated / ai_translated / outdated locales may be re-translated,
         * and markTranslationAiTranslated() refuses exactly the same one status, so
         * the guard here and the guard on the state row cannot disagree. That
         * symmetry is load-bearing: while the trait guarded on wasReviewed() (true
         * for `outdated` as well), an AI run over an outdated locale rewrote the
         * text but left the row at `outdated` — which is sitemap-eligible under
         * Decision D-5 — so unreviewed machine output went straight into the
         * locale's sitemap. Re-translating an outdated locale is a legitimate
         * action (the source has moved on), but it must land at ai_translated and
         * drop out of sitemap eligibility, which is what the trait now does.
         */
        /*
         * Read the status from the DATABASE, not from a possibly eager-loaded
         * relation. This runs inside a queued job now, and the record it was handed
         * may have been loaded by a table row that eager-loaded translationStates
         * before the job was even dispatched.
         */
        if ($record->freshTranslationStatusFor($targetLocale) === TranslationStatus::Reviewed) {
            throw AiTranslationException::alreadyReviewed();
        }

        $source = (string) config('cms.locales.source', 'fa');
        $fields = $this->translatableFields($record, $source);
        $bodyDoc = $this->sourceBodyDocument($record, $source);

        /*
         * The body's prose leaves are collected up front because collection is
         * purely local — it makes no request — and knowing whether the body holds
         * ANY translatable prose is what both decisions below need.
         */
        $bodySegments = [];

        if ($bodyDoc !== null) {
            $this->collectProseSegments($bodyDoc, $bodySegments);
        }

        /*
         * Nothing translatable anywhere: no plain field, and a body that is
         * present but prose-free (a lone gallery_embed, only images, only empty
         * paragraphs). Reporting an empty source is the honest answer. Checking
         * `$bodyDoc === null` instead would call a prose-free body translatable
         * work, run the whole flow over it, and land the locale at ai_translated
         * having translated not one word. A body whose only prose sits inside
         * custom blocks DOES produce segments and is translated normally.
         */
        if ($fields === [] && $bodySegments === []) {
            throw AiTranslationException::emptySource();
        }

        /*
         * The request budget, checked before anything is spent. Chunking is decided
         * from the segment list, which is already in hand and cost nothing, so a
         * document too long to translate within the configured budget can be
         * refused with an actionable message instead of being discovered half way
         * through a paid run.
         */
        $this->guardRequestBudget($fields, $bodySegments);

        $translatedAttributes = [];

        if ($fields !== []) {
            /*
             * ONE request for every plain field. The mapping back is positional
             * against the very array that built the request — array_keys and
             * array_values of the same array, recombined — so there is no second
             * traversal of the record that could order the fields differently.
             * array_combine would fail loudly on a length mismatch; it cannot,
             * because translateSegments() has already hard-asserted the count.
             */
            $translations = $this->translateSegments(
                $provider,
                $apiKey,
                array_values($fields),
                $source,
                $targetLocale,
                self::KIND_FIELDS,
            );

            foreach (array_combine(array_keys($fields), $translations) as $attribute => $value) {
                $record->setTranslation($attribute, $targetLocale, $value);
                $translatedAttributes[] = $attribute;
            }
        }

        /*
         * Written ONLY when there was prose to translate. A prose-free body is
         * left absent in the target locale rather than filled with a copy of the
         * Persian source: an untranslated copy would make the locale look
         * populated to hasAnyTranslationFor() and finished to the review queue.
         * The record can still translate successfully on the strength of its
         * plain fields alone — it simply reports `body` as untranslated, which is
         * what happened.
         */
        if ($bodyDoc !== null && $bodySegments !== []) {
            // setTranslation REPLACES the locale's stored array value, so a
            // re-run overwrites the previous body rather than appending to it.
            $record->setTranslation(
                'body',
                $targetLocale,
                $this->translateBodyDocument($provider, $apiKey, $bodyDoc, $bodySegments, $source, $targetLocale),
            );
            $translatedAttributes[] = 'body';
        }

        /*
         * One transaction for the content AND the bookkeeping.
         *
         * They used to be two independent writes. A failure between them (a deadlock,
         * a worker killed mid-job, a connection dropped) left translated text on the
         * record while the status row still said not_translated: the review queue
         * kept advertising work that was already done, and a translator who acted on
         * it paid for the same translation again. Either both land or neither does.
         */
        DB::transaction(function () use ($record, $targetLocale): void {
            /*
             * Re-check the guard IMMEDIATELY before writing, authoritatively.
             *
             * The check at the top of this method ran minutes ago — several sequential
             * model calls, each with its own timeout and retry budget — and a human can
             * finish reviewing the locale inside that window. Writing anyway would
             * silently revert a sign-off that happened after the machine started, which
             * is the same harm the up-front guard exists to prevent, only harder to
             * notice.
             *
             * Fresh read plus a row lock: the caller's eager-loaded relation is
             * exactly the stale data that cannot be trusted here, and the lock stops
             * a review committing between this read and the save below.
             *
             * Throwing rolls the transaction back, so the translated text is
             * discarded rather than half-applied, and the caller gets the same
             * alreadyReviewed outcome it would have got up front.
             */
            if ($record->freshTranslationStatusFor($targetLocale, lockForUpdate: true) === TranslationStatus::Reviewed) {
                throw AiTranslationException::alreadyReviewed();
            }

            $record->save();

            /*
             * saved() ran HasTranslationStatus::syncTranslationStatuses(), which only
             * assigns ai_translated to a BRAND-NEW locale row. The usual case is an
             * existing not_translated row, which the trait leaves untouched, so mark
             * the transition explicitly through the contract (which keeps all
             * TranslationState manipulation inside the trait).
             */
            $record->markTranslationAiTranslated($targetLocale);
        });

        return new AiTranslationResult($targetLocale, $translatedAttributes);
    }

    /**
     * Whether AI translation is switched on in Settings.
     */
    public static function isEnabled(): bool
    {
        return (bool) Setting::get(Setting::AI_TRANSLATION_ENABLED, false);
    }

    /**
     * The provider the administrator selected, or the default when unset.
     *
     * Unset is the normal state on an install that predates the provider choice,
     * and AiProvider::default() is OpenRouter — the service this feature shipped
     * with — so such an install keeps translating exactly as it did, with the key
     * it already has stored, without anyone touching a setting.
     */
    public static function provider(): AiProvider
    {
        return AiProvider::fromValue(Setting::get(Setting::AI_TRANSLATION_PROVIDER));
    }

    /**
     * Whether the SELECTED provider has a key configured.
     *
     * Exposed so a caller can fail FAST — before queueing anything — on a
     * misconfiguration that is certain to fail. A job that only reports "no API
     * key" ten seconds later, in a notification, is a worse answer to a question
     * that can be answered synchronously.
     *
     * Per provider rather than "any key anywhere": an admin who has stored an
     * OpenRouter key and then switched to ChatQT has a key, and still cannot
     * translate. Reporting otherwise would queue a job guaranteed to fail.
     */
    public static function hasApiKey(): bool
    {
        return self::apiKeyFor(self::provider()) !== null;
    }

    /**
     * The decrypted key for a provider, or null when it has none stored.
     */
    private static function apiKeyFor(AiProvider $provider): ?string
    {
        return Setting::getSecret($provider->apiKeySettingKey());
    }

    /**
     * The model to use, resolved against the provider that will run the request.
     *
     * The provider is passed in by a run that has already resolved one, so the
     * model and the endpoint a request uses can never come from different
     * providers. Resolved fresh only when no provider is supplied (the panel
     * rendering a placeholder, say).
     *
     * Three sources, in order:
     *
     *  1. the model the admin named FOR THIS PROVIDER;
     *  2. for OpenRouter only, the legacy shared `ai_translation_model` — see
     *     below;
     *  3. the provider's own default.
     *
     * Step 2 is the upgrade path, and it is deliberately scoped to OpenRouter. The
     * previous Settings page pre-filled one shared model field with
     * `openai/gpt-4o-mini`, so every install that ever saved settings has that id
     * stored explicitly whether or not anyone chose it. That value describes
     * OpenRouter's catalogue and nothing else: honouring it for OpenRouter keeps an
     * upgraded install byte-identical, while honouring it for GapGPT or ChatQT
     * would send one service's id to another and answer 404 at the end of a paid
     * run.
     */
    public static function model(?AiProvider $provider = null): string
    {
        $provider ??= self::provider();

        $model = Setting::get($provider->modelSettingKey());

        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        if ($provider === AiProvider::OpenRouter) {
            $legacy = Setting::get(Setting::AI_TRANSLATION_MODEL);

            if (is_string($legacy) && trim($legacy) !== '') {
                return trim($legacy);
            }
        }

        return $provider->defaultModel();
    }

    /**
     * The most requests one record and locale may cost.
     *
     * Public so App\Jobs\TranslateRecordJob can size its wall-clock timeout from the
     * same number this class enforces. Deriving the job's budget from config while
     * the translator derived its behaviour separately is how a job ends up killed
     * mid-run by its own timeout after the calls were paid for.
     */
    public static function maxRequestsPerRecord(): int
    {
        return max(1, (int) config('cms.ai.translation.max_requests_per_record', 12));
    }

    public static function maxAttemptsPerRequest(): int
    {
        return max(1, (int) config('cms.ai.translation.max_attempts', 3));
    }

    public static function maxRetryDelay(): int
    {
        return max(0, (int) config('cms.ai.translation.max_retry_delay', 30));
    }

    /**
     * Refuse a record that cannot be translated within the configured budget.
     *
     * Free to check: chunking is computed from the already-collected segments and
     * makes no request. The alternative — discovering the ceiling at request eleven —
     * costs ten paid calls to reach an error message.
     *
     * @param  array<string, string>  $fields
     * @param  list<string>  $bodySegments
     */
    private function guardRequestBudget(array $fields, array $bodySegments): void
    {
        $requests = ($fields === [] ? 0 : 1) + count($this->chunkSegments($bodySegments));

        if ($requests > self::maxRequestsPerRecord()) {
            throw AiTranslationException::sourceTooLong();
        }
    }

    /**
     * Non-empty source-locale values of the model's translatable attributes,
     * excluding the fields that must not be machine-translated.
     *
     * @return array<string, string>
     */
    private function translatableFields(Model&TracksTranslationStatus $record, string $source): array
    {
        $fields = [];

        foreach ($record->getTranslatableAttributes() as $attribute) {
            if (in_array($attribute, self::SKIP_ATTRIBUTES, true)) {
                continue;
            }

            $value = $record->getTranslation($attribute, $source, useFallbackLocale: false);

            if (is_string($value) && trim($value) !== '') {
                $fields[$attribute] = $value;
            }
        }

        return $fields;
    }

    /**
     * The source-locale TipTap body document, or null when it is absent/empty.
     * Returned as-is so it can be walked; only its prose leaves are translated.
     *
     * @return array<string, mixed>|null
     */
    private function sourceBodyDocument(Model&TracksTranslationStatus $record, string $source): ?array
    {
        if (! in_array('body', $record->getTranslatableAttributes(), true)) {
            return null;
        }

        $doc = $record->getTranslation('body', $source, useFallbackLocale: false);

        if (! is_array($doc) || $doc === []) {
            return null;
        }

        return $doc;
    }

    /**
     * Translate a TipTap body document while preserving its structure exactly.
     *
     * $segments is the ordered prose leaves the caller already collected from
     * $doc (text nodes' `text` plus the whitelisted block attrs). They are
     * translated in bounded chunks that map 1:1 overall, and written back into the
     * SAME positions. Node types, marks, and all structural attrs are left
     * byte-identical.
     *
     * Collection happens in translate() rather than here because its RESULT
     * decides whether a body should be written at all — a prose-free body must
     * not be persisted as an untranslated copy of the source — and that decision
     * has to be made before any request is issued.
     *
     * @param  array<string, mixed>  $doc
     * @param  non-empty-list<string>  $segments
     * @return array<string, mixed>
     */
    private function translateBodyDocument(AiProvider $provider, string $apiKey, array $doc, array $segments, string $source, string $target): array
    {
        $translations = $this->translateSegments($provider, $apiKey, $segments, $source, $target, self::KIND_PROSE);

        $cursor = 0;

        $translated = $this->applyProseTranslations($doc, $translations, $cursor);

        /*
         * The other half of the cursor contract. applyProseTranslations() refuses to
         * read PAST the end of the list; this refuses to stop SHORT of it. Together
         * they say: the walk that collected the segments and the walk that writes them
         * back visited exactly the same leaves, in the same order. If they ever
         * disagree — a TipTap accessor changing which attrs count as prose between the
         * two passes, say — the body is discarded rather than written with translations
         * in the wrong nodes, which is the failure nobody would spot until a reader did.
         */
        if ($cursor !== count($translations)) {
            throw AiTranslationException::requestFailed();
        }

        return $translated;
    }

    /**
     * Recursively gather translatable prose from a node into $segments, in
     * document order. A segment is a non-empty (after trim) text-node string or
     * a whitelisted block prose attr.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $segments
     */
    private function collectProseSegments(array $node, array &$segments): void
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : null;

        if ($type === 'text' && $this->isTranslatableString($node['text'] ?? null)) {
            /** @var string $text */
            $text = $node['text'];
            $segments[] = $text;
        }

        // A custom block: Filament stores it as type `customBlock` with the block
        // id and its field values inside attrs (see TipTap's class docblock), so
        // the prose is addressed through the shared accessors — the same ones
        // search indexing uses, which is what keeps the two in step.
        $config = TipTap::customBlockConfig($node);

        foreach (TipTap::customBlockProseAttrs($node, forTranslation: true) as $attr) {
            if ($this->isTranslatableString($config[$attr] ?? null)) {
                /** @var string $value */
                $value = $config[$attr];
                $segments[] = $value;
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $child) {
                if (is_array($child)) {
                    $this->collectProseSegments($child, $segments);
                }
            }
        }
    }

    /**
     * Recursively rebuild a node, replacing each prose leaf with the next
     * translation in $translations (consumed via the shared $cursor, which mirrors
     * the collection order in collectProseSegments). Everything else is copied
     * unchanged.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $translations
     * @return array<string, mixed>
     */
    private function applyProseTranslations(array $node, array $translations, int &$cursor): array
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : null;

        if ($type === 'text' && $this->isTranslatableString($node['text'] ?? null)) {
            $node['text'] = $this->consume($translations, $cursor);
        }

        $blockId = TipTap::customBlockId($node);
        $config = TipTap::customBlockConfig($node);
        $configChanged = false;

        foreach (TipTap::customBlockProseAttrs($node, forTranslation: true) as $attr) {
            if ($this->isTranslatableString($config[$attr] ?? null)) {
                $config[$attr] = $this->consume($translations, $cursor);
                $configChanged = true;
            }
        }

        if ($configChanged && $blockId !== null && isset($node['attrs']) && is_array($node['attrs'])) {
            $node['attrs']['config'] = $config;
            $node['attrs'] = $this->refreshCachedBlockAttrs($node['attrs'], $blockId, $config);
        }

        if (isset($node['content']) && is_array($node['content'])) {
            foreach ($node['content'] as $index => $child) {
                if (is_array($child)) {
                    $node['content'][$index] = $this->applyProseTranslations($child, $translations, $cursor);
                }
            }
        }

        return $node;
    }

    /**
     * Take the next translation, refusing to read past the end of the list.
     *
     * The overrun hole, closed explicitly. `$translations[$cursor++]` on an
     * exhausted list emits an undefined-key warning and evaluates to NULL, and that
     * null was then written straight into a text node's `text` — an invalid
     * ProseMirror/TipTap leaf (RULE #6) and precisely the "garbled body" the
     * hard-fail policy exists to keep out of the database. It cannot happen given
     * the partition + per-chunk 1:1 + asserted total, but a structural invariant
     * that is only argued for is one refactor away from being false, and the cost of
     * checking it is one array_key_exists per leaf.
     *
     * @param  list<string>  $translations
     */
    private function consume(array $translations, int &$cursor): string
    {
        if (! array_key_exists($cursor, $translations)) {
            throw AiTranslationException::requestFailed();
        }

        return $translations[$cursor++];
    }

    /**
     * Regenerate the attrs Filament DERIVES from a custom block's config.
     *
     * Alongside `config`, Filament caches `label` (the collapsed title an editor
     * sees in the document outline), `preview` (base64-encoded HTML of the block,
     * rendered at save time) and `shouldApplyProseStylingToPreview`. They are
     * snapshots of the config, so translating the config without rebuilding them
     * leaves the editor looking at Persian previews above English content — and,
     * worse, an editor who re-saves the English document would write that stale
     * Persian preview back as if it were current.
     *
     * Rebuilding goes through the block class itself rather than re-implementing
     * the label/preview format, so the output matches exactly what
     * CustomBlockAction writes when a human edits the block.
     *
     * Only keys the node ALREADY carries are rebuilt: a document saved by an older
     * Filament version, or a hand-built one, should not gain attrs it never had.
     * An unregistered block id (a block removed since the article was written) is
     * left completely alone — the config is still translated, but nothing is
     * invented for a class that no longer exists.
     *
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function refreshCachedBlockAttrs(array $attrs, string $blockId, array $config): array
    {
        $block = CmsRichEditor::block($blockId);

        if ($block === null) {
            return $attrs;
        }

        if (array_key_exists('label', $attrs)) {
            $attrs['label'] = $block::getPreviewLabel($config);
        }

        if (array_key_exists('preview', $attrs)) {
            $preview = $block::toPreviewHtml($config);

            // toPreviewHtml() is allowed to return null (the base class default).
            // Filament's own base64_encode() call would fail on that, so guard it.
            $attrs['preview'] = is_string($preview) ? base64_encode($preview) : null;
        }

        if (array_key_exists('shouldApplyProseStylingToPreview', $attrs)) {
            $attrs['shouldApplyProseStylingToPreview'] = $block::shouldApplyProseStylingToPreview($config);
        }

        return $attrs;
    }

    /**
     * Whether a value is a non-empty (after trim) string worth translating.
     *
     * @phpstan-assert-if-true string $value
     */
    private function isTranslatableString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Translate a list of strings in bounded chunks, 1:1 and in order.
     *
     * The single entry point for both batch kinds. See the class docblock for why
     * chunking cannot break the cursor contract; the assertion at the end is that
     * argument made executable — the chunked result must be exactly as long as the
     * input, or nothing is written.
     *
     * @param  non-empty-list<string>  $segments
     * @return list<string>
     */
    private function translateSegments(AiProvider $provider, string $apiKey, array $segments, string $source, string $target, string $kind): array
    {
        $translations = [];

        foreach ($this->chunkSegments($segments) as $chunk) {
            foreach ($this->translateBatch($provider, $apiKey, $chunk, $source, $target, $kind) as $translation) {
                $translations[] = $translation;
            }
        }

        /*
         * The whole-input 1:1 contract, checked rather than reasoned about. It
         * follows from the partition and the per-chunk check, and it is the single
         * property every caller depends on, so it is worth one count comparison to
         * make a future change to either half fail here instead of silently writing
         * translations into the wrong nodes.
         */
        if (count($translations) !== count($segments)) {
            throw AiTranslationException::requestFailed();
        }

        return $translations;
    }

    /**
     * Partition a segment list into request-sized chunks, in order.
     *
     * Greedy, bounded by BOTH the segment count and the character total, because
     * either alone is escapable — forty short captions and four enormous paragraphs
     * are the same segment count and nothing like the same number of tokens.
     *
     * A segment is ATOMIC: one longer than the character bound gets a chunk to
     * itself rather than being split, because a split segment could not map back 1:1
     * to the node it came from, which is the invariant that keeps the document's
     * structure intact. The bound is therefore a target, not a guarantee — and an
     * oversized single leaf is a paragraph, not an article, so it is within any
     * model's output budget anyway.
     *
     * @param  list<string>  $segments
     * @return list<non-empty-list<string>>
     */
    private function chunkSegments(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        $maxSegments = max(1, (int) config('cms.ai.translation.max_segments_per_request', 40));
        $maxCharacters = max(1, (int) config('cms.ai.translation.max_characters_per_request', 4000));

        $chunks = [];
        $current = [];
        $currentLength = 0;

        foreach ($segments as $segment) {
            $length = mb_strlen($segment);

            $wouldOverflow = $current !== []
                && (count($current) >= $maxSegments || $currentLength + $length > $maxCharacters);

            if ($wouldOverflow) {
                $chunks[] = $current;
                $current = [];
                $currentLength = 0;
            }

            $current[] = $segment;
            $currentLength += $length;
        }

        /*
         * The tail is always non-empty, so it is appended unconditionally: $segments is
         * non-empty by the guard above, and $current is only ever reset immediately
         * before the segment that triggered the reset is appended to it.
         */
        $chunks[] = $current;

        return $chunks;
    }

    /**
     * Translate one chunk in one round trip, mapping 1:1 back to input positions.
     *
     * The model is asked for a JSON OBJECT — `{"translations": [...]}` — rather than
     * a bare array, because that is the shape OpenAI-compatible JSON mode
     * (`response_format: json_object`) is defined over; a top-level array is not
     * valid in that mode. A bare array is still ACCEPTED on the way back: the model
     * is administrator-chosen from the provider's whole catalogue (Requirement 1.2),
     * plenty of those models ignore response_format, and hard-failing on one that
     * answered correctly-but-unwrapped would make the feature unusable on it for no
     * gain. Unwrapping a container is not a best-effort merge — the contract below
     * is untouched.
     *
     * A length mismatch (or any non-array / non-string / empty-after-trim element)
     * is a HARD failure — never a best-effort merge — so a garbled, duplicated, or
     * structurally-invalid body can never be written. Empty elements are rejected
     * because an empty string written into a text node's `text` is an invalid
     * ProseMirror/TipTap leaf (RULE #6).
     *
     * @param  non-empty-list<string>  $segments
     * @return list<string>
     */
    private function translateBatch(AiProvider $provider, string $apiKey, array $segments, string $source, string $target, string $kind): array
    {
        $system = sprintf(
            'You are a professional translator. The user message is a JSON array of %d string(s) in %s. '
            .'They are %s. '
            .'Translate each element into %s and reply with ONLY a JSON object of the form {"translations": [...]}, '
            .'whose array holds exactly %d element(s) in the SAME order, one translated string per input element. '
            .'Preserve meaning, tone, and any inline formatting. '
            .'Do not add, remove, reorder, merge, or split elements, and translate nothing outside the array.',
            count($segments),
            $this->localeName($source),
            $kind,
            $this->localeName($target),
            count($segments),
        );

        $payload = json_encode($segments, JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw AiTranslationException::requestFailed();
        }

        $content = $this->chat($provider, $apiKey, $system, $payload);

        if (! is_string($content)) {
            throw AiTranslationException::requestFailed();
        }

        /** @var mixed $decoded */
        $decoded = json_decode(trim($content), true);

        if (is_array($decoded) && ! array_is_list($decoded)) {
            /** @var mixed $decoded */
            $decoded = $decoded['translations'] ?? null;
        }

        if (! is_array($decoded) || count($decoded) !== count($segments)) {
            throw AiTranslationException::requestFailed();
        }

        $translations = [];

        foreach ($decoded as $item) {
            // Reject a non-string OR empty-after-trim element: an empty text-node
            // `text` is an invalid TipTap leaf (RULE #6), and trimming keeps every
            // translated value consistently normalised.
            if (! is_string($item) || trim($item) === '') {
                throw AiTranslationException::requestFailed();
            }

            $translations[] = trim($item);
        }

        return $translations;
    }

    /**
     * A language as a person would name it, with the code beside it.
     *
     * The prompt used to interpolate raw ISO codes — "from fa to en" — while the
     * panel already had human names for exactly these locales. "ar" in particular is
     * ambiguous to a model reading it cold (it is also a country code); the endonym
     * is not. Both are sent because together they are strictly more informative than
     * either alone, and TranslatableTabs::localeLabel() is the one place the names
     * live, so the prompt cannot drift from what the editor sees on the form.
     */
    private function localeName(string $locale): string
    {
        return sprintf('%s (%s)', TranslatableTabs::localeLabel($locale), $locale);
    }

    /**
     * One chat-completion POST, with a bounded retry for the two answers that mean
     * "ask again".
     *
     * Returns the assistant message content, or throws
     * AiTranslationException::requestFailed() on any transport, non-2xx, or
     * malformed-response failure. The raw response body and the API key never appear
     * in the thrown message, in a log line, or in the exception's payload (RULE #8).
     *
     * A 429 used to be treated exactly like a 500 and, before that, like a 401: one
     * attempt, then failure. That is the wrong answer to all three. A 429 is the
     * provider saying "not yet" and usually saying WHEN in a Retry-After header; a
     * 5xx is a transient fault; a 401/404 is a configuration error that will be just
     * as wrong on the third attempt and only delays the editor finding out.
     *
     * The dead try/catch that used to wrap `$response->json('choices.0.message
     * .content')` is gone. Laravel's json() with a path returns null for a missing
     * key rather than throwing, so `catch (Throwable)` could not fire and read as
     * protection that did not exist. The real protection is the explicit is_string
     * check the callers make on what comes back.
     */
    private function chat(AiProvider $provider, string $apiKey, string $system, string $user): mixed
    {
        $endpoint = $provider->endpoint();

        $attempts = self::maxAttemptsPerRequest();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $isLastAttempt = $attempt === $attempts;

            try {
                $request = Http::withToken($apiKey)
                    // Separate from the total budget: an endpoint that accepts nothing
                    // at all should report that in a second, not after the full
                    // timeout — several times over, once per request in the run.
                    ->connectTimeout((int) config('cms.ai.translation.connect_timeout', 10))
                    ->timeout((int) config('cms.ai.translation.timeout', 30))
                    ->asJson();

                /*
                 * OpenRouter's recommended attribution headers, sent ONLY to a
                 * provider that documents them. Both come from per-deployment config
                 * rather than being hardcoded, because this is a reusable Core and a
                 * client's name must never be baked into it.
                 *
                 * Withheld from the other providers deliberately. Against OpenRouter
                 * they disclose nothing the request body does not already carry, but
                 * GapGPT and ChatQT never asked for them, and sending a deployment's
                 * URL and the client's name to a third party that has no use for it
                 * is a disclosure with no benefit on either side.
                 */
                if ($provider->sendsAttributionHeaders()) {
                    $request = $request->withHeaders([
                        'HTTP-Referer' => (string) config('app.url'),
                        'X-Title' => (string) config('app.name'),
                    ]);
                }

                $response = $request
                    ->post($endpoint, [
                        'model' => self::model($provider),
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $user],
                        ],
                        /*
                         * Translation is not a creative task, and sampling is actively
                         * harmful here: at a non-zero temperature the same article
                         * re-translated after a small edit comes back reworded
                         * throughout, so a reviewer who had already checked the
                         * unchanged paragraphs has to check them again.
                         */
                        'temperature' => 0,
                        // JSON mode. Cheap insurance against the single most common
                        // malformed reply: a correct JSON body wrapped in a ```json
                        // fence, which json_decode rejects outright.
                        'response_format' => ['type' => 'json_object'],
                        'max_tokens' => (int) config('cms.ai.translation.max_tokens', 4096),
                    ]);
            } catch (ConnectionException) {
                if ($isLastAttempt) {
                    throw AiTranslationException::requestFailed();
                }

                $this->waitBeforeRetry(null, $attempt);

                continue;
            }

            if ($response->successful()) {
                return $response->json('choices.0.message.content');
            }

            // Rate limited, or the provider is having a moment: both mean "ask
            // again". Anything else in the 4xx range is a configuration error and is
            // final — retrying a bad key three times only delays the diagnosis.
            $retryable = $response->status() === 429 || $response->serverError();

            if (! $retryable || $isLastAttempt) {
                throw AiTranslationException::requestFailed();
            }

            $this->waitBeforeRetry($response, $attempt);
        }

        // Unreachable: the loop either returns or throws on its last attempt. Present
        // because the compiler cannot see that, and returning null here would be a
        // silent malformed-response rather than a failure.
        throw AiTranslationException::requestFailed();
    }

    /**
     * Wait before the next attempt, honouring Retry-After up to a hard ceiling.
     *
     * The provider knows when its own limit resets, so Retry-After is a better number
     * than any backoff curve — but only up to a point. A queued job that sleeps for
     * ten minutes because a header said so is occupying a worker the rest of the
     * queue needs, and the queue's own retry is the right place to wait that long, so
     * a request beyond cms.ai.translation.max_retry_delay is refused and the wait
     * falls back to the capped curve.
     *
     * Both the numeric-seconds and HTTP-date forms of the header are read, because
     * RFC 9110 allows either and providers use both.
     *
     * Through Illuminate\Support\Sleep rather than sleep(), so the retry policy is
     * assertable in tests without a test suite that actually waits.
     */
    private function waitBeforeRetry(?Response $response, int $attempt): void
    {
        $cap = self::maxRetryDelay();

        // 1s, 2s, 4s, ... capped. Enough to clear a burst limit without turning a
        // transient fault into a long stall.
        $backoff = min($cap, 2 ** ($attempt - 1));

        $requested = $response === null ? null : $this->retryAfterSeconds($response);

        $delay = $requested !== null && $requested <= $cap
            ? $requested
            : $backoff;

        if ($delay > 0) {
            Sleep::for($delay)->seconds();
        }
    }

    /**
     * Seconds requested by a Retry-After header, or null when it says nothing usable.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        try {
            $until = Carbon::parse($header);
        } catch (\Throwable) {
            // A malformed date is not worth failing the request over; the backoff
            // curve is a perfectly good answer.
            return null;
        }

        // Plain timestamp arithmetic rather than a diff helper: an HTTP-date is always
        // absolute, and a date already in the past means "retry now", not "retry
        // however long ago that was".
        return max(0, $until->getTimestamp() - now()->getTimestamp());
    }
}
