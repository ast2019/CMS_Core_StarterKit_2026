<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use App\Http\Resources\V1\Concerns\ResolvesLocale;
use App\Models\Content;
use App\Support\TipTap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A news article for the Delivery API.
 *
 * Requirements 4.8, 5.5, 7.1, 8.3. Read by Scramble to build the OpenAPI spec
 * (RULE #3), which is why the shape lives in a resource class rather than an
 * inline array.
 *
 * @mixin Content
 */
class ContentResource extends JsonResource
{
    use ResolvesLocale;

    /**
     * Suppresses the `related` key while nested related articles are serialised.
     *
     * Without this the resource recurses without end: including `related` renders a
     * ContentResource per related article, each of which reads the same
     * `?include=related` from the request and expands its own related set. The
     * symptom is not an obvious infinite loop but a 500 from json_encode —
     * "Maximum stack depth exceeded" — which is a confusing way to learn that a
     * response shape is self-referential.
     *
     * One level of relations is also all a consumer can use: related-of-related is
     * an arbitrary walk through the archive, not information about this article.
     */
    private static bool $suppressRelated = false;

    private static function shouldIncludeRelated(Request $request): bool
    {
        if (self::$suppressRelated) {
            return false;
        }

        return $request->boolean('include_related')
            || in_array('related', explode(',', (string) $request->query('include')), true);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private static function withoutNestedRelated(callable $callback): mixed
    {
        self::$suppressRelated = true;

        try {
            return $callback();
        } finally {
            // try/finally, not a plain reset: an exception while serialising a
            // related article would otherwise leave the flag set for the rest of
            // the request and silently strip `related` from every later response.
            self::$suppressRelated = false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = $this->locale($request);

        return [
            'id' => $this->id,
            'title' => $this->translated($this->resource, 'title', $locale),
            'slug' => $this->translated($this->resource, 'slug', $locale),
            'excerpt' => $this->translated($this->resource, 'excerpt', $locale),

            /*
             * RULE #6 — the body is the structured TipTap document, not HTML. The
             * frontend renders nodes, which is what lets one article render as a
             * web page, an AMP page and a feed item without re-authoring.
             */
            'body' => $this->translated($this->resource, 'body', $locale),

            /*
             * GEO (blueprint §6). `answer` is the editor's self-contained
             * paragraph; `headings` are extracted from the body so a consumer can
             * build an FAQ or a jump list without parsing TipTap itself.
             */
            'answer' => $this->translated($this->resource, 'answer_paragraph', $locale),
            'headings' => TipTap::headings($this->translated($this->resource, 'body', $locale)),

            'publish_date' => $this->publish_date?->toIso8601String(),

            /*
             * The same instant, already rendered in the calendar and digits of
             * the resolved locale — «۵ مهر ۱۴۰۵» for fa, «26 September 2026» for
             * en. `meta.calendar` and `meta.timezone` say how it was produced.
             */
            'publish_date_display' => $this->displayDate($this->publish_date, $locale),

            'status' => $this->status->value,

            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'name' => $this->author->name,
            ]),

            'primary_category' => $this->whenLoaded(
                'primaryCategory',
                fn () => $this->primaryCategory === null
                    ? null
                    : CategoryResource::make($this->primaryCategory),
            ),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),

            'featured_image' => $this->whenLoaded(
                'mediaAssets',
                fn () => $this->featuredImage() === null
                    ? null
                    : MediaAssetResource::make($this->featuredImage()),
            ),

            'seo' => [
                'meta_title' => $this->metaTitleFor($locale),
                'meta_description' => $this->metaDescriptionFor($locale),
                // Drafts and unreviewed translations are noindex automatically, so
                // a frontend that renders this verbatim cannot leak them to search.
                'robots' => $this->robotsMetaFor($locale),
            ],

            /*
             * Requirement 4.8 — auto-suggested related articles. Present only when
             * explicitly requested via ?include=related: computing it costs a scored
             * query per article, which must not run for every row of a listing.
             */
            'related' => $this->when(
                self::shouldIncludeRelated($request),
                /*
                 * ->resolve() forces serialisation INSIDE the guard.
                 * ContentResource::collection() only builds the collection; Laravel
                 * serialises it later, by which time the flag has been reset and each
                 * nested article expands its own related set again. Returning the
                 * unresolved collection here was the actual cause of the recursion,
                 * and the guard looked correct while doing nothing.
                 */
                fn (): array => self::withoutNestedRelated(
                    fn (): array => ContentResource::collection($this->relatedContent())->resolve($request),
                ),
            ),

            'meta' => $this->localeMeta($this->resource, $locale),
        ];
    }
}
