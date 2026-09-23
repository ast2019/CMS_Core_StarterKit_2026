{{-- RULE #6. Requirements 4.4, 4.5. --}}
@php
    $locale = app()->getLocale();

    $layoutClasses = [
        'grid' => 'cms-gallery--grid',
        'carousel' => 'cms-gallery--carousel',
        'masonry' => 'cms-gallery--masonry',
    ];

    $layoutClass = $layoutClasses[$layout] ?? $layoutClasses['grid'];
@endphp

@if ($gallery === null)
    {{--
        The referenced gallery was deleted after this article was written. Saying
        so is better than rendering an empty container: in the editor the author
        can fix it, and on the public site an explicit note beats a silent gap
        that nobody notices for months.
    --}}
    <div class="cms-gallery cms-gallery--missing" role="note">
        {{ __('cms.blocks.gallery_embed.missing') }}
    </div>
@else
    <div class="cms-gallery {{ $layoutClass }}" data-gallery-id="{{ $gallery->getKey() }}">
        <h3 class="cms-gallery__title">{{ $gallery->getTranslation('title', $locale) }}</h3>

        @if ($items->isEmpty())
            <p class="cms-gallery__empty">{{ __('cms.blocks.gallery_embed.empty') }}</p>
        @else
            <ul class="cms-gallery__items">
                @foreach ($items as $item)
                    @php $media = $item->getFirstMedia('file'); @endphp

                    <li class="cms-gallery__item">
                        @if ($media)
                            <figure>
                                <img
                                    src="{{ $media->getUrl('medium_webp') }}"
                                    alt="{{ $item->altTextFor($locale) }}"
                                    @if ($item->width) width="{{ $item->width }}" @endif
                                    @if ($item->height) height="{{ $item->height }}" @endif
                                    loading="lazy"
                                    decoding="async"
                                >

                                @php $caption = $item->getTranslation('caption', $locale, false); @endphp

                                @if (filled($caption))
                                    <figcaption>{{ $caption }}</figcaption>
                                @endif
                            </figure>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
