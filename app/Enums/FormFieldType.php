<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Item 15 — the input types a form field may have.
 *
 * A closed set on purpose. Every case here is something the frontend must know how to render
 * (docs/forms.md) and the API must know how to validate (FormSubmissionRules), so a type added
 * to the panel without both would be a field nobody can fill in or one whose value is never
 * checked. File uploads are deliberately absent: they are out of scope for item 15.
 */
enum FormFieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Tel = 'tel';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('cms.forms.type.text'),
            self::Email => __('cms.forms.type.email'),
            self::Tel => __('cms.forms.type.tel'),
            self::Textarea => __('cms.forms.type.textarea'),
            self::Select => __('cms.forms.type.select'),
            self::Checkbox => __('cms.forms.type.checkbox'),
        };
    }

    /**
     * The length a field of this type is held to when its schema names none.
     *
     * Null for the two types whose value is not free text: a select value is checked against its
     * options and a checkbox is a boolean, so a character limit on either means nothing.
     */
    public function defaultMaxLength(): ?int
    {
        return match ($this) {
            self::Text, self::Email => 190,
            self::Tel => 40,
            self::Textarea => 5000,
            self::Select, self::Checkbox => null,
        };
    }

    /**
     * The most an editor may raise this type's limit to.
     *
     * A ceiling rather than trusting the schema, because the limit is the only thing between a
     * public endpoint and an arbitrarily large row. The short types stop at 255 so the values
     * copied into the `name`/`email`/`phone` columns (VARCHAR(255)) always fit.
     */
    public function maxLengthCeiling(): ?int
    {
        return match ($this) {
            self::Text, self::Email, self::Tel => 255,
            self::Textarea => 10000,
            self::Select, self::Checkbox => null,
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
