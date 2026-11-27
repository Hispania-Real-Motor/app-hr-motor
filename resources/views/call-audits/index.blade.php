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
                <p data-call-audits-total class="mt-1 text-sm text-gray-500">{{ $audits->total() }} resultado(s) disponible(s).</p>
                @if (session('success'))
                    <p class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">{{ session('success') }}</p>
                @endif
            </div>

            <form method="GET" action="{{ route('call-audits.index') }}" data-call-audits-filter-form class="border-b border-gray-100 bg-gray-50 px-5 py-4 lg:px-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-7 lg:items-end">
                    <div>
                        <label for="zone_id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Zona</label>
                        <select id="zone_id" name="zone_id" data-live-filter="change" class="w-full rounded-xl border-gray-300 bg-white py-2.5 text-sm focus:border-brand-primary focus:ring-brand-primary/30">
                            <option value="">Todas las zonas</option>
                            @foreach ($zoneOptions as $zoneOptionId => $zoneOptionName)
                                <option value="{{ $zoneOptionId }}" @selected($filters['zone_id'] === $zoneOptionId)>{{ $zoneOptionName }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <div
                            x-data="itTicketToolSelector(@js($commercialOptions), @js($filters['commercial'] ?? ''), @js($selectedCommercialLabel))"
                            @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            class="relative"
                        >
                            <label for="commercial-search" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Comercial</label>
                            <input type="hidden" name="commercial" :value="selected">
                            <div class="relative">
                                <input
                                    id="commercial-search"
                                    type="text"
                                    x-model="query"
                                    @focus="open = true"
                                    @input="open = true; clearSelection()"
                                    @blur="syncQuery()"
                                    @keydown.enter.prevent="if (filteredOptions.length) { select(filteredOptions[0][0]); $nextTick(() => $root.closest('form').requestSubmit()); }"
                                    placeholder="Escribe y selecciona un comercial"
                                    autocomplete="off"
                                    class="w-full rounded-xl border-gray-300 bg-white py-2.5 pl-3 pr-10 text-sm focus:border-brand-primary focus:ring-brand-primary/30"
                                >
                                <button type="button" @click="open = !open" class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600" aria-label="Abrir selector de comerciales">
                                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                        <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                            <div x-cloak x-show="open" x-transition class="absolute z-20 mt-2 max-h-72 w-full overflow-auto rounded-xl border border-gray-200 bg-white shadow-lg">
                                <template x-for="option in filteredOptions" :key="option[0]">
                                    <button type="button" @pointerdown.prevent="select(option[0]); $nextTick(() => $root.closest('form').requestSubmit())" class="flex w-full items-start border-b border-gray-100 px-4 py-3 text-left text-sm last:border-b-0 hover:bg-gray-50">
                                        <span class="font-medium text-brand-secondary" x-text="option[1].label"></span>
                                    </button>
                                </template>
                                <div x-show="filteredOptions.length === 0" class="px-4 py-3 text-sm text-gray-500">No hay coincidencias.</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label for="date_from" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Desde</label>
                        <input id="date_from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" data-live-filter="change" class="w-full rounded-xl border-gray-300 bg-white py-2.5 text-sm focus:border-brand-primary focus:ring-brand-primary/30">
                    </div>
                    <div>
                        <label for="date_to" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Hasta</label>
                        <input id="date_to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" data-live-filter="change" class="w-full rounded-xl border-gray-300 bg-white py-2.5 text-sm focus:border-brand-primary focus:ring-brand-primary/30">
                    </div>
                    <div>
                        <label for="puid" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">PUID</label>
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="7" />
                                <path stroke-linecap="round" d="m20 20-4-4" />
                            </svg>
                            <input id="puid" name="puid" type="search" value="{{ $filters['puid'] ?? '' }}" placeholder="Buscar PUID" data-live-filter="input" class="w-full rounded-xl border-gray-300 bg-white py-2.5 pl-9 pr-3 text-sm focus:border-brand-primary focus:ring-brand-primary/30">
                        </div>
                    </div>
                    <div>
                        <label for="score_filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Nota</label>
                        <select id="score_filter" name="score_filter" data-live-filter="change" class="w-full rounded-xl border-gray-300 bg-white py-2.5 text-sm focus:border-brand-primary focus:ring-brand-primary/30">
                            <option value="">Todas</option>
                            <option value="without_score" @selected($filters['score_filter'] === 'without_score')>Sin nota</option>
                            <option value="with_score" @selected($filters['score_filter'] === 'with_score')>Con nota</option>
                            <option value="passed" @selected($filters['score_filter'] === 'passed')>Aprobados (5 o más)</option>
                            <option value="failed" @selected($filters['score_filter'] === 'failed')>Suspensos (menos de 5)</option>
                        </select>
                    </div>
                    <label class="flex h-[42px] items-center gap-2 self-end rounded-xl border border-gray-300 bg-white px-3 text-sm font-semibold text-brand-secondary">
                        <input type="checkbox" name="requires_listening" value="1" data-live-filter="change" @checked($filters['requires_listening']) class="h-4 w-4 rounded border-gray-300 text-brand-primary focus:ring-brand-primary/30">
                        <span>Llamadas a revisar</span>
                    </label>
                </div>
                @if ($filterQuery !== [])
                    <div class="mt-3 flex justify-end">
                        <a href="{{ route('call-audits.index') }}" data-call-audits-reset class="text-sm font-semibold text-gray-600 underline decoration-gray-300 underline-offset-4 hover:text-brand-secondary">Limpiar filtros</a>
                    </div>
                @endif
            </form>

            <div data-call-audits-results-container class="relative">
            <div data-call-audits-error role="alert" class="hidden mx-5 mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 lg:mx-6">
                No se han podido actualizar las auditorías. Inténtalo de nuevo.
            </div>
            <div data-call-audits-results aria-busy="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-5 py-3">Comercial</th>
                            <th class="px-5 py-3">Avisos</th>
                            @foreach ($sortableColumns as $column => $label)
                                @php
                                    $isActiveSort = $explicitSort && $sort === $column;
                                    $isResetClick = $isActiveSort && $direction === 'desc';
                                    $nextDirection = $isActiveSort && $direction === 'asc' ? 'desc' : 'asc';
                                    $sortUrl = $isResetClick
                                        ? route('call-audits.index', $filterQuery)
                                        : route('call-audits.index', array_merge($filterQuery, ['sort' => $column, 'direction' => $nextDirection]));
                                    $ariaSort = $isActiveSort ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none';
                                    $nextLabel = $isResetClick
                                        ? 'quitar ordenación'
                                        : ($nextDirection === 'asc' ? 'ascendente' : 'descendente');
                                @endphp
                                <th class="px-5 py-3" aria-sort="{{ $ariaSort }}">
                                    <a href="{{ $sortUrl }}" data-call-audits-sort-link class="inline-flex items-center gap-1.5 rounded-md py-1 text-left hover:text-brand-secondary focus:outline-none focus:ring-2 focus:ring-brand-primary/40" aria-label="{{ $isActiveSort ? 'Cambiar' : 'Ordenar' }} por {{ $label }} ({{ $nextLabel }})">
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
                            @php($requiresListening = $audit->requiresListening())
                            @php($scoreClass = $audit->score === null
                                ? 'bg-gray-100 text-gray-700'
                                : ((float) $audit->score < 5
                                    ? 'bg-red-100 text-red-800'
                                    : ((float) $audit->score <= 7.5 ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800')))
                            <tr class="align-middle {{ $requiresListening ? 'bg-red-50/80 hover:bg-red-100' : 'hover:bg-gray-50' }}">
                                @php($commercialUser = $commercialUsers[$audit->employeeId() ?? ''] ?? null)
                                <td class="px-5 py-3 text-gray-700 {{ $requiresListening ? 'border-l-4 border-red-500' : '' }}">
                                    @if($commercialUser)
                                        <a href="{{ route('users.show', $commercialUser) }}"
                                           class="inline-flex min-w-0 items-center gap-3 rounded-xl px-1 py-1 transition hover:bg-brand-secondary/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40">
                                            <img src="{{ $commercialUser->avatar_url }}"
                                                 alt="Avatar de {{ $commercialUser->name }}"
                                                 class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-brand-secondary/10">
                                            <span class="truncate font-medium">{{ $commercialUser->name }}</span>
                                        </a>
                                    @else
                                        <span class="inline-flex min-w-0 items-center gap-3 px-1 py-1">
                                            <img src="{{ asset(\App\Models\User::DEFAULT_AVATAR_PATH) }}"
                                                 alt=""
                                                 aria-hidden="true"
                                                 class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-brand-secondary/10">
                                            <span class="truncate">Comercial sin identificar</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($requiresListening)
                                        <span class="pointer-events-none mx-auto flex w-fit max-w-full cursor-default select-none items-center justify-center gap-1.5 rounded-full border border-red-200 bg-red-100 px-2.5 py-1 text-center text-xs font-semibold leading-4 text-red-800">
                                            <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span class="max-w-[6.5rem] whitespace-normal text-center">Requiere escucha</span>
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-4 text-gray-600">{{ $audit->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-4 font-mono text-xs text-gray-700">{{ $audit->call_puid ?: 'Sin PUID' }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $scoreClass }}" title="Nota provisional">
                                        {{ $audit->score === null ? 'Sin nota' : rtrim(rtrim(number_format((float) $audit->score, 2, ',', '.'), '0'), ',') . '/10' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-gray-600">
                                    {{ is_numeric($coverage) ? $coverage . '%' : (is_scalar($coverage) ? $coverage : 'No disponible') }}
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <div class="inline-flex items-center gap-3">
                                        <a href="{{ route('call-audits.show', $audit) }}" class="whitespace-nowrap font-semibold text-brand-primary hover:underline">Detalle</a>
                                        @if (app_user_has_admin_permission(auth()->user(), 'call-audits.delete'))
                                            <div x-data="{ deleteOpen: false }" @keydown.escape.window="deleteOpen = false">
                                                <button type="button" @click="deleteOpen = true" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-100 hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-500/30" title="Eliminar auditoría" aria-label="Eliminar auditoría">
                                                    <x-heroicon-o-trash class="h-4 w-4" aria-hidden="true" />
                                                </button>

                                                <div x-cloak x-show="deleteOpen" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-8 backdrop-blur-sm" @click.self="deleteOpen = false" role="dialog" aria-modal="true" aria-labelledby="delete-audit-title-{{ $audit->id }}">
                                                    <div class="w-full max-w-xl rounded-[2rem] bg-white p-6 text-left shadow-2xl">
                                                        <div class="flex items-start justify-between gap-4 border-b border-brand-secondary/10 pb-4">
                                                            <div>
                                                                <p class="text-left text-xs font-semibold uppercase tracking-[0.18em] text-rose-600">Eliminar auditoría IA</p>
                                                                <h2 id="delete-audit-title-{{ $audit->id }}" class="mt-1 text-left text-xl font-bold tracking-tight text-brand-secondary">¿Seguro que quieres eliminarla?</h2>
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
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-sm text-gray-500">No hay auditorías IA disponibles.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($audits->hasPages())
                <div class="border-t border-gray-100 px-5 py-4">{{ $audits->links() }}</div>
            @endif
            </div>
            <div data-call-audits-loading class="pointer-events-auto absolute inset-0 z-10 hidden items-start justify-center bg-white/75 px-5 pb-12 pt-8 backdrop-blur-[1px]" aria-hidden="true">
                <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4 text-sm font-semibold text-brand-secondary shadow-lg">
                    <span class="h-7 w-7 animate-spin rounded-full border-4 border-brand-primary/20 border-t-brand-primary" aria-hidden="true"></span>
                    <span>Actualizando llamadas…</span>
                </div>
            </div>
            <p data-call-audits-loading-announcement class="sr-only" role="status" aria-live="polite"></p>
            </div>
        </section>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-call-audits-filter-form]');
            const results = document.querySelector('[data-call-audits-results]');
            const total = document.querySelector('[data-call-audits-total]');
            const loading = document.querySelector('[data-call-audits-loading]');
            const loadingAnnouncement = document.querySelector('[data-call-audits-loading-announcement]');
            const errorMessage = document.querySelector('[data-call-audits-error]');

            if (!form || !results) {
                return;
            }

            let activeRequest;
            let requestSequence = 0;
            let timeout;
            let renderedZone = form.querySelector('[name="zone_id"]')?.value ?? '';

            const setLoading = (isLoading) => {
                results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
                loading?.classList.toggle('hidden', !isLoading);
                loading?.classList.toggle('flex', isLoading);
                loading?.setAttribute('aria-hidden', isLoading ? 'false' : 'true');

                if (loadingAnnouncement) {
                    loadingAnnouncement.textContent = isLoading ? 'Actualizando llamadas…' : '';
                }
            };

            const bindLiveFilters = () => {
                form.querySelectorAll('[data-live-filter]').forEach((control) => {
                    const eventName = control.dataset.liveFilter;

                    control.addEventListener(eventName, () => {
                        window.clearTimeout(timeout);

                        if (eventName === 'input') {
                            timeout = window.setTimeout(() => form.requestSubmit(), 350);
                            return;
                        }

                        form.requestSubmit();
                    });
                });
            };

            const loadResults = async (url, updateHistory = true) => {
                const requestUrl = new URL(url, window.location.origin);
                const requestId = ++requestSequence;
                requestUrl.searchParams.set('ajax', '1');
                activeRequest?.abort();
                activeRequest = new AbortController();
                errorMessage?.classList.add('hidden');
                setLoading(true);

                try {
                    const response = await fetch(requestUrl, {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        signal: activeRequest.signal,
                    });

                    if (requestId !== requestSequence) {
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('No se han podido actualizar las auditorías.');
                    }

                    const payload = await response.json();
                    const documentFragment = new DOMParser().parseFromString(payload.html, 'text/html');
                    const nextResults = documentFragment.querySelector('[data-call-audits-results]');
                    const nextForm = documentFragment.querySelector('[data-call-audits-filter-form]');

                    if (!nextResults) {
                        throw new Error('Respuesta de auditorías no válida.');
                    }

                    results.innerHTML = nextResults.innerHTML;
                    setLoading(false);

                    const nextZone = nextForm?.querySelector('[name="zone_id"]')?.value ?? '';

                    if (nextForm && nextZone !== renderedZone) {
                        form.innerHTML = nextForm.innerHTML;
                        renderedZone = nextZone;
                        window.Alpine?.initTree(form);
                        bindLiveFilters();
                    }

                    if (total) {
                        total.textContent = `${payload.total} resultado(s) disponible(s).`;
                    }

                    if (updateHistory) {
                        requestUrl.searchParams.delete('ajax');
                        window.history.pushState({}, '', requestUrl);
                    }

                    window.Alpine?.initTree(results);
                } catch (error) {
                    if (error.name !== 'AbortError' && requestId === requestSequence) {
                        setLoading(false);
                        errorMessage?.classList.remove('hidden');
                        if (loadingAnnouncement) {
                            loadingAnnouncement.textContent = 'No se han podido actualizar las auditorías. Inténtalo de nuevo.';
                        }
                        console.error(error);
                    }
                }
            };

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const url = new URL(form.action, window.location.origin);
                new FormData(form).forEach((value, key) => {
                    if (value !== '') {
                        url.searchParams.set(key, value);
                    }
                });
                url.searchParams.delete('page');
                loadResults(url);
            });

            results.addEventListener('click', (event) => {
                const link = event.target.closest('a');

                if (!link || (!link.matches('[data-call-audits-sort-link]') && !new URL(link.href).searchParams.has('page'))) {
                    return;
                }

                event.preventDefault();
                loadResults(link.href);
            });

            document.querySelector('[data-call-audits-reset]')?.addEventListener('click', (event) => {
                event.preventDefault();
                form.reset();
                const commercialSearch = form.querySelector('#commercial-search');
                commercialSearch?.dispatchEvent(new Event('input', { bubbles: true }));
                loadResults(event.currentTarget.href);
            });

            bindLiveFilters();

            window.addEventListener('popstate', () => loadResults(window.location.href, false));
        });
    </script>
@endsection
