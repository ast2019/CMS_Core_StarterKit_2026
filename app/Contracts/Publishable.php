<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\ContentStatus;
use Illuminate\Validation\ValidationException;

/**
 * A model that moves through the editorial workflow and can therefore be
 * publicly visible or not.
 *
 * Requirement 3.6.
 *
 * This exists so HasSeoMeta can ask "is this live?" without guessing. The
 * earlier version used method_exists() and property_exists() probes, which had
 * two problems: static analysis correctly reported them as always-true on a
 * Model, and more importantly the fallback silently returned `true` for any model
 * it could not interrogate. A new content type that forgot HasPublishStatus would
 * have been treated as publicly visible and emitted `index, follow` for its
 * drafts. An interface turns that from a silent default into a compile-time
 * decision.
 */
interface Publishable
{
    /**
     * Whether the record is live right now — published AND past its publish date.
     */
    public function isLive(): bool;

    public function isScheduled(): bool;

    /**
     * Move to another status, refusing a transition the workflow does not allow.
     *
     * Declared on the contract rather than left on the trait so a caller holding a
     * `Model&Publishable` can move a record through the workflow without knowing its
     * concrete class. The panel's bulk publish action is that caller, and before this
     * it wrote the `status` column by hand — which skipped the legal-transition map
     * (letting an archived record go straight back to live) and the `published` audit
     * event. A workflow operation belongs on the interface that names the workflow.
     *
     * @throws ValidationException when the transition is not
     *                             allowed from the current status
     */
    public function transitionTo(ContentStatus $target, ?int $userId = null): void;
}
