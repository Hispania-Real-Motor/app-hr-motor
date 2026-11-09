<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Una fila por teléfono normalizado, agrupando todos sus eventos de “Teléfono de comercial no encontrado en Salesforce”.
        </x-slot>

        @if (! $this->integrationIsConnected())
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Estado: Pendiente de integración.</p>
        @endif

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
