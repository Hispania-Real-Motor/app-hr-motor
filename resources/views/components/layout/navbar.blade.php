@php
    $authUser = auth()->user();
    $navItems = collect(config('navigation.main', []))
        ->map(function (array $item) use ($authUser) {
            if (($item['route'] ?? null) === 'chat.beta' && ! app_can_access_chat_beta($authUser)) {
                return null;
            }

            if (($item['route'] ?? null) === 'curriculums.index' && ! app_can_access_curriculums($authUser)) {
                return null;
            }

            if (($item['route'] ?? null) === 'tickets.index' && ! app_can_see_tickets_navigation($authUser)) {
                return null;
            }

            if (($item['label'] ?? null) === 'Empresa') {
                $item['children'] = collect($item['children'] ?? [])
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

                        if (($child['route'] ?? null) === 'tickets.reports' && ! app_can_view_ticket_reports($authUser)) {
                            return false;
                        }

                        return true;
                    })
                    ->values()
                    ->all();

                if ($item['children'] === []) {
                    return null;
                }
            }

            if (($item['label'] ?? null) === 'Rankings' && ! empty($item['children'])) {
                $item['children'] = collect($item['children'])
                    ->filter(fn (array $child) => app_can_access_rankings($authUser) || ! in_array($child['route'] ?? null, ['leaderboard.sales', 'leaderboard.purchases', 'leaderboard.vehicles'], true))
                    ->values()
                    ->all();

                if ($item['children'] === []) {
                    return null;
                }
            }

            return $item;
        })
        ->filter()
        ->values()
        ->all();
    $visibleRole = app_visible_role($authUser);
    $visibleRoleLabel = app_visible_role_label($authUser);
    $roleViewerActive = app_role_viewer_active($authUser);
    $roleViewerOptions = app_role_viewer_options($authUser);
    $unreadNotifications = $authUser
        ? $authUser->unreadNotifications()->latest()->get()
        : collect();
    $unreadNotificationCount = $unreadNotifications->count();
@endphp
@php
    $navItemClass = 'inline-flex h-10 items-center whitespace-nowrap px-2 text-sm font-medium leading-none transition';
    $navItemActiveClass = 'text-brand-primary';
    $navItemInactiveClass = 'text-gray-700 hover:text-gray-900';
@endphp

<nav x-data="{ open: false, profileOpen: false, notificationsOpen: false, roleViewerOpen: false, activeDropdown: null }"
    x-effect="window.bodyScrollLock?.set('navbar', open || (roleViewerOpen && window.matchMedia('(max-width: 1279px)').matches))"
    @keydown.escape.window="profileOpen = false; notificationsOpen = false; roleViewerOpen = false; activeDropdown = null; open = false"
    class="sticky top-0 z-50 border-b border-gray-200 bg-white">
    <div class="mx-auto flex h-20 max-w-7xl items-center justify-between px-6 lg:px-8">
        <a href="{{ route('home') }}" class="flex shrink-0 items-center">
            <img src="{{ asset('images/logo-horizontal.svg') }}" alt="HR Motor" class="h-10 w-auto shrink-0">
        </a>

        <div class="flex min-w-0 items-center gap-2 md:gap-6">
            <div class="hidden min-w-0 items-center gap-8 xl:flex">
                @foreach (collect($navItems)->filter(fn (array $item) => empty($item['children'] ?? [])) as $item)
                    @php
                        $isItemActive = $item['route'] === 'agenda.index'
                            ? request()->routeIs('agenda.index', 'agenda.contacts.*')
                            : ($item['route'] === 'reviews.index'
                                ? request()->routeIs('reviews.*')
                                : ($item['route'] === 'tickets.index'
                                    ? request()->routeIs('tickets.*')
                                    : request()->routeIs($item['route'])));
                    @endphp
                    <a href="{{ route($item['route']) }}"
                        class="{{ $navItemClass }} {{ $isItemActive ? $navItemActiveClass : $navItemInactiveClass }}">
                        {{ $item['label'] }}
                    </a>
                @endforeach

                @foreach (collect($navItems)->filter(fn (array $item) => ! empty($item['children'] ?? [])) as $item)
                    @php
                        $dropdownKey = 'nav-' . \Illuminate\Support\Str::slug($item['label']);
                        $visibleChildren = collect($item['children'])
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

                                if (($child['route'] ?? null) === 'tickets.reports' && ! app_can_view_ticket_reports($authUser)) {
                                    return false;
                                }

                                return true;
                            })
                            ->values();
                        $isParentActive = $visibleChildren->contains(fn ($child) => request()->routeIs($child['route']));
                    @endphp
                    @if ($visibleChildren->isEmpty())
                        @continue
                    @endif
                    <div class="relative" @mouseenter="activeDropdown = '{{ $dropdownKey }}'" @mouseleave="activeDropdown = null">
                        <button type="button"
                            class="{{ $navItemClass }} gap-1.5 {{ $isParentActive ? $navItemActiveClass : $navItemInactiveClass }}"
                            :aria-expanded="(activeDropdown === '{{ $dropdownKey }}').toString()">
                            <span>{{ $item['label'] }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition"
                                :class="{ 'rotate-180': activeDropdown === '{{ $dropdownKey }}' }" fill="none" viewBox="0 0 24 24"
                                stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="activeDropdown === '{{ $dropdownKey }}'" x-cloak class="absolute left-0 top-full h-3 w-full"></div>

                        <div x-show="activeDropdown === '{{ $dropdownKey }}'" x-cloak x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute left-1/2 top-full mt-2 w-60 -translate-x-1/2 overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-xl">
                            <div class="p-2">
                                @foreach ($visibleChildren as $child)
                                    @php $isChildActive = request()->routeIs($child['route']); @endphp
                                    <a href="{{ route($child['route']) }}"
                                        class="block rounded-xl px-4 py-3 text-sm font-medium {{ $isChildActive ? 'text-brand-primary' : 'text-brand-secondary transition hover:text-brand-primary' }}">
                                        {!! $child['label'] !!}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach

                @auth
                    @if (app_user_can_access_admin_panel($authUser))
                        <a href="{{ url('/backoffice') }}"
                            class="{{ $navItemClass }} px-1 font-semibold {{ request()->is('backoffice*') ? $navItemActiveClass : $navItemInactiveClass }}">
                            Admin
                        </a>
                    @endif
                @endauth
            </div>

            @auth
                @if (app_role_viewer_enabled($authUser))
                    <button type="button" @click="roleViewerOpen = !roleViewerOpen; notificationsOpen = false; profileOpen = false"
                        class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-lg border {{ $roleViewerActive ? 'border-brand-primary/20 bg-brand-primary/5 text-brand-primary' : 'border-transparent text-gray-700 hover:bg-gray-100 hover:text-gray-900' }} transition xl:hidden"
                        aria-label="Abrir visor de roles" :aria-expanded="roleViewerOpen.toString()">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.0007 12C15.0007 13.6569 13.6576 15 12.0007 15C10.3439 15 9.00073 13.6569 9.00073 12C9.00073 10.3431 10.3439 9 12.0007 9C13.6576 9 15.0007 10.3431 15.0007 12Z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12.0012 5C7.52354 5 3.73326 7.94288 2.45898 12C3.73324 16.0571 7.52354 19 12.0012 19C16.4788 19 20.2691 16.0571 21.5434 12C20.2691 7.94291 16.4788 5 12.0012 5Z" />
                        </svg>
                    </button>

                    <div class="relative hidden shrink-0 xl:block" @click.outside="roleViewerOpen = false">
                        <button type="button" @click="roleViewerOpen = !roleViewerOpen; notificationsOpen = false; profileOpen = false"
                            class="inline-flex h-10 shrink-0 cursor-pointer items-center gap-2 rounded-lg border {{ $roleViewerActive ? 'border-brand-primary/20 bg-brand-primary/5 text-brand-primary' : 'border-transparent text-gray-700 hover:bg-gray-100 hover:text-gray-900' }} px-3 transition"
                            aria-label="Abrir visor de roles" :aria-expanded="roleViewerOpen.toString()">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M15.0007 12C15.0007 13.6569 13.6576 15 12.0007 15C10.3439 15 9.00073 13.6569 9.00073 12C9.00073 10.3431 10.3439 9 12.0007 9C13.6576 9 15.0007 10.3431 15.0007 12Z" />
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M12.0012 5C7.52354 5 3.73326 7.94288 2.45898 12C3.73324 16.0571 7.52354 19 12.0012 19C16.4788 19 20.2691 16.0571 21.5434 12C20.2691 7.94291 16.4788 5 12.0012 5Z" />
                            </svg>
                            <span class="hidden lg:inline text-sm font-medium">{{ $roleViewerActive ? $visibleRoleLabel : 'Visor' }}</span>
                        </button>

                        <div x-show="roleViewerOpen" x-cloak x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute right-0 top-full mt-3 flex max-h-[calc(100vh-6rem)] w-[min(24rem,calc(100vw-1rem))] flex-col overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-xl">
                            <div class="border-b border-brand-secondary/10 px-4 py-3">
                                <p class="text-sm font-semibold text-brand-secondary">Visor de roles</p>
                                <p class="mt-1 text-xs text-brand-secondary/60">
                                    Navega la app como otro rol con los permisos disponibles para tu perfil.
                                </p>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto p-2">
                                @foreach ($roleViewerOptions as $role => $label)
                                    <form method="POST" action="{{ route('role-viewer.store') }}">
                                        @csrf
                                        <input type="hidden" name="role" value="{{ $role }}">
                                        <button type="submit"
                                            class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium transition hover:bg-brand-secondary/5 {{ $visibleRole === $role ? 'text-brand-primary' : 'text-brand-secondary' }}">
                                            <span class="min-w-0 flex-1 break-words">{{ $label }}</span>
                                            @if ($visibleRole === $role)
                                                <span class="rounded-full bg-brand-primary/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-primary">Activa</span>
                                            @endif
                                        </button>
                                    </form>
                                @endforeach

                                @if ($roleViewerActive)
                                    <form method="POST" action="{{ route('role-viewer.destroy') }}" class="mt-1">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            class="flex w-full cursor-pointer items-center justify-between rounded-xl px-3 py-3 text-left text-sm font-semibold text-brand-secondary transition hover:bg-brand-secondary/5">
                                            <span>Volver a mi rol</span>
                                            <span class="text-xs text-brand-secondary/45">Reiniciar</span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <div class="relative shrink-0" @click.outside="notificationsOpen = false" data-notification-summary-url="{{ route('notifications.summary') }}">
                    <button type="button" @click="notificationsOpen = !notificationsOpen; profileOpen = false; if (notificationsOpen) { window.refreshNotifications?.({ allowBrowserNotifications: false }); }"
                        class="relative inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-lg text-gray-700 transition hover:bg-gray-100 hover:text-gray-900"
                        aria-label="Ver notificaciones" :aria-expanded="notificationsOpen.toString()">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0018 9.75v-.7V9a6 6 0 10-12 0v.05-.001v.701a8.967 8.967 0 00-2.312 6.022c1.733.64 3.561 1.083 5.454 1.31m5.715 0a24.255 24.255 0 01-5.715 0m5.715 0a3 3 0 11-5.715 0" />
                        </svg>

                        @if ($unreadNotificationCount > 0)
                            <span
                                class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-semibold text-white shadow-sm ring-2 ring-white"
                                data-notification-badge>
                                {{ $unreadNotificationCount > 9 ? '+9' : $unreadNotificationCount }}
                            </span>
                        @else
                            <span
                                class="absolute -right-1 -top-1 hidden h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-[11px] font-semibold text-white shadow-sm ring-2 ring-white"
                                data-notification-badge></span>
                        @endif
                    </button>

                    <div x-show="notificationsOpen" x-cloak x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-1"
                        class="absolute right-0 top-full mt-3 w-80 overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-xl sm:w-96">
                        <div class="flex items-center justify-between gap-3 border-b border-brand-secondary/10 px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-brand-secondary">Notificaciones</p>
                            </div>

                            <form method="POST" action="{{ route('notifications.destroy-unread') }}" class="shrink-0">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="inline-flex items-center rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $unreadNotificationCount > 0 ? 'text-red-600 hover:bg-red-50' : 'cursor-default text-brand-secondary/30' }} disabled:cursor-default disabled:hover:bg-transparent"
                                    {{ $unreadNotificationCount > 0 ? '' : 'disabled' }}
                                    aria-label="Borrar todas las notificaciones sin leer">
                                    Borrar todas
                                </button>
                            </form>
                        </div>

                        <div class="max-h-[28rem] overflow-y-auto p-2" data-notification-list>
                            @forelse ($unreadNotifications as $notification)
                                @php
                                    $isPriorityNotification = (bool) data_get($notification->data, 'priority', false);
                                    $notificationTitle = data_get($notification->data, 'title', data_get($notification->data, 'message', 'Notificación'));
                                    $notificationDescription = data_get($notification->data, 'description', data_get($notification->data, 'thread_title', ''));
                                    $notificationLinkLabel = data_get($notification->data, 'link_label', 'Abrir');
                                    $notificationLinkLabel = $notificationLinkLabel === 'Abrir enlace' ? 'Abrir' : $notificationLinkLabel;
                                    $notificationLinkUrl = data_get($notification->data, 'link_url', data_get($notification->data, 'thread_url'));
                                @endphp
                                <a href="{{ route('notifications.show', $notification->id) }}"
                                    data-notification-id="{{ $notification->id }}"
                                    data-notification-type="{{ data_get($notification->data, 'type', $notification->type) }}"
                                    class="block rounded-2xl px-3 py-3 transition {{ $isPriorityNotification ? 'mb-3 border border-amber-200 bg-amber-50/80 hover:bg-amber-100/80' : 'mb-1 hover:bg-brand-secondary/5' }} last:mb-0">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $isPriorityNotification ? 'bg-amber-500 text-white shadow-sm' : 'bg-brand-primary/10 text-brand-primary' }}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="block h-3.5 w-3.5 shrink-0"
                                                viewBox="0 0 24 24" fill="none">
                                                @if ($isPriorityNotification)
                                                    <path d="M9.15316 5.40838C10.4198 3.13613 11.0531 2 12 2C12.9469 2 13.5802 3.13612 14.8468 5.40837L15.1745 5.99623C15.5345 6.64193 15.7144 6.96479 15.9951 7.17781C16.2757 7.39083 16.6251 7.4699 17.3241 7.62805L17.9605 7.77203C20.4201 8.32856 21.65 8.60682 21.9426 9.54773C22.2352 10.4886 21.3968 11.4691 19.7199 13.4299L19.2861 13.9372C18.8096 14.4944 18.5713 14.773 18.4641 15.1177C18.357 15.4624 18.393 15.8341 18.465 16.5776L18.5306 17.2544C18.7841 19.8706 18.9109 21.1787 18.1449 21.7602C17.3788 22.3417 16.2273 21.8115 13.9243 20.7512L13.3285 20.4768C12.6741 20.1755 12.3469 20.0248 12 20.0248C11.6531 20.0248 11.3259 20.1755 10.6715 20.4768L10.0757 20.7512C7.77268 21.8115 6.62118 22.3417 5.85515 21.7602C5.08912 21.1787 5.21588 19.8706 5.4694 17.2544L5.53498 16.5776C5.60703 15.8341 5.64305 15.4624 5.53586 15.1177C5.42868 14.773 5.19043 14.4944 4.71392 13.9372L4.2801 13.4299C2.60325 11.4691 1.76482 10.4886 2.05742 9.54773C2.35002 8.60682 3.57986 8.32856 6.03954 7.77203L6.67589 7.62805C7.37485 7.4699 7.72433 7.39083 8.00494 7.17781C8.28555 6.96479 8.46553 6.64194 8.82547 5.99623L9.15316 5.40838Z" fill="currentColor"/>
                                                @else
                                                    <path d="M9.15316 5.40838C10.4198 3.13613 11.0531 2 12 2C12.9469 2 13.5802 3.13612 14.8468 5.40837L15.1745 5.99623C15.5345 6.64193 15.7144 6.96479 15.9951 7.17781C16.2757 7.39083 16.6251 7.4699 17.3241 7.62805L17.9605 7.77203C20.4201 8.32856 21.65 8.60682 21.9426 9.54773C22.2352 10.4886 21.3968 11.4691 19.7199 13.4299L19.2861 13.9372C18.8096 14.4944 18.5713 14.773 18.4641 15.1177C18.357 15.4624 18.393 15.8341 18.465 16.5776L18.5306 17.2544C18.7841 19.8706 18.9109 21.1787 18.1449 21.7602C17.3788 22.3417 16.2273 21.8115 13.9243 20.7512L13.3285 20.4768C12.6741 20.1755 12.3469 20.0248 12 20.0248C11.6531 20.0248 11.3259 20.1755 10.6715 20.4768L10.0757 20.7512C7.77268 21.8115 6.62118 22.3417 5.85515 21.7602C5.08912 21.1787 5.21588 19.8706 5.4694 17.2544L5.53498 16.5776C5.60703 15.8341 5.64305 15.4624 5.53586 15.1177C5.42868 14.773 5.19043 14.4944 4.71392 13.9372L4.2801 13.4299C2.60325 11.4691 1.76482 10.4886 2.05742 9.54773C2.35002 8.60682 3.57986 8.32856 6.03954 7.77203L6.67589 7.62805C7.37485 7.4699 7.72433 7.39083 8.00494 7.17781C8.28555 6.96479 8.46553 6.64194 8.82547 5.99623L9.15316 5.40838Z" stroke="currentColor" stroke-width="1.5"/>
                                                @endif
                                            </svg>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold {{ $isPriorityNotification ? 'text-amber-950' : 'text-brand-secondary' }}">
                                                        {{ $notificationTitle }}
                                                    </p>
                                                    @if (($notification->message_count ?? 1) > 1)
                                                        <span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none {{ $isPriorityNotification ? 'bg-amber-100 text-amber-700' : 'bg-brand-primary/10 text-brand-primary' }}">
                                                            {{ $notification->message_count > 9 ? '+9' : $notification->message_count }} mensajes
                                                        </span>
                                                    @endif
                                                </div>

                                                @if ($isPriorityNotification)
                                                    <span class="inline-flex shrink-0 rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-amber-700">
                                                        Prioritaria
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($notificationDescription)
                                                <p class="mt-1 text-sm {{ $isPriorityNotification ? 'text-amber-900/75' : 'text-brand-secondary/70' }}">
                                                    {{ $notificationDescription }}
                                                </p>
                                            @endif
                                            <p class="mt-1 text-xs text-brand-secondary/40">
                                                {{ $notification->created_at?->diffForHumans() }}
                                            </p>
                                            @if ($notificationLinkUrl)
                                                <span class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold {{ $isPriorityNotification ? 'text-amber-700' : 'text-brand-primary' }}">
                                                    {{ $notificationLinkLabel }}
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                                    </svg>
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @empty
                                <div class="rounded-2xl border border-dashed border-brand-secondary/10 bg-slate-50 px-4 py-6 text-center" data-notification-empty>
                                    <p class="text-sm font-semibold text-brand-secondary">Abre la campana para cargar tus notificaciones</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const root = document.querySelector('[data-notification-summary-url]');
                        const originalTitle = document.title;
                        const originalTitleBase = originalTitle.replace(/^\(\+?\d+\)\s*/u, '');
                        const faviconLink = document.querySelector('link[rel="icon"]');
                        const originalFaviconHref = faviconLink?.getAttribute('href') || @js(asset('favicon.ico'));
                        const notificationRealtimeConfig = @js(config('chat_realtime'));
                        const notificationCurrentUserId = @js((int) ($authUser?->id ?? 0));
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

                        if (!root) {
                            return;
                        }

                        const summaryUrl = root.dataset.notificationSummaryUrl;
                        const badges = Array.from(root.querySelectorAll('[data-notification-badge]'));
                        const list = root.querySelector('[data-notification-list]');
                        const knownNotificationIds = new Set(Array.from(list?.querySelectorAll('[data-notification-id]') ?? []).map((element) => String(element.dataset.notificationId || '')));
                        const knownBrowserNotificationIds = new Set();
                        let browserNotificationsSeeded = false;
                        let notificationSocket = null;
                        let notificationSocketId = '';
                        let notificationReconnectTimer = null;
                        let notificationReconnectAttempt = 0;
                        let notificationHandshakeTimer = null;
                        let notificationChannel = '';
                        let notificationDisconnecting = false;
                        let notificationRefreshTimer = null;
                        let notificationRefreshInFlight = false;
                        let notificationRefreshQueued = false;
                        const initialUnreadCount = @js((int) $unreadNotificationCount);
                        const emptyStateClass = 'rounded-2xl border border-dashed border-brand-secondary/10 bg-slate-50 px-4 py-6 text-center';
                        const defaultNotificationIconUrl = @js(asset('images/users/hrmotor-default-user-avatar.png'));

                        const escapeHtml = (value) => {
                            const span = document.createElement('span');
                            span.textContent = value ?? '';
                            return span.innerHTML;
                        };

                        const setPageTitleWithUnreadCount = (count) => {
                            if (!count || count <= 0) {
                                document.title = originalTitle;
                                return;
                            }

                            const label = count > 9 ? '+9' : String(count);
                            document.title = `(${label}) ${originalTitleBase}`;
                        };

                        const renderFaviconWithBadge = async (count) => {
                            if (!faviconLink) {
                                return;
                            }

                            if (!count || count <= 0) {
                                faviconLink.setAttribute('href', originalFaviconHref);
                                return;
                            }

                            try {
                                const size = 64;
                                const canvas = document.createElement('canvas');
                                const context = canvas.getContext('2d');

                                if (!context) {
                                    return;
                                }

                                canvas.width = size;
                                canvas.height = size;

                                const icon = new Image();
                                icon.crossOrigin = 'anonymous';

                                await new Promise((resolve, reject) => {
                                    icon.onload = resolve;
                                    icon.onerror = reject;
                                    icon.src = originalFaviconHref;
                                });

                                context.clearRect(0, 0, size, size);
                                context.drawImage(icon, 0, 0, size, size);

                                const badgeSize = 14;
                                const badgeX = 3;
                                const badgeY = 16;

                                context.fillStyle = '#1F2944';
                                context.beginPath();
                                context.arc(badgeX + badgeSize / 2, badgeY + badgeSize / 2, badgeSize / 2, 0, Math.PI * 2);
                                context.fill();

                                faviconLink.setAttribute('href', canvas.toDataURL('image/png'));
                            } catch (error) {
                                console.error(error);
                            }
                        };

                        const updateNotificationBadge = (count) => {
                            const nextCount = Math.max(0, Number(count) || 0);

                            if (!badges.length) {
                                return;
                            }

                            badges.forEach((badge) => {
                                badge.hidden = nextCount <= 0;
                                badge.setAttribute('aria-hidden', nextCount <= 0 ? 'true' : 'false');

                                if (nextCount > 0) {
                                    badge.textContent = nextCount > 9 ? '+9' : String(nextCount);
                                    badge.classList.remove('hidden');
                                } else {
                                    badge.textContent = '';
                                    badge.classList.add('hidden');
                                }
                            });

                            setPageTitleWithUnreadCount(nextCount);
                            void renderFaviconWithBadge(nextCount);
                        };

                        const buildNotificationWsUrl = () => {
                            if (!notificationRealtimeConfig.enabled || !notificationRealtimeConfig.app_key) {
                                return null;
                            }

                            const currentLocation = window.location;
                            const schemeSource = String(notificationRealtimeConfig.public_scheme || currentLocation.protocol || 'https:').toLowerCase();
                            const scheme = schemeSource === 'https:' || schemeSource === 'https' ? 'wss' : 'ws';
                            const host = String(notificationRealtimeConfig.public_host || currentLocation.hostname || '').trim();
                            const publicPort = String(notificationRealtimeConfig.public_port || '').trim();
                            const currentPort = String(currentLocation.port || '').trim();
                            const port = publicPort !== '' ? publicPort : currentPort;
                            const path = String(notificationRealtimeConfig.public_path || notificationRealtimeConfig.path || '').trim().replace(/\/$/, '');
                            const basePath = path !== '' ? path : '';
                            const shouldOmitPort = (scheme === 'wss' && port === '443') || (scheme === 'ws' && port === '80');

                            return `${scheme}://${host}${port !== '' && !shouldOmitPort ? `:${port}` : ''}${basePath}/app/${encodeURIComponent(String(notificationRealtimeConfig.app_key))}?protocol=7&client=js&version=1.0.0&flash=false`;
                        };

                        const buildNotificationChannelName = (userId) => {
                            const prefix = String(notificationRealtimeConfig.channel_prefix || 'private-');
                            return `${prefix}notifications.users.${userId}`;
                        };

                        const getNotificationRealtimeHeaders = () => {
                            if (!notificationSocketId) {
                                return {};
                            }

                            return {
                                'X-Socket-ID': notificationSocketId,
                            };
                        };

                        const disconnectNotificationRealtime = () => {
                            const hadSocket = Boolean(notificationSocket);
                            notificationDisconnecting = hadSocket;

                            if (notificationReconnectTimer) {
                                window.clearTimeout(notificationReconnectTimer);
                                notificationReconnectTimer = null;
                            }

                            if (notificationHandshakeTimer) {
                                window.clearTimeout(notificationHandshakeTimer);
                                notificationHandshakeTimer = null;
                            }

                            if (notificationSocket) {
                                try {
                                    notificationSocket.close();
                                } catch (error) {
                                    console.error(error);
                                }
                            }

                            notificationSocket = null;
                            notificationSocketId = '';
                            notificationChannel = '';

                            if (!hadSocket) {
                                notificationDisconnecting = false;
                            }
                        };

                        const subscribeNotificationChannel = async () => {
                            if (!notificationSocket || notificationSocket.readyState !== WebSocket.OPEN || !notificationCurrentUserId) {
                                return;
                            }

                            const channelName = buildNotificationChannelName(notificationCurrentUserId);

                            if (notificationChannel === channelName) {
                                return;
                            }

                            const authResponse = await fetch(String(notificationRealtimeConfig.auth_endpoint || '/broadcasting/auth'), {
                                method: 'POST',
                                headers: {
                                    ...getNotificationRealtimeHeaders(),
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': csrfToken,
                                    Accept: 'application/json',
                                },
                                body: new URLSearchParams({
                                    socket_id: notificationSocketId,
                                    channel_name: channelName,
                                }),
                            });

                            if (!authResponse.ok) {
                                return;
                            }

                            const authPayload = await authResponse.json().catch(() => ({}));
                            notificationChannel = channelName;
                            notificationSocket.send(JSON.stringify({
                                event: 'pusher:subscribe',
                                data: {
                                    channel: channelName,
                                    auth: authPayload.auth,
                                },
                            }));
                        };

                        const connectNotificationRealtime = () => {
                            if (!notificationCurrentUserId || !notificationRealtimeConfig.enabled || typeof WebSocket === 'undefined') {
                                return;
                            }

                            const wsUrl = buildNotificationWsUrl();

                            if (!wsUrl) {
                                return;
                            }

                            if (notificationSocket && (notificationSocket.readyState === WebSocket.OPEN || notificationSocket.readyState === WebSocket.CONNECTING)) {
                                return;
                            }

                            disconnectNotificationRealtime();

                            const socket = new WebSocket(wsUrl);
                            notificationSocket = socket;

                            if (notificationHandshakeTimer) {
                                window.clearTimeout(notificationHandshakeTimer);
                            }

                            notificationHandshakeTimer = window.setTimeout(() => {
                                if (notificationSocket === socket && !notificationSocketId) {
                                    disconnectNotificationRealtime();
                                }
                            }, 8000);

                            socket.addEventListener('message', async (event) => {
                                let payload;

                                try {
                                    payload = JSON.parse(event.data);
                                } catch (error) {
                                    return;
                                }

                                if (payload.event === 'pusher:connection_established') {
                                    let connectionData = payload.data;

                                    if (typeof connectionData === 'string') {
                                        try {
                                            connectionData = JSON.parse(connectionData);
                                        } catch (error) {
                                            connectionData = {};
                                        }
                                    }

                                    if (notificationHandshakeTimer) {
                                        window.clearTimeout(notificationHandshakeTimer);
                                        notificationHandshakeTimer = null;
                                    }

                                    notificationSocketId = String(connectionData?.socket_id || '');
                                    notificationReconnectAttempt = 0;
                                    await subscribeNotificationChannel();
                                    return;
                                }

                                if (payload.event === 'pusher:ping') {
                                    socket.send(JSON.stringify({
                                        event: 'pusher:pong',
                                        data: {},
                                    }));
                                    return;
                                }

                                const eventData = typeof payload.data === 'string'
                                    ? (() => {
                                        try {
                                            return JSON.parse(payload.data);
                                        } catch (error) {
                                            return payload.data;
                                        }
                                    })()
                                    : payload.data;

                                if (payload.event === 'notifications.badge.updated') {
                                    updateNotificationBadge(eventData?.count ?? 0);
                                    if (notificationRefreshTimer) {
                                        window.clearTimeout(notificationRefreshTimer);
                                    }

                                    notificationRefreshTimer = window.setTimeout(() => {
                                        notificationRefreshTimer = null;
                                        void refreshNotifications({ allowBrowserNotifications: true });
                                    }, 75);
                                }
                            });

                            socket.addEventListener('close', () => {
                                if (notificationHandshakeTimer) {
                                    window.clearTimeout(notificationHandshakeTimer);
                                    notificationHandshakeTimer = null;
                                }

                                notificationSocket = null;
                                notificationSocketId = '';
                                notificationChannel = '';

                                if (notificationDisconnecting) {
                                    notificationDisconnecting = false;
                                    return;
                                }

                                if (!notificationRealtimeConfig.enabled) {
                                    return;
                                }

                                const delay = Math.min(15000, 1000 * (notificationReconnectAttempt + 1));
                                notificationReconnectAttempt += 1;

                                if (notificationReconnectTimer) {
                                    window.clearTimeout(notificationReconnectTimer);
                                }

                                notificationReconnectTimer = window.setTimeout(() => {
                                    notificationReconnectTimer = null;
                                    connectNotificationRealtime();
                                }, delay);
                            });

                            socket.addEventListener('error', () => {
                                try {
                                    socket.close();
                                } catch (error) {
                                    console.error(error);
                                }
                            });
                        };

                        const isChatBrowserNotificationSupported = () => {
                            return 'Notification' in window && window.isSecureContext;
                        };

                        const getActiveChatConversationId = () => {
                            const value = window.chatActiveConversationId;
                            const conversationId = Number(value || 0);

                            return Number.isInteger(conversationId) && conversationId > 0 ? conversationId : 0;
                        };

                        const shouldSuppressChatBrowserNotification = (notification) => {
                            const notificationType = String(notification.type || '');

                            if (!notificationType.startsWith('chat.message.received')) {
                                return false;
                            }

                            const notificationConversationId = Number(notification.conversation_id || 0);

                            if (!notificationConversationId) {
                                return false;
                            }

                            return notificationConversationId === getActiveChatConversationId();
                        };

                        const requestBrowserNotificationPermission = async () => {
                            if (!isChatBrowserNotificationSupported() || Notification.permission !== 'default') {
                                return;
                            }

                            try {
                                await Notification.requestPermission();
                            } catch (error) {
                                console.error(error);
                            }
                        };

                        const showBrowserNotification = (notification) => {
                            if (!isChatBrowserNotificationSupported() || Notification.permission !== 'granted') {
                                return;
                            }

                            const notificationType = String(notification.type || '');

                            if (!notificationType.startsWith('chat.message.received') && !notificationType.startsWith('it-ticket.')) {
                                return;
                            }

                            if (shouldSuppressChatBrowserNotification(notification)) {
                                return;
                            }

                            const browserNotification = new Notification(notification.title ?? 'Nuevo mensaje', {
                                body: notification.description ?? '',
                                icon: notification.actor_avatar_url || defaultNotificationIconUrl,
                                tag: `chat-${notification.id}`,
                                renotify: true,
                            });

                            browserNotification.onclick = (event) => {
                                event.preventDefault();

                                const targetUrl = notification.link_url || '/notificaciones/' + notification.id;

                                window.focus();
                                window.location.href = targetUrl;
                            };
                        };

                        const renderNotification = (notification) => {
                            const priorityClass = notification.priority
                                ? 'mb-3 border border-amber-200 bg-amber-50/80 hover:bg-amber-100/80'
                                : 'mb-1 hover:bg-brand-secondary/5';
                            const iconClass = notification.priority ? 'bg-amber-500 text-white shadow-sm' : 'bg-brand-primary/10 text-brand-primary';
                            const titleClass = notification.priority ? 'text-amber-950' : 'text-brand-secondary';
                            const descriptionClass = notification.priority ? 'text-amber-900/75' : 'text-brand-secondary/70';
                            const timeClass = 'text-brand-secondary/40';
                            const linkClass = notification.priority ? 'text-amber-700' : 'text-brand-primary';
                            const linkLabel = escapeHtml(notification.link_label ?? 'Abrir');
                            const groupedCount = Number(notification.message_count || 1);
                            const groupedCountHtml = groupedCount > 1
                                ? `<span class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold leading-none ${notification.priority ? 'bg-amber-100 text-amber-700' : 'bg-brand-primary/10 text-brand-primary'}">${groupedCount > 9 ? '+9' : groupedCount} mensajes</span>`
                                : '';

                            return `
                                <a href="/notificaciones/${notification.id}"
                                    data-notification-id="${escapeHtml(notification.id)}"
                                    data-notification-type="${escapeHtml(notification.type ?? '')}"
                                    class="block rounded-2xl px-3 py-3 transition ${priorityClass}">
                                    <div class="flex items-start gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${iconClass}">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="block h-3.5 w-3.5 shrink-0"
                                                viewBox="0 0 24 24" fill="none">
                                                <path d="M9.15316 5.40838C10.4198 3.13613 11.0531 2 12 2C12.9469 2 13.5802 3.13612 14.8468 5.40837L15.1745 5.99623C15.5345 6.64193 15.7144 6.96479 15.9951 7.17781C16.2757 7.39083 16.6251 7.4699 17.3241 7.62805L17.9605 7.77203C20.4201 8.32856 21.65 8.60682 21.9426 9.54773C22.2352 10.4886 21.3968 11.4691 19.7199 13.4299L19.2861 13.9372C18.8096 14.4944 18.5713 14.773 18.4641 15.1177C18.357 15.4624 18.393 15.8341 18.465 16.5776L18.5306 17.2544C18.7841 19.8706 18.9109 21.1787 18.1449 21.7602C17.3788 22.3417 16.2273 21.8115 13.9243 20.7512L13.3285 20.4768C12.6741 20.1755 12.3469 20.0248 12 20.0248C11.6531 20.0248 11.3259 20.1755 10.6715 20.4768L10.0757 20.7512C7.77268 21.8115 6.62118 22.3417 5.85515 21.7602C5.08912 21.1787 5.21588 19.8706 5.4694 17.2544L5.53498 16.5776C5.60703 15.8341 5.64305 15.4624 5.53586 15.1177C5.42868 14.773 5.19043 14.4944 4.71392 13.9372L4.2801 13.4299C2.60325 11.4691 1.76482 10.4886 2.05742 9.54773C2.35002 8.60682 3.57986 8.32856 6.03954 7.77203L6.67589 7.62805C7.37485 7.4699 7.72433 7.39083 8.00494 7.17781C8.28555 6.96479 8.46553 6.64194 8.82547 5.99623L9.15316 5.40838Z" fill="currentColor"/>
                                            </svg>
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold ${titleClass}">${escapeHtml(notification.title)}</p>
                                                    ${groupedCountHtml}
                                                </div>
                                                ${notification.priority ? '<span class="inline-flex shrink-0 rounded-full bg-amber-100 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-amber-700">Prioritaria</span>' : ''}
                                            </div>
                                            ${notification.description ? `<p class="mt-1 text-sm ${descriptionClass}">${escapeHtml(notification.description)}</p>` : ''}
                                            <p class="mt-1 text-xs ${timeClass}">${escapeHtml(notification.created_at_label ?? '')}</p>
                                            ${notification.link_url ? `<span class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold ${linkClass}">${linkLabel}<svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg></span>` : ''}
                                        </div>
                                    </div>
                                </a>
                            `;
                        };

                        const renderNotificationLoadingState = () => {
                            return `
                                <div class="rounded-2xl border border-dashed border-brand-secondary/10 bg-slate-50 px-4 py-4" data-notification-loading>
                                    <div class="space-y-3 animate-pulse">
                                        <div class="h-4 w-28 rounded-full bg-slate-200"></div>
                                        <div class="h-3 w-full rounded-full bg-slate-200"></div>
                                        <div class="h-3 w-4/5 rounded-full bg-slate-200"></div>
                                        <div class="flex items-start gap-3 pt-2">
                                            <div class="h-9 w-9 rounded-full bg-slate-200"></div>
                                            <div class="min-w-0 flex-1 space-y-2">
                                                <div class="h-3 w-1/2 rounded-full bg-slate-200"></div>
                                                <div class="h-3 w-3/4 rounded-full bg-slate-200"></div>
                                                <div class="h-3 w-2/3 rounded-full bg-slate-200"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;
                        };

                        const refreshNotifications = async ({ allowBrowserNotifications = false } = {}) => {
                            if (notificationRefreshInFlight) {
                                notificationRefreshQueued = true;
                                return;
                            }

                            notificationRefreshInFlight = true;

                            try {
                                const showLoadingState = !list || list.dataset.notificationState !== 'empty';

                                if (list && showLoadingState) {
                                    list.innerHTML = renderNotificationLoadingState();
                                }

                                const response = await fetch(summaryUrl, {
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest',
                                        Accept: 'application/json',
                                    },
                                });

                                if (!response.ok) {
                                    return;
                                }

                                const payload = await response.json();
                                const count = Number(payload.unread_count ?? payload.count ?? 0);
                                const nextNotifications = Array.isArray(payload.notifications) ? payload.notifications : [];
                                const rawNotifications = Array.isArray(payload.raw_notifications) ? payload.raw_notifications : [];
                                const nextNotificationIds = new Set(nextNotifications.map((notification) => String(notification.id || '')));

                                if (!browserNotificationsSeeded) {
                                    rawNotifications.forEach((notification) => {
                                        const notificationId = String(notification.id || '');

                                        if (notificationId) {
                                            knownBrowserNotificationIds.add(notificationId);
                                        }
                                    });

                                    browserNotificationsSeeded = true;
                                } else if (allowBrowserNotifications) {
                                    rawNotifications.forEach((notification) => {
                                        const notificationId = String(notification.id || '');

                                        if (!notificationId || knownBrowserNotificationIds.has(notificationId)) {
                                            return;
                                        }

                                        if (shouldSuppressChatBrowserNotification(notification)) {
                                            knownBrowserNotificationIds.add(notificationId);
                                            return;
                                        }

                                        showBrowserNotification(notification);
                                        knownBrowserNotificationIds.add(notificationId);
                                    });
                                }

                                if (allowBrowserNotifications) {
                                    nextNotifications.forEach((notification) => {
                                        const notificationId = String(notification.id || '');

                                        if (!notificationId || knownNotificationIds.has(notificationId)) {
                                            return;
                                        }

                                        if (shouldSuppressChatBrowserNotification(notification)) {
                                            return;
                                        }

                                        showBrowserNotification(notification);
                                    });
                                }

                                updateNotificationBadge(count);

                                if (list) {
                                    if (nextNotifications.length === 0) {
                                        list.innerHTML = `<div class="${emptyStateClass}"><p class="text-sm font-semibold text-brand-secondary">No tienes notificaciones pendientes</p></div>`;
                                        list.dataset.notificationState = 'empty';
                                    } else {
                                        list.innerHTML = nextNotifications.map(renderNotification).join('');
                                        list.dataset.notificationState = 'loaded';
                                    }
                                }

                                nextNotificationIds.forEach((notificationId) => knownNotificationIds.add(notificationId));
                            } catch (error) {
                                console.error(error);
                            } finally {
                                notificationRefreshInFlight = false;

                                if (notificationRefreshQueued) {
                                    notificationRefreshQueued = false;
                                    void refreshNotifications();
                                }
                            }
                        };

                        window.refreshNotifications = refreshNotifications;
                        window.updateNotificationBadge = updateNotificationBadge;

                        void requestBrowserNotificationPermission();
                        window.addEventListener('pointerdown', requestBrowserNotificationPermission, { once: true });
                        updateNotificationBadge(initialUnreadCount);
                        connectNotificationRealtime();
                    });
                </script>

                <div class="relative hidden shrink-0 xl:block" @click.outside="profileOpen = false">
                    <button type="button" @click="profileOpen = !profileOpen"
                        class="flex shrink-0 cursor-pointer items-center rounded-full bg-white/90 p-0.5 shadow-sm ring-1 ring-black/5 transition hover:-translate-y-0.5 hover:shadow-md"
                        aria-label="Abrir menu de perfil" :aria-expanded="profileOpen.toString()">
                        <img src="{{ auth()->user()->avatar_url }}" alt="Avatar de {{ auth()->user()->name }}"
                            class="h-10 w-10 shrink-0 rounded-full object-cover">
                    </button>

                    <div x-show="profileOpen" x-cloak x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0"
                        x-transition:leave-end="opacity-0 translate-y-1"
                        class="absolute right-0 mt-3 w-60 overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-xl">
                        <a href="{{ route('profile.show') }}" @click="profileOpen = false"
                            class="block border-b border-brand-secondary/10 px-4 py-3 transition hover:bg-brand-secondary/5">
                            <p class="text-sm font-semibold text-brand-secondary">{{ auth()->user()->name }}</p>
                            <p class="mt-1 text-xs text-brand-secondary/60">{{ auth()->user()->email }}</p>
                        </a>

                        <div class="p-2">
                            <a href="{{ route('profile.edit') }}" @click="profileOpen = false"
                                class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium text-brand-secondary transition hover:bg-brand-secondary/5">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-brand-primary" fill="none"
                                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M15.75 6.75a3 3 0 11-6 0 3 3 0 016 0zm-10.5 12a7.5 7.5 0 1115 0" />
                                </svg>
                                <span>Modificar perfil</span>
                            </a>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                    class="flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-red-600 transition hover:bg-red-50">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15" />
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M18 12H9m0 0l3-3m-3 3l3 3" />
                                    </svg>
                                    <span>Cerrar sesion</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endauth

            <button type="button" @click="open = !open"
                class="inline-flex shrink-0 items-center justify-center rounded-lg p-2 text-gray-700 transition hover:bg-gray-100 hover:text-gray-900 xl:hidden"
                aria-label="Abrir menu de navegacion" :aria-expanded="open.toString()">
                <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>

                <svg x-show="open" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                    viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6l-12 12" />
                </svg>
            </button>
        </div>
    </div>

    @auth
        @if (app_role_viewer_enabled($authUser))
            <div x-show="roleViewerOpen" x-cloak x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-1" class="w-full overflow-x-hidden border-t border-gray-200 bg-white xl:hidden">
                <div class="mx-auto max-h-[calc(100dvh-5rem)] max-w-7xl overflow-y-auto overscroll-contain px-6 py-4 lg:px-8">
                    <p class="text-xs font-semibold uppercase tracking-[0.12em] text-brand-secondary/60">
                        Visor de roles
                    </p>
                    <p class="mt-1 text-sm text-brand-secondary/60">
                        Navega la app como otro rol con los permisos disponibles para tu perfil.
                    </p>

                    <div class="mt-3 space-y-1">
                        @foreach ($roleViewerOptions as $role => $label)
                            <form method="POST" action="{{ route('role-viewer.store') }}">
                                @csrf
                                <input type="hidden" name="role" value="{{ $role }}">
                                <button type="submit" @click="roleViewerOpen = false"
                                    class="flex w-full items-center justify-between rounded-lg px-3 py-3 text-left text-sm font-medium transition {{ $visibleRole === $role ? 'bg-brand-primary/5 text-brand-primary' : 'text-gray-700 hover:bg-gray-100' }}">
                                    <span class="min-w-0 flex-1 break-words">{{ $label }}</span>
                                    @if ($visibleRole === $role)
                                        <span class="ml-3 rounded-full bg-brand-primary/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-primary">Activa</span>
                                    @endif
                                </button>
                            </form>
                        @endforeach

                        @if ($roleViewerActive)
                            <form method="POST" action="{{ route('role-viewer.destroy') }}" class="pt-1">
                                @csrf
                                @method('DELETE')
                                <button type="submit" @click="roleViewerOpen = false"
                                    class="flex w-full items-center justify-between rounded-lg px-3 py-3 text-left text-sm font-semibold text-gray-700 transition hover:bg-gray-100">
                                <span>Volver a mi rol</span>
                                    <span class="ml-3 text-xs text-gray-400">Reiniciar</span>
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @endauth

    <div x-show="open" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-1" x-cloak
        class="fixed inset-x-0 top-[5rem] z-50 max-h-[calc(100dvh-5rem)] overflow-y-auto border-t border-gray-200 bg-white shadow-lg xl:hidden">
        <div class="mx-auto max-w-7xl px-6 py-4 lg:px-8">
            @auth
                <a href="{{ route('profile.show') }}" @click="open = false"
                    class="mb-3 flex items-center gap-3 rounded-2xl border border-brand-secondary/10 bg-slate-50 px-3 py-3 text-sm font-medium text-brand-secondary transition hover:bg-brand-secondary/5">
                    <img src="{{ auth()->user()->avatar_url }}" alt="Avatar de {{ auth()->user()->name }}"
                        class="h-11 w-11 rounded-full object-cover ring-1 ring-brand-secondary/10">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-brand-secondary/60">Ver perfil</p>
                    </div>
                </a>
            @endauth

            @foreach ($navItems as $item)
                @if (! empty($item['children']))
                    @php
                        $visibleChildren = collect($item['children'])
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
                            ->values();
                    @endphp
                    @if ($visibleChildren->isEmpty())
                        @continue
                    @endif
                    @foreach ($visibleChildren as $child)
                        @php $isChildActive = request()->routeIs($child['route']); @endphp
                        <a href="{{ route($child['route']) }}" @click="open = false"
                            class="block rounded-lg px-3 py-2 text-sm font-medium transition {{ $isChildActive ? 'text-brand-primary' : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900' }}">
                            {!! $child['label'] !!}
                        </a>
                    @endforeach
                @else
                    @php
                        $isItemActive = $item['route'] === 'agenda.index'
                            ? request()->routeIs('agenda.index', 'agenda.contacts.*')
                            : ($item['route'] === 'reviews.index'
                                ? request()->routeIs('reviews.*')
                                : ($item['route'] === 'tickets.index'
                                    ? request()->routeIs('tickets.*')
                                    : request()->routeIs($item['route'])));
                    @endphp
                    <a href="{{ route($item['route']) }}" @click="open = false"
                        class="block rounded-lg px-3 py-2 text-sm font-medium transition {{ $isItemActive ? 'text-brand-primary' : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900' }}">
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach

            @auth
                @if (app_user_can_access_admin_panel($authUser))
                    <a href="{{ url('/backoffice') }}" @click="open = false"
                        class="mt-2 block rounded-lg px-3 py-2 text-sm font-medium {{ request()->is('backoffice*') ? 'text-brand-primary' : 'text-gray-700 transition hover:bg-gray-100 hover:text-gray-900' }}">
                        Admin
                    </a>
                @endif
            @endauth

            <form method="POST" action="{{ route('logout') }}" class="mt-2 border-t border-gray-200 pt-2">
                @csrf
                <button type="submit" @click="open = false"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 hover:text-red-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-7.5a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 006 21h7.5a2.25 2.25 0 002.25-2.25V15" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 12H9m0 0l3-3m-3 3l3 3" />
                    </svg>
                    <span>Cerrar sesion</span>
                </button>
            </form>
        </div>
    </div>
</nav>
