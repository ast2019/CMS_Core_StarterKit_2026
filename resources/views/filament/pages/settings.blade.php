<x-filament-panels::page>
    {{-- Requirement 5.3 — AI translation configuration, stored in the database. --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                {{ __('cms.settings.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
