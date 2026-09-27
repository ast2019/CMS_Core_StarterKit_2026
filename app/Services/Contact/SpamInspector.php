<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Http\Requests\StoreContactSubmissionRequest;
use App\Models\SystemHeartbeat;
use Illuminate\Http\Request;

/**
 * Item 16 — whether a contact submission looks automated.
 *
 * Returns a REASON rather than a boolean, because the panel shows the editor which check
 * fired and the two answers are acted on differently: a honeypot hit is almost always a
 * bot, while a missing timing field is almost always the frontend not sending it. A
 * boolean would collapse a deployment bug and an attack into the same row.
 *
 * Nothing here rejects anything. The caller stores the submission either way and the flag
 * only decides which list it appears in — see the migration adding `is_spam` for why that
 * is the right shape, and config('cms.contact.spam') for what these checks are honestly
 * worth.
 *
 * A separate class rather than rules on the Form Request: a Form Request can only answer
 * "valid" or "422", and 422 is precisely the outcome this feature exists to avoid.
 */
class SpamInspector
{
    /**
     * The spam reason key for this request, or null when nothing fired.
     *
     * Checks run in order of confidence, and the FIRST one to fire is the one recorded.
     * A bot that fills the honeypot usually also submits instantly, and "honeypot" is the
     * more diagnostic of the two answers.
     */
    public function reasonFor(Request $request): ?string
    {
        $this->recordFieldSightings($request);

        if ($this->honeypotWasFilled($request)) {
            return 'honeypot';
        }

        return $this->timingReason($request);
    }

    /**
     * Note that a submission ARRIVED carrying each spam field.
     *
     * WHY THIS IS WORTH A WRITE.
     *
     * Both checks are only a signal when the field reaches us, and the field comes from a
     * frontend in a different repository. Rename `CMS_CONTACT_HONEYPOT_FIELD` without a
     * matching frontend deploy and the honeypot is simply off — 201s all round, a clean
     * inbox, and nothing anywhere saying the site's main spam defence stopped working. A
     * defence that can switch itself off silently is the failure mode this project spends
     * most of its effort avoiding, and "the deployment guide says change both together" is
     * advice, not detection.
     *
     * Recorded on `system_heartbeats` rather than in a column on the submission, because the
     * question is "when did we last SEE one of these" and that is exactly what that table
     * answers. It also means no per-row storage for a fact about the deployment rather than
     * about the message.
     *
     * An upsert per submission, against a route limited to 3/minute — the cost is nil.
     */
    private function recordFieldSightings(Request $request): void
    {
        foreach (['honeypot_field' => SystemHeartbeat::CONTACT_HONEYPOT, 'timing_field' => SystemHeartbeat::CONTACT_TIMING] as $configKey => $heartbeatKey) {
            $field = $this->fieldName($configKey);

            /*
             * `has()`, not `filled()`. An EMPTY honeypot is the normal, correct outcome for
             * every real enquiry — it means the frontend rendered the decoy and the visitor
             * left it alone, which is precisely the thing being confirmed. Waiting for a
             * filled one would only report sightings of bots.
             */
            if ($field !== null && $request->has($field)) {
                SystemHeartbeat::record($heartbeatKey);
            }
        }
    }

    /**
     * Whether the decoy field arrived with anything in it.
     *
     * `filled()` rather than a presence check: an ABSENT honeypot is not suspicious on its
     * own — a frontend that predates this feature sends no such field, and flagging those
     * would flag every enquiry on the site. Only a field that arrived with content is a
     * signal, and that signal is unambiguous because no human can see the input.
     *
     * Read from the request rather than from validated input, because the decoy is
     * deliberately absent from StoreContactSubmissionRequest::rules() — validating it would
     * publish its name in the OpenAPI spec, which is the one place a scraper would look.
     */
    private function honeypotWasFilled(Request $request): bool
    {
        $field = $this->fieldName('honeypot_field');

        if ($field === null) {
            return false;
        }

        return filled($request->input($field));
    }

    /**
     * Whether the form was submitted faster than a human could have filled it.
     */
    private function timingReason(Request $request): ?string
    {
        $field = $this->fieldName('timing_field');

        if ($field === null) {
            return null;
        }

        $raw = $request->input($field);

        if (blank($raw)) {
            // Off by default, and deliberately so — see the config note. A deployment
            // whose frontend does not send the field would otherwise flag its whole inbox.
            return (bool) config('cms.contact.spam.require_timing', false)
                ? 'missing_timing'
                : null;
        }

        if (! is_numeric($raw)) {
            /*
             * Garbage in the field is not treated as spam. It is far more likely to be a
             * frontend sending an ISO string or a Date object than an attacker choosing
             * to fail a check they could simply omit — and flagging it would turn a
             * formatting mistake on the frontend into invisible lost enquiries.
             */
            return null;
        }

        $presentedAt = (int) $raw;
        $elapsed = now()->getTimestamp() - $presentedAt;

        /*
         * A timestamp in the future is not flagged either. The value comes from the
         * VISITOR's clock, which is routinely minutes out and occasionally years out, and
         * a wrong clock is not abuse. Only a positive elapsed time below the threshold is
         * evidence, because that is the one case where both clocks agree the submission
         * was instant.
         */
        if ($elapsed < 0) {
            return null;
        }

        $minimum = max(0, (int) config('cms.contact.spam.min_fill_seconds', 3));

        return $elapsed < $minimum ? 'too_fast' : null;
    }

    /**
     * A configured field name, or null when the check is not usable.
     *
     * Two ways it is not usable. The deployment blanked it, which is the documented way to
     * switch one check off. Or it was set to the name of a REAL form field — and that one
     * has to be refused rather than obeyed: `CMS_CONTACT_HONEYPOT_FIELD=subject` would read
     * every visitor's subject line as a filled decoy, flag 100% of submissions, hide them
     * all behind the default filter and report nothing wrong anywhere. The inbox would
     * simply go dark.
     *
     * A typo lands on a name nobody sends, which fails open (the check stops firing) rather
     * than closed. That is the safer of the two directions and is what the honeypot-sighting
     * card on SystemStatusWidget exists to surface.
     */
    private function fieldName(string $key): ?string
    {
        $name = config("cms.contact.spam.{$key}");

        if (! is_string($name) || $name === '') {
            return null;
        }

        return in_array($name, self::submissionFieldNames(), strict: true) ? null : $name;
    }

    /**
     * The fields a visitor legitimately fills in.
     *
     * Read from the Form Request rather than listed here, so adding a field to the contact
     * form cannot leave a second copy of this list behind to disagree with it.
     *
     * @return list<string>
     */
    private static function submissionFieldNames(): array
    {
        return array_keys((new StoreContactSubmissionRequest)->rules());
    }
}
