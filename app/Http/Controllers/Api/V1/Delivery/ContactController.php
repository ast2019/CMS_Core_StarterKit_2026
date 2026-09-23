<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

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
    public function store(StoreContactSubmissionRequest $request): JsonResponse
    {
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
