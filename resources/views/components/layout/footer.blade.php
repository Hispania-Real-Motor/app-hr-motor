@php
    $authUser = auth()->user();
    $footerPlatformItems = collect(config('navigation.footer.platform', []))
        ->filter(function (array $item) use ($authUser): bool {
            if (($item['route'] ?? null) === 'tools.informes' && ! app_can_access_hr_reports($authUser)) {
                return false;
            }

            if (($item['route'] ?? null) === 'videos' && ! app_can_access_videos()) {
                return false;
            }

            if (($item['route'] ?? null) === 'reviews.index' && ! app_can_access_reviews()) {
                return false;
            }

            if (($item['route'] ?? null) === 'curriculums.index' && ! app_can_access_curriculums()) {
                return false;
            }

            if (($item['route'] ?? null) === 'tickets.index' && ! app_can_see_tickets_navigation($authUser)) {
                return false;
            }

            if (in_array($item['route'] ?? null, ['leaderboard.sales', 'leaderboard.purchases', 'leaderboard.vehicles'], true) && ! app_can_access_rankings()) {
                return false;
            }

            if (! empty($item['children'])) {
                $item['children'] = collect($item['children'])
                    ->filter(function (array $child) use ($authUser): bool {
                        if (($child['route'] ?? null) === 'videos' && ! app_can_access_videos($authUser)) {
                            return false;
                        }

                        if (($child['route'] ?? null) === 'reviews.index' && ! app_can_access_reviews($authUser)) {
                            return false;
                        }

                        if (($child['route'] ?? null) === 'tools.informes' && ! app_can_access_hr_reports($authUser)) {
                            return false;
                        }

                        return true;
                    })
                    ->values()
                    ->all();

                if ($item['children'] === []) {
                    return false;
                }
            }

            return true;
        })
        ->values()
        ->all();
    $footerPlatformColumns = collect($footerPlatformItems)->chunk((int) ceil(max(count($footerPlatformItems), 1) / 2));
@endphp

<footer class="mt-16 bg-brand-secondary text-white">
    <div class="mx-auto max-w-7xl px-6 py-10 lg:px-8">
        <div class="flex flex-col gap-8 md:flex-row md:items-start md:justify-between">
            <div class="max-w-sm">
                <a href="{{ route('home') }}" class="inline-flex items-center">
                    <img src="{{ asset('images/logo-hr-white.svg') }}" alt="HR Motor" class="h-10 w-auto">
                </a>

                <p class="mt-4 text-sm leading-6 text-white/75">
                    Portal interno de recursos, herramientas y contenidos de apoyo para el equipo.
                </p>
            </div>

            <div class="grid gap-8 sm:grid-cols-2 lg:gap-16 xl:gap-24">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-white/90">
                        Plataforma
                    </h2>

                    <div class="mt-4 grid gap-3 text-sm text-white/75 sm:grid-cols-2 sm:gap-x-8 sm:gap-y-3">
                        @foreach ($footerPlatformColumns as $column)
                            <ul class="space-y-3">
                        @foreach ($column as $item)
                            <li>
                                @if (! empty($item['children']))
                                    <p class="font-semibold text-white/90">{{ $item['label'] }}</p>
                                    <ul class="mt-2 space-y-2 pl-3">
                                        @foreach ($item['children'] as $child)
                                            <li>
                                                <a href="{{ route($child['route']) }}" class="transition hover:text-white">
                                                    {{ $child['label'] }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <a href="{{ route($item['route']) }}" class="transition hover:text-white">
                                        {{ $item['label'] }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                        @endforeach
                    </div>
                </div>

                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.12em] text-white/90">
                        Soporte
                    </h2>

                    <ul class="mt-4 space-y-3 text-sm text-white/75">
                        <li>
                            <a href="{{ app_it_support_url_for() }}" class="transition hover:text-white">
                                Asistencia IT
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('legal') }}" class="transition hover:text-white">
                                Legal
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-8 border-t border-white/10 pt-6">
            <p class="text-sm text-white/60">
                © {{ now()->year }} HR Motor. Portal interno.
            </p>
        </div>
    </div>
</footer>
