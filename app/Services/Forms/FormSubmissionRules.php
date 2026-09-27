<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormFieldType;
use App\Models\Form;
use Illuminate\Validation\Rule;

/**
 * Item 15 — server-side validation built from a form's schema.
 *
 * The schema a frontend renders is also the ONLY thing the endpoint accepts: each field
 * contributes exactly one rule set, keyed by its key, and nothing else is validated — which is
 * what makes unknown keys disappear, since only validated input is ever stored. The frontend's
 * own validation is a convenience for the visitor; this is the rule.
 *
 * The contact form is the exception, and uses the rules POST /api/v1/contact has always
 * applied (ContactFormStructure::rules()): its fields are fixed to that contract, and one of its
 * rules — an email OR a phone number — has no per-field equivalent in a schema.
 *
 * @phpstan-import-type FormField from FormSchema
 */
final class FormSubmissionRules
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Form $form): array
    {
        if ($form->isContactForm()) {
            return ContactFormStructure::rules();
        }

        $rules = [];

        foreach ($form->fields as $field) {
            $rules[$field['key']] = self::forField($field);
        }

        return $rules;
    }

    /**
     * Field labels in the visitor's locale, so a 422 names "Email" or «ایمیل» rather than a key.
     *
     * @return array<string, string>
     */
    public static function attributes(Form $form, string $locale): array
    {
        $attributes = [];

        foreach ($form->fields as $field) {
            $attributes[$field['key']] = FormSchema::localised($field['label'], $locale) ?? $field['key'];
        }

        return $attributes;
    }

    /**
     * @param  FormField  $field
     * @return list<mixed>
     */
    private static function forField(array $field): array
    {
        $type = FormFieldType::from($field['type']);
        $maxLength = FormSchema::effectiveMaxLength($field);

        if ($type === FormFieldType::Checkbox) {
            /*
             * A required checkbox is a consent box, and "required" means TICKED — `required`
             * alone would accept an explicit false. `accepted` is Laravel's rule for exactly
             * that. An optional one only has to be a boolean.
             */
            return $field['required'] ? ['accepted'] : ['nullable', 'boolean'];
        }

        $rules = [$field['required'] ? 'required' : 'nullable', 'string'];

        $rules = [...$rules, ...match ($type) {
            FormFieldType::Email => ['email:rfc'],

            /*
             * Digits in any script (\p{Nd}), because an Iranian visitor types ۰۹۱۲ as readily
             * as 0912 and both are the same number; plus the separators people actually write.
             * Letters are refused, which is the whole of what "is a phone number" can be
             * checked for without a per-country library.
             */
            FormFieldType::Tel => ['regex:/^[\p{Nd}+()\-.\s]+$/u'],

            FormFieldType::Select => [Rule::in(array_map(
                static fn (array $option): string => $option['value'],
                $field['options'],
            ))],

            default => [],
        }];

        if ($maxLength !== null) {
            $rules[] = "max:{$maxLength}";
        }

        return $rules;
    }
}
