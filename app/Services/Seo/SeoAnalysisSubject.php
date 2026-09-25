<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * One locale's worth of a record, flattened for SeoAnalyser.
 *
 * Exists so the ANALYSIS is a pure function of plain values while the GATHERING
 * stays on the model, where spatie's translation API lives.
 *
 * That split is not ceremony. HasSeoMeta is the only place that knows which
 * attributes a given model actually declares as translatable — asking spatie for
 * an undeclared attribute throws rather than returning null, the trap that once
 * turned a missing meta description on a Page into a 500 (see
 * HasSeoMeta::firstTranslatableValue()). Passing a snapshot means the analyser can
 * never make that mistake, can be unit-tested without touching the database, and
 * can be handed unsaved form state by the panel exactly as easily as a saved record
 * — which is what keeps the editor's score and the API's score the same number.
 */
final readonly class SeoAnalysisSubject
{
    /**
     * @param  string  $locale  The locale being analysed. Not used for folding —
     *                          ScriptFolding is deliberately locale-independent —
     *                          but carried so a caller can report which tab a score
     *                          belongs to.
     * @param  string  $keyphrase  The editor's focus keyphrase, or '' when unset or
     *                             unsupported by this model.
     * @param  string  $metaTitle  RESOLVED, i.e. after HasSeoMeta's fallbacks. The
     *                             checks must run against what will actually be
     *                             served, not against a blank override field.
     * @param  mixed  $body  A TipTap document, a string, or null.
     * @param  bool  $hasBody  Whether this model type carries prose AT ALL. A
     *                         Category has none, and scoring it 0 for a missing
     *                         300-word article would be a false negative rather
     *                         than advice.
     */
    public function __construct(
        public string $locale,
        public string $keyphrase,
        public string $metaTitle,
        public string $metaDescription,
        public string $slug,
        public mixed $body = null,
        public bool $hasBody = false,
    ) {}
}
