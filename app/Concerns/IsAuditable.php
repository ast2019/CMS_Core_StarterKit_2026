<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\AuditRedaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    /*
     * Events that produce an audit entry are DELIBERATELY NOT LISTED HERE ANY MORE.
     *
     * There used to be `protected static array $recordEvents = ['created', 'updated', 'deleted']`.
     * Spatie only consults its own defaults when that property is absent — and its defaults are the
     * same three PLUS `restored` for any model using SoftDeletes
     * (LogsActivity::eventsToBeRecorded). So the explicit list was silently opting every model out
     * of recording restores: the trail showed a record being deleted and never showed it coming
     * back, and an auditor reading it would conclude the record was gone.
     *
     * Removing the property is the whole fix for restores. Adding 'restored' to the list by hand
     * would have worked too, but `forceDeleted` would not: Spatie registers each listed event with
     * `static::$eventName(...)`, and a model that does NOT soft-delete has no such static method, so
     * Eloquent routes it through __callStatic, instantiates the model mid-boot and throws
     * "bootIfNotBooted may not be called while it is being booted" — taking the entire application
     * down at boot, for every request. Hence the explicit hook below instead.
     *
     * `publish` is not an Eloquent event either; the publish workflow logs it explicitly, because
     * "who put this live" is the single question an audit trail on a CMS exists to answer and a
     * status column changing inside a generic `updated` row buries it.
     */

    /**
     * Record a PERMANENT deletion as its own event.
     *
     * Without this, the trail could not tell a trash from a destruction: `forceDelete()` fires
     * `deleted` as well, so both wrote an identical `deleted` row — while cms:prune-trash's docblock
     * and docs/deployment.md both lean on the log recording that a record WAS destroyed. After a
     * retention sweep an auditor could see that something was deleted in March and had no way to
     * know whether it could still be recovered.
     *
     * Registered only for models that can be force-deleted, for the boot-order reason above, and
     * logged directly rather than through Spatie's event list so the event name is `destroyed` and
     * reads differently from `deleted` in the audit page.
     */
    public static function bootIsAuditable(): void
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            return;
        }

        /*
         * registerModelEvent(), not static::forceDeleted().
         *
         * Eloquent's Model defines the registrar; the named helper comes from the SoftDeletes trait,
         * so writing `static::forceDeleted(...)` is only valid in the classes that use it — and
         * static analysis reads a trait in the context of EVERY class that uses it, so it correctly
         * reported an undefined static method for Setting and ContactSetting even though the guard
         * above means the line never runs for them. The underlying API says exactly the same thing
         * and says it in a form that is true for all of them.
         */
        static::registerModelEvent('forceDeleted', function (Model $model): void {
            activity('cms')
                ->performedOn($model)
                ->withProperties(['permanent' => true])
                ->event('destroyed')
                ->log('destroyed');
        });
    }
}
