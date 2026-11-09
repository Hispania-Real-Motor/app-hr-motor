<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="description">
            Tipos de incidencia detectados en Enreach.
        </x-slot>

        <div class="grid gap-6 md:grid-cols-2">
            @foreach ($this->errorTypes() as $errorType)
                <a
                    href="{{ $errorType['url'] }}"
                    class="fi-section rounded-xl border border-gray-200 bg-white p-6 shadow-sm transition hover:border-primary-500 dark:border-white/10 dark:bg-white/5"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                                {{ $errorType['label'] }}
                            </h2>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                Teléfonos distintos afectados
                            </p>
                        </div>

                        <span class="text-3xl font-semibold text-gray-950 dark:text-white">
                            {{ $errorType['count'] }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
