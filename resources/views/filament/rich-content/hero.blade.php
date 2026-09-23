{{-- RULE #6. Requirements 4.4, 4.5. --}}
@php
    $locale = app()->getLocale();

    // Only http(s) and root-relative paths. A javascript: or data: URL in a CTA
    // would be a stored XSS reachable by any visitor who clicks the button.
    $safeCtaUrl = filled($ctaUrl) && preg_match('#^(https?://|/)#i', $ctaUrl) === 1
        ? $ctaUrl
        : null;
@endphp

<section class="cms-hero">
    @if ($asset)
        @php
            // Requirement 7.6 — explicit dimensions so the browser reserves space
            // and the hero does not shift the page as it loads.
            $media = $asset->getFirstMedia('file');
        @endphp

        <figure class="cms-hero__media">
            @if ($media)
                <img
                    src="{{ $media->getUrl('large_webp') }}"
                    alt="{{ $asset->altTextFor($locale) }}"
                    @if ($asset->width) width="{{ $asset->width }}" @endif
                    @if ($asset->height) height="{{ $asset->height }}" @endif
                    loading="lazy"
                    decoding="async"
                >
            @endif
        </figure>
    @endif

    <div class="cms-hero__content">
        <h2 class="cms-hero__heading">{{ $heading }}</h2>

        @if (filled($lead))
            <p class="cms-hero__lead">{!! nl2br(e($lead)) !!}</p>
        @endif

        @if ($safeCtaUrl && filled($ctaLabel))
            <a class="cms-hero__cta" href="{{ $safeCtaUrl }}">{{ $ctaLabel }}</a>
        @endif
    </div>
</section>
