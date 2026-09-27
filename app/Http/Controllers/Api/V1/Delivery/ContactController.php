<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactSubmissionRequest;
use App\Models\Form;
use App\Services\Forms\SubmissionRecorder;
use Illuminate\Http\JsonResponse;

/**
 * Accepts public contact form submissions.
 *
 * Requirement 3.1.
 *
 * Item 15 — kept, with its request contract unchanged, for every frontend already posting here.
 * It now records against the seeded `contact` form through the same SubmissionRecorder as
 * POST /api/v1/forms/{key}/submissions, so the two endpoints store, flag and rate-limit
 * identically. See docs/forms.md.
 */
class ContactController extends Controller
{
    use ResolvesDeliveryRequest;

    public function store(StoreContactSubmissionRequest $request, SubmissionRecorder $recorder): JsonResponse
    {
        /*
         * Requirement 1.1. Gated alongside the read endpoint that serves the form's
         * labels and address: a site with the contact module off renders no form, so an
         * accepted submission could only come from a stale cached page or a script — and
         * writing it to a table nobody in the panel is looking at is worse than refusing.
         */
        $this->ensureModuleEnabled('contact');

        $submission = $recorder->record(Form::contact(), $request->validated(), $request);

        /*
         * The created record is deliberately NOT echoed back. Returning it would
         * turn a public write endpoint into a read oracle — a caller could confirm
         * what was stored, and any future field added to the model would leak
         * through this response without anyone deciding to expose it.
         */
        return new JsonResponse([
            'data' => ['id' => $submission->id],
            'message' => __('cms.contact.received'),
        ], JsonResponse::HTTP_CREATED);
    }
}
