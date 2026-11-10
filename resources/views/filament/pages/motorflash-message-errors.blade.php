<x-filament-panels::page>
    @php($lineCategory = $this->lineErrorsCategory())

    <div class="grid gap-6 md:grid-cols-2">
        @include('filament.components.error-type-card', ['errorType' => $lineCategory])
    </div>
</x-filament-panels::page>
