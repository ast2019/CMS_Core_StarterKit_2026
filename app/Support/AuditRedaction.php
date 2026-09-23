<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Attributes that must never be written to the audit trail.
 *
 * RULE #8 — Requirements 9.5, 9.6.
 *
 * A final class rather than a constant on the IsAuditable trait, for two
 * reasons. PHP forbids reading a trait constant directly
 * (`IsAuditable::ALWAYS_EXCLUDED` is a fatal error; it must be reached through a
 * using class), which makes the list awkward to assert against in tests. And a
 * config entry would be the wrong home: a per-site .env could then widen the
 * redaction list until nothing was logged at all, which is precisely the opt-out
 * RULE #8 forbids.
 */
final class AuditRedaction
{
    /**
     * The audit table is append-only and never pruned, so anything written into
     * it outlives every later rotation of the original value. A password hash or
     * TOTP secret captured here would remain readable long after the live
     * credential had been replaced.
     *
     * @var list<string>
     */
    public const ALWAYS_EXCLUDED = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',

        // The activity row carries its own timestamp; logging these would add a
        // diff entry to every single save and bury the real changes.
        'created_at',
        'updated_at',
    ];

    private function __construct() {}
}
