<x-filament-panels::page>
    <div class="grid gap-6 md:grid-cols-2">
        @foreach ($this->errorTypes() as $errorType)
            @include('filament.components.error-type-card', ['errorType' => $errorType])
        @endforeach
    </div>
</x-filament-panels::page>
