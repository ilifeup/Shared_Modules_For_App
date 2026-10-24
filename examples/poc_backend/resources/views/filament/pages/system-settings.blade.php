<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 1.5rem;">
            <x-filament::button type="submit">
                儲存設定
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
