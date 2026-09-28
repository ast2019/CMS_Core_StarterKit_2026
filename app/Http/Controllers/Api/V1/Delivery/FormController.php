<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Controllers\Api\V1\Delivery\Concerns\ResolvesDeliveryRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFormSubmissionRequest;
use App\Http\Resources\V1\FormResource;
use App\Models\Form;
use App\Services\Api\DeliveryCache;
use App\Services\Forms\SubmissionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Item 15 — forms built in the panel, served and submitted over the Delivery API.
 *
 * Gated on Form::builderEnabled() — the `forms` switch under the `contact` module. The contact
 * form's own endpoints (/api/v1/contact) belong to the contact module and stay up with forms
 * off. See docs/forms.md for the frontend side.
 */
class FormController extends Controller
{
    use ResolvesDeliveryRequest;

    public function __construct(private readonly DeliveryCache $cache) {}

    /**
     * A form's schema, localised for the request locale.
     */
    public function show(Request $request, string $key): FormResource
    {
        // Before the cache lookup, so a cached schema cannot outlive the switch.
        if (! Form::builderEnabled()) {
            throw new NotFoundHttpException('The [forms] module is not enabled on this site.');
        }

        $locale = $this->locale($request);

        return $this->cache->remember(
            $this->cache->key('forms.show', $locale, ['key' => $key]),
            [DeliveryCache::TAG_SETTINGS],
            function () use ($key): FormResource {
                $form = Form::findActiveByKey($key);

                if ($form === null) {
                    throw new NotFoundHttpException("No active form has the key [{$key}].");
                }

                return FormResource::make($form);
            },
        );
    }

    /**
     * Submit answers to a form.
     *
     * Always answers 201 with the new id once the input is valid — including when a spam check
     * fired, because the submission is stored and flagged rather than refused (item 16), and a
     * different answer would tell the sender which check they tripped.
     */
    public function submit(StoreFormSubmissionRequest $request, SubmissionRecorder $recorder): JsonResponse
    {
        $submission = $recorder->record($request->form(), $request->validated(), $request);

        // As on the contact endpoint: the stored record is never echoed back.
        return new JsonResponse([
            'data' => ['id' => $submission->id],
            'message' => __('cms.contact.received'),
        ], JsonResponse::HTTP_CREATED);
    }
}
