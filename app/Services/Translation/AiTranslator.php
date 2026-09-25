<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Contracts\TracksTranslationStatus;
use App\Enums\TranslationStatus;
use App\Filament\Schemas\CmsRichEditor;
use App\Models\Setting;
use App\Support\TipTap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Machine-translate a record's source-locale fields into a target locale via
 * OpenRouter (Requirement 5.3 — the ai_translated stage of the lifecycle).
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
 * trivially faked with Http::fake() in tests, and adds no dependency. The
 * decision is recorded in the feature findings.
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
     *                                source, or an OpenRouter failure.
     */
    public function translate(Model&TracksTranslationStatus $record, string $targetLocale): AiTranslationResult
    {
        if (! self::isEnabled()) {
            throw AiTranslationException::disabled();
        }

        $apiKey = Setting::getSecret(Setting::OPENROUTER_API_KEY);

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
        if ($record->translationStatusFor($targetLocale) === TranslationStatus::Reviewed) {
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

        $translatedAttributes = [];

        foreach ($fields as $attribute => $text) {
            $record->setTranslation(
                $attribute,
                $targetLocale,
                $this->translateText($apiKey, $text, $source, $targetLocale),
            );
            $translatedAttributes[] = $attribute;
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
                $this->translateBodyDocument($apiKey, $bodyDoc, $bodySegments, $source, $targetLocale),
            );
            $translatedAttributes[] = 'body';
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
     * The configured model, falling back to the config default when unset.
     */
    public static function model(): string
    {
        $model = Setting::get(Setting::AI_TRANSLATION_MODEL);

        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        return (string) config('cms.ai.translation.default_model', 'openai/gpt-4o-mini');
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
     * $doc (text nodes' `text` plus the whitelisted block attrs). They are batch
     * translated 1:1 and written back into the SAME positions. Node types, marks,
     * and all structural attrs are left byte-identical.
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
    private function translateBodyDocument(string $apiKey, array $doc, array $segments, string $source, string $target): array
    {
        $translations = $this->translateBatch($apiKey, $segments, $source, $target);

        $cursor = 0;

        return $this->applyProseTranslations($doc, $translations, $cursor);
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
            $node['text'] = $translations[$cursor++];
        }

        $blockId = TipTap::customBlockId($node);
        $config = TipTap::customBlockConfig($node);
        $configChanged = false;

        foreach (TipTap::customBlockProseAttrs($node, forTranslation: true) as $attr) {
            if ($this->isTranslatableString($config[$attr] ?? null)) {
                $config[$attr] = $translations[$cursor++];
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
     * One chat-completion round trip. Any transport or non-2xx failure becomes a
     * domain exception carrying a localisation key; the raw response body and the
     * API key never appear in the thrown message.
     */
    private function translateText(string $apiKey, string $text, string $source, string $target): string
    {
        $system = sprintf(
            'You are a professional translator. Translate the user message from %s to %s. '
            .'Return only the translation, with no quotes, notes, or explanation. '
            .'Preserve meaning, tone, and any inline formatting.',
            $source,
            $target,
        );

        $content = $this->chat($apiKey, $system, $text);

        if (! is_string($content) || trim($content) === '') {
            throw AiTranslationException::requestFailed();
        }

        return trim($content);
    }

    /**
     * Translate a list of prose segments in one round trip, mapping 1:1 back to
     * their input positions. The model is instructed to return a JSON array of
     * the SAME length in the SAME order. A length mismatch (or any non-array /
     * non-string / empty-after-trim element) is a HARD failure — never a
     * best-effort merge — so a garbled, duplicated, or structurally-invalid body
     * can never be written. Empty elements are rejected here for the same reason
     * translateText() rejects empty single-field output: an empty string written
     * into a text node's `text` is an invalid ProseMirror/TipTap leaf (RULE #6).
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function translateBatch(string $apiKey, array $segments, string $source, string $target): array
    {
        $system = sprintf(
            'You are a professional translator. The user message is a JSON array of strings in %s. '
            .'Translate each element into %s and return ONLY a JSON array of the SAME length, in the SAME order, '
            .'with one translated string per input element. Preserve meaning, tone, and any inline formatting. '
            .'Do not add, remove, reorder, merge, or split elements, and translate nothing outside the array.',
            $source,
            $target,
        );

        $payload = json_encode($segments, JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw AiTranslationException::requestFailed();
        }

        $content = $this->chat($apiKey, $system, $payload);

        if (! is_string($content)) {
            throw AiTranslationException::requestFailed();
        }

        /** @var mixed $decoded */
        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded) || count($decoded) !== count($segments)) {
            throw AiTranslationException::requestFailed();
        }

        $translations = [];

        foreach ($decoded as $item) {
            // Reject a non-string OR empty-after-trim element. Mirrors
            // translateText(), which throws when the single-field result trims
            // to '': an empty text-node `text` is an invalid TipTap leaf (RULE
            // #6), and trimming keeps the batch path's output consistent with
            // the single-field path (which returns trim($content)).
            if (! is_string($item) || trim($item) === '') {
                throw AiTranslationException::requestFailed();
            }

            $translations[] = trim($item);
        }

        return $translations;
    }

    /**
     * One chat-completion POST. Returns the assistant message content, or throws
     * AiTranslationException::requestFailed() on any transport, non-2xx, or
     * malformed-response failure. The raw response body and the API key never
     * appear in the thrown message.
     */
    private function chat(string $apiKey, string $system, string $user): mixed
    {
        $endpoint = (string) config(
            'cms.ai.translation.endpoint',
            'https://openrouter.ai/api/v1/chat/completions',
        );

        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('cms.ai.translation.timeout', 30))
                ->asJson()
                ->post($endpoint, [
                    'model' => self::model(),
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (ConnectionException) {
            throw AiTranslationException::requestFailed();
        }

        if (! $response->successful()) {
            throw AiTranslationException::requestFailed();
        }

        try {
            return $response->json('choices.0.message.content');
        } catch (Throwable) {
            throw AiTranslationException::requestFailed();
        }
    }
}
