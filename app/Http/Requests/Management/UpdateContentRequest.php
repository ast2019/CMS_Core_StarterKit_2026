<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

/**
 * Requirement 9.7.
 *
 * Extends the store rules and relaxes the required title: a PATCH that only moves
 * publish_date must not have to resend the whole translatable payload.
 */
class UpdateContentRequest extends StoreContentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $source = (string) config('cms.locales.source', 'fa');

        $rules['title'] = ['sometimes', 'array'];
        $rules["title.{$source}"] = ['sometimes', 'string', 'max:255'];

        return $rules;
    }
}
