<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\TracksTranslationStatus;
use App\Filament\Schemas\TranslatableTabs;
use App\Models\User;
use App\Services\Translation\AiTranslationException;
use App\Services\Translation\AiTranslator;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Machine-translate one record into one locale, on the queue.
 *
 * Requirement 5.3. Follows the structure of SyncSearchIndexes — the project's other
 * job — including re-fetching the model by key rather than serialising it.
 *
 * WHY QUEUED:
 *
 * AiTranslator::translate() makes SEQUENTIAL OpenRouter calls: one batched request
 * for every plain field, plus one per chunk of the TipTap body. Batching brought the
 * floor down from six requests to two, but a long article is several body chunks,
 * each with its own timeout and its own retry budget, so the worst case the
 * configuration permits is still many minutes of wall clock. Run inside the Livewire
 * request, as it used to be, PHP's max_execution_time or nginx's
 * fastcgi_read_timeout kills it first — so the work is lost AFTER the API calls have
 * been paid for, the record keeps whatever partial state the kill left behind, and a
 * PHP-FPM worker was occupied for minutes to achieve that. Queueing removes the
 * deadline entirely.
 *
 * WHY UNIQUE:
 *
 * The action is a table row button, and a double click used to fire two full
 * translation runs: both spent money, both wrote the same fields, and the later one
 * won. ShouldBeUnique keyed on (record class, record id, target locale) collapses
 * that to one run. The key is deliberately NOT keyed on the user: two translators
 * asking for the same locale of the same article want the same single result.
 *
 * WHY RETRIES ARE ALMOST OFF:
 *
 * A retry re-translates everything from scratch — there is no partial-progress
 * checkpoint — so each attempt costs another full set of API calls. Domain failures
 * (feature disabled, missing key, already reviewed, empty source, upstream refusal)
 * are therefore reported to the user and NOT retried: they are answers, not
 * transient faults, and re-asking would only spend again. $tries = 2 exists for
 * infrastructure faults where nothing was written (a worker restart, a lost
 * database connection). failOnTimeout keeps a timeout from becoming a second paid
 * attempt.
 */
class TranslateRecordJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One retry, for infrastructure faults only.
     *
     * AiTranslationException never reaches the queue's failure handling — handle()
     * reports it and returns — so a retry can only be triggered by something that
     * is not a translation outcome.
     */
    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60];

    /**
     * A timeout is not a transient fault to be retried but evidence that the run
     * exceeded the time its own API budget allows. Retrying would issue a second
     * full set of paid calls with no reason to believe they would be faster.
     */
    public bool $failOnTimeout = true;

    /**
     * Wall-clock budget for the whole run.
     *
     * A KILL-SWITCH sized to the worst case the configuration permits, not an
     * expectation: every permitted request using its full timeout, every attempt
     * retried, every retry waiting the capped delay. A typical run finishes in
     * seconds. Erring generous is deliberate — a timeout set to the expected duration
     * kills runs that were merely slow, after their calls were paid for, which is
     * strictly worse than a worker occupied by a genuinely stuck job until the cap.
     *
     * Derived from AiTranslator's accessors rather than from a second reading of
     * config, so the translator's enforced request cap and this budget cannot
     * disagree.
     */
    public int $timeout;

    /**
     * How long the uniqueness lock survives if a worker dies holding it.
     *
     * Without this a SIGKILLed worker would block every future translation of that
     * (record, locale) for ever, and the only symptom would be an action that
     * reports "queued" and never does anything.
     */
    public int $uniqueFor;

    /**
     * @param  class-string  $modelClass
     * @param  int|null  $requestedBy  User to notify about the outcome. Null when
     *                                 nobody is waiting (a console or test caller),
     *                                 in which case failures only reach the log.
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly int|string $modelKey,
        private readonly string $targetLocale,
        private readonly ?int $requestedBy = null,
    ) {
        $perRequest = max(1, (int) config('cms.ai.translation.timeout', 30));

        /*
         * Every number comes from AiTranslator's own accessors rather than from a
         * second reading of config. The translator ENFORCES the request cap, so a job
         * that computed its budget independently could be killed by its own timeout
         * part way through a run the translator considered perfectly legal — losing
         * work that was already paid for, which is the exact harm this timeout exists
         * to avoid.
         *
         * `+ 1` for the single batched request carrying the plain fields, on top of
         * the body's chunks.
         */
        $requests = AiTranslator::maxRequestsPerRecord() + 1;
        $attempts = AiTranslator::maxAttemptsPerRequest();

        $this->timeout = (($perRequest + AiTranslator::maxRetryDelay()) * $attempts * $requests) + 60;
        $this->uniqueFor = $this->timeout + 300;
    }

    /**
     * (record, locale) — not (record, locale, user): two people asking for the same
     * translation want one translation, not two.
     */
    public function uniqueId(): string
    {
        return $this->modelClass.':'.$this->modelKey.':'.$this->targetLocale;
    }

    public function handle(AiTranslator $translator): void
    {
        /*
         * Re-fetched by key rather than serialised into the job, for the same reason
         * SyncSearchIndexes does it: a serialised model carries dispatch-time
         * attribute values, so an edit made while the job waited in the queue would
         * be silently reverted by the save at the end of the translation.
         *
         * Nothing is eager-loaded on purpose. translationStates in particular must
         * NOT be preloaded here — AiTranslator guards its write on the CURRENT
         * status, and a relation loaded now would already be minutes old by the time
         * that guard runs.
         */
        $record = $this->modelClass::query()->find($this->modelKey);

        // Both halves are asserted, not just the contract: AiTranslator::translate()
        // takes Model&TracksTranslationStatus, and narrowing only the interface
        // would leave static analysis unable to prove the Model half.
        if (! $record instanceof Model || ! $record instanceof TracksTranslationStatus) {
            /*
             * Deleted between dispatch and execution, or a model that lost the
             * lifecycle trait. Either way there is nothing to translate and nothing
             * the user can do about it, so this is a log line rather than a
             * notification.
             */
            Log::info('Skipped AI translation: the record is gone or does not track translation status.', [
                'model' => $this->modelClass,
                'key' => $this->modelKey,
                'locale' => $this->targetLocale,
            ]);

            return;
        }

        try {
            $translator->translate($record, $this->targetLocale);
        } catch (AiTranslationException $exception) {
            /*
             * A domain outcome, not a crash: the feature is off, the key is missing,
             * the locale was signed off while this job waited, there was nothing to
             * translate, or OpenRouter refused. Reporting it and returning keeps the
             * queue from retrying — a retry would re-issue every paid call to reach
             * the same answer.
             *
             * The exception carries a localisation key, never the API key or a raw
             * response body, so surfacing it cannot leak a secret.
             */
            $this->notifyFailure($exception);

            return;
        }

        $this->notifySuccess();
    }

    /**
     * An unexpected failure (not an AiTranslationException) still owes the person
     * who asked an answer — they were told the work was queued, and silence is
     * indistinguishable from a queue that is not running.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('AI translation job failed.', [
            'model' => $this->modelClass,
            'key' => $this->modelKey,
            'locale' => $this->targetLocale,
            'error' => $exception?->getMessage(),
        ]);

        $this->notify(
            __('cms.ai_translation.failed'),
            __('cms.ai_translation.error.request_failed'),
            danger: true,
        );
    }

    private function notifySuccess(): void
    {
        $this->notify(
            __('cms.ai_translation.success', [
                'locale' => TranslatableTabs::localeLabel($this->targetLocale),
            ]),
            null,
            danger: false,
        );
    }

    private function notifyFailure(AiTranslationException $exception): void
    {
        /*
         * "Already reviewed" is a deliberate policy outcome — the locale was signed
         * off and the machine refused to overwrite it — so it is a warning rather
         * than an error, exactly as the synchronous action presented it.
         */
        $alreadyReviewed = $exception->translationKey === 'cms.ai_translation.error.already_reviewed';

        $this->notify(
            $alreadyReviewed ? __('cms.ai_translation.skipped') : __('cms.ai_translation.failed'),
            __($exception->translationKey),
            danger: ! $alreadyReviewed,
            warning: $alreadyReviewed,
        );
    }

    /**
     * Deliver the outcome as a Filament DATABASE notification.
     *
     * A flash notification is not an option here: the request that queued the work
     * finished long before the work did. The database notification waits in the
     * panel's bell until the translator next looks, which is the only delivery
     * mechanism that survives the gap.
     */
    private function notify(string $title, ?string $body, bool $danger, bool $warning = false): void
    {
        if ($this->requestedBy === null) {
            return;
        }

        $user = User::query()->find($this->requestedBy);

        if ($user === null) {
            return;
        }

        $notification = Notification::make()->title($title);

        if ($body !== null) {
            $notification->body($body);
        }

        if ($danger) {
            $notification->danger();
        } elseif ($warning) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->sendToDatabase($user);
    }
}
