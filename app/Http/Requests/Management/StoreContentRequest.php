<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\ContentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Requirement 9.7 — every write validated through a Form Request.
 */
class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller also calls authorize('create'); this gates the module.
        return (bool) config('cms.modules.content', true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $locales = (array) config('cms.locales.supported', ['fa']);
        $source = (string) config('cms.locales.source', 'fa');

        $rules = [
            /*
             * Translatable fields arrive as locale maps. The source locale's title is
             * required; the others are not, because demanding a translation at
             * creation time is precisely the "activate a locale later" trap the
             * translation lifecycle exists to avoid.
             */
            'title' => ['required', 'array'],
            "title.{$source}" => ['required', 'string', 'max:255'],

            'status' => ['sometimes', Rule::enum(ContentStatus::class)],
            'publish_date' => ['nullable', 'date'],
            /*
             * Item 11 — `exists:` DOES NOT KNOW ABOUT SOFT DELETES.
             *
             * The rule runs a raw query builder, not Eloquent, so the SoftDeletes global
             * scope never applies: `exists:categories,id` happily accepts the id of a
             * category in the trash. That is not a harmless nicety — a trashed primary
             * category decides an article's canonical URL and breadcrumb trail, and both
             * would resolve to nothing while the API answered 201.
             *
             * `whereNull('deleted_at')` on the rule restores the invariant the panel already
             * had, since the panel's Selects read Eloquent relations and so exclude the trash
             * for free. Without it the Management API was the one door left open.
             */
            'primary_category_id' => ['nullable', 'integer', $this->existsAndNotTrashed('categories')],

            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', $this->existsAndNotTrashed('categories')],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', $this->existsAndNotTrashed('tags')],
        ];

        // Only the configured locales are accepted as keys. Without this, a payload
        // carrying `title.de` would be silently stored in the JSON column and
        // become invisible data nothing reads.
        foreach (['title', 'slug', 'excerpt', 'answer_paragraph', 'meta_title', 'meta_description', 'robots_meta'] as $field) {
            $rules[$field] ??= ['sometimes', 'array'];
            $rules["{$field}.*"] = ['nullable', 'string'];

            foreach ($locales as $locale) {
                $rules["{$field}.{$locale}"] ??= ['nullable', 'string'];
            }
        }

        // The body is a TipTap document, so an array rather than a string.
        $rules['body'] = ['sometimes', 'array'];
        $rules['body.*'] = ['nullable', 'array'];

        return $rules;
    }

    /**
     * An `exists` rule that respects soft deletes (item 11).
     *
     * Laravel's `exists:` runs through the query builder rather than through Eloquent, so no
     * global scope applies and a trashed row satisfies it. Every taxonomy this request accepts
     * an id for is now soft-deletable, which turned that from a subtlety into a way for an API
     * caller to attach a category nobody can see — and for a primary category, to decide an
     * article's canonical URL with a record in the trash.
     */
    protected function existsAndNotTrashed(string $table): Exists
    {
        return Rule::exists($table, 'id')->whereNull('deleted_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'A title is required in at least the source locale.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $locales = (array) config('cms.locales.supported', ['fa']);

        /*
         * Reject unknown locale keys loudly rather than dropping them. Silently
         * discarding `title.de` would make a client believe it had stored German
         * content; failing tells them the locale is not configured.
         */
        foreach (['title', 'slug', 'excerpt', 'body', 'answer_paragraph', 'meta_title', 'meta_description', 'robots_meta'] as $field) {
            $value = $this->input($field);

            if (! is_array($value)) {
                continue;
            }

            $unknown = array_diff(array_keys($value), $locales);

            if ($unknown !== []) {
                abort(422, sprintf(
                    'Unsupported locale(s) [%s] for field [%s]. Configured locales: %s.',
                    implode(', ', $unknown),
                    $field,
                    implode(', ', $locales),
                ));
            }
        }
    }
}
