<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Contracts\TracksTranslationStatus;
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
 * WHY only plain-text fields are translated:
 *
 * The RichEditor `body` is a TipTap JSON document (RULE #6). Round-tripping that
 * structure through a translation model risks corrupting the node tree, so `body`
 * is deliberately left for a human to translate in the editor. `slug` is skipped
 * because it is per-locale and editorially owned (matching
 * HasTranslationStatus::sourceContentHash, which also skips it), and `robots_meta`
 * is a directive token ("index, follow"), not prose. Everything else — title,
 * excerpt, answer paragraph, meta title/description — is short prose and is
 * translated.
 */
class AiTranslator
{
    /**
     * Translatable attributes that must never be sent to the model.
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
     * @throws AiTranslationException on disabled feature, missing key, empty
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

        $source = (string) config('cms.locales.source', 'fa');
        $fields = $this->translatableFields($record, $source);

        if ($fields === []) {
            throw AiTranslationException::emptySource();
        }

        $translated = [];

        foreach ($fields as $attribute => $text) {
            $translated[$attribute] = $this->translateText($apiKey, $text, $source, $targetLocale);
            $record->setTranslation($attribute, $targetLocale, $translated[$attribute]);
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

        return new AiTranslationResult($targetLocale, array_keys($translated));
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
     * One chat-completion round trip. Any transport or non-2xx failure becomes a
     * domain exception carrying a localisation key; the raw response body and the
     * API key never appear in the thrown message.
     */
    private function translateText(string $apiKey, string $text, string $source, string $target): string
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
                        [
                            'role' => 'system',
                            'content' => sprintf(
                                'You are a professional translator. Translate the user message from %s to %s. '
                                .'Return only the translation, with no quotes, notes, or explanation. '
                                .'Preserve meaning, tone, and any inline formatting.',
                                $source,
                                $target,
                            ),
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);
        } catch (ConnectionException) {
            throw AiTranslationException::requestFailed();
        }

        if (! $response->successful()) {
            throw AiTranslationException::requestFailed();
        }

        try {
            $content = $response->json('choices.0.message.content');
        } catch (Throwable) {
            throw AiTranslationException::requestFailed();
        }

        if (! is_string($content) || trim($content) === '') {
            throw AiTranslationException::requestFailed();
        }

        return trim($content);
    }
}
