@extends('layouts.app')

@section('title', 'Detalle de auditoría IA')

@section('content')
    @php
        $transcription = $audit->transcription;
        $evaluation = $audit->evaluationData();
        $criteria = $audit->criteriaForDisplay();
        $alerts = $audit->alerts();
        $sourceQuality = $audit->sourceQuality();
        $attribution = $audit->attribution();
        $display = fn (mixed $value, string $fallback = 'No disponible'): string => $value === null
            ? $fallback
            : (is_bool($value) ? ($value ? 'Sí' : 'No') : (is_scalar($value) ? (string) $value : $fallback));
        $textList = function (mixed $value): array {
            if (is_scalar($value)) {
                return [(string) $value];
            }

            if (! is_array($value)) {
                return [];
            }

            return collect($value)
                ->map(function (mixed $item): ?string {
                    if (is_scalar($item)) {
                        return (string) $item;
                    }

                    if (is_array($item)) {
                        foreach (['texto', 'text', 'hecho', 'descripcion'] as $key) {
                            if (isset($item[$key]) && is_scalar($item[$key])) {
                                return (string) $item[$key];
                            }
                        }
                    }

                    return null;
                })
                ->filter()
                ->values()
                ->all();
        };
        $strengths = $textList($evaluation['fortalezas'] ?? null);
        $improvements = $textList($evaluation['mejoras'] ?? null);
        $actionPlan = $textList($evaluation['planAccion'] ?? null);
        $sourceQualityItems = $textList($sourceQuality);
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('call-audits.index') }}" class="text-sm font-semibold text-brand-primary hover:underline">← Volver a auditorías</a>
        <div class="mb-8 mt-5">
            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-primary">Empresa</p>
            <h1 class="mt-2 text-3xl font-bold text-brand-secondary">Detalle de auditoría IA</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">Evaluación provisional basada en una transcripción automática. Puede requerir contraste con el audio.</p>
        </div>

        <div class="space-y-6">
            <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm lg:p-6">
                <h2 class="text-lg font-semibold text-brand-secondary">Resumen de la llamada</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div><dt class="text-sm text-gray-500">Comercial</dt><dd class="text-lg font-semibold">{{ $commercialName }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Nota provisional</dt><dd class="text-lg font-semibold">{{ $audit->score === null ? 'Sin nota' : number_format((float) $audit->score, 2, ',', '.') . '/10' }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Cobertura</dt><dd class="text-lg font-semibold">{{ $display($audit->coverage()) }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Estado</dt><dd class="text-sm font-semibold">{{ $display($audit->status()) }}</dd></div>
                    <div><dt class="text-sm text-gray-500">Criterios</dt><dd class="text-lg font-semibold">{{ count($criteria) }}</dd></div>
                </div>
                @if ($audit->summary() !== null)
                    <p class="mt-5 whitespace-pre-wrap text-sm leading-6">{{ $display($audit->summary()) }}</p>
                @endif
            </section>

            @foreach ([['Fortalezas', $strengths], ['Mejoras', $improvements], ['Plan de acción', $actionPlan]] as [$heading, $items])
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm lg:p-6">
                    <h2 class="text-lg font-semibold text-brand-secondary">{{ $heading }}</h2>
                    @if ($items !== [])
                        <ul class="mt-4 space-y-3 text-sm leading-6 text-gray-700">
                            @foreach ($items as $item)
                                <li class="border-l-2 border-brand-primary/40 pl-4">{{ $item }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-4 text-sm text-gray-500">No disponible.</p>
                    @endif
                </section>
            @endforeach

            @if ($alerts !== [])
                <section class="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm lg:p-6">
                    <h2 class="text-lg font-semibold text-amber-900">Alertas relevantes</h2>
                    <ul class="mt-4 space-y-3 text-sm leading-6 text-amber-950">
                        @foreach ($alerts as $alert)
                            <li class="flex gap-2"><span aria-hidden="true">⚠</span><span>{{ $display($alert) }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <details class="group rounded-3xl border border-gray-200 bg-white shadow-sm">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-lg font-semibold text-brand-secondary marker:hidden lg:px-6">
                    <span>Transcripción completa</span>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center text-gray-500" aria-hidden="true">
                        <svg class="h-5 w-5 group-open:hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                        <svg class="hidden h-5 w-5 group-open:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                        </svg>
                    </span>
                </summary>
                <div class="border-t border-gray-100 p-5 lg:p-6">
                    <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap break-words text-sm leading-6">{{ $transcription?->transcript_text ?: 'No hay transcripción disponible.' }}</pre>
                </div>
            </details>

            <details class="group rounded-3xl border border-gray-200 bg-gray-50 shadow-sm">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 text-lg font-semibold text-brand-secondary marker:hidden lg:px-6">
                    <span>Información técnica</span>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center text-gray-500" aria-hidden="true">
                        <svg class="h-5 w-5 group-open:hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                        </svg>
                        <svg class="hidden h-5 w-5 group-open:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                        </svg>
                    </span>
                </summary>
                <div class="border-t border-gray-200 p-5 lg:p-6">
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div><dt class="text-sm text-gray-500">PUID</dt><dd class="break-all font-mono text-sm">{{ $display($transcription?->call_puid) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">Salesforce Task</dt><dd class="break-all font-mono text-sm">{{ $display($transcription?->salesforce_task_id) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">Versión de reglas</dt><dd class="break-words text-sm">{{ $display($audit->rules_version ?: ($audit->auditResultData()['rulesVersion'] ?? null)) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">Versión del evaluador</dt><dd class="break-words text-sm">{{ $display($audit->evaluator_version ?: ($audit->auditResultData()['evaluatorVersion'] ?? null)) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">Calidad de fuente</dt><dd class="text-sm">{{ $sourceQualityItems === [] ? 'No disponible' : implode(' ', $sourceQualityItems) }}</dd></div>
                        <div><dt class="text-sm text-gray-500">Atribución</dt><dd class="text-sm">{{ is_array($attribution) ? $display($attribution['source'] ?? null) : $display($attribution) }}</dd></div>
                        @if (is_array($attribution) && array_key_exists('confirmed', $attribution))
                            <div><dt class="text-sm text-gray-500">Atribución confirmada</dt><dd class="text-sm">{{ $display($attribution['confirmed']) }}</dd></div>
                        @endif
                        @if (is_array($attribution) && array_key_exists('employeeId', $attribution))
                            <div><dt class="text-sm text-gray-500">Identificador de empleado</dt><dd class="break-all font-mono text-sm">{{ $display($attribution['employeeId']) }}</dd></div>
                        @endif
                    </dl>
                </div>
            </details>

            <section class="rounded-3xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4 lg:px-6">
                    <h2 class="text-lg font-semibold text-brand-secondary">Criterios de evaluación</h2>
                    <p class="mt-1 text-sm text-gray-500">Cada criterio empieza plegado. Abre solo los que quieras revisar.</p>
                </div>
                <div class="divide-y divide-gray-100">
                    @forelse ($criteria as $criterion)
                        <details class="group">
                            <summary class="flex cursor-pointer list-none items-center gap-3 px-5 py-4 hover:bg-gray-50 marker:hidden lg:px-6">
                                <span class="w-12 shrink-0 font-mono text-xs font-semibold text-brand-primary">{{ $criterion['id'] }}</span>
                                <span class="min-w-0 flex-1 break-words text-sm font-semibold text-brand-secondary">{{ $criterion['name'] }}</span>
                                @if ($criterion['weight'] !== null)
                                    <span class="hidden shrink-0 text-xs text-gray-500 sm:inline">Peso {{ $criterion['weight'] }}</span>
                                @endif
                                <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">{{ $display($criterion['value']) }}</span>
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center text-gray-500" aria-hidden="true">
                                    <svg class="h-5 w-5 group-open:hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                    <svg class="hidden h-5 w-5 group-open:block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                                    </svg>
                                </span>
                            </summary>
                            <div @class([
                                'grid gap-5 bg-gray-50 px-5 py-5 lg:px-6',
                                'lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]' => ! $criterion['is_not_applicable'],
                            ])>
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Justificación</h3>
                                    <p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-gray-700">{{ $display($criterion['justification'], 'Sin justificación disponible.') }}</p>
                                </div>
                                @unless ($criterion['is_not_applicable'])
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Evidencias</h3>
                                    @if ($criterion['evidence'] !== [])
                                        <div class="mt-2 space-y-3">
                                            @foreach ($criterion['evidence'] as $evidence)
                                                <blockquote class="border-l-2 border-gray-300 pl-3 text-sm leading-6 text-gray-700">
                                                    {{ $evidence['text'] }}
                                                    @if (! empty($evidence['id']) || ! empty($evidence['actor']) || ! empty($evidence['certainty']) || $evidence['from'] !== null || $evidence['until'] !== null)
                                                        <footer class="mt-1 text-xs text-gray-500">{{ implode(' · ', array_filter([$evidence['id'] ?? null, $evidence['actor'] ?? null, $evidence['certainty'] ?? null, $evidence['from'] ?? null, $evidence['until'] ?? null], fn ($value) => $value !== null && $value !== '')) }}</footer>
                                                    @endif
                                                </blockquote>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="mt-2 text-sm text-gray-500">Sin evidencias disponibles.</p>
                                    @endif

                                    @if ($criterion['context'] !== [])
                                        <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-gray-500">Contexto</h3>
                                        <div class="mt-2 space-y-3">
                                            @foreach ($criterion['context'] as $evidence)
                                                <blockquote class="border-l-2 border-slate-300 pl-3 text-sm leading-6 text-gray-600">
                                                    {{ $evidence['text'] }}
                                                    @if (! empty($evidence['id']) || ! empty($evidence['actor']) || ! empty($evidence['certainty']) || $evidence['from'] !== null || $evidence['until'] !== null)
                                                        <footer class="mt-1 text-xs text-gray-500">{{ implode(' · ', array_filter([$evidence['id'] ?? null, $evidence['actor'] ?? null, $evidence['certainty'] ?? null, $evidence['from'] ?? null, $evidence['until'] ?? null], fn ($value) => $value !== null && $value !== '')) }}</footer>
                                                    @endif
                                                </blockquote>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                @endunless
                            </div>
                        </details>
                    @empty
                        <p class="px-5 py-8 text-sm text-gray-500 lg:px-6">No hay criterios estructurados disponibles.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
