@extends('layouts.app')

@section('content')
    <main
        class="mx-auto flex min-h-screen max-w-7xl flex-col px-6 py-6"
        data-agenda-url="{{ route('agenda.index') }}"
    >
        <section class="overflow-hidden rounded-[2rem] border border-brand-secondary/10 bg-white shadow-sm">
            <div class="bg-brand-secondary px-6 py-8 text-white sm:px-8">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div>
                        <span class="inline-flex rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-white/90">
                            Agenda interna
                        </span>
                        <h1 class="mt-4 max-w-2xl text-3xl font-bold tracking-tight sm:text-4xl">
                            Directorio del equipo y contactos externos
                        </h1>
                        <p class="mt-4 max-w-2xl text-sm leading-6 text-white/80 sm:text-base">
                            Directorio interno de contactos de la empresa para consultar personas, correos, teléfonos y extensiones de un vistazo.
                        </p>
                    </div>

                    <div class="grid w-full items-stretch gap-3 sm:grid-cols-3 xl:max-w-[30rem] xl:flex-1">
                        <article class="flex h-full min-h-[6.5rem] flex-col rounded-2xl border border-white/10 bg-white/10 px-3 py-3 backdrop-blur">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/65">Usuarios totales</p>
                            <p class="mt-auto pt-2.5 text-2xl font-semibold leading-none">{{ $agendaStats['users_total'] }}</p>
                        </article>
                        <article class="flex h-full min-h-[6.5rem] flex-col rounded-2xl border border-white/10 bg-white/10 px-3 py-3 backdrop-blur">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/65">Contactos</p>
                            <p class="mt-auto pt-2.5 text-2xl font-semibold leading-none">{{ $agendaStats['contacts'] }}</p>
                        </article>
                        <article class="flex h-full min-h-[6.5rem] flex-col rounded-2xl border border-white/10 bg-white/10 px-3 py-3 backdrop-blur">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-white/65">Total</p>
                            <p class="mt-auto pt-2.5 text-2xl font-semibold leading-none">{{ $agendaStats['total'] }}</p>
                        </article>
                    </div>
                </div>
            </div>

            <div class="px-6 py-6">
                <form id="agenda-search-form" method="GET" action="{{ route('agenda.index') }}" class="mb-6">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="w-full xl:flex-1 xl:max-w-none">
                            <label for="agenda-search" class="mb-2 block text-sm font-medium text-brand-secondary/80">
                                Buscar contactos
                            </label>

                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-brand-secondary/50">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>

                                <input
                                    id="agenda-search"
                                    type="text"
                                    name="search"
                                    value="{{ $search }}"
                                    placeholder="Buscar por nombre, correo o numero"
                                    autocomplete="off"
                                    class="w-full rounded-2xl border border-gray-300 py-3 pl-11 pr-28 text-sm text-brand-secondary outline-none transition focus:border-brand-primary focus:ring-2 focus:ring-brand-primary/20"
                                >

                                <div class="absolute inset-y-0 right-3 flex items-center gap-2">
                                    <button
                                        id="agenda-clear"
                                        type="button"
                                        class="hidden inline-flex cursor-pointer items-center rounded-lg border border-brand-secondary/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-secondary transition hover:bg-brand-secondary/5"
                                    >
                                        Limpiar
                                    </button>
                                </div>
                            </div>

                        </div>

                    </div>
                </form>

                <div id="agenda-loading" class="hidden space-y-4">
                    <div class="rounded-2xl border border-brand-secondary/10 bg-white p-4 shadow-sm">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 animate-pulse rounded-full bg-slate-200"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-4 w-2/5 animate-pulse rounded bg-slate-200"></div>
                                <div class="h-3 w-1/4 animate-pulse rounded bg-slate-200"></div>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-brand-secondary/10">
                                <thead class="bg-brand-secondary/5">
                                    <tr>
                                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Nombre</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Correo</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Teléfono</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Extensión Enreach</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/70">Tipo</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-brand-secondary/10 bg-white">
                                    @for ($row = 0; $row < 6; $row++)
                                        <tr>
                                            <td class="px-6 py-4">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-11 w-11 animate-pulse rounded-full bg-slate-200"></div>
                                                    <div class="flex-1 space-y-2">
                                                        <div class="h-4 w-48 animate-pulse rounded bg-slate-200"></div>
                                                        <div class="h-3 w-28 animate-pulse rounded bg-slate-200"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4"><div class="h-4 w-44 animate-pulse rounded bg-slate-200"></div></td>
                                            <td class="px-6 py-4"><div class="h-4 w-32 animate-pulse rounded bg-slate-200"></div></td>
                                            <td class="px-6 py-4"><div class="h-4 w-28 animate-pulse rounded bg-slate-200"></div></td>
                                            <td class="px-6 py-4"><div class="h-6 w-24 animate-pulse rounded-full bg-slate-200"></div></td>
                                        </tr>
                                    @endfor
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="agenda-results">
                    @include('agenda.partials.results', ['results' => $results])
                </div>
            </div>
        </section>
    </main>

    <script>
        (() => {
            const main = document.querySelector('[data-agenda-url]');
            const searchInput = document.getElementById('agenda-search');
            const clearButton = document.getElementById('agenda-clear');
            const form = document.getElementById('agenda-search-form');
            const loading = document.getElementById('agenda-loading');
            const results = document.getElementById('agenda-results');
            const agendaUrl = main?.dataset.agendaUrl;
            let searchTimeout = null;
            let abortController = null;
            let lastRequestKey = '';

            if (!main || !searchInput || !clearButton || !form || !loading || !results || !agendaUrl) {
                return;
            }

            const toggleClearButton = () => {
                clearButton.classList.toggle('hidden', searchInput.value.trim() === '');
            };

            const setLoading = (isLoading) => {
                loading.classList.toggle('hidden', !isLoading);
                results.classList.toggle('hidden', isLoading);
            };

            const updateHistory = (search, page) => {
                const historyUrl = new URL(agendaUrl, window.location.origin);

                if (search !== '') {
                    historyUrl.searchParams.set('search', search);
                }

                if (Number(page) > 1) {
                    historyUrl.searchParams.set('page', page);
                }

                window.history.replaceState({}, '', historyUrl.toString());
            };

            const loadResults = async ({ page = 1, updateUrl = true } = {}) => {
                const search = searchInput.value.trim();
                const requestUrl = new URL(agendaUrl, window.location.origin);

                if (search !== '') {
                    requestUrl.searchParams.set('search', search);
                }

                if (Number(page) > 1) {
                    requestUrl.searchParams.set('page', page);
                }

                requestUrl.searchParams.set('ajax', '1');

                const requestKey = requestUrl.searchParams.toString();

                if (requestKey === lastRequestKey) {
                    return;
                }

                lastRequestKey = requestKey;

                if (abortController) {
                    abortController.abort();
                }

                const currentController = new AbortController();
                abortController = currentController;
                setLoading(true);

                try {
                    const response = await fetch(requestUrl.toString(), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json',
                        },
                        signal: currentController.signal,
                    });

                    if (!response.ok) {
                        throw new Error('No se pudo cargar la agenda');
                    }

                    const payload = await response.json();

                    if (!payload?.html) {
                        throw new Error('Respuesta invalida de agenda');
                    }

                    results.innerHTML = payload.html;
                    results.classList.remove('agenda-results-pop');
                    void results.offsetWidth;
                    results.classList.add('agenda-results-pop');

                    if (updateUrl) {
                        updateHistory(search, page);
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        console.error(error);
                    }
                } finally {
                    if (abortController === currentController) {
                        setLoading(false);
                    }
                }
            };

            const queueSearch = () => {
                clearTimeout(searchTimeout);

                searchTimeout = setTimeout(() => {
                    loadResults({ page: 1 });
                }, 250);
            };

            searchInput.addEventListener('input', () => {
                toggleClearButton();
                queueSearch();
            });

            clearButton.addEventListener('click', () => {
                if (searchInput.value === '') {
                    return;
                }

                searchInput.value = '';
                toggleClearButton();
                loadResults({ page: 1 });
            });

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                loadResults({ page: 1 });
            });

            results.addEventListener('click', (event) => {
                const link = event.target.closest('a[href]');

                if (!link || !results.contains(link)) {
                    return;
                }

                const url = new URL(link.href);

                if (url.pathname !== window.location.pathname) {
                    return;
                }

                const page = url.searchParams.get('page');

                if (!page) {
                    return;
                }

                event.preventDefault();
                loadResults({ page });
            });

            toggleClearButton();
        })();
    </script>
@endsection
