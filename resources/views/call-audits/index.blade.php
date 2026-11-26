@extends('layouts.app')

@section('title', 'Auditorías IA de llamadas')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-8">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-primary">Empresa</p>
            <h1 class="mt-2 text-3xl font-bold text-brand-secondary">Auditorías IA de llamadas</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">
                Consulta de llamadas auditadas automáticamente. Las valoraciones son provisionales y pueden requerir contraste con el audio.
            </p>
        </div>

        <section class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 class="text-lg font-semibold text-brand-secondary">Llamadas auditadas</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $audits->total() }} resultado(s) disponible(s).</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3">Comercial</th>
                            @foreach ($sortableColumns as $column => $label)
                                @php
                                    $isActiveSort = $explicitSort && $sort === $column;
                                    $isResetClick = $isActiveSort && $direction === 'desc';
                                    $nextDirection = $isActiveSort && $direction === 'asc' ? 'desc' : 'asc';
                                    $sortUrl = $isResetClick
                                        ? route('call-audits.index')
                                        : route('call-audits.index', ['sort' => $column, 'direction' => $nextDirection]);
                                    $ariaSort = $isActiveSort ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none';
                                    $nextLabel = $isResetClick
                                        ? 'quitar ordenación'
                                        : ($nextDirection === 'asc' ? 'ascendente' : 'descendente');
                                @endphp
                                <th class="px-5 py-3" aria-sort="{{ $ariaSort }}">
                                    <a href="{{ $sortUrl }}" class="inline-flex items-center gap-1.5 rounded-md py-1 text-left hover:text-brand-secondary focus:outline-none focus:ring-2 focus:ring-brand-primary/40" aria-label="{{ $isActiveSort ? 'Cambiar' : 'Ordenar' }} por {{ $label }} ({{ $nextLabel }})">
                                        <span>{{ $label }}</span>
                                        <span aria-hidden="true" class="text-sm {{ $isActiveSort ? 'text-brand-primary' : 'text-gray-400' }}">{{ $isActiveSort ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                    </a>
                                </th>
                            @endforeach
                            <th class="px-5 py-3"><span class="sr-only">Acción</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($audits as $audit)
                            @php($coverage = $audit->coverage())
                            <tr class="align-middle">
                                <td class="px-5 py-4 text-gray-700">{{ $commercialNames[$audit->employeeId() ?? ''] ?? 'Comercial sin identificar' }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-gray-600">{{ $audit->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-4 font-mono text-xs text-gray-700">{{ $audit->call_puid ?: 'Sin PUID' }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $audit->score === null ? 'bg-gray-100 text-gray-700' : 'bg-blue-100 text-blue-800' }}" title="Nota provisional">
                                        {{ $audit->score === null ? 'Sin nota' : number_format((float) $audit->score, 2, ',', '.') . '/10' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-gray-600">
                                    {{ is_numeric($coverage) ? $coverage . '%' : (is_scalar($coverage) ? $coverage : 'No disponible') }}
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('call-audits.show', $audit) }}" class="font-semibold text-brand-primary hover:underline">Ver detalle</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">No hay auditorías IA disponibles.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($audits->hasPages())
                <div class="border-t border-gray-100 px-5 py-4">{{ $audits->links() }}</div>
            @endif
        </section>
    </div>
@endsection
