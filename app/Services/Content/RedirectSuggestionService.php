<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\RedirectType;
use App\Models\Category;
use App\Models\Content;
use App\Models\Gallery;
use App\Models\Redirect;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a published record's slug change into a 301 suggestion.
 *
 * Requirement 7.5.
 *
 * Suggestion, not automatic creation. Two reasons the blueprint's "auto-prompt"
 * wording is taken literally:
 *  - a slug corrected several times while drafting would leave a chain of dead
 *    hops, and redirect chains cost more than no redirect at all;
 *  - only the editor knows whether the old URL was ever public.
 */
class RedirectSuggestionService
{
    /**
     * Slug changes worth offering a redirect for.
     *
     * @param  array<string, string|null>  $previousSlugs
     * @return array<string, array{from: string, to: string}>
     */
    public function pendingFor(Model $record, array $previousSlugs): array
    {
        $current = $record->getTranslations('slug');
        $pending = [];

        foreach ($current as $locale => $newSlug) {
            $oldSlug = $previousSlugs[$locale] ?? null;

            // A slug appearing for the first time is not a change: there was no
            // old URL to redirect from.
            if (blank($oldSlug) || blank($newSlug) || $oldSlug === $newSlug) {
                continue;
            }

            $from = $this->pathFor($record, $locale, (string) $oldSlug);
            $to = $this->pathFor($record, $locale, (string) $newSlug);

            if ($from === $to) {
                continue;
            }

            // Skip if a redirect already covers this path, so repeated saves do
            // not raise the same suggestion forever.
            if (Redirect::query()->where('from_path', Redirect::normalisePath($from))->exists()) {
                continue;
            }

            $pending[$locale] = ['from' => $from, 'to' => $to];
        }

        return $pending;
    }

    /**
     * @param  array<string, array{from: string, to: string}>  $changes
     * @return int number of redirects created
     */
    public function create(Model $record, array $changes): int
    {
        $created = 0;

        foreach ($changes as $change) {
            $from = Redirect::normalisePath($change['from']);
            $to = Redirect::normalisePath($change['to']);

            // A redirect pointing at itself is an infinite loop; the middleware
            // guards against it too, but refusing to store it is cheaper.
            if ($from === $to) {
                continue;
            }

            Redirect::query()->create([
                'from_path' => $from,
                'to_path' => $to,
                'type' => RedirectType::Permanent,
                'source_type' => $record::class,
                'source_id' => $record->getKey(),
                'created_by' => auth()->id(),
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * The public path for a record in a locale.
     *
     * Mirrors the Delivery API's URL shape (MenuItem::resolveUrl uses the same
     * segments), so a generated redirect points at a path that actually resolves.
     */
    public function pathFor(Model $record, string $locale, string $slug): string
    {
        $segment = match ($record::class) {
            Content::class => 'news',
            Gallery::class => 'gallery',
            Category::class => 'category',
            default => null,
        };

        return $segment === null
            ? "/{$locale}/{$slug}"
            : "/{$locale}/{$segment}/{$slug}";
    }
}
