<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\AuditRedaction;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * RULE #8 — AUDIT LOGGING.
 *
 * "every admin write action logged automatically, no opt-out"
 *
 * Blueprint §12.8, Requirements 9.5, 9.6.
 *
 * Why this wrapper exists rather than using LogsActivity directly:
 *
 * Spatie's trait delegates configuration to a `getActivitylogOptions()` method
 * that each model defines. That is the opt-out. A model could return
 * `LogOptions::defaults()->logOnly([])` or `dontSubmitEmptyLogs()` and log
 * nothing while still appearing, to a reviewer scanning `use` statements, to be
 * fully audited. This trait supplies that method itself with a fixed
 * configuration, so the decision is made in one place for every model.
 *
 * PHP lets a class override a trait method, so the trait alone cannot make this
 * airtight. tests/Architecture/AuditLoggingTest.php closes the gap: it asserts
 * that every content model's `getActivitylogOptions()` is still the one defined
 * in this file, by comparing the reflected method's declaring file. Overriding
 * it in a model fails the build.
 */
trait IsAuditable
{
    use LogsActivity;

    /**
     * Fixed audit configuration. Do not override this in a model — the
     * architecture test will fail the build if you do.
     *
     * `logFillable()` plus `logUnguarded()` records every mass-assignable and
     * unguarded attribute. `logOnlyDirty()` is deliberately NOT used: for a
     * forensic trail we want the full before/after snapshot of a write, not
     * only the columns Eloquent noticed changing. The extra rows are cheap next
     * to being unable to answer "what did this record look like before?".
     *
     * `logEmptyChanges()` is likewise left on: a save that changed nothing is
     * still a fact about who touched the record and when.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logUnguarded()
            ->logEmptyChanges()
            ->useLogName('cms')
            ->logExcept($this->auditExcludedAttributes())
            ->setDescriptionForEvent(
                fn (string $eventName): string => sprintf(
                    '%s.%s',
                    class_basename($this),
                    $eventName,
                ),
            );
    }

    /**
     * Attributes never written to the audit trail.
     *
     * Secrets must not be duplicated into a table that is, by design,
     * append-only and never pruned — logging a TOTP secret or password hash
     * there would outlive every rotation of the original value. Timestamps are
     * excluded because the activity row carries its own.
     *
     * A model may extend this list (to exclude an extra secret) but the
     * architecture test asserts the baseline entries are always present, so it
     * cannot be used to quietly stop logging real content fields.
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        return AuditRedaction::ALWAYS_EXCLUDED;
    }

    /**
     * Events that produce an audit entry.
     *
     * `publish` is not an Eloquent event — the publish workflow logs it
     * explicitly, because "who put this live" is the single question an audit
     * trail on a CMS exists to answer, and a status column changing from
     * 'review' to 'published' inside a generic `updated` row buries it.
     *
     * @var list<string>
     */
    protected static array $recordEvents = ['created', 'updated', 'deleted'];
}
