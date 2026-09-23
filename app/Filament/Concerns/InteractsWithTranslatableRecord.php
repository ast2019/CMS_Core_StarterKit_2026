<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\TipTap;

/**
 * Bridges spatie/laravel-translatable and Filament form state.
 *
 * The mismatch this solves: translatable attributes are stored as JSON maps of
 * locale => value, but the trait's getAttributeValue() returns only the *current*
 * locale's string. So Filament, which fills forms from the record's attributes,
 * sees `title` as a plain Persian string and a field named `title.en` has nothing
 * to bind to. Filling the form directly would silently show every locale the same
 * Persian text, and saving would overwrite all three with it.
 *
 * Fixing it in both directions is deliberate:
 *  - before fill, replace each translatable attribute with its full locale map so
 *    `title.fa` / `title.en` / `title.ar` bind correctly;
 *  - on save, the map passes straight through, because the model's setAttribute()
 *    already routes an array to setTranslations().
 *
 * Requirement 5.1.
 */
trait InteractsWithTranslatableRecord
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        foreach ($record->getTranslatableAttributes() as $attribute) {
            $data[$attribute] = $record->getTranslations($attribute);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normaliseTranslatablePayload($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->normaliseTranslatablePayload($data);
    }

    /**
     * Drop locales the editor left blank.
     *
     * Without this, an untouched English tab submits `['en' => null]`, which
     * counts as "a translation exists" to hasAnyTranslationFor() and would start
     * the locale's lifecycle at ai_translated instead of not_translated — and then
     * show up in the translation queue as work already done.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normaliseTranslatablePayload(array $data): array
    {
        $model = $this->getModel();
        $translatable = (new $model)->getTranslatableAttributes();

        foreach ($translatable as $attribute) {
            if (! isset($data[$attribute]) || ! is_array($data[$attribute])) {
                continue;
            }

            $data[$attribute] = array_filter(
                $data[$attribute],
                /*
                 * TipTap::isEmpty rather than a plain truthiness check. An
                 * untouched RichEditor does not submit null — it submits a
                 * structurally empty document, {"type":"doc","content":[]}, which
                 * is a non-empty array and survives any naive filter. Left in, every
                 * article would appear to have English and Arabic bodies and the
                 * translation queue would show work nobody had done.
                 */
                static fn ($value): bool => ! TipTap::isEmpty($value),
            );
        }

        return $data;
    }
}
