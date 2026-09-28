<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Contracts\Publishable;
use App\Enums\ContentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Route the form's status field through transitionTo(), the same way the Management
 * API and PublishingBulkActions do.
 *
 * Mass-assigning `status` skipped the legal-transition map, the first-publish date,
 * the `published`/`archived` audit events, and — for Authors — the `content.publish`
 * ability check. The form still shows the dropdown; this concern strips the column
 * from the write and applies it afterwards under the same rules.
 */
trait AppliesPublishingWorkflow
{
    protected ?ContentStatus $pendingStatusTransition = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractStatusForWorkflow(array $data, ?Model $existing = null): array
    {
        if (! array_key_exists('status', $data) || blank($data['status'])) {
            return $data;
        }

        $target = $data['status'] instanceof ContentStatus
            ? $data['status']
            : ContentStatus::from((string) $data['status']);

        $current = $existing instanceof Publishable
            ? $existing->getAttribute('status')
            : ContentStatus::Draft;

        if (! $current instanceof ContentStatus) {
            $current = ContentStatus::Draft;
        }

        $this->authorizeStatusChange($current, $target, $existing);

        $this->pendingStatusTransition = $target;

        /*
         * Create always starts as Draft; the pending transition runs after the row
         * exists so transitionTo() can set publish_date and write the audit event.
         * Edit drops status from the mass-assign so a hand-written column cannot
         * bypass the map.
         */
        if ($existing === null) {
            $data['status'] = ContentStatus::Draft->value;
        } else {
            unset($data['status']);
        }

        return $data;
    }

    protected function applyPendingStatusTransition(): void
    {
        $target = $this->pendingStatusTransition;
        $this->pendingStatusTransition = null;

        if ($target === null) {
            return;
        }

        $record = $this->getRecord();

        if (! $record instanceof Publishable) {
            return;
        }

        if ($record->getAttribute('status') === $target) {
            return;
        }

        try {
            $record->transitionTo($target, auth()->id());
        } catch (ValidationException $exception) {
            // Re-throw so Filament surfaces the status field error on the form.
            throw $exception;
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeStatusChange(
        ContentStatus $current,
        ContentStatus $target,
        ?Model $existing,
    ): void {
        if ($current === $target) {
            return;
        }

        $gated = [ContentStatus::Published, ContentStatus::Archived];

        if (! in_array($target, $gated, strict: true)
            && ! in_array($current, $gated, strict: true)) {
            // Draft ↔ review stays an ordinary edit.
            return;
        }

        $subject = $existing;

        if ($subject === null) {
            /*
             * On create there is no record yet. Policies take a model instance; a
             * probe with the would-be author is enough for the ability check.
             */
            $subject = $this->publishingProbe();
        }

        $this->authorize('publish', $subject);
    }

    /**
     * A stand-in model for authorize('publish') before the create write.
     */
    private function publishingProbe(): Model
    {
        $modelClass = static::getResource()::getModel();

        /** @var Model $probe */
        $probe = new $modelClass([
            'status' => ContentStatus::Draft,
            'author_id' => auth()->id(),
        ]);

        return $probe;
    }
}
