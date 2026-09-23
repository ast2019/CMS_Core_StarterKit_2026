{{--
    RULE #6 custom block rendering. Requirements 4.4, 4.5.

    Every interpolation uses {{ }} (escaped), never {!! !!}. Editor content is
    authored by staff, but staff routinely paste from Word, email and other CMSes,
    and a stored XSS in a shared Core would propagate to every client site built
    from it.
--}}
@php
    $toneClasses = [
        'info' => 'cms-callout--info',
        'success' => 'cms-callout--success',
        'warning' => 'cms-callout--warning',
        'danger' => 'cms-callout--danger',
    ];

    // Whitelist the tone rather than interpolating it into a class name, so a
    // hand-edited JSON document cannot inject arbitrary classes.
    $toneClass = $toneClasses[$tone] ?? $toneClasses['info'];
@endphp

<aside class="cms-callout {{ $toneClass }}" role="note">
    @if (filled($title))
        <p class="cms-callout__title">{{ $title }}</p>
    @endif

    <div class="cms-callout__body">
        {{-- nl2br on an escaped string keeps author line breaks without
             admitting markup. --}}
        {!! nl2br(e($body)) !!}
    </div>
</aside>
