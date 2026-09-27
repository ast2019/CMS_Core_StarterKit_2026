<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Content;
use App\Models\TranslationState;
use Illuminate\Support\Facades\Cache;

/**
 * Item 38 — the two translation-backlog numbers the panel shows on its navigation.
 *
 * A navigation badge is computed on EVERY page render, for EVERY user, whether or not they look at
 * it. These two were uncached aggregates — a `whereHas` COUNT over contents and a COUNT over
 * translation_states — so every click anywhere in the panel paid for both, for the whole team, all
 * day, to show a number that changes when somebody finishes translating something.
 *
 * Cached and cleared by the writes that can move them, which makes the numbers exact rather than
 * eventually right: the invalidation hooks are on TranslationState (saved, deleted) and on every
 * translatable model's trash lifecycle (HasTranslationStatus), because the article badge counts only
 * articles that are not in the trash. The one write path that bypasses model events — the bulk
 * delete of a destroyed record's states — calls forget() itself.
 *
 * The TTL is a backstop for writes nobody anticipated, not the mechanism. Forgetting rather than
 * recomputing on write means a burst of saves costs nothing; the next page render recomputes once.
 */
class TranslationBacklog
{
    public const ARTICLES_KEY = 'cms:panel:translation-backlog:articles';

    public const STATES_KEY = 'cms:panel:translation-backlog:states';

    private const TTL_MINUTES = 10;

    /**
     * Articles with at least one locale needing work — the Articles menu badge.
     */
    public static function articlesNeedingWork(): int
    {
        return (int) Cache::remember(
            self::ARTICLES_KEY,
            now()->addMinutes(self::TTL_MINUTES),
            fn (): int => Content::query()
                ->whereHas('translationStates', fn ($query) => $query->needingAttention())
                ->count(),
        );
    }

    /**
     * Locale-states needing work across every translatable type — the review page badge.
     */
    public static function statesNeedingWork(): int
    {
        return (int) Cache::remember(
            self::STATES_KEY,
            now()->addMinutes(self::TTL_MINUTES),
            fn (): int => TranslationState::query()->needingAttention()->count(),
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::ARTICLES_KEY);
        Cache::forget(self::STATES_KEY);
    }
}
