<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Setting;

/**
 * Which service performs machine translation (Requirement 5.3).
 *
 * WHY A PROVIDER ENUM RATHER THAN THREE CLIENT CLASSES:
 *
 * All three services speak the SAME protocol — OpenAI's chat-completions shape,
 * `Authorization: Bearer <key>`, `{"choices":[{"message":{"content":...}}]}` — so
 * what actually differs between them is three values: the endpoint, the catalogue
 * of model ids, and whether the provider wants OpenRouter's attribution headers.
 * Writing a client per provider would triple the code that carries AiTranslator's
 * hard-won invariants (the 1:1 segment contract, the chunk partition, the retry
 * policy) and give three places for them to drift apart. One client plus a
 * provider descriptor keeps every one of those guarantees in a single body of
 * code, and adding a fourth OpenAI-compatible service later is one case here and
 * one entry in config/cms.php.
 *
 * WHY THE KEY IS PER PROVIDER, NOT ONE SHARED FIELD:
 *
 * Each service issues its own credential, so a single shared key field would be
 * overwritten the moment an admin tried a second provider — and switching back
 * would mean fetching the first key again from a third-party dashboard. Three
 * secrets means an admin can configure all three once and switch between them
 * freely, which is the whole point of offering a choice. Every one is written
 * through Setting::putSecret() and so stored encrypted, keeping plaintext
 * credentials out of the append-only audit trail (RULE #8).
 *
 * The endpoints live in config/cms.php rather than here so an operator can point a
 * provider at a regional mirror or a corporate proxy without editing code; this
 * enum is the typed accessor over that map.
 */
enum AiProvider: string
{
    case OpenRouter = 'openrouter';

    case GapGpt = 'gapgpt';

    case ChatQt = 'chatqt';

    /**
     * The provider used when the admin has not chosen one.
     *
     * From `cms.ai.provider`, which ships as OpenRouter because that is the
     * provider this feature shipped with: an install that has never seen the
     * provider setting must keep translating through exactly the service it was
     * translating through before, with the key it already has stored.
     *
     * Resolved with tryFrom rather than fromValue() — fromValue() calls this
     * method, so using it here would recurse on a misconfigured config value.
     */
    public static function default(): self
    {
        $configured = config('cms.ai.provider');

        return is_string($configured)
            ? self::tryFrom($configured) ?? self::OpenRouter
            : self::OpenRouter;
    }

    /**
     * Resolve a stored/submitted value, falling back to the default.
     *
     * Tolerant rather than strict because the input is a database value: a setting
     * written by an older version, a hand-edited row, or a provider removed from a
     * future release must degrade to "translate through the default" instead of
     * throwing a ValueError out of a queued job where nobody sees it.
     */
    public static function fromValue(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom($value) ?? self::default()
            : self::default();
    }

    public function label(): string
    {
        return __("cms.ai_provider.{$this->value}");
    }

    public function description(): string
    {
        return __("cms.ai_provider.{$this->value}_help");
    }

    /**
     * The Setting key holding this provider's encrypted API key.
     *
     * Returns the Setting constants themselves rather than rebuilding the strings,
     * so the enum and the model cannot disagree about where a key is stored.
     */
    public function apiKeySettingKey(): string
    {
        return match ($this) {
            self::OpenRouter => Setting::OPENROUTER_API_KEY,
            self::GapGpt => Setting::GAPGPT_API_KEY,
            self::ChatQt => Setting::CHATQT_API_KEY,
        };
    }

    /**
     * The Setting key holding the admin's chosen model FOR THIS PROVIDER.
     *
     * Per provider for the same reason the key is: a model id is not portable.
     * With one shared model field, an admin who had named a model and then
     * switched service would send the old provider's id to the new one, which
     * answers 404 — and the install most likely to hit that is an upgraded one,
     * because the previous Settings page pre-filled the shared field with
     * `openai/gpt-4o-mini` whether the admin chose it or not. Storing the model
     * beside the provider it belongs to makes that mix-up unrepresentable rather
     * than something a warning has to catch.
     */
    public function modelSettingKey(): string
    {
        return Setting::AI_TRANSLATION_MODEL.'_'.$this->value;
    }

    /**
     * This provider's chat-completions URL.
     */
    public function endpoint(): string
    {
        return (string) config("cms.ai.providers.{$this->value}.endpoint");
    }

    /**
     * The model used when the admin has not named one.
     *
     * Per provider, because the catalogues are not identical and a model id is not
     * portable: a default that is valid on one service is a 404 on another, which
     * surfaces to the editor as a generic failure. Falls back to the global default
     * so a provider entry that omits a model is still usable.
     */
    public function defaultModel(): string
    {
        $model = config("cms.ai.providers.{$this->value}.default_model");

        if (is_string($model) && trim($model) !== '') {
            return trim($model);
        }

        return (string) config('cms.ai.translation.default_model', 'openai/gpt-4o-mini');
    }

    /**
     * Whether to send OpenRouter's `HTTP-Referer` / `X-Title` attribution headers.
     *
     * OpenRouter documents them and uses them to attribute traffic; the other
     * services do not ask for them. They are sent only where they mean something
     * rather than to every provider, because a header a provider does not
     * understand is at best noise and at worst discloses the deployment's URL and
     * client name to a third party that had no reason to receive it.
     */
    public function sendsAttributionHeaders(): bool
    {
        return (bool) config("cms.ai.providers.{$this->value}.attribution_headers", false);
    }

    /**
     * Where an admin gets a key, linked from the Settings page.
     */
    public function docsUrl(): ?string
    {
        $url = config("cms.ai.providers.{$this->value}.docs_url");

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }
}
