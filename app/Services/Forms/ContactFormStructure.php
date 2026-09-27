<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Http\Requests\StoreContactSubmissionRequest;

/**
 * Item 15 — the built-in `contact` form's fields are a contract, not content.
 *
 * `POST /api/v1/contact` predates the form builder and has a fixed request contract
 * (StoreContactSubmissionRequest): five fields, `name` and `message` required, and an email OR
 * a phone number. Existing frontends post to it and must keep working unchanged. The seeded
 * `contact` form is that endpoint's schema — what a frontend renders — so if an editor could add
 * a required field to it, or delete `message`, the schema would describe a form the endpoint
 * does not accept, and a frontend rendering it would collect answers the endpoint throws away.
 *
 * So on this one form the STRUCTURE (which fields, their keys, types, required flags and limits)
 * is fixed here, and only the WORDING — labels, placeholders, help text — and the order are
 * editable. Enforced on the model (Form::saving) rather than only by disabling inputs in the
 * panel, because a disabled input is a suggestion to the browser, not a rule on the server.
 *
 * A site that needs a different enquiry form creates a new form with its own key; that is what
 * the builder is for.
 *
 * @phpstan-import-type FormField from FormSchema
 */
final class ContactFormStructure
{
    public const KEY = 'contact';

    /**
     * Mirrors StoreContactSubmissionRequest::rules(). A test holds the two together.
     *
     * @var array<string, array{type: string, required: bool, max_length: int}>
     */
    public const FIELDS = [
        'name' => ['type' => 'text', 'required' => true, 'max_length' => 120],
        'email' => ['type' => 'email', 'required' => false, 'max_length' => 190],
        'phone' => ['type' => 'tel', 'required' => false, 'max_length' => 40],
        'subject' => ['type' => 'text', 'required' => false, 'max_length' => 190],
        'message' => ['type' => 'textarea', 'required' => true, 'max_length' => 5000],
    ];

    /**
     * Re-impose the fixed structure on a submitted field list, keeping its wording and order.
     *
     * Each fixed field takes its wording from the submitted entry with the same key, or — if the
     * submission lost it — from the previously stored entry, so a tampered or partial save cannot
     * delete a label an editor wrote. Anything not in the structure is dropped.
     *
     * @param  list<FormField>  $submitted
     * @param  list<FormField>  $previous
     * @return list<FormField>
     */
    public static function enforce(array $submitted, array $previous): array
    {
        $byKey = static function (array $fields): array {
            $indexed = [];

            foreach ($fields as $field) {
                $indexed[$field['key']] = $field;
            }

            return $indexed;
        };

        $submittedByKey = $byKey($submitted);
        $previousByKey = $byKey($previous);

        $order = [
            ...array_values(array_filter(array_keys($submittedByKey), static fn (string $key): bool => isset(self::FIELDS[$key]))),
            ...array_keys(self::FIELDS),
        ];

        $enforced = [];

        foreach (array_unique($order) as $key) {
            $wording = $submittedByKey[$key] ?? $previousByKey[$key] ?? null;

            $enforced[] = [
                'key' => $key,
                ...self::FIELDS[$key],
                'label' => $wording['label'] ?? [(string) config('cms.locales.source', 'fa') => $key],
                'placeholder' => $wording['placeholder'] ?? [],
                'help' => $wording['help'] ?? [],
                'options' => [],
            ];
        }

        return $enforced;
    }

    /**
     * The validation the contact form has always had, for whichever endpoint receives it.
     *
     * Read from the Form Request rather than copied, so `POST /api/v1/contact` and
     * `POST /api/v1/forms/contact/submissions` cannot disagree about what a valid enquiry is.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return (new StoreContactSubmissionRequest)->rules();
    }
}
