<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Helpers for the TipTap documents the RichEditor stores (RULE #6).
 *
 * Three problems this solves, all discovered in practice rather than in advance:
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
 *
 * 3. A custom block is NOT addressed the way it reads in the editor. Filament
 *    stores every block under the single node type `customBlock` and puts the
 *    block's own id and its field values one level down, inside `attrs`:
 *
 *        {"type":"customBlock","attrs":{
 *            "id":"callout",
 *            "config":{"tone":"info","title":"…","body":"…"},
 *            "label":"…","preview":"<base64 html>",
 *            "shouldApplyProseStylingToPreview":true}}
 *
 *    Keying off `type` (`'callout'`, `'hero'`, …) or reading prose from the top
 *    level of `attrs` therefore matches NOTHING that a real editor ever saves —
 *    silently, because the walk simply finds no prose and moves on. That is
 *    exactly what happened here: callout bodies were absent from the search index
 *    and hero headings were never machine-translated, while hand-built fixtures
 *    using the imaginary shape kept every test green. The accessors below are the
 *    ONE place that knows the real layout, so search indexing and translation
 *    cannot drift apart again.
 */
final class TipTap
{
    /**
     * The node `type` Filament writes for EVERY custom block, regardless of which
     * block it is. See Filament\Forms\Components\RichEditor\Actions\
     * CustomBlockAction::action() and TipTapExtensions\CustomBlockExtension::$name.
     */
    public const CUSTOM_BLOCK_NODE_TYPE = 'customBlock';

    /**
     * Prose attrs of this project's custom blocks (RULE #6), keyed by BLOCK ID —
     * `attrs.id`, not the node `type`. The values live in `attrs.config`, so a
     * callout's body has to be pulled out explicitly or it would never be
     * searchable and never be translated.
     *
     * Every other config key is structural and is deliberately absent: tone,
     * cta_url, media_asset_id, gallery_id, layout, max_items. gallery_embed has
     * no entry at all because it only references a gallery by id and carries no
     * prose of its own.
     *
     * @var array<string, list<string>>
     */
    private const CUSTOM_BLOCK_PROSE_ATTRS = [
        'callout' => ['title', 'body'],
        'hero' => ['heading', 'lead', 'cta_label'],
        'quote' => ['quote', 'attribution', 'attribution_role'],
    ];

    /**
     * Prose that is worth INDEXING but must never be machine-translated.
     *
     * `attribution` is who said the quote — almost always a person's name. Sending
     * a name to a translation model does not translate it, it transliterates or
     * "localises" it ("مریم رجوی" coming back as something no reader recognises),
     * which damages a factual attribution and, for a quote, misattributes it. A
     * human reviewer can still change it by hand in the target locale. It stays
     * searchable, because searching a speaker's name is a real query.
     *
     * `attribution_role` ("وزیر بهداشت") IS a translatable job title, so it is not
     * listed here.
     *
     * @var array<string, list<string>>
     */
    private const CUSTOM_BLOCK_UNTRANSLATABLE_PROSE_ATTRS = [
        'quote' => ['attribution'],
    ];

    private function __construct() {}

    /**
     * The block id of a custom-block node (`callout`, `hero`, …), or null when the
     * node is not a custom block or carries no id.
     *
     * @param  array<mixed>  $node
     */
    public static function customBlockId(array $node): ?string
    {
        if (($node['type'] ?? null) !== self::CUSTOM_BLOCK_NODE_TYPE) {
            return null;
        }

        $id = $node['attrs']['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * A custom-block node's field values — the editor's form data, stored under
     * `attrs.config`. Returns [] for anything else, so callers can treat "not a
     * block" and "a block with no fields" identically.
     *
     * @param  array<mixed>  $node
     * @return array<string, mixed>
     */
    public static function customBlockConfig(array $node): array
    {
        if (self::customBlockId($node) === null) {
            return [];
        }

        $config = $node['attrs']['config'] ?? null;

        if (! is_array($config)) {
            return [];
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * The names of the prose keys inside a custom-block node's `attrs.config`, in
     * the order the block declares its fields. Empty for a node that is not a
     * recognised custom block.
     *
     * $forTranslation drops the attrs that are prose for search purposes but must
     * not be sent to a translation model (see
     * CUSTOM_BLOCK_UNTRANSLATABLE_PROSE_ATTRS).
     *
     * @param  array<mixed>  $node
     * @return list<string>
     */
    public static function customBlockProseAttrs(array $node, bool $forTranslation = false): array
    {
        $id = self::customBlockId($node);

        if ($id === null) {
            return [];
        }

        $attrs = self::CUSTOM_BLOCK_PROSE_ATTRS[$id] ?? [];

        if (! $forTranslation) {
            return $attrs;
        }

        $excluded = self::CUSTOM_BLOCK_UNTRANSLATABLE_PROSE_ATTRS[$id] ?? [];

        return array_values(array_filter(
            $attrs,
            static fn (string $attr): bool => ! in_array($attr, $excluded, true),
        ));
    }

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

        // A custom block: its prose lives in attrs.config, not in child text nodes.
        $config = self::customBlockConfig($node);

        foreach (self::customBlockProseAttrs($node) as $attribute) {
            $value = $config[$attribute] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $fragments[] = $value;
            }
        }

        foreach ($node as $key => $value) {
            // Skip attrs: the prose inside attrs.config is already handled above
            // for known blocks, and everything else in attrs — ids, URLs, layout
            // keys, the base64 `preview` HTML — is structural. Indexing those
            // would pollute relevance.
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
