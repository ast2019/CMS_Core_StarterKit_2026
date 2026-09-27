<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use App\Contracts\Publishable;
use App\Enums\ContentStatus;
use App\Support\Plural;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Publish and unpublish several records at once.
 *
 * The ability and the policy methods already existed — `content.publish` in the D-10
 * matrix, `ContentPolicy::publish()` and `unpublish()` — and nothing in the panel was
 * wired to them. Publishing was one record at a time through the status dropdown inside
 * the form, so clearing a review queue of forty drafts meant forty round trips through
 * an edit screen.
 *
 * WHY PUBLISHING SETS A DATE.
 *
 * `HasPublishStatus::live()` requires BOTH a published status and a publish_date that
 * has passed, so setting the status alone would report success and leave the record
 * invisible — the single most confusing outcome this action could produce. A record with
 * no date gets the current time; a record that already has one keeps it, including a
 * future one, because an editor who scheduled something and then bulk-published it meant
 * to approve the schedule, not to cancel it.
 *
 * WHY EACH RECORD IS AUTHORISED AND SAVED INDIVIDUALLY.
 *
 * Authorising the action once and then issuing a mass UPDATE would skip the per-record
 * policy check — `content.update.own` limits an Author to their own records — and would
 * bypass the model events that keep the audit trail (RULE #8), the search index and the
 * Delivery cache correct. So the loop is deliberate: a bulk action here is a convenience
 * over the same writes an editor would have made by hand, not a shortcut around them.
 *
 * Records the user may not act on are skipped and counted, and the notification says how
 * many — silently doing less than asked is how someone believes forty articles went live
 * when thirty did.
 */
class PublishingBulkActions
{
    /**
     * @return array<int, BulkAction>
     */
    public static function make(): array
    {
        return [
            self::publish(),
            self::unpublish(),
        ];
    }

    public static function publish(): BulkAction
    {
        return BulkAction::make('publish')
            ->label(__('cms.action.publish_selected'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            // A bulk status change is not undoable from the table, so it asks first.
            ->requiresConfirmation()
            ->modalDescription(__('cms.action.publish_selected_confirm'))
            ->action(fn (Collection $records) => self::apply(
                $records,
                ContentStatus::Published,
                'publish',
                'cms.action.publish_selected_done',
            ))
            ->deselectRecordsAfterCompletion();
    }

    public static function unpublish(): BulkAction
    {
        return BulkAction::make('unpublish')
            ->label(__('cms.action.unpublish_selected'))
            ->icon('heroicon-o-eye-slash')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('cms.action.unpublish_selected_confirm'))
            ->action(fn (Collection $records) => self::apply(
                $records,
                ContentStatus::Draft,
                'unpublish',
                'cms.action.unpublish_selected_done',
            ))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private static function apply(
        Collection $records,
        ContentStatus $status,
        string $ability,
        string $doneKey,
    ): void {
        $changed = 0;
        $refused = 0;
        $unchanged = 0;

        foreach ($records as $record) {
            if (! (auth()->user()?->can($ability, $record) ?? false)) {
                $refused++;

                continue;
            }

            if (! $record instanceof Publishable) {
                $refused++;

                continue;
            }

            if ($record->getAttribute('status') === $status) {
                // Already where it was asked to be. Counted separately so the report can
                // say "nothing to do" rather than implying a refusal.
                $unchanged++;

                continue;
            }

            try {
                /*
                 * THROUGH transitionTo(), not by assigning the column.
                 *
                 * Writing `status` by hand reproduced two thirds of it — the status and
                 * the first-publish date — and skipped the two parts that matter:
                 *
                 *  - the legal-transition map. ContentStatus::Archived may only go to
                 *    Draft, and the Management API refuses anything else; a hand-written
                 *    status let this action move an archived article straight back to
                 *    live, which is the one path the map exists to close.
                 *  - the `published` audit event, with from/to/publish_date and a causer.
                 *    A bulk publish otherwise left only a generic `updated` row, burying
                 *    the "who put this live" question RULE #8 exists to answer.
                 */
                $record->transitionTo($status);
            } catch (ValidationException) {
                /*
                 * An illegal transition — archived straight to published, say. Counted
                 * as refused rather than rethrown: one bad row in a selection of forty
                 * must not abandon the other thirty-nine, and the notification says how
                 * many were left.
                 */
                $refused++;

                continue;
            }

            $changed++;
        }

        self::report($doneKey, $changed, $refused, $unchanged);
    }

    private static function report(string $doneKey, int $changed, int $refused, int $unchanged): void
    {
        $notification = Notification::make()
            ->title(Plural::choice($doneKey, $changed));

        $notes = [];

        if ($refused > 0) {
            // Named rather than swallowed: believing forty records changed when thirty
            // did is worse than the refusal itself.
            $notes[] = Plural::choice('cms.action.bulk_skipped', $refused);
        }

        if ($unchanged > 0) {
            $notes[] = Plural::choice('cms.action.bulk_unchanged', $unchanged);
        }

        $notes === []
            ? $notification->success()
            : $notification->body(implode(' ', $notes))->warning();

        $notification->send();
    }
}
