<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\Dates\LocalizedDate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Spatie\Activitylog\Models\Activity;

/**
 * Item 35 — two people editing the same record no longer silently overwrite each other.
 *
 * THE FAILURE. Editor A opens an article; editor B opens it too, fixes a typo and saves; A saves a
 * minute later. A's form still held the text as it was when A opened it, so A's save wrote B's typo
 * straight back — and B's fix was gone with no error and no warning. In a newsroom, where the same
 * breaking story is touched by several people in ten minutes, this is not an edge case.
 *
 * It happens without a second person too. AI translation is queued and writes the target-locale
 * fields minutes after the click; the video metadata listener writes the extracted duration after an
 * upload. An editor saving the still-open form afterwards writes the old values back over both.
 *
 * THE APPROACH: optimistic, not locking. Nothing is reserved when a form opens. At save time the page
 * asks whether the record changed underneath the form, and if so it stops and says so, offering a
 * reload or a deliberate overwrite. An edit lock was rejected: a closed or crashed tab releases
 * nothing, so locks need heartbeats, expiry and a "break the lock" button, and the common outcome is
 * an editor locked out of a breaking story by a colleague who went home.
 *
 * HOW "CHANGED UNDERNEATH" IS DECIDED — and why it does not rely on the audit log.
 *
 * The first version asked the activity log who had written the record, and treated "no row by
 * someone else" as "no conflict". Review showed that fails in both directions: User and Redirect are
 * not audited, `saveQuietly()` and query-builder updates write no row, so real conflicts went through
 * silently; and `denied` rows are not writes at all, yet counted as one and blocked legitimate saves.
 *
 * So the rule no longer asks who. It asks WHEN, using request boundaries:
 *
 *  - At the START of every Livewire request from this page (the `hydrate` hook) the stored
 *    `updated_at` is compared with the baseline. If it moved, it moved BETWEEN requests — while the
 *    editor was reading or typing, not doing anything on this page — so somebody or something else
 *    wrote it. That is remembered as a pending conflict.
 *  - At the END of every request (the `dehydrate` hook) the baseline is moved to whatever is stored
 *    now. Anything written DURING the request was written by this page acting for this editor — a
 *    save, a version restore, a trash restore, a publish action, an after-save hook — and is absorbed.
 *
 * That needs no knowledge of which actions write, which models are audited, or which events count,
 * and it holds for every edit page including the ones whose models keep no audit trail.
 *
 * The activity log is still consulted, but only to NAME the writer in the warning, restricted to
 * write events so a `denied` row cannot be mistaken for an edit. When nothing in the log explains the
 * change — an unaudited model, a quiet save — the warning says a background process changed it,
 * which is true.
 *
 * Known limits, both narrow: a write by someone else that lands DURING one of this page's own requests
 * (a window of one request's duration) is absorbed as this page's own; and the same editor in two tabs
 * is warned in the second tab, which is correct but names them as the other writer.
 */
trait GuardsAgainstConcurrentEdits
{
    /**
     * The record's `updated_at` as stored at the end of this page's last request.
     */
    #[Locked]
    public ?string $concurrencyBaseline = null;

    /**
     * When the baseline was last taken — the lower bound for naming the writer from the audit log.
     */
    #[Locked]
    public ?string $concurrencyCheckedFrom = null;

    /**
     * The stored `updated_at` of a change noticed between requests, kept until it is resolved by a
     * reload (a fresh mount), a deliberate overwrite, or a save that proceeds after it.
     */
    #[Locked]
    public ?string $concurrentChangeSeenAt = null;

    /**
     * Set by the notification's "save anyway" action, for the one save in that same request.
     */
    private bool $overwriteConcurrentEdit = false;

    /**
     * Livewire trait hook — runs after the page's own mount(), so the record is resolved.
     */
    public function mountGuardsAgainstConcurrentEdits(): void
    {
        $this->rememberConcurrencyBaseline();
    }

    /**
     * Start of every subsequent request: did the record move while the editor was not acting here?
     */
    public function hydrateGuardsAgainstConcurrentEdits(): void
    {
        if ($this->concurrencyBaseline === null) {
            return;
        }

        $stored = $this->storedUpdatedAt($this->getRecord());

        if ($stored !== null && $stored !== $this->concurrencyBaseline) {
            $this->concurrentChangeSeenAt = $stored;
        }
    }

    /**
     * End of every request: whatever this page wrote during the request is its own.
     */
    public function dehydrateGuardsAgainstConcurrentEdits(): void
    {
        $this->concurrencyBaseline = $this->storedUpdatedAt($this->getRecord());
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        if ($this->refuseForConcurrentEdit()) {
            return;
        }

        parent::save($shouldRedirect, $shouldSendSavedNotification);
    }

    /**
     * The per-section save Filament offers for `->saveable()` components. Nothing in this panel uses
     * one yet; guarded anyway, because an unguarded second write path is how this protection would
     * quietly stop covering a page the day someone adds one.
     */
    public function saveFormComponentOnly(Component $component): void
    {
        if ($this->refuseForConcurrentEdit()) {
            return;
        }

        parent::saveFormComponentOnly($component);
    }

    /**
     * The "save anyway" button on the conflict notification.
     *
     * An event rather than a URL because the form's unsaved state lives in this Livewire component: a
     * link would reload the page and throw away exactly the edits the editor is choosing to keep.
     */
    #[On('cms-overwrite-concurrent-edit')]
    public function saveOverwritingConcurrentEdit(): void
    {
        $this->overwriteConcurrentEdit = true;

        $this->save();
    }

    /**
     * Whether to stop this save, having told the editor why.
     */
    protected function refuseForConcurrentEdit(): bool
    {
        if ($this->overwriteConcurrentEdit) {
            // A deliberate decision, made in this request, about the change the editor was shown.
            $this->overwriteConcurrentEdit = false;
            $this->concurrentChangeSeenAt = null;

            return false;
        }

        if ($this->concurrentChangeSeenAt === null) {
            return false;
        }

        $this->notifyConcurrentEdit($this->describeConcurrentWriter());

        return true;
    }

    protected function rememberConcurrencyBaseline(): void
    {
        $this->concurrencyBaseline = $this->storedUpdatedAt($this->getRecord());
        $this->concurrencyCheckedFrom = now()->toDateTimeString();
        $this->concurrentChangeSeenAt = null;
    }

    /**
     * Who most likely made the change, and when — for the warning only; nothing is decided on it.
     *
     * @return array{who: string, when: string}
     */
    protected function describeConcurrentWriter(): array
    {
        $record = $this->getRecord();

        $latestWrite = Activity::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            // Write events only. A `denied` row is someone being refused, which is not an edit.
            ->whereIn('event', ['created', 'updated', 'deleted', 'restored', 'destroyed', 'published', 'archived'])
            ->when($this->concurrencyCheckedFrom !== null, fn ($query) => $query->where('created_at', '>=', $this->concurrencyCheckedFrom))
            ->with('causer')
            ->latest('id')
            ->first();

        $who = $latestWrite === null
            // No audit row explains it: an unaudited model, a quiet save, a background job.
            ? __('cms.concurrency.background')
            : ($latestWrite->causer?->getAttribute('name') ?? __('cms.audit.system'));

        return [
            'who' => (string) $who,
            'when' => (string) LocalizedDate::human(Carbon::parse((string) $this->concurrentChangeSeenAt)),
        ];
    }

    /**
     * @param  array{who: string, when: string}  $conflict
     */
    protected function notifyConcurrentEdit(array $conflict): void
    {
        Notification::make()
            ->title(__('cms.concurrency.title'))
            ->body(__('cms.concurrency.body', $conflict))
            ->warning()
            // Persistent: this is a decision the editor has to make, not a status to glance at.
            ->persistent()
            ->actions([
                /*
                 * Reload first: it is the safe choice, showing the other change and losing only this
                 * editor's unsaved edits — which the unsaved-changes prompt (item 36) asks about
                 * before discarding. The page's own URL from the resource, not the request's: a
                 * Livewire save is a POST to /livewire/update.
                 */
                Action::make('reload')
                    ->label(__('cms.concurrency.reload'))
                    ->button()
                    ->url(static::getResource()::getUrl('edit', ['record' => $this->getRecord()])),

                Action::make('overwrite')
                    ->label(__('cms.concurrency.overwrite'))
                    ->color('danger')
                    ->dispatch('cms-overwrite-concurrent-edit')
                    ->close(),
            ])
            ->send();
    }

    /**
     * The record's `updated_at` exactly as the database holds it, read fresh.
     *
     * A fresh query rather than the component's model: Livewire rehydrates that model on every request,
     * but only at the start of it, and this is also called at the END of a request that may have
     * written the row since. Compared as the raw stored string so a cast or a timezone conversion
     * cannot make two identical values look different.
     */
    private function storedUpdatedAt(Model $record): ?string
    {
        if (! $record->exists || ! $record->usesTimestamps()) {
            return null;
        }

        $value = $record->newQueryWithoutScopes()
            ->whereKey($record->getKey())
            ->toBase()
            ->value($record->getUpdatedAtColumn());

        return $value === null ? null : (string) $value;
    }
}
