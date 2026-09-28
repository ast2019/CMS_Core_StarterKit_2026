<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormFieldType;
use App\Support\Digits;

/**
 * Item 15 — the one definition of what a form field looks like.
 *
 * A form's fields are a JSON column (see the create_forms_table migration), and JSON has no
 * schema of its own. Everything that READS that column — the Delivery API, the validation
 * rules, the inbox — would otherwise have to defend itself against whatever shape happens to
 * be stored: a field with no key, a type nobody renders, a select with an option list of
 * nulls. So the shape is enforced once, on the way IN (Form::saving calls normalise()), and
 * every reader can trust it.
 *
 * Stateless, so it lives beside the other services rather than on the model: the same rules
 * apply to a schema that is being edited and has not been saved yet.
 *
 * @phpstan-type LocaleMap array<string, string>
 * @phpstan-type FormFieldOption array{value: string, label: LocaleMap}
 * @phpstan-type FormField array{key: string, type: string, required: bool, max_length: int|null, label: LocaleMap, placeholder: LocaleMap, help: LocaleMap, options: list<FormFieldOption>}
 */
final class FormSchema
{
    /**
     * A field key is a request parameter name and a JSON object key in someone else's code.
     *
     * Lowercase ASCII, starting with a letter, underscores allowed: it must survive as an HTML
     * `name` attribute, a JavaScript property and a Laravel validation key without escaping —
     * a dot, for one, would be read by the validator as a path into a nested array.
     */
    public const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public const FIELD_KEY_MAX_LENGTH = 40;

    /**
     * A form key is what the frontend fetches the form by, and it appears in the URL
     * (`/api/v1/forms/{key}`), so hyphens rather than underscores, like the menu location keys.
     */
    public const FORM_KEY_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public const FORM_KEY_MAX_LENGTH = 64;

    /**
     * Upper bounds on the schema itself.
     *
     * Not a product limit anyone should meet. They exist because validation rules are built
     * from the schema on every public submission, so the schema's size is part of the cost of
     * an unauthenticated request.
     */
    public const MAX_FIELDS = 30;

    public const MAX_OPTIONS = 50;

    public const OPTION_VALUE_MAX_LENGTH = 100;

    public const TEXT_MAX_LENGTH = 500;

    /**
     * Bring a stored or submitted field list into the canonical shape.
     *
     * Lenient by design: a malformed entry is DROPPED rather than rejected, because this runs on
     * save and the panel has already validated what an editor typed — anything still malformed
     * came from a seeder, an import or a hand-edited row, and refusing to save the whole form over
     * it would help nobody. A duplicate key keeps its first occurrence, since two fields answering
     * to one request parameter cannot both be validated.
     *
     * @return list<FormField>
     */
    public static function normalise(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $normalised = [];
        $seen = [];

        foreach (array_values($fields) as $field) {
            if (! is_array($field) || count($normalised) >= self::MAX_FIELDS) {
                continue;
            }

            $key = is_string($field['key'] ?? null) ? trim($field['key']) : '';
            $type = FormFieldType::tryFrom(is_string($field['type'] ?? null) ? $field['type'] : '');

            if ($type === null || ! self::isValidFieldKey($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $normalised[] = [
                'key' => $key,
                'type' => $type->value,
                'required' => (bool) ($field['required'] ?? false),
                'max_length' => self::maxLength($type, $field['max_length'] ?? null),
                'label' => self::localeMap($field['label'] ?? null),
                'placeholder' => self::localeMap($field['placeholder'] ?? null),
                'help' => self::localeMap($field['help'] ?? null),
                'options' => $type->hasOptions() ? self::options($field['options'] ?? null) : [],
            ];
        }

        return $normalised;
    }

    public static function isValidFieldKey(string $key): bool
    {
        return $key !== ''
            && strlen($key) <= self::FIELD_KEY_MAX_LENGTH
            && preg_match(self::FIELD_KEY_PATTERN, $key) === 1;
    }

    /**
     * Names a field may not take, because the spam checks read them off the same request.
     *
     * A field called `cms_reference` would be the honeypot: every visitor who filled it in would
     * be flagged. SpamInspector refuses such a collision at runtime (the check switches itself off
     * for that form), and the panel refuses it at authoring time so it never gets that far.
     *
     * @return list<string>
     */
    public static function reservedFieldKeys(): array
    {
        $reserved = [];

        foreach (['honeypot_field', 'timing_field'] as $configKey) {
            $name = config("cms.contact.spam.{$configKey}");

            if (is_string($name) && $name !== '') {
                $reserved[] = $name;
            }
        }

        return $reserved;
    }

    /**
     * The limit a field is validated against: its own, or its type's default.
     *
     * @param  FormField  $field
     */
    public static function effectiveMaxLength(array $field): ?int
    {
        return $field['max_length'] ?? FormFieldType::from($field['type'])->defaultMaxLength();
    }

    /**
     * One translated string, in the requested locale where it exists.
     *
     * Falls back to the source locale, then to any locale that has a value. The last step is
     * what keeps a field renderable when it was authored in a locale other than the source —
     * an empty label is worse than one in the wrong language, because the frontend would render
     * an input with nothing next to it.
     */
    public static function localised(mixed $map, string $locale): ?string
    {
        if (! is_array($map)) {
            return null;
        }

        $source = (string) config('cms.locales.source', 'fa');

        foreach ([$locale, $source] as $candidate) {
            if (is_string($map[$candidate] ?? null) && $map[$candidate] !== '') {
                return $map[$candidate];
            }
        }

        foreach ($map as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether a translated string had to fall back from the requested locale.
     */
    public static function fallsBack(mixed $map, string $locale): bool
    {
        return is_array($map)
            && $map !== []
            && ! (is_string($map[$locale] ?? null) && $map[$locale] !== '');
    }

    /**
     * The stored payload for a validated submission: one entry per answered field, in schema
     * order, typed by the field.
     *
     * Blank answers are left out rather than stored as nulls. The inbox renders from the schema
     * and the payload together, so an absent key already reads as "not answered", and storing a
     * null per unanswered field would only make every row longer.
     *
     * @param  list<FormField>  $fields
     * @param  array<string, mixed>  $validated
     * @return array<string, string|bool>
     */
    public static function payloadFrom(array $fields, array $validated): array
    {
        $payload = [];

        foreach ($fields as $field) {
            $key = $field['key'];

            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $value = $validated[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $payload[$key] = match (true) {
                $field['type'] === FormFieldType::Checkbox->value => filter_var($value, FILTER_VALIDATE_BOOL),

                /*
                 * A phone number is stored with ASCII digits whatever keyboard typed it.
                 * The rule accepts ۰۹۱۲ and 0912 alike (FormSubmissionRules), so storing
                 * them as typed made one caller two numbers — in the inbox, in its search,
                 * in an export and in a tel: link.
                 */
                $field['type'] === FormFieldType::Tel->value && is_string($value) => Digits::toAscii($value),

                default => is_scalar($value) ? (string) $value : '',
            };
        }

        return $payload;
    }

    /**
     * What a submission says, as label/value pairs a person can read.
     *
     * Labels and option names come from the form's CURRENT schema, in the reader's locale, so an
     * editor reads "Preferred time: Morning" rather than `slot: am`. A key the schema no longer
     * has — a field removed after this message arrived — is still shown, under its raw key: the
     * visitor did send it, and hiding it would make the stored record look shorter than it is.
     *
     * @param  list<FormField>  $fields
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, label: string, value: string, multiline: bool}>
     */
    public static function describe(array $fields, array $payload, string $locale): array
    {
        $entries = [];
        $described = [];

        foreach ($fields as $field) {
            $key = $field['key'];

            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $described[$key] = true;
            $entries[] = [
                'key' => $key,
                'label' => self::localised($field['label'], $locale) ?? $key,
                'value' => self::displayValue($field, $payload[$key], $locale),
                'multiline' => $field['type'] === FormFieldType::Textarea->value,
            ];
        }

        foreach ($payload as $key => $value) {
            if (isset($described[$key])) {
                continue;
            }

            $entries[] = [
                'key' => (string) $key,
                'label' => (string) $key,
                'value' => self::stringify($value),
                'multiline' => false,
            ];
        }

        return $entries;
    }

    /**
     * @param  FormField  $field
     */
    private static function displayValue(array $field, mixed $value, string $locale): string
    {
        if ($field['type'] === FormFieldType::Checkbox->value) {
            return filter_var($value, FILTER_VALIDATE_BOOL)
                ? __('cms.forms.value_yes')
                : __('cms.forms.value_no');
        }

        if ($field['type'] === FormFieldType::Select->value) {
            foreach ($field['options'] as $option) {
                if ($option['value'] === (string) $value) {
                    return self::localised($option['label'], $locale) ?? $option['value'];
                }
            }
        }

        return self::stringify($value);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? __('cms.forms.value_yes') : __('cms.forms.value_no'),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    private static function maxLength(FormFieldType $type, mixed $value): ?int
    {
        $ceiling = $type->maxLengthCeiling();

        if ($ceiling === null || ! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return min((int) $value, $ceiling);
    }

    /**
     * Only configured locales, only non-blank strings.
     *
     * An untouched locale tab submits `['en' => null]`; keeping it would make "has an English
     * label" true for a field that has none, and the API would then report no fallback for it.
     *
     * @return LocaleMap
     */
    private static function localeMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ((array) config('cms.locales.supported', ['fa']) as $locale) {
            $text = $value[$locale] ?? null;

            if (is_string($text) && trim($text) !== '') {
                $map[(string) $locale] = mb_substr(trim($text), 0, self::TEXT_MAX_LENGTH);
            }
        }

        return $map;
    }

    /**
     * @return list<FormFieldOption>
     */
    private static function options(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalised = [];
        $seen = [];

        foreach (array_values($options) as $option) {
            if (! is_array($option) || count($normalised) >= self::MAX_OPTIONS) {
                continue;
            }

            $value = is_scalar($option['value'] ?? null) ? trim((string) $option['value']) : '';

            if ($value === '' || mb_strlen($value) > self::OPTION_VALUE_MAX_LENGTH || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $normalised[] = ['value' => $value, 'label' => self::localeMap($option['label'] ?? null)];
        }

        return $normalised;
    }
}
