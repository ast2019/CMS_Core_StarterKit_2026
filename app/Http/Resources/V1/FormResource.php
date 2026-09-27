<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Form;
use App\Services\Forms\FormSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item 15 — a form's schema, localised for rendering.
 *
 * Every translated string is resolved to ONE string in the request locale, falling back to the
 * source locale, so a frontend renders labels without knowing about locale maps at all. Whether
 * anything fell back is reported once, in `meta`, as on every other translated payload
 * (Requirement 5.5) — a frontend can then decide not to show a Persian form under /en.
 *
 * The honeypot and timing field names are deliberately NOT in this payload. Their names are
 * kept out of every machine-readable surface — StoreContactSubmissionRequest leaves the decoy out
 * of its rules so it never reaches the OpenAPI spec — because a bot scripting the API would read
 * them here and step around both checks, on this form and on POST /api/v1/contact alike. The
 * frontend takes them from its own configuration, as it does for the contact form
 * (docs/deployment.md, "Contact form spam defences").
 *
 * @mixin Form
 *
 * @phpstan-import-type FormField from FormSchema
 */
class FormResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Form $form */
        $form = $this->resource;
        $locale = $this->locale($request);

        return [
            'key' => $form->key,
            'title' => $this->translated($form, 'title', $locale),
            /**
             * In the order the form presents them.
             *
             * @var list<array{key: string, type: 'text'|'email'|'tel'|'textarea'|'select'|'checkbox', required: bool, label: string, placeholder: string|null, help: string|null, max_length: int|null, options: list<array{value: string, label: string}>|null}>
             */
            'fields' => array_map(
                fn (array $field): array => $this->field($field, $locale),
                $form->fields,
            ),

            'meta' => $this->formLocaleMeta($form, $locale),
        ];
    }

    /**
     * @param  FormField  $field
     * @return array<string, mixed>
     */
    private function field(array $field, string $locale): array
    {
        return [
            'key' => $field['key'],
            'type' => $field['type'],
            'required' => $field['required'],
            'label' => FormSchema::localised($field['label'], $locale) ?? $field['key'],
            'placeholder' => FormSchema::localised($field['placeholder'], $locale),
            'help' => FormSchema::localised($field['help'], $locale),
            'max_length' => FormSchema::effectiveMaxLength($field),

            // Null rather than [] for a type that has no options, so "no options" and "a select
            // whose options are missing" are distinguishable.
            'options' => $field['type'] === 'select'
                ? array_map(fn (array $option): array => [
                    'value' => $option['value'],
                    'label' => FormSchema::localised($option['label'], $locale) ?? $option['value'],
                ], $field['options'])
                : null,
        ];
    }

    /**
     * Whether ANY string in the form — title, label, placeholder, help, option — had to fall
     * back from the requested locale.
     *
     * One flag for the whole form rather than one per string, because the decision it informs
     * ("render this form under /en or not") is about the form, and a form that is half English is
     * a Persian form as far as a visitor is concerned.
     *
     * @return array{locale: string, is_fallback: bool, fallback_locale: string|null}
     */
    private function formLocaleMeta(Form $form, string $locale): array
    {
        $isFallback = false;

        if ($locale !== $this->sourceLocale()) {
            $maps = [$form->getTranslations('title')];

            foreach ($form->fields as $field) {
                $maps = [...$maps, $field['label'], $field['placeholder'], $field['help']];

                foreach ($field['options'] as $option) {
                    $maps[] = $option['label'];
                }
            }

            foreach ($maps as $map) {
                if (FormSchema::fallsBack($map, $locale)) {
                    $isFallback = true;
                    break;
                }
            }
        }

        return [
            'locale' => $locale,
            'is_fallback' => $isFallback,
            'fallback_locale' => $isFallback ? $this->sourceLocale() : null,
        ];
    }
}
