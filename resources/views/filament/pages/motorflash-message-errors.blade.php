<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Tipos de incidencia detectados en Motorflash Message.
        </x-slot>

        @php($lineCategory = $this->lineErrorsCategory())

        <div class="grid gap-6 md:grid-cols-2">
            @include('filament.components.error-type-card', ['errorType' => $lineCategory])
        </div>

    </x-filament::section>
</x-filament-panels::page>
