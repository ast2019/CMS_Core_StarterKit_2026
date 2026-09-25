<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The only write the Delivery API accepts.
 *
 * Requirements 3.1, 9.7 (every write validated through a Form Request).
 *
 * Note this does NOT violate Requirement 8.3's "read-only" Delivery API in spirit:
 * a contact form submission creates no content and reads nothing back. It is
 * scoped to its own route with a much tighter rate limit than the read endpoints,
 * because it is the one public endpoint that writes a row.
 */
class StoreContactSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Public by design. The module toggle used to be checked here and has moved to
         * ContactController::store(), because a Form Request can only refuse with a 403
         * and every other Delivery endpoint answers a disabled module with a 404
         * (ResolvesDeliveryRequest::ensureModuleEnabled). The distinction is not
         * pedantry: 403 tells a caller there is something here to get access to, which
         * for a module that does not exist on this site is simply untrue — and one
         * endpoint disagreeing with the other seven is the kind of inconsistency a
         * frontend developer has to special-case.
         */
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            /*
             * At least one contact channel is required, but neither individually:
             * demanding an email address excludes the many Iranian users who would
             * rather be called back, and demanding a phone number excludes everyone
             * else.
             */
            'email' => ['nullable', 'email:rfc', 'max:190', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],

            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => __('validation.required_without', [
                'attribute' => __('cms.field.email'),
                'values' => __('cms.field.phone'),
            ]),
            'phone.required_without' => __('validation.required_without', [
                'attribute' => __('cms.field.phone'),
                'values' => __('cms.field.email'),
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('cms.field.name'),
            'email' => __('cms.field.email'),
            'phone' => __('cms.field.phone'),
            'subject' => __('cms.field.subject'),
            'message' => __('cms.field.message'),
        ];
    }
}
