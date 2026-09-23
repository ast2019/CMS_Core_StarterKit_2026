<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for the TipTap documents the RichEditor stores (RULE #6).
 *
 * Two problems this solves, both discovered in practice rather than in advance:
 *
 * 1. An untouched RichEditor does not submit null — it submits a structurally
 *    empty document, `{"type":"doc","content":[]}`. That is a non-empty PHP array,
 *    so a naive `array_filter` keeps it and every article ends up claiming it has
 *    English and Arabic bodies. The translation lifecycle then reports those
 *    locales as machine-translated work awaiting review, when nobody has written
 *    a word (Requirement 5.3).
 *
 * 2. Search indexing needs plain text. Feeding raw TipTap JSON to Meilisearch
 *    would index the structural keys — "paragraph", "heading", "doc" — and every
 *    document would match a search for "paragraph" (Decision D-7).
 */
final class TipTap
{
    /**
     * Node types whose textual payload lives in attrs rather than in child text
     * nodes. These are this project's custom blocks (RULE #6), so their content
     * has to be pulled out explicitly or a callout's body would never be
     * searchable.
     *
     * @var array<string, list<string>>
     */
    private const CUSTOM_BLOCK_TEXT_ATTRS = [
        'callout' => ['title', 'body'],
        'hero' => ['heading', 'lead', 'cta_label'],
        'quote' => ['quote', 'attribution', 'attribution_role'],
        // gallery_embed references a gallery by id and carries no prose of its own.
    ];

    private function __construct() {}

    /**
     * Whether a document contains no actual content.
     *
     * Deliberately checks for *text*, not for the presence of nodes: a document
     * holding one empty paragraph is still empty to a reader, and that is exactly
     * what an editor leaves behind by clicking into a tab and clicking out again.
     */
    public static function isEmpty(mixed $document): bool
    {
        if ($document === null || $document === '' || $document === []) {
            return true;
        }

        if (is_string($document)) {
            return trim(strip_tags($document)) === '';
        }

        if (! is_array($document)) {
            return false;
        }

        return self::toPlainText($document) === '';
    }

    /**
     * Flatten a TipTap document to plain text.
     *
     * Used for search indexing (Decision D-7) and for deriving a meta description
     * when an editor left one blank.
     */
    public static function toPlainText(mixed $document, string $separator = ' '): string
    {
        if (is_string($document)) {
            return trim(preg_replace('/\s+/u', ' ', strip_tags($document)) ?? '');
        }

        if (! is_array($document)) {
            return '';
        }

        $fragments = [];
        self::collectText($document, $fragments);

        $text = implode($separator, array_filter(
            array_map('trim', $fragments),
            static fn (string $fragment): bool => $fragment !== '',
        ));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $fragments
     */
    private static function collectText(array $node, array &$fragments): void
    {
        // A text node.
        if (($node['type'] ?? null) === 'text' && isset($node['text']) && is_string($node['text'])) {
            $fragments[] = $node['text'];
        }

        // A custom block: its prose lives in attrs, not in child text nodes.
        $type = $node['type'] ?? null;

        if (is_string($type) && isset(self::CUSTOM_BLOCK_TEXT_ATTRS[$type])) {
            foreach (self::CUSTOM_BLOCK_TEXT_ATTRS[$type] as $attribute) {
                $value = $node['attrs'][$attribute] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $fragments[] = $value;
                }
            }
        }

        foreach ($node as $key => $value) {
            // Skip attrs: already handled above for known blocks, and for unknown
            // blocks attrs hold ids, URLs and layout keys rather than prose —
            // indexing those would pollute relevance.
            if ($key === 'attrs' || ! is_array($value)) {
                continue;
            }

            self::collectText($value, $fragments);
        }
    }

    /**
     * Headings, for the question-based-heading part of the GEO strategy
     * (blueprint §6).
     *
     * @return list<string>
     */
    public static function headings(mixed $document, int $maxLevel = 3): array
    {
        if (! is_array($document)) {
            return [];
        }

        $headings = [];
        self::collectHeadings($document, $maxLevel, $headings);

        return $headings;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $headings
     */
    private static function collectHeadings(array $node, int $maxLevel, array &$headings): void
    {
        if (($node['type'] ?? null) === 'heading') {
            $level = (int) ($node['attrs']['level'] ?? 0);

            if ($level > 0 && $level <= $maxLevel) {
                $text = self::toPlainText($node['content'] ?? []);

                if ($text !== '') {
                    $headings[] = $text;
                }
            }
        }

        foreach ($node as $key => $value) {
            if ($key === 'attrs' || ! is_array($value)) {
                continue;
            }

            self::collectHeadings($value, $maxLevel, $headings);
        }
    }
}
