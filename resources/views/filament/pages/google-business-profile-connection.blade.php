<x-filament-panels::page>
    <div class="mx-auto w-full max-w-3xl">
        <x-filament::section>
            <x-slot name="heading">Conexión Google Reseñas</x-slot>

            <x-slot name="description">
                Gestiona desde aquí la conexión con Google Business Profile para sincronizar las reseñas.
            </x-slot>

            <div class="space-y-4 pt-2">
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Estado de la conexión</p>
                    @if ($connected)
                        <p class="mt-1 text-sm text-success-600 dark:text-success-400">Conectada</p>
                        @if ($accountName)
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Cuenta: {{ $accountName }}</p>
                        @endif
                        @if ($lastSyncedAt)
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Última sincronización: {{ $lastSyncedAt }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-warning-600 dark:text-warning-400">No hay una conexión activa.</p>
                    @endif
                </div>

                <x-filament::button
                    tag="a"
                    :href="route('google-business-profile.connect')"
                    icon="heroicon-o-link"
                >
                    {{ $connected ? 'Reconectar con Google' : 'Conectar con Google' }}
                </x-filament::button>

                <x-filament::button
                    wire:click="syncReviews"
                    wire:loading.attr="disabled"
                    wire:target="syncReviews"
                    :disabled="! $connected"
                    icon="heroicon-o-arrow-path"
                >
                    <span wire:loading.remove wire:target="syncReviews">Sincronizar reseñas</span>
                    <span wire:loading wire:target="syncReviews">Sincronizando reseñas...</span>
                </x-filament::button>

                @unless ($connected)
                    <p class="text-sm text-warning-600 dark:text-warning-400">
                        Conecta Google antes de sincronizar las reseñas
                    </p>
                @endunless

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Las credenciales y los tokens se almacenan de forma segura y nunca se muestran en esta pantalla.
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
