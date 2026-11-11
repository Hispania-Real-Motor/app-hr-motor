<x-filament-panels::page>
    @php($lineCategory = $this->lineErrorsCategory())
    @php($commercialCategory = $this->commercialErrorsCategory())

    <div class="grid gap-6 md:grid-cols-2">
        @include('filament.components.error-type-card', ['errorType' => $lineCategory])
        @include('filament.components.error-type-card', ['errorType' => $commercialCategory])
    </div>
</x-filament-panels::page>
