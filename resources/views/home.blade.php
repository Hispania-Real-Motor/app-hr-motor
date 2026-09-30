@extends('layouts.app')

@section('content')
    @php
        $generalSection = collect($buttonSections)->firstWhere('title', 'Herramientas generales');
        $communicationSection = collect($buttonSections)->firstWhere('title', 'Comunicación');

        $otherSections = collect($buttonSections)->reject(function ($section) {
            return in_array($section['title'], ['Herramientas generales', 'Comunicación']);
        });

        $magazinePath = $magazine->pdf_path ?? \App\Models\MonthlyMagazineSetting::DEFAULT_PDF_PATH;
        $magazineUrl = $magazine->pdf_url ?? asset($magazinePath);
        $magazineEmbedUrl = $magazineUrl;
        $magazineTagLabel = $magazine->tag_label ?? \App\Models\MonthlyMagazineSetting::DEFAULT_TAG_LABEL;
        $homeLeaderboardSubtitle = static fn ($entry) => $entry->user?->dealership ?: 'Sin delegación asignada';
        $visibleRole = app_visible_role(auth()->user());
        $canAccessRankings = app_can_access_rankings(auth()->user());
        $canAccessVideos = app_can_access_videos(auth()->user());
        $authUser = auth()->user();
        $isCallCenterHome = app_user_has_any_role($authUser, [\App\Models\User::ROLE_CALL_CENTER]);
        $canAccessItSupport = $authUser
            && $authUser->extra_role !== \App\Models\User::ROLE_INFORMATION_TECHNOLOGY
            && (
                $authUser->role !== \App\Models\User::ROLE_ADMIN
                || app_role_viewer_active($authUser)
            )
            && app_visible_role($authUser) !== \App\Models\User::ROLE_INFORMATION_TECHNOLOGY;
        $movementPillBaseClasses = 'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ring-1';
        $movementPillCompactClasses = 'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold ring-1';
        $movementIconClasses = 'h-3.5 w-3.5 shrink-0';
        $storeManagerTooltipClasses = 'pointer-events-none absolute left-full top-1/2 z-10 ml-3 inline-flex -translate-y-1/2 whitespace-nowrap rounded-xl bg-brand-secondary px-3 py-1.5 text-[11px] font-semibold text-white opacity-0 shadow-lg transition duration-200 group-hover:opacity-100';
    @endphp

    <main class="mx-auto flex w-full max-w-7xl flex-1 flex-col px-6 py-6">
        <div class="space-y-8">
            <div class="grid gap-8 lg:grid-cols-2 lg:items-start">
                <div class="flex flex-col gap-8">
                    @if ($communicationSection)
                        <section class="rounded-3xl border border-brand-primary/20 bg-brand-primary/5 p-5 shadow-sm sm:p-6">
                            <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                                <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                    {{ $communicationSection['title'] }}
                                </h2>

                                <div
                                    class="inline-flex rounded-full bg-brand-primary/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-brand-primary sm:absolute sm:right-0 sm:top-1/2 sm:-translate-y-1/2">
                                    Destacado
                                </div>
                            </div>

                            <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                                @foreach ($communicationSection['buttons'] as $button)
                                    @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                    <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                        class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-primary/20 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                        <div class="bg-white">
                                            <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                                class="block w-full">
                                        </div>

                                        <div
                                            class="flex flex-1 items-center justify-center border-t border-brand-primary/10 px-4 py-3">
                                            <h3
                                                class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                                {{ $button['label'] }}
                                            </h3>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($canAccessItSupport)
                        <section
                            class="{{ $isCallCenterHome ? 'flex flex-col' : 'flex flex-1 flex-col' }} rounded-3xl border border-brand-secondary/10 bg-white p-5 shadow-sm">
                            <div class="mb-4 text-center">
                                <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                    Asistencia IT
                                </h2>
                            </div>

                            <div class="{{ $isCallCenterHome ? '' : 'flex flex-1 items-center justify-center' }}">
                                <a href="{{ $itSupportUrl }}"
                                    class="group relative flex w-full max-w-xl flex-col overflow-hidden rounded-[2rem] border border-brand-primary/15 bg-white shadow-sm ring-1 ring-brand-primary/5 transition duration-200 hover:-translate-y-1 hover:shadow-xl">
                                    <div class="absolute right-5 top-5 rounded-full bg-brand-primary/8 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-brand-primary ring-1 ring-brand-primary/10">
                                        Soporte
                                    </div>

                                    <div class="absolute inset-x-0 top-0 h-1.5 bg-brand-primary"></div>

                                    <div class="px-6 py-5 sm:px-7 sm:py-6">
                                        <div class="text-center sm:text-left">
                                            <div class="mb-4 flex justify-center sm:justify-start">
                                                <div
                                                    class="flex h-18 w-18 items-center justify-center rounded-3xl border border-brand-primary/10 bg-brand-primary/8 text-brand-primary transition duration-200 group-hover:scale-105 group-hover:bg-brand-primary/12">
                                                    <x-icons.it-support class="h-7 w-7" />
                                                </div>
                                            </div>

                                            <div class="space-y-2">
                                                <h3 class="text-xl font-bold tracking-tight text-brand-secondary">
                                                    Reportar incidencia
                                                </h3>

                                                <p class="text-sm leading-5 text-brand-secondary/70">
                                                    Abre una incidencia para que IT revise errores, bloqueos o accesos.
                                                </p>

                                                <div class="flex flex-wrap justify-center gap-2 sm:justify-start">
                                                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-brand-secondary ring-1 ring-brand-secondary/10">
                                                        Hardware
                                                    </span>
                                                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-brand-secondary ring-1 ring-brand-secondary/10">
                                                        Software
                                                    </span>
                                                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-brand-secondary ring-1 ring-brand-secondary/10">
                                                        Accesos
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between gap-4 border-t border-brand-primary/10 bg-slate-50/80 px-6 py-3.5 sm:px-7">
                                        <div class="text-left">
                                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-brand-secondary/50">
                                                Canal recomendado
                                            </p>
                                            <p class="mt-1 text-sm font-medium text-brand-secondary">
                                                Portal de incidencias IT
                                            </p>
                                        </div>

                                        <span
                                            class="inline-flex items-center gap-2 rounded-full bg-brand-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition duration-200 group-hover:translate-x-1">
                                            Abrir ahora
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5l6 6m0 0-6 6m6-6h-15" />
                                            </svg>
                                        </span>
                                    </div>
                                </a>
                            </div>
                        </section>
                    @endif

                    @if ($isCallCenterHome && $callCenterResourcesSection)
                        <section class="rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                            <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                                <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                    {{ $callCenterResourcesSection['title'] }}
                                </h2>
                            </div>

                            <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                                @foreach ($callCenterResourcesSection['buttons'] as $button)
                                    @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                    <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                        class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                        <div class="bg-white">
                                            <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                                class="block w-full">
                                        </div>

                                        <div
                                            class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                            <h3
                                                class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                                {{ $button['label'] }}
                                            </h3>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif

                </div>

                @if ($generalSection)
                    <section class="h-full rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $generalSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($generalSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            @foreach ($otherSections as $section)
                <section>
                    <div class="mb-5">
                        <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                            {{ $section['title'] }}
                        </h2>
                    </div>

                    <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                        @foreach ($section['buttons'] as $button)
                            @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                            <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                <div class="bg-white">
                                    <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                        class="block w-full">
                                </div>

                                <div
                                    class="flex flex-1 items-center justify-center border-t border-brand-primary/10 px-4 py-3">
                                    <h3
                                        class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                        {{ $button['label'] }}
                                    </h3>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        @if ((! empty($otherResourcesSection) && ! $isCallCenterHome) || $humanResourcesOtherResourcesSection !== null || ! empty($informaticaOtherResourcesSection) || ! empty($informaticaAccessSection) || ! empty($legalOtherResourcesSection) || ! empty($sparePartsResourcesSection) || ! empty($financingOtherResourcesSection) || ! empty($logisticsResourcesSection))
            <div class="space-y-8">
                @if (! empty($otherResourcesSection) && ! $isCallCenterHome)
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $otherResourcesSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                                @foreach ($otherResourcesSection['buttons'] as $button)
                                    @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                    <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                        class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                        <div class="bg-white">
                                            <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                                class="block w-full">
                                        </div>

                                        <div
                                            class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                            <h3
                                                class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                                {{ $button['label'] }}
                                            </h3>
                                        </div>
                                    </a>
                                @endforeach
                        </div>
                    </section>
                @endif

                @if ($humanResourcesOtherResourcesSection !== null)
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $humanResourcesOtherResourcesSection['title'] }}
                            </h2>
                        </div>

                        @if (! empty($humanResourcesOtherResourcesSection['buttons']))
                            <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                                @foreach ($humanResourcesOtherResourcesSection['buttons'] as $button)
                                    @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                    <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                        class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                        <div class="bg-white">
                                            <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                                class="block w-full">
                                        </div>

                                        <div
                                            class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                            <h3
                                                class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                                {{ $button['label'] }}
                                            </h3>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                @if (! empty($informaticaOtherResourcesSection))
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $informaticaOtherResourcesSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($informaticaOtherResourcesSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if (! empty($informaticaAccessSection))
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $informaticaAccessSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($informaticaAccessSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if (! empty($legalOtherResourcesSection))
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $legalOtherResourcesSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($legalOtherResourcesSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if (! empty($sparePartsResourcesSection))
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $sparePartsResourcesSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($sparePartsResourcesSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if (! empty($financingOtherResourcesSection))
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $financingOtherResourcesSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($financingOtherResourcesSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if (! empty($logisticsResourcesSection))
                    <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                            <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                                {{ $logisticsResourcesSection['title'] }}
                            </h2>
                        </div>

                        <div class="grid justify-center grid-cols-[repeat(auto-fit,minmax(136px,136px))] gap-6">
                            @foreach ($logisticsResourcesSection['buttons'] as $button)
                                @php $opensInNewTab = $button['open_in_new_tab'] ?? true; @endphp
                                <a href="{{ $button['url'] }}" @if ($opensInNewTab) target="_blank" rel="noopener noreferrer" @endif
                                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-md">
                                    <div class="bg-white">
                                        <img src="{{ $button['image'] }}" alt="{{ $button['label'] }}"
                                            class="block w-full">
                                    </div>

                                    <div
                                        class="flex flex-1 items-center justify-center border-t border-brand-secondary/10 px-4 py-3">
                                        <h3
                                            class="text-center text-sm font-semibold uppercase tracking-wide text-brand-secondary">
                                            {{ $button['label'] }}
                                        </h3>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        @endif

        @if ($canAccessRankings && $homeLeaderboardEntries->isNotEmpty())
            <section class="mt-8 overflow-hidden rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.28em] text-brand-primary">Ranking</p>
                        <h2 class="mt-2 text-2xl font-bold tracking-tight text-brand-secondary">
                            Top 10 comerciales del mes
                        </h2>
                        <p class="mt-2 text-sm text-brand-secondary/70">
                            Los tres primeros destacan arriba y el resto completa el ranking justo debajo.
                        </p>
                    </div>

                    <a href="{{ route('leaderboard.sales') }}"
                        class="inline-flex items-center justify-center rounded-2xl border border-brand-secondary/10 bg-white px-4 py-2 text-sm font-semibold text-brand-secondary transition hover:bg-brand-secondary/5">
                        Ver ranking completo
                    </a>
                </div>

                <div class="mt-6 grid gap-4 lg:grid-cols-3">
                    @foreach ($homeLeaderboardEntries->take(3) as $entry)
                        @php
                            $canOpenProfile = $entry->user && auth()->check();
                            $movement = $homeLeaderboardMovements[$entry->id] ?? ['direction' => 'same', 'amount' => 0, 'label' => 'Se mantiene igual que ayer'];
                            $medalStyles = match ($entry->ranking_position) {
                                1 => [
                                    'card' => 'border-yellow-300/80 bg-[linear-gradient(180deg,rgba(255,248,214,0.98),rgba(255,255,255,1))] shadow-[0_7px_0_0_rgba(217,167,34,0.24)]',
                                    'badge' => 'bg-gradient-to-r from-yellow-500 via-amber-400 to-yellow-300 text-amber-950',
                                    'ring' => 'ring-yellow-300/70',
                                    'accent' => 'text-amber-600',
                                ],
                                2 => [
                                    'card' => 'border-slate-300/80 bg-[linear-gradient(180deg,rgba(241,245,249,0.98),rgba(255,255,255,1))] shadow-[0_7px_0_0_rgba(100,116,139,0.22)]',
                                    'badge' => 'border border-slate-300/80 bg-[linear-gradient(135deg,#64748b_0%,#e2e8f0_50%,#94a3b8_100%)] text-slate-900',
                                    'ring' => 'ring-slate-300/80',
                                    'accent' => 'text-slate-500',
                                ],
                                default => [
                                    'card' => 'border-orange-300/80 bg-[linear-gradient(180deg,rgba(255,237,213,0.98),rgba(255,255,255,1))] shadow-[0_7px_0_0_rgba(180,83,9,0.22)]',
                                    'badge' => 'bg-gradient-to-r from-orange-700 via-amber-700 to-orange-300 text-white',
                                    'ring' => 'ring-orange-300/80',
                                    'accent' => 'text-orange-600',
                                ],
                            };
                        @endphp

                        @if ($canOpenProfile)
                            <a href="{{ route('users.show', $entry->user) }}"
                                class="group block h-full rounded-[1.75rem] transition duration-200 hover:-translate-y-1">
                        @endif
                        <article class="relative flex h-full flex-col overflow-hidden rounded-[1.75rem] border p-6 transition duration-200 {{ $medalStyles['card'] }}">
                            <div class="absolute right-4 top-4 flex items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $medalStyles['badge'] }}">
                                    #{{ $entry->ranking_position }}
                                </span>
                                @if ($movement['direction'] === 'up')
                                    <span class="{{ $movementPillCompactClasses }} bg-emerald-100 text-emerald-800 ring-emerald-200" title="{{ $movement['label'] }}">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="{{ $movementIconClasses }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18V6m0 0-5 5m5-5 5 5" />
                                        </svg>
                                        <span>{{ $movement['amount'] }}</span>
                                        <span class="sr-only">{{ $movement['label'] }}</span>
                                    </span>
                                @elseif ($movement['direction'] === 'down')
                                    <span class="{{ $movementPillCompactClasses }} bg-red-100 text-red-700 ring-red-200" title="{{ $movement['label'] }}">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="{{ $movementIconClasses }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m0 0-5-5m5 5 5-5" />
                                        </svg>
                                        <span>{{ $movement['amount'] }}</span>
                                        <span class="sr-only">{{ $movement['label'] }}</span>
                                    </span>
                                @else
                                    <span class="{{ $movementPillCompactClasses }} justify-center bg-slate-200 text-slate-600 ring-slate-300" title="{{ $movement['label'] }}">
                                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="{{ $movementIconClasses }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 12h10" />
                                        </svg>
                                        <span class="sr-only">{{ $movement['label'] }}</span>
                                    </span>
                                @endif
                            </div>
                            <div class="grid flex-1 grid-cols-[auto_minmax(0,1fr)] items-start gap-4 pr-24">
                                <img src="{{ $entry->user?->avatar_url ?? asset(\App\Models\User::DEFAULT_AVATAR_PATH) }}"
                                    alt="Avatar de {{ $entry->user?->name ?? $entry->seller_name }}"
                                    class="h-16 w-16 rounded-2xl object-cover ring-2 {{ $medalStyles['ring'] }}">
                                <div class="min-w-0 max-w-full">
                                    <span class="group relative inline-flex max-w-full">
                                        <p class="text-xl font-semibold leading-tight break-words {{ $entry->user?->isStoreManager() ? 'text-amber-700' : 'text-brand-secondary' }} {{ $canOpenProfile ? 'transition group-hover:text-brand-primary' : '' }}">{{ $entry->user?->name ?? $entry->seller_name }}</p>
                                        @if ($entry->user?->isStoreManager())
                                            <span class="{{ $storeManagerTooltipClasses }}">Jefe de tienda</span>
                                        @endif
                                    </span>
                                    <p class="text-sm text-brand-secondary/60">{{ $homeLeaderboardSubtitle($entry) }}</p>
                                </div>
                            </div>
                            <p class="mt-6 text-sm uppercase tracking-[0.3em] text-brand-secondary/50">Ventas</p>
                            <p class="mt-2 text-3xl font-semibold {{ $medalStyles['accent'] }}">
                                {{ number_format((float) $entry->total_sales, 0, ',', '.') }}
                            </p>
                        </article>
                        @if ($canOpenProfile)
                            </a>
                        @endif
                    @endforeach
                </div>

                @if ($homeLeaderboardEntries->count() > 3)
                    <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($homeLeaderboardEntries->slice(3) as $entry)
                        @php
                                $canOpenProfile = $entry->user && auth()->check();
                                $movement = $homeLeaderboardMovements[$entry->id] ?? ['direction' => 'same', 'amount' => 0, 'label' => 'Se mantiene igual que ayer'];
                                $rankStyles = match ($entry->ranking_position) {
                                    4 => 'border-brand-primary/20 bg-brand-primary/[0.03]',
                                    5 => 'border-brand-secondary/12 bg-white',
                                    6 => 'border-brand-secondary/12 bg-white',
                                    default => 'border-brand-secondary/10 bg-white/90',
                                };
                            @endphp

                            @if ($canOpenProfile)
                                <a href="{{ route('users.show', $entry->user) }}"
                                    class="group block rounded-[1.5rem] transition duration-200 hover:-translate-y-1 hover:shadow-md">
                            @endif
                            <article class="grid min-h-[8.2rem] grid-cols-[minmax(0,1fr)_auto] grid-rows-[auto_1fr] gap-x-3 gap-y-2 rounded-[1.5rem] border px-3 py-3 shadow-sm transition duration-200 sm:flex sm:min-h-0 sm:items-center sm:gap-4 sm:px-4 sm:py-4 {{ $rankStyles }} {{ $canOpenProfile ? 'hover:shadow-md' : '' }}">
                                <div class="flex items-center gap-2 self-start">
                                    <div class="flex h-10 min-w-10 items-center justify-center rounded-full bg-brand-secondary text-xs font-semibold text-white sm:h-11 sm:min-w-11 sm:text-sm">
                                        #{{ $entry->ranking_position }}
                                    </div>
                                    @if ($movement['direction'] === 'up')
                                        <span class="{{ $movementPillCompactClasses }} bg-emerald-100 text-emerald-800 ring-emerald-200" title="{{ $movement['label'] }}">
                                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="{{ $movementIconClasses }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 18V6m0 0-5 5m5-5 5 5" />
                                            </svg>
                                            <span>{{ $movement['amount'] }}</span>
                                            <span class="sr-only">{{ $movement['label'] }}</span>
                                        </span>
                                    @elseif ($movement['direction'] === 'down')
                                        <span class="{{ $movementPillCompactClasses }} bg-red-100 text-red-700 ring-red-200" title="{{ $movement['label'] }}">
                                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="{{ $movementIconClasses }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m0 0-5-5m5 5 5-5" />
                                            </svg>
                                            <span>{{ $movement['amount'] }}</span>
                                            <span class="sr-only">{{ $movement['label'] }}</span>
                                        </span>
                                    @else
                                        <span class="{{ $movementPillCompactClasses }} justify-center bg-slate-200 text-slate-600 ring-slate-300" title="{{ $movement['label'] }}">
                                            <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="{{ $movementIconClasses }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 12h10" />
                                            </svg>
                                            <span class="sr-only">{{ $movement['label'] }}</span>
                                        </span>
                                    @endif
                                </div>
                                <div class="row-span-2 flex flex-col items-end justify-between gap-2 self-stretch sm:hidden">
                                    <img src="{{ $entry->user?->avatar_url ?? asset(\App\Models\User::DEFAULT_AVATAR_PATH) }}"
                                        alt="Avatar de {{ $entry->user?->name ?? $entry->seller_name }}"
                                        class="h-12 w-12 rounded-xl object-cover ring-1 ring-brand-secondary/10">
                                    <div class="text-right">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.22em] text-brand-secondary/45">Ventas</p>
                                        <p class="mt-1 text-xl font-semibold text-brand-primary">
                                            {{ number_format((float) $entry->total_sales, 0, ',', '.') }}
                                        </p>
                                    </div>
                                </div>
                                <div class="row-start-2 min-w-0 self-end sm:row-start-auto sm:flex-1">
                                    <span class="group relative inline-flex max-w-full">
                                        <p class="line-clamp-2 text-sm font-semibold leading-snug {{ $entry->user?->isStoreManager() ? 'text-amber-700' : 'text-brand-secondary' }} sm:truncate {{ $canOpenProfile ? 'transition group-hover:text-brand-primary' : '' }}">{{ $entry->user?->name ?? $entry->seller_name }}</p>
                                        @if ($entry->user?->isStoreManager())
                                            <span class="{{ $storeManagerTooltipClasses }}">Jefe de tienda</span>
                                        @endif
                                    </span>
                                    <p class="truncate text-xs text-brand-secondary/60">{{ $homeLeaderboardSubtitle($entry) }}</p>
                                </div>
                                <img src="{{ $entry->user?->avatar_url ?? asset(\App\Models\User::DEFAULT_AVATAR_PATH) }}"
                                    alt="Avatar de {{ $entry->user?->name ?? $entry->seller_name }}"
                                    class="hidden h-12 w-12 rounded-xl object-cover ring-1 ring-brand-secondary/10 sm:block">
                                <div class="hidden text-right sm:block">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-secondary/45">Ventas</p>
                                    <p class="mt-1 text-2xl font-semibold text-brand-primary">
                                        {{ number_format((float) $entry->total_sales, 0, ',', '.') }}
                                    </p>
                                </div>
                            </article>
                            @if ($canOpenProfile)
                                </a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-5 flex flex-col items-center gap-2 sm:relative sm:block">
                <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                    Revista mensual
                </h2>

                <div
                    class="inline-flex rounded-full bg-brand-primary/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-brand-primary sm:absolute sm:right-0 sm:top-1/2 sm:-translate-y-1/2">
                    {{ $magazineTagLabel }}
                </div>

                <p class="text-center text-sm text-brand-secondary/70 sm:mt-2">
                    Consulta la última edición de la revista interna.
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-brand-secondary/10">
                <iframe src="{{ $magazineEmbedUrl }}" class="h-96 w-full sm:h-110" frameborder="0" allowfullscreen></iframe>
            </div>
        </section>

        @if ($canAccessVideos)
            <section class="mt-8 rounded-3xl border border-brand-secondary/10 bg-white p-6 shadow-sm">
            <div class="mb-6">
                <h2 class="text-center text-2xl font-bold tracking-tight text-brand-secondary">
                    Vídeos de formación
                </h2>

                <p class="mt-2 text-center text-brand-secondary/70">
                    Consulta aquí los vídeos destacados.
                </p>
            </div>

            <div class="grid gap-8 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($videos as $video)
                    <article class="overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-sm">
                        <div class="aspect-video">
                            <iframe class="h-full w-full"
                                src="https://www.youtube.com/embed/{{ $video['youtube_id'] }}"
                                title="{{ $video['title'] }}" frameborder="0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen></iframe>
                        </div>

                        <div class="px-4 py-3">
                            <h3 class="text-center text-sm font-semibold text-brand-secondary">
                                {{ $video['title'] }}
                            </h3>
                        </div>
                    </article>
                @endforeach
            </div>
            </section>
        @endif
    </main>
@endsection
