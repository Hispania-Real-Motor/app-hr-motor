<x-filament-panels::page>
    <x-filament::section>
        @if (! $this->integrationIsConnected())
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">Estado: Pendiente de integración.</p>
        @endif

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
