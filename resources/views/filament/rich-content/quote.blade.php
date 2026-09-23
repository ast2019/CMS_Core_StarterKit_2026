{{-- RULE #6. Requirements 4.4, 4.5, and 7.3 (attribution feeds Article JSON-LD). --}}
<figure class="cms-quote">
    <blockquote class="cms-quote__text">
        {!! nl2br(e($quote)) !!}
    </blockquote>

    @if (filled($attribution))
        <figcaption class="cms-quote__attribution">
            <span class="cms-quote__author">{{ $attribution }}</span>

            @if (filled($attributionRole))
                <span class="cms-quote__role">{{ $attributionRole }}</span>
            @endif
        </figcaption>
    @endif
</figure>
