<x-filament-panels::page>
    {{--
        Site settings. Almost every value here is published to the FRONTEND through the
        Delivery API rather than used by this panel — see App\Filament\Pages\Settings.

        One submit button, rendered here. The page previously declared getFormActions()
        AS WELL as a button in this template, which is one button too many on a page
        whose save has side effects (maintenance mode takes the public API offline).
    --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                {{ __('cms.settings.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
