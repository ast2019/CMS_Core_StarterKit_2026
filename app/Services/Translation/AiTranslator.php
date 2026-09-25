<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Contracts\TracksTranslationStatus;
use App\Enums\TranslationStatus;
use App\Models\Setting;
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
 * nodes' `text`, plus the prose attrs of the custom blocks), translates those
 * strings, and writes them back into the SAME node positions. Node types, marks,
 * and every structural attr (URLs, IDs, media_asset_id, gallery_id, tone, level,
 * layout, cta_url, max_items, ...) stay byte-identical, so the structure cannot
 * be corrupted. `slug` is still skipped because it is per-locale and editorially
 * owned (matching HasTranslationStatus::sourceContentHash, which also skips it),
 * and `robots_meta` is a directive token ("index, follow"), not prose.
 *
 * WHY re-translation cannot duplicate work:
 *
 * A locale a human already marked Reviewed is refused up front (before any HTTP
 * work) so signed-off text is never overwritten or re-translated. This closes a
 * gap: markTranslationAiTranslated() already refused to downgrade a reviewed
 * STATUS row, but translate() used to overwrite the field values regardless.
 * Re-running over an unreviewed locale REPLACES the target text via
 * setTranslation() (it never appends), so nothing is duplicated.
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
     * TipTap custom-block prose attrs to translate, keyed by block node `type`.
     * Every other attr on these nodes (tone, level, cta_url, media_asset_id,
     * gallery_id, layout, max_items, ...) is structural and left untouched.
     * gallery_embed is intentionally absent: it carries no prose.
     *
     * @var array<string, list<string>>
     */
    private const BLOCK_PROSE_ATTRS = [
        'callout' => ['title', 'body'],
        'hero' => ['heading', 'lead', 'cta_label'],
        'quote' => ['quote', 'attribution', 'attribution_role'],
    ];

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
         * not_translated / ai_translated / outdated locales may be re-translated.
         */
        if ($record->translationStatusFor($targetLocale) === TranslationStatus::Reviewed) {
            throw AiTranslationException::alreadyReviewed();
        }

        $source = (string) config('cms.locales.source', 'fa');
        $fields = $this->translatableFields($record, $source);
        $bodyDoc = $this->sourceBodyDocument($record, $source);

        if ($fields === [] && $bodyDoc === null) {
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

        if ($bodyDoc !== null) {
            [$rebuiltDoc, $bodyHadText] = $this->translateBodyDocument($apiKey, $bodyDoc, $source, $targetLocale);

            // setTranslation REPLACES the locale's stored array value, so a
            // re-run overwrites the previous body rather than appending to it.
            $record->setTranslation('body', $targetLocale, $rebuiltDoc);

            if ($bodyHadText) {
                $translatedAttributes[] = 'body';
            }
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
     * The tree is walked once to collect the ordered prose leaves (text nodes'
     * `text` plus the whitelisted block attrs), those strings are batch
     * translated 1:1, then written back into the SAME positions. Node types,
     * marks, and all structural attrs are left byte-identical.
     *
     * @param  array<string, mixed>  $doc
     * @return array{0: array<string, mixed>, 1: bool} rebuilt doc, and whether it contained any prose
     */
    private function translateBodyDocument(string $apiKey, array $doc, string $source, string $target): array
    {
        $segments = [];
        $this->collectProseSegments($doc, $segments);

        if ($segments === []) {
            return [$doc, false];
        }

        $translations = $this->translateBatch($apiKey, $segments, $source, $target);

        $cursor = 0;
        $rebuilt = $this->applyProseTranslations($doc, $translations, $cursor);

        return [$rebuilt, true];
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

        if ($type !== null && isset(self::BLOCK_PROSE_ATTRS[$type]) && isset($node['attrs']) && is_array($node['attrs'])) {
            foreach (self::BLOCK_PROSE_ATTRS[$type] as $attr) {
                if ($this->isTranslatableString($node['attrs'][$attr] ?? null)) {
                    /** @var string $value */
                    $value = $node['attrs'][$attr];
                    $segments[] = $value;
                }
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

        if ($type !== null && isset(self::BLOCK_PROSE_ATTRS[$type]) && isset($node['attrs']) && is_array($node['attrs'])) {
            foreach (self::BLOCK_PROSE_ATTRS[$type] as $attr) {
                if ($this->isTranslatableString($node['attrs'][$attr] ?? null)) {
                    $node['attrs'][$attr] = $translations[$cursor++];
                }
            }
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
