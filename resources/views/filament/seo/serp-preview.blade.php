{{--
    Google-style search-result preview for one locale (Requirement 7.1).

    Built by App\Services\Seo\SerpPreviewBuilder and placed by
    App\Filament\Schemas\SeoSection. Nothing is resolved in this template: the
    title, description, URL and robots directive all arrive already decided by the
    model and by UrlBuilder, so the panel cannot show one thing while the Delivery
    API serves another.

    RULE #4 — no remote asset of any kind. The classes are defined in
    resources/css/filament/admin/theme.css and the font is the panel's own.

    Direction: the block follows the LOCALE being previewed rather than the panel,
    so the English tab reads left-to-right inside the RTL admin. The URL is forced
    LTR regardless, via .cms-ltr — a URL is a Latin path even when its slug is
    Persian, and bidi reordering shuffles its segments into something that cannot be
    proofread against the real address.

    @var array{title: string, title_overflow: string, description: string,
               description_overflow: string, url: string|null, robots: string,
               indexable: bool, locale: string} $preview
    @var bool $rtl
--}}
<div class="cms-serp" dir="{{ $rtl ? 'rtl' : 'ltr' }}" lang="{{ $preview['locale'] }}">
    <div class="cms-serp-url cms-ltr" dir="ltr">
        {{ $preview['url'] ?? __('cms.seo.preview.no_url') }}
    </div>

    <div class="cms-serp-title">
        @if ($preview['title'] === '')
            <span class="cms-serp-empty">{{ __('cms.seo.preview.empty_title') }}</span>
        @else
            {{ $preview['title'] }}{{-- The tail a search result would cut. Shown struck
                through rather than dropped, so the advisory limit stops being an
                abstract number and becomes "these are the words you lose". --}}
            @if ($preview['title_overflow'] !== '')
                <span class="cms-serp-cut" title="{{ __('cms.seo.preview.cut_hint') }}">{{ ' '.$preview['title_overflow'] }}</span>
            @endif
        @endif
    </div>

    <div class="cms-serp-description">
        @if ($preview['description'] === '')
            <span class="cms-serp-empty">{{ __('cms.seo.preview.empty_description') }}</span>
        @else
            {{ $preview['description'] }}
            @if ($preview['description_overflow'] !== '')
                <span class="cms-serp-cut" title="{{ __('cms.seo.preview.cut_hint') }}">{{ ' '.$preview['description_overflow'] }}</span>
            @endif
        @endif
    </div>

    @unless ($preview['indexable'])
        {{-- robotsMetaFor() sets noindex by itself for a draft and for an unreviewed
             translation (Decision D-5), so this is the only place an editor finds out
             that the result they are polishing will not be shown at all. --}}
        <p class="cms-serp-noindex">
            {{ __('cms.seo.preview.noindex', ['robots' => $preview['robots']]) }}
        </p>
    @endunless
</div>
