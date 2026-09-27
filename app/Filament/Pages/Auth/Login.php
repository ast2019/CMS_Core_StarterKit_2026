<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Listeners\RecordAuthenticationActivity;
use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Item 52 — Filament's login page, plus a record of a wrong second factor.
 *
 * A wrong password or a disabled account already produces a `login_failed` audit row, through the
 * `Failed` event Filament fires for both. A wrong TOTP code produces nothing at all: Filament
 * validates the challenge form and raises a validation error without firing any event, and its
 * multi-factor code dispatches none of its own. That is the WORST gap to leave, because it is the
 * one failure that proves the password is already known — someone got past the first factor and is
 * guessing the second.
 *
 * Deliberately the smallest possible override. Filament's authenticate() runs the password check and
 * the challenge inside two Timeboxes that pad failures to a constant duration; reimplementing it
 * would mean re-deriving that timing protection and tracking every upstream change to it. Instead
 * the whole call is wrapped, and only one specific outcome is recognised: a ValidationException
 * thrown while this component is mid-challenge for a known account. The exception is rethrown
 * unchanged, so the editor sees exactly what Filament would have shown.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        // Captured BEFORE the call: Filament clears it on some failure paths, and the question is
        // whether this request was answering a challenge.
        $challengedUserId = $this->challengedUserId();

        try {
            return parent::authenticate();
        } catch (ValidationException $exception) {
            if ($challengedUserId !== null && $this->isSecondFactorFailure($exception)) {
                $user = User::query()->find($challengedUserId);

                if ($user instanceof User) {
                    app(RecordAuthenticationActivity::class)->recordFailure($user, 'wrong_second_factor');
                }
            }

            throw $exception;
        }
    }

    /**
     * The account this page is currently challenging, or null when no challenge is in progress.
     *
     * Filament stores it encrypted in a Livewire property; decrypting it is how Filament itself
     * checks the challenge belongs to the same account.
     */
    private function challengedUserId(): mixed
    {
        if (blank($this->userUndertakingMultiFactorAuthentication)) {
            return null;
        }

        try {
            return decrypt($this->userUndertakingMultiFactorAuthentication);
        } catch (\Throwable) {
            // A tampered value is not ours to diagnose; Filament rejects it on its own terms.
            return null;
        }
    }

    /**
     * Whether the validation failure came from the challenge form rather than from the password form.
     *
     * The challenge form's state lives under `data.multiFactor` (Filament's Login::multiFactor…
     * statePath), so its errors are keyed `data.multiFactor.*`. A credential or access failure is
     * always reported as `data.email` (throwFailureValidationException) and already reaches the
     * `Failed` listener — for instance when an account is deactivated mid-challenge — so it is
     * excluded here, or one attempt would write two rows.
     */
    private function isSecondFactorFailure(ValidationException $exception): bool
    {
        $fields = array_keys($exception->errors());

        if ($fields === [] || in_array('data.email', $fields, true)) {
            return false;
        }

        foreach ($fields as $field) {
            if (str_starts_with((string) $field, 'data.multiFactor.')) {
                return true;
            }
        }

        return false;
    }
}
