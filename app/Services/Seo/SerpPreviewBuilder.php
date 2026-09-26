<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Contracts\HasSeoMetadata;
use Illuminate\Database\Eloquent\Model;

/**
 * What one record looks like in a search result, per locale (Requirement 7.1).
 *
 * Rendered by App\Filament\Schemas\SeoSection through
 * resources/views/filament/seo/serp-preview.blade.php.
 *
 * ---------------------------------------------------------------------------
 * Why a service and not three lines in the form schema
 * ---------------------------------------------------------------------------
 * Every value here already has exactly one owner: the title and description come
 * from HasSeoMeta's fallback chain, the URL from UrlBuilder, the robots directive
 * from robotsMetaFor(). A preview that recomputed any of them would be a preview of
 * something other than what gets served — and this preview's entire value is that
 * an editor can trust it. So this class resolves nothing itself; it arranges
 * answers the model and the URL builder already give, and adds the one thing
 * neither of them does: where the text gets cut off.
 *
 * ---------------------------------------------------------------------------
 * Honesty about the truncation
 * ---------------------------------------------------------------------------
 * Google truncates by PIXEL width, not by character count — a title of capital
 * Latin letters is cut earlier than one of narrow lowercase, and Persian glyph
 * widths are different again. Nobody outside Google can reproduce it. What this
 * class does instead is cut at the advisory character limits the panel has quoted
 * since the SEO section was built (HasSeoMetadata::META_TITLE_ADVISORY_LIMIT /
 * META_DESCRIPTION_ADVISORY_LIMIT), and hand the caller BOTH halves — what
 * survives and what is lost. Showing the lost tail rather than silently dropping it
 * is the point: the editor sees which of their words falls off the end, which is
 * the argument for the limit that a character counter alone never makes.
 *
 * Cuts land on a word boundary, because a limit that appears to slice a Persian
 * word in half reads as a rendering bug rather than as advice.
 */
class SerpPreviewBuilder
{
    public function __construct(private readonly UrlBuilder $urls) {}

    /**
     * @param  Model&HasSeoMetadata  $record  May be unsaved: the panel hands in a
     *                                        throwaway instance filled from live form
     *                                        state, which is what makes the preview
     *                                        update before anything is written.
     * @return array{
     *     title: string,
     *     title_overflow: string,
     *     description: string,
     *     description_overflow: string,
     *     url: string|null,
     *     robots: string,
     *     indexable: bool,
     *     locale: string,
     * }
     */
    public function for(Model&HasSeoMetadata $record, string $locale): array
    {
        [$title, $titleOverflow] = $this->split(
            $record->metaTitleFor($locale),
            HasSeoMetadata::META_TITLE_ADVISORY_LIMIT,
        );

        [$description, $descriptionOverflow] = $this->split(
            $record->metaDescriptionFor($locale),
            HasSeoMetadata::META_DESCRIPTION_ADVISORY_LIMIT,
        );

        $robots = $record->robotsMetaFor($locale);

        return [
            'title' => $title,
            'title_overflow' => $titleOverflow,
            'description' => $description,
            'description_overflow' => $descriptionOverflow,
            /*
             * canonicalFor(), so the preview inherits every rule about this site's URL
             * shape — including the one an editor is most likely to get wrong: the
             * homepage lives at /{locale} and NOT at /{locale}/{slug}, so previewing
             * its slug in the path would advertise a URL that does not exist. null
             * means the record has no slug in this locale, which is a real and
             * reportable state rather than a reason to invent a path.
             */
            'url' => $this->urls->canonicalFor($record, $locale),
            'robots' => $robots,
            /*
             * Surfaced because robotsMetaFor() sets noindex on its own initiative —
             * for a draft, and for a locale whose translation has not been reviewed
             * (Decision D-5). Without this line an editor could polish a title and
             * description for a page that is not going to appear in any result at all,
             * and nothing in the panel would have said so.
             */
            'indexable' => ! str_contains($robots, 'noindex'),
            'locale' => $locale,
        ];
    }

    /**
     * Split a string into the part a SERP would show and the part it would cut.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $value, int $limit): array
    {
        if (mb_strlen($value) <= $limit) {
            return [$value, ''];
        }

        $kept = mb_substr($value, 0, $limit);

        // Back off to the last space so the cut lands between words. mb_strrpos on
        // the kept part, not on the whole string: the boundary has to be at or before
        // the limit, or the "shown" half would be longer than the limit it is
        // demonstrating.
        $boundary = mb_strrpos($kept, ' ');

        if ($boundary !== false && $boundary > 0) {
            return [mb_substr($value, 0, $boundary), ltrim(mb_substr($value, $boundary))];
        }

        // A single unbroken run longer than the limit — a very long compound, or a
        // language without spaces. Cut mid-word rather than reporting no overflow.
        return [$kept, mb_substr($value, $limit)];
    }
}
