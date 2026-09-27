<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Ask the Gate a question while RENDERING, without it being audited as a refusal.
 *
 * CmsServiceProvider::logAuthorisationDenials() records every denied check, because a refused
 * action is a security signal. But a screen that decides whether to draw an edit link is not an
 * action: an Author looking at the editorial calendar is not "attempting" to edit every colleague's
 * article in it, and auditing it that way would write one `denied:update` row per entry per page
 * load — a write per row, and an audit trail in which real refusals drown.
 *
 * The Gate itself is still what answers, so Gate::before (inactive accounts, admins) and the
 * policies stay the single source of the rules; only the denial log is told to look away.
 * Anything that performs the action authorises again, audibly — Filament's edit page authorises
 * `update` on mount, so following a link someone should not have is still recorded.
 */
final class AuthorisationProbe
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     */
    public static function quietly(Closure $check): mixed
    {
        self::$depth++;

        try {
            return $check();
        } finally {
            self::$depth--;
        }
    }

    public static function isQuiet(): bool
    {
        return self::$depth > 0;
    }
}
