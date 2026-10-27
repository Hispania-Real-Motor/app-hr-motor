@extends('layouts.app')

@section('content')
    {{-- La pantalla funcional se conserva para habilitarla cuando esté terminada. --}}
    @if (false)
    <main class="mx-auto max-w-7xl px-6 py-10 lg:px-8">
        <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.15em] text-brand-primary">Informática</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-brand-secondary">Altas y bajas</h1>
                <p class="mt-2 text-sm text-brand-secondary/70">Gestiona las solicitudes de alta de herramientas.</p>
            </div>
            <a href="{{ route('tools.altas-bajas.create') }}" class="inline-flex items-center justify-center rounded-xl bg-brand-primary px-5 py-3 text-sm font-semibold text-white transition hover:opacity-90">Nueva alta</a>
        </div>

        <section class="mt-8 overflow-hidden rounded-3xl border border-brand-secondary/10 bg-white shadow-sm">
            <div class="border-b border-brand-secondary/10 px-6 py-5"><h2 class="text-lg font-semibold text-brand-secondary">Últimas solicitudes</h2></div>
            @if ($requests->isEmpty())
                <div class="px-6 py-12 text-center text-sm text-brand-secondary/60">Todavía no hay solicitudes de alta.</div>
            @else
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-100 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-brand-secondary/60"><tr><th class="px-6 py-4">Empleado</th><th class="px-6 py-4">Tipo</th><th class="px-6 py-4">Estado</th><th class="px-6 py-4">Solicitado por</th><th class="px-6 py-4">Fecha</th><th class="px-6 py-4"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($requests as $request)
                            <tr><td class="px-6 py-4 font-semibold text-brand-secondary">{{ data_get($request->employee_data, 'name') }}</td><td class="px-6 py-4">{{ ucfirst($request->type) }}</td><td class="px-6 py-4"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-brand-secondary">{{ match ($request->status) { 'pending' => 'Pendiente', 'processing' => 'Procesando', 'completed' => 'Completado', 'completed_with_errors' => 'Completado con errores', 'failed' => 'Fallido', default => $request->status } }}</span></td><td class="px-6 py-4">{{ $request->requestedBy?->name }}</td><td class="px-6 py-4 text-brand-secondary/70">{{ $request->created_at?->format('d/m/Y H:i') }}</td><td class="px-6 py-4 text-right"><a class="font-semibold text-brand-primary hover:underline" href="{{ route('tools.altas-bajas.show', $request) }}">Ver detalle</a></td></tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if ($requests->hasPages()) <div class="border-t border-gray-100 px-6 py-4">{{ $requests->links() }}</div> @endif
            @endif
        </section>
    </main>
    @else
        <main class="mx-auto w-full max-w-4xl px-6 py-10 lg:px-8">
            <p class="text-sm font-semibold uppercase tracking-[0.15em] text-brand-primary">Informática</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-brand-secondary">Altas y bajas</h1>

            <section class="mt-8 rounded-3xl border border-amber-200 bg-amber-50 px-6 py-10 text-center sm:px-10" aria-labelledby="construction-title">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-700" aria-hidden="true">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 2.8 17a2 2 0 0 0 1.7 3h15a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                    </svg>
                </div>
                <h2 id="construction-title" class="mt-5 text-xl font-bold text-brand-secondary">Página en construcción</h2>
                <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-brand-secondary/70">Estamos trabajando en esta funcionalidad. Estará disponible cuando esté preparada.</p>
                <a href="{{ route('home') }}" class="mt-6 inline-flex items-center justify-center rounded-xl border border-brand-secondary/15 bg-white px-5 py-3 text-sm font-semibold text-brand-secondary transition hover:bg-slate-50">Volver al inicio</a>
            </section>
        </main>
    @endif
@endsection
