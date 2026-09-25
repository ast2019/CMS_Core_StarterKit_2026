<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * The outcome of a successful AiTranslator::translate() call: which locale was
 * written and which attributes were filled. Returned rather than a bare bool so
 * a caller can report "translated 4 fields into English" without re-inspecting
 * the record.
 */
final class AiTranslationResult
{
    /**
     * @param  list<string>  $attributes
     */
    public function __construct(
        public readonly string $locale,
        public readonly array $attributes,
    ) {}

    public function fieldCount(): int
    {
        return count($this->attributes);
    }
}
