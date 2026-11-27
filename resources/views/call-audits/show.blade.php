@extends('layouts.app')

@section('title', 'Detalle de auditoría IA')

@section('content')
    @php
        $transcription = $audit->transcription;
        $evaluation = $audit->evaluationData();
        $criteria = $audit->criteriaForDisplay();
        $alerts = $audit->additionalAlerts();
        $requiresListening = $audit->requiresListening();
        $listeningAlerts = $audit->listeningAlerts();
        $criticalGlobal = $audit->criticalGlobal();
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
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <a href="{{ route('call-audits.index') }}" class="text-sm font-semibold text-brand-primary hover:underline">← Volver a auditorías</a>
        <div class="mb-8 mt-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-primary">Empresa</p>
                    <h1 class="mt-2 text-3xl font-bold text-brand-secondary">Detalle de auditoría IA</h1>
                </div>
                @if (app_user_has_admin_permission(auth()->user(), 'call-audits.delete'))
                    <div x-data="{ deleteOpen: false }" @keydown.escape.window="deleteOpen = false">
                        <button type="button" @click="deleteOpen = true" class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-red-500/30">
                            <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                            Eliminar auditoría
                        </button>

                        <div x-cloak x-show="deleteOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-8 backdrop-blur-sm" @click.self="deleteOpen = false" role="dialog" aria-modal="true" aria-labelledby="delete-audit-title">
                            <div class="w-full max-w-xl rounded-[2rem] bg-white p-6 text-left shadow-2xl">
                                <div class="flex items-start justify-between gap-4 border-b border-brand-secondary/10 pb-4">
                                    <div>
                                        <p class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-rose-600">Eliminar auditoría IA</p>
                                        <h2 id="delete-audit-title" class="mt-1 text-left text-xl font-bold tracking-tight text-brand-secondary">¿Seguro que quieres eliminarla?</h2>
                                    </div>
                                    <button type="button" @click="deleteOpen = false" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition hover:bg-slate-200 hover:text-slate-700" aria-label="Cerrar modal">
                                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                                    </button>
                                </div>
                                <p class="mt-5 text-left text-sm leading-6 text-brand-secondary/70">Esta acción eliminará definitivamente la auditoría y su transcripción asociada. No se puede deshacer.</p>
                                <form method="POST" action="{{ route('call-audits.destroy', $audit) }}" class="mt-6 flex flex-wrap justify-end gap-3">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" @click="deleteOpen = false" class="inline-flex h-11 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-brand-secondary transition hover:bg-slate-50">Cancelar</button>
                                    <button type="submit" class="inline-flex h-11 items-center justify-center rounded-2xl bg-rose-600 px-5 text-sm font-semibold text-white transition hover:bg-rose-500">Eliminar auditoría</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">Evaluación provisional basada en una transcripción automática. Puede requerir contraste con el audio.</p>
        </div>

        <div class="space-y-6">
            @if ($requiresListening)
                <section id="requires-listening" class="scroll-mt-6 rounded-3xl border border-red-200 border-l-4 border-l-red-600 bg-red-50 p-5 shadow-sm lg:p-6">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-6 w-6 shrink-0 text-red-700" aria-hidden="true" />
                        <div class="min-w-0">
                            <h2 class="text-lg font-bold text-red-900">Requiere escucha del responsable</h2>
                            <p class="mt-2 text-sm leading-6 text-red-900/80">Incidencia detectada por IA pendiente de contrastar con el audio. El comercial mostrado no implica necesariamente que pronunciara la frase: en una transferencia pueden intervenir varias personas.</p>
                        </div>
                    </div>

                    @if ($criticalGlobal)
                        <p class="mt-4 rounded-xl border border-red-200 bg-white/70 px-4 py-3 text-sm font-semibold text-red-900">El crítico global anula la nota de la evaluación.</p>
                    @endif

                    @if ($listeningAlerts !== [])
                        <div class="mt-5 space-y-4">
                            @foreach ($listeningAlerts as $listeningAlert)
                                <article class="rounded-2xl border border-red-200 bg-white/80 p-4">
                                    <div class="flex flex-wrap gap-2 text-xs font-semibold uppercase tracking-wide text-red-800">
                                        @if ($listeningAlert['code'] !== null)
                                            <span>Código: {{ $listeningAlert['code'] }}</span>
                                        @endif
                                        @if ($listeningAlert['criterion'] !== null)
                                            <span>Criterio: {{ $listeningAlert['criterion'] }}</span>
                                        @endif
                                        @if ($listeningAlert['severity'] !== null)
                                            <span>Severidad: {{ $listeningAlert['severity'] }}</span>
                                        @endif
                                    </div>
                                    @if ($listeningAlert['reason'] !== null)
                                        <p class="mt-2 text-sm leading-6 text-red-950">{{ $listeningAlert['reason'] }}</p>
                                    @endif
                                    @if ($listeningAlert['evidences'] !== [])
                                        <div class="mt-3 space-y-2">
                                            <h3 class="text-xs font-semibold uppercase tracking-wide text-red-800">Evidencias</h3>
                                            @foreach ($listeningAlert['evidences'] as $listeningEvidence)
                                                <blockquote class="border-l-2 border-red-300 pl-3 text-sm leading-6 text-red-950">
                                                    {{ $listeningEvidence['text'] }}
                                                    @php($evidenceMetadata = array_filter([$listeningEvidence['id'], $listeningEvidence['actor'], $listeningEvidence['from'], $listeningEvidence['until']], fn ($value) => $value !== null && $value !== ''))
                                                    @if ($evidenceMetadata !== [])
                                                        <footer class="mt-1 text-xs text-red-800/70">{{ implode(' · ', $evidenceMetadata) }}</footer>
                                                    @endif
                                                </blockquote>
                                            @endforeach
                                        </div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-4 text-sm text-red-900/80">La IA ha marcado la llamada para escucha, pero no hay avisos estructurados disponibles.</p>
                    @endif
                </section>
            @endif

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
                            <li class="flex gap-2">
                                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" aria-hidden="true" />
                                <div class="min-w-0">
                                    <div class="flex flex-wrap gap-2 text-xs font-semibold uppercase tracking-wide text-amber-800">
                                        @if ($alert['code'] !== null)<span>Código: {{ $alert['code'] }}</span>@endif
                                        @if ($alert['criterion'] !== null)<span>Criterio: {{ $alert['criterion'] }}</span>@endif
                                        @if ($alert['severity'] !== null)<span>Severidad: {{ $alert['severity'] }}</span>@endif
                                    </div>
                                    @if ($alert['reason'] !== null)
                                        <p class="{{ $alert['code'] !== null || $alert['criterion'] !== null || $alert['severity'] !== null ? 'mt-1' : '' }}">{{ $alert['reason'] }}</p>
                                    @endif
                                    @if ($alert['evidences'] !== [])
                                        <div class="mt-2 space-y-1">
                                            @foreach ($alert['evidences'] as $evidence)
                                                <blockquote class="border-l-2 border-amber-300 pl-3">{{ $evidence['text'] }}</blockquote>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </li>
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
                        @if ($audit->employeeId() !== null)
                            <div><dt class="text-sm text-gray-500">Identificador de empleado</dt><dd class="break-all font-mono text-sm">{{ $display($audit->employeeId()) }}</dd></div>
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
