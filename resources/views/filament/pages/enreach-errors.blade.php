<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Tipos de incidencia detectados en Enreach.
        </x-slot>

        <div class="grid gap-6 md:grid-cols-2">
            @foreach ($this->errorTypes() as $errorType)
                @include('filament.components.error-type-card', ['errorType' => $errorType])
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
