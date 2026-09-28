<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Form;
use App\Services\Forms\FormSubmissionRules;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Item 15 — a submission to any active form (POST /api/v1/forms/{key}/submissions).
 *
 * The body is the form's fields, keyed by field key, plus the honeypot and timing fields the
 * contact form already uses (config('cms.contact.spam')), deliberately undocumented here for the
 * reason StoreContactSubmissionRequest gives. Which fields exist, and their rules, come from the
 * form's schema at request time (FormSubmissionRules), so they cannot be listed here — see
 * docs/forms.md for the shape.
 * Keys the schema does not define are ignored: only validated input is stored.
 */
class StoreFormSubmissionRequest extends FormRequest
{
    private ?Form $form = null;

    /**
     * Public by design, like the contact form. Availability is decided in prepareForValidation()
     * with a 404, because a Form Request can only refuse authorisation with a 403 and a form that
     * does not exist (or is withdrawn, or whose module is off) is not a permissions question.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The form being submitted to, resolved once.
     *
     * @throws NotFoundHttpException when the module is off or no active form has this key
     */
    public function form(): Form
    {
        if ($this->form !== null) {
            return $this->form;
        }

        /*
         * The `forms` switch, nested under `contact` (Form::builderEnabled()). Checked before
         * validation, so a switched-off endpoint answers 404 rather than 422. The contact form
         * itself is still accepted on POST /api/v1/contact with forms off.
         */
        if (! Form::builderEnabled()) {
            throw new NotFoundHttpException('The [forms] module is not enabled on this site.');
        }

        $key = $this->route('key');
        $form = is_string($key) ? Form::findActiveByKey($key) : null;

        if ($form === null) {
            throw new NotFoundHttpException('No active form has this key.');
        }

        return $this->form = $form;
    }

    /**
     * Runs before authorisation and validation, so an unknown form answers 404 rather than
     * a 422 listing the fields of a form that does not exist.
     */
    protected function prepareForValidation(): void
    {
        $this->form();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /*
         * No route (the API documentation generator instantiating this class to read its rules)
         * means no form, and so no rules — rather than a 404 thrown out of the doc generator.
         */
        if ($this->form === null && $this->route('key') === null) {
            return [];
        }

        return FormSubmissionRules::for($this->form());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        if ($this->form !== null && $this->form->isContactForm()) {
            return (new StoreContactSubmissionRequest)->messages();
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        if ($this->form === null) {
            return [];
        }

        if ($this->form->isContactForm()) {
            return (new StoreContactSubmissionRequest)->attributes();
        }

        return FormSubmissionRules::attributes($this->form, app()->getLocale());
    }
}
