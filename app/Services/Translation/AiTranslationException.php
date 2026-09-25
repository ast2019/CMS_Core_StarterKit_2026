<?php

declare(strict_types=1);

namespace App\Services\Translation;

use RuntimeException;

/**
 * A machine-translation attempt that could not complete.
 *
 * Carries a translation KEY rather than a rendered string, so the caller (a
 * Filament action) decides the presentation and every user-facing message stays
 * in lang/fa|en|ar. The message passed to the parent RuntimeException is the key
 * too, which keeps the exception useful in logs without ever embedding the API
 * key — no OpenRouter response body or credential is placed in the message.
 */
final class AiTranslationException extends RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
    ) {
        parent::__construct($translationKey);
    }

    public static function disabled(): self
    {
        return new self('cms.ai_translation.error.disabled');
    }

    public static function missingKey(): self
    {
        return new self('cms.ai_translation.error.missing_key');
    }

    public static function requestFailed(): self
    {
        return new self('cms.ai_translation.error.request_failed');
    }

    public static function emptySource(): self
    {
        return new self('cms.ai_translation.error.empty_source');
    }

    /**
     * A human already signed off on this locale, so re-running the machine over
     * it is refused: overwriting reviewed text would silently revert the
     * sign-off and could re-translate/duplicate content the editor already
     * finalised. The caller surfaces this as "skipped because already reviewed".
     */
    public static function alreadyReviewed(): self
    {
        return new self('cms.ai_translation.error.already_reviewed');
    }
}
