@extends('layouts.app')

@php
    use Illuminate\Support\Str;

    $navTitle = 'Reseñas';
    $starFillWidth = fn ($value) => max(0, min(100, ((float) $value / 5) * 100));
@endphp

@section('title', 'Reseñas')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-brand-primary">Marketing</p>
                <h1 class="mt-2 text-3xl font-bold text-brand-secondary">Reseñas</h1>
                <p class="mt-2 max-w-3xl text-sm text-gray-600">
                    Visor centralizado de reseñas de Google Business Profile para todas las delegaciones, con métricas
                    mensuales, historial y respuestas desde la app.
                </p>
            </div>

            <div class="w-full lg:w-[34rem]">
                <div class="grid gap-3 sm:grid-cols-2">
                <form method="POST" action="{{ route('reviews.refresh') }}" data-review-sync-loader-form class="sm:col-span-2">
                    @csrf
                    <button
                        type="submit"
                        data-review-sync-loader-button
                        data-review-sync-loader-default="Sincronizar ahora"
                        data-review-sync-loader-loading="Sincronizando..."
                        class="inline-flex h-12 w-full cursor-pointer items-center justify-center rounded-2xl bg-brand-primary px-4 text-sm font-semibold text-white transition hover:opacity-90">
                        <svg data-review-sync-loader-icon xmlns="http://www.w3.org/2000/svg" class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356m-1.636 10.26a9 9 0 11-2.867-9.668L21 9.348" />
                        </svg>
                        <span data-review-sync-loader-label>Sincronizar ahora</span>
                    </button>
                </form>
                <a href="{{ route('reviews.reports') }}"
                    class="inline-flex h-12 w-full items-center justify-center rounded-2xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                    Informes
                </a>
                <a href="{{ route('reviews.all') }}"
                    class="inline-flex h-12 w-full items-center justify-center rounded-2xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                    Todas las reseñas
                </a>
                </div>
            </div>
        </div>

        <div
            id="review-sync-loader"
            class="pointer-events-none fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/45 px-6 py-8 opacity-0 backdrop-blur-sm transition-opacity duration-200"
        >
            <div class="w-full max-w-md rounded-[2rem] border border-white/60 bg-white/95 p-7 text-center shadow-[0_30px_80px_rgba(15,23,42,0.22)]">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[radial-gradient(circle_at_top,rgba(239,68,68,0.18),rgba(255,255,255,0.95))] ring-1 ring-brand-primary/10">
                    <div class="h-8 w-8 animate-spin rounded-full border-[3px] border-brand-primary/20 border-t-brand-primary"></div>
                </div>
                <h2 class="mt-5 text-xl font-semibold text-brand-secondary">Sincronizando reseñas</h2>
                <p class="mt-2 text-sm leading-6 text-brand-secondary/70">
                    Estamos actualizando las reseñas y el historial. Esta pantalla se cerrará sola al terminar.
                </p>
            </div>
        </div>

        <script>
            (() => {
                const overlay = document.getElementById('review-sync-loader');

                document.querySelectorAll('[data-review-sync-loader-form]').forEach((form) => {
                    let submitted = false;

                    form.addEventListener('submit', (event) => {
                        if (submitted) {
                            return;
                        }

                        submitted = true;
                        event.preventDefault();

                        const button = form.querySelector('[data-review-sync-loader-button]');
                        const label = form.querySelector('[data-review-sync-loader-label]');
                        const icon = form.querySelector('[data-review-sync-loader-icon]');

                        if (button) {
                            button.disabled = true;
                            button.classList.add('opacity-90');
                        }

                        if (label && button?.dataset.reviewSyncLoaderLoading) {
                            label.textContent = button.dataset.reviewSyncLoaderLoading;
                        }

                        if (icon) {
                            icon.classList.add('animate-spin');
                        }

                        if (overlay) {
                            overlay.classList.remove('hidden');

                            requestAnimationFrame(() => {
                                overlay.classList.remove('pointer-events-none', 'opacity-0');
                                overlay.classList.add('flex', 'opacity-100');
                            });
                        }

                        window.setTimeout(() => form.submit(), 80);
                    });
                });
            })();
        </script>

        @if (session('success'))
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div x-data="{ open: false }" class="mb-8 rounded-3xl border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-brand-secondary">Últimas reseñas</p>
                    <p class="text-xs text-gray-500">Las últimas reseñas sin responder se muestran aquí para actuar rápido.</p>
                </div>
                <button type="button" @click="open = !open"
                    class="inline-flex cursor-pointer items-center gap-2 self-start rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-red-500"></span>
                    {{ $latestUnanswered->count() }} sin responder
                </button>
            </div>

            <div x-show="open" x-cloak class="grid gap-3 px-5 py-4 md:grid-cols-2 xl:grid-cols-4">
                @forelse ($latestUnanswered as $review)
                    @php
                        $reviewAnchor = '#review-' . $review->id;
                        $reviewLocationLabel = $review->dealership?->name ?? $review->location_title ?? 'Delegación sin asignar';
                        $reviewUrl = $review->dealership_id
                            ? route('reviews.show', $review->dealership_id) . $reviewAnchor
                            : ($review->location_name
                                ? route('reviews.location', rtrim(strtr(base64_encode($review->location_name), '+/', '-_'), '=')) . $reviewAnchor
                                : null);
                    @endphp
                    @if ($reviewUrl)
                        <a href="{{ $reviewUrl }}"
                            class="rounded-2xl border border-gray-100 bg-gray-50 p-4 transition hover:border-brand-primary/20 hover:bg-brand-primary/5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-brand-secondary">
                                        {{ $reviewLocationLabel }}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $review->reviewer_name ?? 'Cliente anónimo' }}</p>
                                </div>
                                <span class="rounded-full bg-red-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-700">
                                    {{ $review->rating ?? 0 }}★
                                </span>
                            </div>
                            <p class="mt-3 line-clamp-3 text-sm text-gray-600">{{ $review->comment ?? 'Sin texto de reseña.' }}</p>
                        </a>
                    @else
                        <div class="rounded-2xl border border-gray-100 bg-gray-50 p-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-brand-secondary">
                                        {{ $reviewLocationLabel }}
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $review->reviewer_name ?? 'Cliente anónimo' }}</p>
                                </div>
                                <span class="rounded-full bg-red-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-700">
                                    {{ $review->rating ?? 0 }}★
                                </span>
                            </div>
                            <p class="mt-3 line-clamp-3 text-sm text-gray-600">{{ $review->comment ?? 'Sin texto de reseña.' }}</p>
                        </div>
                    @endif
                @empty
                    <div class="rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500 md:col-span-2 xl:col-span-4">
                        No hay reseñas pendientes de respuesta.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-gray-500">Reseñas totales</p>
                <p class="mt-3 text-3xl font-bold text-brand-secondary">{{ number_format($stats['total_reviews']) }}</p>
            </div>
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-gray-500">Media general</p>
                <p class="mt-3 text-3xl font-bold text-brand-secondary">{{ number_format($stats['average_rating'], 2) }}</p>
            </div>
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-gray-500">Reseñas este mes</p>
                <p class="mt-3 text-3xl font-bold text-brand-secondary">{{ number_format($stats['monthly_reviews']) }}</p>
            </div>
            <div class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-medium text-gray-500">Sin responder</p>
                <p class="mt-3 text-3xl font-bold text-brand-secondary">{{ number_format($stats['unanswered_reviews']) }}</p>
            </div>
        </div>

        <div
            x-data="{
                search: @js(request('search', '')),
                debouncedSearch: '',
                isSearching: false,
                searchTimeout: null,
                locationSearchables: @js($locationSummaries->map(fn ($summary) => implode(' ', [
                    $summary['location_title'],
                    $summary['location_name'],
                ]))->values()),
                init() {
                    this.debouncedSearch = this.search;

                    this.$watch('search', (value) => {
                        this.isSearching = true;

                        clearTimeout(this.searchTimeout);

                        this.searchTimeout = setTimeout(() => {
                            this.debouncedSearch = value;
                            this.isSearching = false;
                        }, 180);
                    });
                },
                normalize(value) {
                    return String(value ?? '')
                        .toLowerCase()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '');
                },
                matchesText(value) {
                    const term = this.normalize(this.debouncedSearch).trim();

                    if (! term) {
                        return true;
                    }

                    return this.normalize(value).includes(term);
                },
                hasMatchingLocations() {
                    const term = this.normalize(this.debouncedSearch).trim();

                    if (! this.locationSearchables.length) {
                        return false;
                    }

                    if (! term) {
                        return true;
                    }

                    return this.locationSearchables.some((value) => this.normalize(value).includes(term));
                },
            }"
        >
            <div class="mt-8 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-secondary">Buscar delegaciones</h2>
                        <p class="text-sm text-gray-500">Filtra en tiempo real las delegaciones vinculadas y las ubicaciones de Google.</p>
                    </div>

                    <form method="GET" class="w-full md:max-w-[18rem]">
                        <label for="dealership-sort" class="sr-only">Ordenar delegaciones</label>
                        <input type="hidden" name="search" x-model="search">
                        <select
                            id="dealership-sort"
                            name="dealership_sort"
                            x-on:change="$el.form.submit()"
                            aria-label="Ordenar delegaciones"
                            class="h-12 w-full rounded-2xl border-gray-200 bg-gray-50 px-4 text-sm focus:border-brand-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-primary/15"
                        >
                            <option value="alpha" @selected(($dealershipSort ?? 'alpha') === 'alpha')>A - Z</option>
                            <option value="reviews_asc" @selected(($dealershipSort ?? 'alpha') === 'reviews_asc')>Reseñas ↑</option>
                            <option value="reviews_desc" @selected(($dealershipSort ?? 'alpha') === 'reviews_desc')>Reseñas ↓</option>
                            <option value="rating_asc" @selected(($dealershipSort ?? 'alpha') === 'rating_asc')>Valoración ↑</option>
                            <option value="rating_desc" @selected(($dealershipSort ?? 'alpha') === 'rating_desc')>Valoración ↓</option>
                            <option value="monthly_rating_asc" @selected(($dealershipSort ?? 'alpha') === 'monthly_rating_asc')>Este mes ↑</option>
                            <option value="monthly_rating_desc" @selected(($dealershipSort ?? 'alpha') === 'monthly_rating_desc')>Este mes ↓</option>
                        </select>
                    </form>

                    <div class="w-full md:max-w-xl">
                        <label for="reviews-search" class="sr-only">Buscar delegaciones</label>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                            <input
                                id="reviews-search"
                                x-model="search"
                                type="text"
                                placeholder="Buscar delegación, location o ciudad..."
                                class="w-full rounded-2xl border-gray-200 bg-gray-50 py-3 pl-12 pr-4 text-sm placeholder:text-gray-400 focus:border-brand-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-primary/15"
                            >
                        </div>
                    </div>
                </div>
            </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($dealershipSummaries as $summary)
                @php
                    $dealership = $summary['dealership'];
                    $avg = max(0, min(5, (float) $summary['average_rating']));
                    $monthlyAvg = max(0, min(5, (float) $summary['monthly_average_rating']));
                    $searchable = implode(' ', [
                        $dealership->name,
                        $dealership->google_business_profile_location_title ?? '',
                        $dealership->google_business_profile_location_name ?? '',
                    ]);
                @endphp
                <a href="{{ route('reviews.show', $dealership) }}"
                    x-show="matchesText(@js($searchable))"
                    class="group rounded-3xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-primary/20 hover:shadow-md">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-lg font-semibold text-brand-secondary">{{ $dealership->name }}</p>
                            <p class="mt-1 text-sm text-gray-500">{{ $dealership->google_business_profile_location_title ?? 'Sin vincular' }}</p>
                        </div>
                        <span class="rounded-full bg-brand-primary/10 px-3 py-1 text-xs font-semibold text-brand-primary">
                            {{ $summary['total_reviews'] }} total
                        </span>
                    </div>

                    <div class="mt-5 space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Media actual</span>
                            <span class="font-semibold text-brand-secondary">{{ number_format($avg, 2) }}</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <div class="relative inline-flex text-2xl leading-none">
                                <div class="flex text-gray-200" aria-hidden="true">
                                    <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
                                </div>
                                <div class="absolute inset-0 overflow-hidden whitespace-nowrap text-amber-400" aria-hidden="true" style="width: {{ $starFillWidth($avg) }}%;">
                                    <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Media este mes</span>
                            <span class="font-semibold text-brand-secondary">{{ number_format($monthlyAvg, 2) }}</span>
                        </div>

                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">Sin responder</span>
                            <span class="font-semibold text-brand-secondary">{{ $summary['unanswered_reviews'] }}</span>
                        </div>
                    </div>
                </a>
            @empty
                @if ($locationSummaries->isEmpty())
                    <div class="rounded-3xl border border-dashed border-gray-200 bg-white p-8 text-sm text-gray-500 xl:col-span-3">
                        Aún no hay delegaciones vinculadas con reseñas.
                    </div>
                @endif
            @endforelse
        </div>

        @if ($locationSummaries->isNotEmpty())
            <div class="mt-10" x-show="hasMatchingLocations()" x-cloak>
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-brand-secondary">Ubicaciones de Google</h2>
                    <p class="text-sm text-gray-500">Mostramos las ubicaciones reales de Google Business Profile para no depender de que la tabla de delegaciones esté completa.</p>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($locationSummaries as $summary)
                        @php
                            $avg = max(0, min(5, (float) $summary['average_rating']));
                            $monthlyAvg = max(0, min(5, (float) $summary['monthly_average_rating']));
                            $searchable = implode(' ', [
                                $summary['location_title'],
                                $summary['location_name'],
                            ]);
                        @endphp
                        <a href="{{ route('reviews.location', $summary['key']) }}"
                            x-show="matchesText(@js($searchable))"
                            class="group rounded-3xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-primary/20 hover:shadow-md">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-lg font-semibold text-brand-secondary">{{ $summary['location_title'] }}</p>
                                    <p class="mt-1 text-sm text-gray-500">Google location</p>
                                </div>
                                <span class="rounded-full bg-brand-primary/10 px-3 py-1 text-xs font-semibold text-brand-primary">
                                    {{ $summary['total_reviews'] }} total
                                </span>
                            </div>

                            <div class="mt-5 space-y-3">
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-500">Media actual</span>
                                    <span class="font-semibold text-brand-secondary">{{ number_format($avg, 2) }}</span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <div class="relative inline-flex text-2xl leading-none">
                                        <div class="flex text-gray-200" aria-hidden="true">
                                            <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
                                        </div>
                                        <div class="absolute inset-0 overflow-hidden whitespace-nowrap text-amber-400" aria-hidden="true" style="width: {{ $starFillWidth($avg) }}%;">
                                            <span>★</span><span>★</span><span>★</span><span>★</span><span>★</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-500">Media este mes</span>
                                    <span class="font-semibold text-brand-secondary">{{ number_format($monthlyAvg, 2) }}</span>
                                </div>

                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-gray-500">Sin responder</span>
                                    <span class="font-semibold text-brand-secondary">{{ $summary['unanswered_reviews'] }}</span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

    </div>

    <script>
        (function () {
            const root = document.querySelector('[data-reviews-root]');

            if (!root) {
                return;
            }

            let requestToken = 0;

            const getForm = () => root.querySelector('[data-reviews-filter-form]');
            const getDateInputs = () => ({
                from: root.querySelector('[data-date-input="from"]'),
                to: root.querySelector('[data-date-input="to"]'),
            });

            const getDateDisplays = () => ({
                from: root.querySelector('[data-display-date-from]'),
                to: root.querySelector('[data-display-date-to]'),
            });

            const normalizeUrl = (url) => {
                const cleanUrl = new URL(url.toString());
                cleanUrl.searchParams.delete('ajax');
                return cleanUrl;
            };

            const setLoadingState = (isLoading) => {
                root.setAttribute('aria-busy', isLoading ? 'true' : 'false');

                const loading = root.querySelector('[data-reviews-loading]');
                if (loading) {
                    loading.hidden = !isLoading;
                }
            };

            const setFormDisabled = (disabled) => {
                const form = getForm();
                if (!form) {
                    return;
                }

                form.querySelectorAll('input, select, button').forEach((control) => {
                    if (control.dataset.keepEnabled === '1') {
                        return;
                    }

                    control.disabled = disabled;
                });
            };

            const formatDateLabel = (value) => {
                if (!value) {
                    return '';
                }

                const [year, month, day] = value.split('-');
                if (!year || !month || !day) {
                    return value;
                }

                return `${day}/${month}/${year}`;
            };

            const refreshDateLabels = () => {
                const inputs = getDateInputs();
                const displays = getDateDisplays();

                if (inputs.from && displays.from) {
                    displays.from.textContent = inputs.from.value ? formatDateLabel(inputs.from.value) : 'Desde';
                }

                if (inputs.to && displays.to) {
                    displays.to.textContent = inputs.to.value ? formatDateLabel(inputs.to.value) : 'Hasta';
                }
            };

            const syncDateConstraints = () => {
                const inputs = getDateInputs();

                if (inputs.from && inputs.to) {
                    inputs.from.max = inputs.to.value || '';
                    inputs.to.min = inputs.from.value || '';

                    if (inputs.from.value && inputs.to.value && inputs.to.value < inputs.from.value) {
                        inputs.to.value = inputs.from.value;
                    }
                }
            };

            const buildUrlFromForm = (page = null) => {
                const form = getForm();

                if (!form) {
                    return new URL(window.location.href);
                }

                const url = new URL(form.action, window.location.origin);
                const params = new URLSearchParams(new FormData(form));

                params.delete('ajax');

                if (page) {
                    params.set('page', page);
                } else {
                    params.delete('page');
                }

                url.search = params.toString();

                return url;
            };

            const syncUrl = (url) => {
                const cleanUrl = normalizeUrl(url);
                window.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}`);
            };

            const renderReviews = async (url) => {
                const currentToken = ++requestToken;
                const fetchUrl = new URL(url.toString());
                fetchUrl.searchParams.set('ajax', '1');

                setLoadingState(true);
                setFormDisabled(true);

                try {
                    const response = await fetch(fetchUrl, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();

                    if (currentToken !== requestToken) {
                        return;
                    }

                    root.innerHTML = payload.html;
                    syncDateConstraints();
                    refreshDateLabels();
                    syncUrl(url);
                } catch (error) {
                    console.error('No se ha podido actualizar la tabla de reseñas.', error);
                } finally {
                    if (currentToken === requestToken) {
                        setLoadingState(false);
                        setFormDisabled(false);
                    }
                }
            };

            root.addEventListener('submit', (event) => {
                const form = event.target.closest('[data-reviews-filter-form]');

                if (!form) {
                    return;
                }

                event.preventDefault();
                renderReviews(buildUrlFromForm());
            });

            root.addEventListener('change', (event) => {
                if (!event.target.closest('[data-reviews-filter-form] select, [data-reviews-filter-form] input[type="date"]')) {
                    return;
                }

                syncDateConstraints();
                refreshDateLabels();
            });

            root.addEventListener('click', (event) => {
                const resetButton = event.target.closest('[data-reviews-reset]');

                if (resetButton) {
                    event.preventDefault();

                    const form = getForm();
                    if (!form) {
                        return;
                    }

                    form.querySelectorAll('input[type="text"], input[type="date"]').forEach((input) => {
                        input.value = '';
                    });

                    form.querySelectorAll('select').forEach((select) => {
                        select.selectedIndex = 0;
                    });

                    syncDateConstraints();
                    refreshDateLabels();
                    renderReviews(buildUrlFromForm());
                    return;
                }

                const paginationLink = event.target.closest('[data-reviews-pagination] a');
                if (!paginationLink) {
                    return;
                }

                event.preventDefault();
                renderReviews(new URL(paginationLink.href));
            });

            root.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-date-trigger]');

                if (!trigger) {
                    return;
                }

                const inputs = getDateInputs();
                const key = trigger.getAttribute('data-date-trigger');
                const input = key === 'from' ? inputs.from : inputs.to;

                if (!input) {
                    return;
                }

                if (typeof input.showPicker === 'function') {
                    input.showPicker();
                    return;
                }

                input.click();
            });

            window.addEventListener('popstate', () => {
                renderReviews(new URL(window.location.href));
            });

            syncDateConstraints();
            refreshDateLabels();
        })();
    </script>
@endsection
