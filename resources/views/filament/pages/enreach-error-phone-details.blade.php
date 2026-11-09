<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Eventos individuales registrados para el teléfono {{ $phone }}, ordenados del más reciente al más antiguo.
        </x-slot>

        @if (! $this->integrationIsConnected())
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Estado: Pendiente de integración.</p>
        @endif

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
