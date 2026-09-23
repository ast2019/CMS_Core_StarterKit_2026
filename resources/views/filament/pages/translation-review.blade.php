<x-filament-panels::page>
    {{-- Requirements 5.3, 5.4, 5.6. --}}
    <x-filament::section>
        <x-slot name="description">
            {{ __('cms.translation_review.intro') }}
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
