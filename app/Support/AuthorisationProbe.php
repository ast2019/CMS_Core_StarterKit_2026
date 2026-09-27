<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

/**
 * Whether a Gate check is a DISPLAY decision, which the denial audit must not record.
 *
 * CmsServiceProvider::logAuthorisationDenials() records every denied check, because a refused
 * action is a security signal. But deciding whether to draw a button is not an action. Filament
 * asks the Gate about the edit and delete actions of every row it renders, so an Author opening
 * the article list was audited as refused `update` and `delete` on each colleague's article, and a
 * Viewer opening the media library wrote fifty rows for a page of twenty-five files — on every
 * render, including each search keystroke. The trail filled with refusals nobody attempted, a
 * database write per row, and the real ones were impossible to find.
 *
 * Two ways a check counts as a display decision:
 *
 *  - It runs while Livewire is rendering the view of the component currently being handled. That
 *    is where Filament resolves the visibility of actions, columns and navigation. Everything a user
 *    can actually DO is authorised outside it, in the same request: a page authorises access in
 *    mount() and on hydrate (a direct visit to an edit page someone may not have is still
 *    recorded), and an action authorises again when it is mounted and when it is called (a
 *    hand-crafted request for a hidden button is still recorded too).
 *  - It is wrapped in quietly(). NEEDED for display decisions made in a page's render() or
 *    getViewData(): Livewire builds the view data BEFORE it pushes its render stack, so those
 *    checks are otherwise audited. EditorialCalendar::entriesFor() is one; do not remove its
 *    wrapper as redundant.
 *
 * The Gate itself still answers either way, so Gate::before (inactive accounts, admins) and the
 * policies stay the single source of the rules. Only the denial log looks away.
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
        return self::$depth > 0 || self::isRendering();
    }

    /**
     * Whether Livewire is inside the Blade render of the component it is currently handling.
     *
     * Not simply "the render stack is non-empty", for two reasons that both fail OPEN — silencing
     * the audit when it should not be:
     *
     *  - Livewire pops its render stack with tap(), not finally. A render that throws leaves its
     *    entry behind, and nothing clears it outside tests (`flush-state`; Octane is not installed),
     *    so every later denial in that process would go unrecorded. The component stack IS popped
     *    in finally, so requiring the two tops to agree makes a stale entry inert.
     *  - A child component mounted during its parent's render (a relation manager, an embedded
     *    component) would otherwise authorise under the parent's entry. With the comparison, the
     *    child's own mount is audited like any other; its render is quiet like any other.
     *
     * Both stacks are public statics of Livewire's own. AuthorisationProbeTest pins the behaviour,
     * so a Livewire upgrade that changes them fails there instead of silently re-flooding or
     * silencing the audit trail.
     */
    private static function isRendering(): bool
    {
        $rendering = end(HandleComponents::$renderStack);

        return $rendering !== false && $rendering === end(HandleComponents::$componentStack);
    }
}
