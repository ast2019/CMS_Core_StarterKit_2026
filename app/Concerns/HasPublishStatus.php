<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ContentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Editorial workflow: draft -> review -> published -> archived, with scheduling.
 *
 * Requirement 3.6.
 */
trait HasPublishStatus
{
    /**
     * Records that are live *right now*.
     *
     * Status alone is not enough. A record with status=published and a
     * publish_date in the future is scheduled, not live, and the Delivery API
     * must not return it — that is the entire point of scheduling. Every public
     * query goes through this scope so the check cannot be forgotten in one
     * endpoint.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', ContentStatus::Published)
            ->where(function (Builder $inner): void {
                $inner->whereNull('publish_date')
                    ->orWhere('publish_date', '<=', now());
            });
    }

    /**
     * Published but not yet due.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeScheduled(Builder $query): void
    {
        $query->where('status', ContentStatus::Published)
            ->whereNotNull('publish_date')
            ->where('publish_date', '>', now());
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeWithStatus(Builder $query, ContentStatus $status): void
    {
        $query->where('status', $status);
    }

    public function isLive(): bool
    {
        if ($this->status !== ContentStatus::Published) {
            return false;
        }

        return $this->publish_date === null || $this->publish_date->isPast();
    }

    public function isScheduled(): bool
    {
        return $this->status === ContentStatus::Published
            && $this->publish_date !== null
            && $this->publish_date->isFuture();
    }

    /**
     * Move to a new status, refusing illegal jumps.
     *
     * The transition map lives on the enum. Enforcing it here rather than only
     * in the panel means the Management API cannot move an archived record
     * straight back to published, bypassing review.
     *
     * @throws ValidationException
     */
    public function transitionTo(ContentStatus $target, ?int $userId = null): void
    {
        $current = $this->status;

        if ($current === $target) {
            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => __('cms.validation.invalid_transition', [
                    'from' => $current->label(),
                    'to' => $target->label(),
                ]),
            ]);
        }

        $this->status = $target;

        // First publish sets the go-live moment; re-publishing later does not
        // rewrite it, or an article's date would jump every time it was edited.
        if ($target === ContentStatus::Published && $this->publish_date === null) {
            $this->publish_date = now();
        }

        $this->save();

        /*
         * RULE #8 — "who put this live" is the question an audit trail on a CMS
         * exists to answer, and it would otherwise be buried inside a generic
         * `updated` row among every other column change.
         */
        if (in_array($target, [ContentStatus::Published, ContentStatus::Archived], strict: true)) {
            /*
             * Resolve the causer to a MODEL, never pass a bare id.
             *
             * activitylog's CauserResolver turns an int into a model through the
             * default auth provider, which is null when the request came in on the
             * Sanctum guard — so publishing via the Management API died with
             * "Call to a member function retrieveById() on null" while the same code
             * worked from the panel.
             */
            $causer = $userId !== null
                ? User::query()->find($userId)
                : auth()->user();

            activity('cms')
                ->performedOn($this)
                ->causedBy($causer)
                ->withProperties([
                    'from' => $current->value,
                    'to' => $target->value,
                    'publish_date' => $this->publish_date?->toIso8601String(),
                ])
                ->event($target === ContentStatus::Published ? 'published' : 'archived')
                ->log(class_basename($this).'.'.$target->value);
        }
    }

    public function publish(?int $userId = null): void
    {
        $this->transitionTo(ContentStatus::Published, $userId);
    }

    public function archive(?int $userId = null): void
    {
        $this->transitionTo(ContentStatus::Archived, $userId);
    }
}
