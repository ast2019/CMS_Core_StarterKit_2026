{{--
    Focus-keyphrase and content checks for one locale (Requirement 7.1).

    Computed by App\Services\Seo\SeoAnalyser through
    HasSeoMeta::seoAnalysisFor(), so the score shown here is the score the API
    reports — the same arrangement the advisory warnings already use.

    Every check states WHAT WAS MEASURED in its own label, and the footnote says
    plainly what this analysis cannot do (no stemming, no synonyms, no readability
    grading for Persian). A score whose method is hidden turns into superstition,
    and an editor rewriting good copy to satisfy a check they have misread is worse
    off than one with no score at all.

    @var array{keyphrase: string, score: int|null, band: string|null,
               checks: list<array{id: string, status: string, value: string|null}>} $analysis
--}}
<div class="cms-seo-analysis">
    @if ($analysis['keyphrase'] === '')
        <p class="cms-seo-analysis-hint">{{ __('cms.seo.analysis.no_keyphrase') }}</p>
    @endif

    @if ($analysis['score'] !== null)
        <p class="cms-seo-score cms-seo-score-{{ $analysis['band'] }}">
            <span class="cms-seo-score-value" dir="ltr">{{ $analysis['score'] }}%</span>
            <span>{{ __('cms.seo.analysis.band.'.$analysis['band']) }}</span>
        </p>
    @endif

    @if ($analysis['checks'] !== [])
        <ul class="cms-seo-checks">
            @foreach ($analysis['checks'] as $check)
                <li class="cms-seo-check cms-seo-check-{{ $check['status'] }}">
                    {{ __('cms.seo.analysis.check.'.$check['id'].'.'.$check['status'], [
                        'keyphrase' => $analysis['keyphrase'],
                        'value' => $check['value'] ?? '',
                        'min_words' => (int) config('cms.seo.analysis.min_words', 300),
                        'density_min' => (float) config('cms.seo.analysis.density_min', 0.5),
                        'density_max' => (float) config('cms.seo.analysis.density_max', 2.5),
                        'section_words' => (int) config('cms.seo.analysis.max_section_words', 300),
                        'opening_words' => (int) config('cms.seo.analysis.opening_words', 50),
                    ]) }}
                </li>
            @endforeach
        </ul>

        <p class="cms-seo-analysis-caveat">{{ __('cms.seo.analysis.caveat') }}</p>
    @endif
</div>
