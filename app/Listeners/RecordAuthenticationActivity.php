<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/**
 * Item 52 — sign-ins are part of the audit trail.
 *
 * The log recorded every write in the panel and every authorisation denial, but not who had signed
 * in, so the questions an administrator asks first after something goes wrong — was this account
 * used last night, from where, is somebody trying the password — had no answer anywhere.
 *
 * Two events, both into the same `cms` log the Audit page reads:
 *
 *  - `login` — a successful sign-in. `via_cookie` marks one restored from a remember-me cookie, the
 *    device remembering rather than a person typing a password.
 *  - `login_failed` — a rejected attempt against an EXISTING account, with a `reason`, because the
 *    three causes call for different responses: `wrong_password` (someone is guessing),
 *    `account_disabled` (a correct password for a deactivated account — the password is known, and
 *    so is the fact that this person is no longer supposed to have access), and
 *    `wrong_second_factor` (the password is known and the second factor is being guessed — the
 *    strongest compromise signal of the three; written by App\Filament\Pages\Auth\Login, because
 *    Filament fires no event for it).
 *
 * Attempts against addresses that match NO account are deliberately not written. They say nothing
 * about any account here, the only data they carry is whatever string the attacker typed, and an
 * audit trail that can be filled with attacker-chosen text by anyone who can reach the login form is
 * a trail an administrator learns to stop reading. The login rate limit still applies to them.
 *
 * The password is never touched: Failed carries the credentials, and this reads nothing from them.
 *
 * Failed rows have the account as SUBJECT and no causer. Nobody knows who typed the password — that is
 * the point of the row — so it is filed under the account it was aimed at, and the Audit page shows
 * the missing causer as "system". Recording the account as the causer would put an attacker's attempts
 * into every "what did this user do" query.
 *
 * The writes are DEFERRED to after the response. A failed sign-in runs inside Filament's Timebox,
 * which pads every failure to a constant duration so response time cannot reveal whether an account
 * exists; an INSERT inside that window only stays hidden while it fits under the padding. Deferring
 * takes it out of the timed section entirely, and costs nothing — the audit row is the same row a
 * few milliseconds later.
 */
class RecordAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        /*
         * viaRemember(), not $event->remember. The event's flag is also true for a password sign-in
         * with "Remember me" ticked — Filament passes the checkbox straight through — so it cannot
         * tell a person typing a password from a device replaying a cookie. Only the guard knows
         * which one just happened.
         */
        $guard = auth()->guard($event->guard);
        $viaCookie = method_exists($guard, 'viaRemember') && $guard->viaRemember();

        $context = $this->requestContext();

        defer(function () use ($user, $viaCookie, $context): void {
            activity('cms')
                ->causedBy($user)
                ->performedOn($user)
                ->event('login')
                ->withProperties([...$context, 'via_cookie' => $viaCookie])
                ->log('login');

            /*
             * The users list reads this column rather than scanning the log.
             *
             * Through the BASE query builder, so `updated_at` is left alone. A sign-in is not an edit
             * of the account, and moving updated_at would make the concurrent-edit guard (item 35)
             * warn an administrator who happened to be editing this user at the moment they signed in.
             */
            User::query()->whereKey($user->getKey())->toBase()->update(['last_login_at' => now()]);
        });
    }

    public function handleFailed(Failed $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        /*
         * An inactive account is rejected by Filament's panel-access check AFTER the password was
         * found correct, so is_active separates the two causes Failed does not distinguish.
         */
        $this->recordFailure($event->user, $event->user->is_active ? 'wrong_password' : 'account_disabled');
    }

    /**
     * One failed sign-in against an existing account.
     *
     * @param  'wrong_password'|'account_disabled'|'wrong_second_factor'  $reason
     */
    public function recordFailure(User $user, string $reason): void
    {
        $context = $this->requestContext();

        defer(function () use ($user, $reason, $context): void {
            activity('cms')
                ->performedOn($user)
                ->event('login_failed')
                ->withProperties([...$context, 'reason' => $reason])
                ->log('login_failed');
        });
    }

    /**
     * Where the attempt came from, captured while the request is still available (the deferred write
     * runs after the response). The IP is whatever TrustProxies resolved, so it is the visitor's
     * rather than the proxy's; the user agent is truncated because it is client-supplied.
     *
     * @return array{ip: string|null, user_agent: string}
     */
    private function requestContext(): array
    {
        return [
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
        ];
    }
}
