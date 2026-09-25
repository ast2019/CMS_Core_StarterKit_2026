<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactSubmissionRequest;
use App\Models\ContactSubmission;
use Illuminate\Http\JsonResponse;

/**
 * Accepts public contact form submissions.
 *
 * Requirement 3.1.
 */
class ContactController extends Controller
{
    use ResolvesDeliveryRequest;

    public function store(StoreContactSubmissionRequest $request): JsonResponse
    {
        /*
         * Requirement 1.1. Gated alongside the read endpoint that serves the form's
         * labels and address: a site with the contact module off renders no form, so an
         * accepted submission could only come from a stale cached page or a script — and
         * writing it to a table nobody in the panel is looking at is worse than refusing.
         */
        $this->ensureModuleEnabled('contact');

        $submission = ContactSubmission::query()->create([
            ...$request->validated(),

            /*
             * Captured server-side, never from the payload. A client-supplied IP
             * would be trivially forged, which would make the abuse trail and the
             * rate-limit forensics worthless.
             */
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

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
