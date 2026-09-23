<x-filament-panels::page>
    {{--
        RULE #8. Requirements 9.5, 9.6.

        Table only. There is deliberately no create, edit or delete affordance
        anywhere on this page — an audit trail an administrator can alter is not
        evidence of anything.
    --}}
    <x-filament::section>
        <x-slot name="description">
            {{ __('cms.audit.intro') }}
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
