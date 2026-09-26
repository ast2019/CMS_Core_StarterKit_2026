<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\RedirectType;
use App\Models\Redirect;
use App\Services\Seo\UrlBuilder;
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
    public function __construct(private readonly UrlBuilder $urls) {}

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
     * Delegates to UrlBuilder, which owns the site's URL shape. This method used to
     * carry its own copy of the segment map and a docblock saying it "mirrors" the
     * navigation resolver — mirroring was the bug: a fifth linkable type, or a
     * changed segment, had to be remembered in three files, and a 301 pointing
     * somewhere the sitemap does not list is a silently broken link.
     *
     * pathForRecordSlug() rather than pathFor(): the OLD slug is no longer on the record
     * by the time a redirect is offered, so the path has to be built from a slug passed
     * in. The result is root-relative, which is what `redirects.from_path` stores and
     * what HandleRedirects matches on.
     *
     * The record-aware variant rather than the class-only pathForSlug(), because the
     * homepage's URL does not contain its slug. Renaming the homepage's slug therefore
     * produces the same path before and after, pendingFor() sees `from === to` and offers
     * no redirect — which is right: no public URL changed. The class-only version would
     * have suggested a 301 from /fa/old-home-slug, a URL that was never reachable.
     */
    public function pathFor(Model $record, string $locale, string $slug): string
    {
        return $this->urls->pathForRecordSlug($record, $locale, $slug);
    }
}
