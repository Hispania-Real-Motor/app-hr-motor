@extends('layouts.chat-shell')

@section('content')
    @php
        $authUser = auth()->user();
        $selectedParticipant = $selectedConversation?->otherParticipant($authUser);
        $selectedConversationIsGroup = $selectedConversation?->isGroupConversation() ?? false;
        $selectedConversationGroup = $selectedConversationIsGroup ? $selectedConversation?->chatGroup : null;
        $selectedConversationGroupAvatarUrl = $selectedConversationGroup?->avatar_url;
        $selectedConversationMessages = $selectedConversation?->messages ?? collect();
        $favoriteUserIds = $favoriteUserIds ?? [];
        $selectedParticipantIsFavorite = $selectedParticipant?->id ? in_array($selectedParticipant->id, $favoriteUserIds, true) : false;
        $selectedParticipantChatRoleLabel = $selectedParticipant?->chat_role_label ?? '';
        $selectedParticipantDealershipName = $selectedParticipant?->resolved_dealership_name ?: 'Sin delegación';
        $selectedParticipantIsDisabled = $selectedParticipant?->isDisabled() ?? false;
        $selectedConversationIsDisabled = ! $selectedConversationIsGroup && $selectedParticipantIsDisabled;
        $composerDisabled = $selectedParticipantIsDisabled && ! $selectedConversationIsGroup;
        $selectedParticipantAvatarUrl = $selectedParticipant?->avatar_url ?? asset('images/users/hrmotor-default-user-avatar.png');
        $selectedParticipantName = $selectedParticipant?->name ?? 'Usuario';
        $selectedParticipantProfileUrl = $selectedParticipant ? route('users.show', $selectedParticipant) : '#';
        $selectedParticipantFavoriteToggleUrl = $selectedParticipant ? route('chat.beta.favorites.toggle', $selectedParticipant) : '#';
        $policyAccepted = $policyAccepted ?? true;
        $chatUnreadTotal = (int) ($conversations->sum('unread_messages_count') ?? 0);
        $groupUnreadTotal = (int) ($chatGroups->sum(fn ($chatGroup) => (int) ($chatGroup->conversation?->unread_messages_count ?? 0)) ?? 0);
        $chatUnreadBadgeLabel = $chatUnreadTotal > 9 ? '+9' : (string) $chatUnreadTotal;
        $groupUnreadBadgeLabel = $groupUnreadTotal > 9 ? '+9' : (string) $groupUnreadTotal;
        $chatMessagesPageSize = 24;
        $chatMessagesSyncOverlap = 16;
    @endphp
    <script>
        window.chatInitialConversationIsGroup = @js($selectedConversationIsGroup);
        window.chatInitialConversationIsDisabled = @js($selectedConversationIsDisabled);
        window.chatCurrentUserId = @js($authUser->id);
        window.chatActiveConversationId = @js($selectedConversation?->id ?? null);
        window.chatInitialConversationParticipants = @js($selectedConversationIsGroup && $selectedConversationGroup ? $selectedConversationGroup->participants->map(function ($participant) {
            return [
                'id' => $participant->id,
                'name' => $participant->name,
                'profile_url' => route('users.show', $participant),
                'avatar_url' => $participant->avatar_url,
                'resolved_dealership_name' => $participant->resolved_dealership_name,
                'extra_role_label' => $participant->extra_role ? (\App\Models\User::extraRoleLabels()[$participant->extra_role] ?? ucfirst((string) $participant->extra_role)) : null,
                'chat_role_label' => $participant->chat_role_label,
                'is_disabled' => $participant->isDisabled(),
            ];
        })->values()->all() : []);
        window.chatInitialGroupModalData = @js($selectedConversationIsGroup && $selectedConversationGroup ? [
            'conversation_name' => $selectedConversationGroup->name,
            'conversation_avatar_url' => $selectedConversationGroup->avatar_url,
            'conversation_system_group_type' => $selectedConversationGroup->system_group_type,
            'conversation_participants' => $selectedConversationGroup->participants->map(function ($participant) {
                return [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'profile_url' => route('users.show', $participant),
                    'avatar_url' => $participant->avatar_url,
                    'resolved_dealership_name' => $participant->resolved_dealership_name,
                    'extra_role_label' => $participant->extra_role ? (\App\Models\User::extraRoleLabels()[$participant->extra_role] ?? ucfirst((string) $participant->extra_role)) : null,
                ];
            })->values()->all(),
        ] : null);
        window.chatRealtimeConfig = @js(config('chat_realtime'));
    </script>

    @php
        $privateConversations = $conversations->filter(fn ($conversation) => ! $conversation->isGroupConversation())->values();
        $chatUnreadTotal = (int) ($privateConversations->sum('unread_messages_count') ?? 0);
    @endphp

        <section
            x-data="imageLightbox()"
            x-effect="document.body.classList.toggle('overflow-hidden', isImageOpen)"
            @keydown.escape.window="closeImage()"
            @keydown.window="handleKeydown($event)"
            @open-image.window="openImage($event.detail)"
            class="flex min-h-0 flex-1 w-full overflow-hidden bg-slate-100"
            data-chat-root
            data-chat-summary-url="{{ route('chat.beta.summary') }}"
            data-selected-conversation-id="{{ $selectedConversation?->id ?? '' }}"
            data-chat-selected-conversation-is-disabled="{{ $selectedConversationIsDisabled ? '1' : '0' }}"
            data-chat-composer-disabled="{{ $composerDisabled ? '1' : '0' }}"
        >
        <div class="fixed inset-0 top-[calc(5rem+1px)] z-40 hidden bg-slate-950/20 md:hidden" data-chat-mobile-sidebar-backdrop onclick="window.chatToggleMobileSidebar?.(false)"></div>
        <aside class="fixed left-0 top-[calc(5rem+1px)] z-50 flex h-[calc(100dvh-5rem-1px)] w-[21rem] max-w-[85vw] -translate-x-full overflow-hidden border-r border-slate-200 bg-white shadow-[12px_0_40px_rgba(15,23,42,0.04)] will-change-transform transform-gpu transition-[width,min-width,max-width,transform] duration-300 ease-in-out md:static md:z-auto md:h-full md:max-w-[21rem] md:translate-x-0" data-chat-sidebar>
            <div class="relative h-full w-full">
            <div data-chat-sidebar-expanded-shell class="absolute inset-0 flex h-full flex-col opacity-100 translate-x-0 pointer-events-auto transition-all duration-300 ease-in-out">
            <div class="flex min-h-[4.75rem] items-center border-b border-slate-200 px-4 py-2">
                <div class="flex w-full items-center gap-2">
                        <button type="button" data-chat-sidebar-tab="favorites" aria-pressed="false"
                            class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-2xl border border-transparent text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary"
                            aria-label="Abrir favoritos">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M11.245 4.174C11.4765 3.50808 11.5922 3.17513 11.7634 3.08285C11.9115 3.00298 12.0898 3.00298 12.238 3.08285C12.4091 3.17513 12.5248 3.50808 12.7563 4.174L14.2866 8.57639C14.3525 8.76592 14.3854 8.86068 14.4448 8.93125C14.4972 8.99359 14.5641 9.04218 14.6396 9.07278C14.725 9.10743 14.8253 9.10947 15.0259 9.11356L19.6857 9.20852C20.3906 9.22288 20.743 9.23007 20.8837 9.36432C21.0054 9.48051 21.0605 9.65014 21.0303 9.81569C20.9955 10.007 20.7146 10.2199 20.1528 10.6459L16.4387 13.4616C16.2788 13.5829 16.1989 13.6435 16.1501 13.7217C16.107 13.7909 16.0815 13.8695 16.0757 13.9507C16.0692 14.0427 16.0982 14.1387 16.1563 14.3308L17.506 18.7919C17.7101 19.4667 17.8122 19.8041 17.728 19.9793C17.6551 20.131 17.5108 20.2358 17.344 20.2583C17.1513 20.2842 16.862 20.0829 16.2833 19.6802L12.4576 17.0181C12.2929 16.9035 12.2106 16.8462 12.1211 16.8239C12.042 16.8043 11.9593 16.8043 11.8803 16.8239C11.7908 16.8462 11.7084 16.9035 11.5437 17.0181L7.71805 19.6802C7.13937 20.0829 6.85003 20.2842 6.65733 20.2583C6.49056 20.2358 6.34626 20.131 6.27337 19.9793C6.18915 19.8041 6.29123 19.4667 6.49538 18.7919L7.84503 14.3308C7.90313 14.1387 7.93218 14.0427 7.92564 13.9507C7.91986 13.8695 7.89432 13.7909 7.85123 13.7217C7.80246 13.6435 7.72251 13.5829 7.56262 13.4616L3.84858 10.6459C3.28678 10.2199 3.00588 10.007 2.97101 9.81569C2.94082 9.65014 2.99594 9.48051 3.11767 9.36432C3.25831 9.23007 3.61074 9.22289 4.31559 9.20852L8.9754 9.11356C9.176 9.10947 9.27631 9.10743 9.36177 9.07278C9.43726 9.04218 9.50414 8.99359 9.55657 8.93125C9.61593 8.86068 9.64887 8.76592 9.71475 8.57639L11.245 4.174Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>

                    <form method="GET" action="{{ route('chat.beta') }}" class="relative flex-1">
                        <div class="relative flex items-center gap-2">
                            <div class="relative min-w-0 flex-1">
                                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="7"></circle>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m20 20-3.5-3.5"></path>
                                    </svg>
                                </span>
                                <input type="text" name="search" value="{{ $search }}"
                                    placeholder="Buscar..."
                                    data-chat-search-input
                                    class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-9 pr-4 text-sm text-brand-secondary outline-none transition placeholder:text-slate-400 focus:border-brand-primary focus:bg-white focus:ring-4 focus:ring-brand-primary/10">
                            </div>

                            <button
                                type="button"
                                class="hidden h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary md:inline-flex"
                                aria-label="Contraer panel lateral"
                                aria-expanded="true"
                                data-chat-sidebar-collapse-button
                                onclick="window.chatSetSidebarCollapsed?.(true)"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6"></path>
                                </svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="flex-1 min-h-0 overflow-y-auto">
                <div class="border-b border-slate-200 px-4 py-3">
                    <div class="grid grid-cols-3 rounded-2xl bg-slate-100 p-1 text-xs font-semibold">
                        <button type="button" data-chat-sidebar-tab="chats" aria-pressed="true"
                            class="relative inline-flex cursor-pointer items-center justify-center rounded-xl bg-brand-primary px-3 py-2 text-white shadow-sm transition hover:bg-brand-primary/95">
                            Chats
                            <span data-chat-tab-badge="chats" class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-[#1E90FF] px-1.5 py-0.5 text-[11px] font-semibold text-white" style="{{ $chatUnreadTotal > 0 ? '' : 'display:none;' }}">
                                {{ $chatUnreadBadgeLabel }}
                            </span>
                        </button>
                        <button type="button" data-chat-sidebar-tab="team" aria-pressed="false"
                            class="inline-flex cursor-pointer items-center justify-center rounded-xl px-3 py-2 text-slate-500 transition hover:bg-slate-100">
                            Equipo
                        </button>
                        <button type="button" data-chat-sidebar-tab="groups" aria-pressed="false"
                            class="relative inline-flex cursor-pointer items-center justify-center rounded-xl px-3 py-2 text-slate-500 transition hover:bg-slate-100">
                            Grupos
                            <span data-chat-tab-badge="groups" class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-[#1E90FF] px-1.5 py-0.5 text-[11px] font-semibold text-white" style="{{ $groupUnreadTotal > 0 ? '' : 'display:none;' }}">
                                {{ $groupUnreadBadgeLabel }}
                            </span>
                        </button>
                    </div>
                </div>

                <div class="hidden" data-chat-sidebar-panel="favorites">
                    <div data-chat-favorites-list>
                        @if (! empty($favoriteContacts))
                            <div class="divide-y divide-slate-100 border-y border-slate-100">
                                @foreach ($favoriteContacts as $favoriteContact)
                                    <a href="{{ route('chat.beta', ['recipient' => $favoriteContact['id']]) }}"
                                        data-chat-recipient-link
                                        class="group flex w-full cursor-pointer items-center gap-3 px-4 py-3 transition hover:bg-slate-50">
                                        <div class="shrink-0">
                                            <img src="{{ $favoriteContact['avatar_url'] }}"
                                                alt="Avatar de {{ $favoriteContact['name'] }}"
                                                class="h-11 w-11 rounded-2xl object-cover {{ ($favoriteContact['is_disabled'] ?? false) ? 'grayscale opacity-75' : '' }}">
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="truncate text-sm font-semibold text-amber-600">
                                                        {{ $favoriteContact['name'] }}
                                                    </p>
                                                    <p class="truncate text-xs text-slate-500">
                                                        {{ $favoriteContact['chat_role_label'] ?? '' }}@if (! empty($favoriteContact['chat_role_label'])) &middot; @endif{{ $favoriteContact['resolved_dealership_name'] ?: 'Sin delegación' }}
                                                        @if ($favoriteContact['is_disabled'] ?? false)
                                                            <span class="ml-2 inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-600">Desactivado</span>
                                                        @endif
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        @else
                            <div class="border-y border-slate-100 px-4 py-8 text-center text-sm text-slate-500">
                                Marca contactos como favoritos para verlos aquí.
                            </div>
                        @endif
                    </div>
                </div>

                <div data-chat-sidebar-panel="chats">
                    <div data-chat-search-results>
                        @include('tools.chat-beta.partials.search-results', ['people' => $people, 'search' => $search])
                    </div>

                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Recientes</p>
                            <span class="text-xs text-slate-400" data-chat-unread-total>{{ $chatUnreadTotal }}</span>
                        </div>
                    </div>

                    <div class="divide-y divide-slate-100 border-y border-slate-100" data-chat-conversations-list>
                        @forelse ($privateConversations as $conversation)
                            @php
                                $isGroupConversation = $conversation->isGroupConversation();
                                $partner = $conversation->otherParticipant($authUser);
                                $groupParticipantsCount = $isGroupConversation ? ($conversation->chatGroup?->participants?->count() ?? 0) : 0;
                                $isSelected = $selectedConversation?->id === $conversation->id;
                            @endphp
                            <a href="{{ route('chat.beta', ['conversation' => $conversation->id]) }}"
                                data-chat-conversation-link
                                data-chat-conversation-id="{{ $conversation->id }}"
                                class="group flex w-full cursor-pointer items-center gap-3 px-4 py-3 transition {{ $isSelected ? 'bg-brand-primary/10' : 'hover:bg-slate-50' }}">
                                <div class="relative shrink-0">
                                    @if ($isGroupConversation)
                                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-primary/10 text-brand-primary">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M5 21C5 17.134 8.13401 14 12 14C15.866 14 19 17.134 19 21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </div>
                                    @else
                                        <img src="{{ $partner?->avatar_url ?? asset('images/users/hrmotor-default-user-avatar.png') }}"
                                            alt="Avatar de {{ $partner?->name ?? 'Usuario' }}"
                                            class="h-11 w-11 rounded-2xl object-cover">
                                    @endif
                                    @if (($conversation->unread_messages_count ?? 0) > 0)
                                        <span class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-primary px-1 text-[11px] font-semibold text-white" data-chat-unread-badge>
                                            {{ $conversation->unread_messages_count }}
                                        </span>
                                    @else
                                        <span class="absolute -right-1 -top-1 hidden h-5 min-w-5 items-center justify-center rounded-full bg-brand-primary px-1 text-[11px] font-semibold text-white" data-chat-unread-badge></span>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold {{ $isGroupConversation ? 'text-brand-secondary' : (in_array($partner?->id, $favoriteUserIds, true) ? 'text-amber-600' : 'text-brand-secondary') }}" data-chat-partner-name>{{ $isGroupConversation ? ($conversation->chatGroup?->name ?? 'Grupo de chat') : ($partner?->name ?? 'Conversación') }}</p>
                                            <p class="truncate text-xs text-slate-500" data-chat-partner-role>
                                                @if ($isGroupConversation)
                                                    <span>Grupo</span>
                                                    <span class="mx-1">·</span>
                                                    <span>{{ $groupParticipantsCount }} participante{{ $groupParticipantsCount === 1 ? '' : 's' }}</span>
                                                @else
                                                    <span>{{ $partner?->chat_role_label ?? '' }}</span>
                                                    @if ($partner?->isDisabled())
                                                        <span class="ml-2 inline-flex align-middle text-amber-500" title="Usuario desactivado" aria-label="Usuario desactivado">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                                <path d="M12 15H12.01M12 12V9M4.98207 19H19.0179C20.5615 19 21.5233 17.3256 20.7455 15.9923L13.7276 3.96153C12.9558 2.63852 11.0442 2.63852 10.2724 3.96153L3.25452 15.9923C2.47675 17.3256 3.43849 19 4.98207 19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg>
                                                        </span>
                                                    @endif
                                                @endif
                                            </p>
                                            <p class="truncate text-xs text-slate-500" data-chat-last-message>
                                                {{ $conversation->last_message_excerpt ?: 'Empieza la conversación' }}
                                            </p>
                                        </div>

                                        @if ($conversation->last_message_at)
                                            <span class="shrink-0 text-[11px] text-slate-400" data-chat-last-message-at>
                                                {{ $conversation->last_message_at->translatedFormat('d/m H:i') }}
                                            </span>
                                        @else
                                            <span class="shrink-0 text-[11px] text-slate-400" data-chat-last-message-at></span>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @empty
                            <div class="px-4 py-8 text-center">
                                <p class="text-sm font-semibold text-brand-secondary">Sin conversaciones aún</p>
                                <p class="mt-1 text-sm leading-6 text-slate-500">
                                    Busca a un compañero y abre el primer chat.
                                </p>
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="hidden" data-chat-sidebar-panel="team">
                    <div class="divide-y divide-slate-100 border-y border-slate-100">
                        @forelse ($teamUsers as $group)
                            <details class="group" data-chat-team-accordion>
                                <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 transition hover:bg-slate-50">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-brand-secondary">{{ $group['role_label'] }}</p>
                                        <p class="text-xs text-slate-400">{{ count($group['users']) }} persona{{ count($group['users']) === 1 ? '' : 's' }}</p>
                                    </div>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                    </svg>
                                </summary>

                                <div class="border-t border-slate-100">
                                    @foreach ($group['users'] as $teamUser)
                                        <a href="{{ route('chat.beta', ['recipient' => $teamUser['id']]) }}" class="flex cursor-pointer items-center gap-3 px-4 py-3 transition hover:bg-slate-50">
                                            <img src="{{ $teamUser['avatar_url'] }}" alt="Avatar de {{ $teamUser['name'] }}" class="h-10 w-10 rounded-2xl object-cover {{ ($teamUser['is_disabled'] ?? false) ? 'grayscale opacity-75' : '' }}">
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-semibold {{ in_array($teamUser['id'], $favoriteUserIds, true) ? 'text-amber-600' : 'text-brand-secondary' }}">{{ $teamUser['name'] }}</p>
                                                <p class="truncate text-xs text-slate-500">
                                                    {{ $teamUser['chat_role_label'] ?? '' }}@if (! empty($teamUser['chat_role_label'])) &middot; @endif{{ $teamUser['resolved_dealership_name'] ?: 'Sin delegación' }}
                                                    @if ($teamUser['is_disabled'] ?? false)
                                                        <span class="ml-2 inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-600">Desactivado</span>
                                                    @endif
                                                </p>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @empty
                            <div class="px-4 py-8 text-center text-sm text-slate-500">
                                No hay usuarios activos para mostrar.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="hidden" data-chat-sidebar-panel="groups">
                    <div class="px-4 py-3">
                        <div class="flex items-center justify-between">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Tus grupos</p>
                            <span class="text-xs text-slate-400">{{ $chatGroups->count() }}</span>
                        </div>
                    </div>

                    <div class="divide-y divide-slate-100 border-y border-slate-100" data-chat-groups-list>
                        @forelse ($chatGroups as $chatGroup)
                            @php
                                $groupConversation = $chatGroup->conversation;
                                $isSelectedGroup = $selectedConversationIsGroup && $selectedConversation?->company_chat_group_id === $chatGroup->id;
                                $chatGroupAvatarUrl = filled($chatGroup->avatar_url)
                                    ? $chatGroup->avatar_url
                                    : null;
                            @endphp
                            <a href="{{ route('chat.beta', ['group' => $chatGroup->id]) }}"
                                data-chat-group-link
                                class="group flex w-full items-center gap-3 px-4 py-3 transition {{ $isSelectedGroup ? 'bg-brand-primary/10' : 'hover:bg-slate-50' }}">
                                <div class="relative shrink-0">
                                    @if ($chatGroupAvatarUrl)
                                        <img src="{{ $chatGroupAvatarUrl }}"
                                            alt="Avatar de {{ $chatGroup->name }}"
                                            class="h-11 w-11 cursor-pointer rounded-2xl object-cover">
                                    @else
                                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-primary/10 text-brand-primary">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M5 21C5 17.134 8.13401 14 12 14C15.866 14 19 17.134 19 21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </div>
                                    @endif
                                    @if ((int) ($groupConversation?->unread_messages_count ?? 0) > 0)
                                        <span class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-primary px-1 text-[11px] font-semibold text-white">
                                            {{ $groupConversation?->unread_messages_count }}
                                        </span>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-secondary">{{ $chatGroup->name }}</p>
                                            <p class="truncate text-xs text-slate-500">
                                                {{ $groupConversation?->last_message_excerpt ?: 'Empieza la conversación' }}
                                            </p>
                                        </div>
                                        @if ($groupConversation?->last_message_at)
                                            <span class="shrink-0 text-[11px] text-slate-400">
                                                {{ $groupConversation->last_message_at->translatedFormat('d/m H:i') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @empty
                            <div class="border-y border-slate-100 px-4 py-8 text-center text-sm text-slate-500">
                                Aún no participas en ningún grupo.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
            </div>

            <div class="absolute inset-0 flex h-full w-[4.75rem] flex-col items-center justify-center border-r border-slate-200 bg-white px-2 py-4 shadow-[12px_0_40px_rgba(15,23,42,0.04)] opacity-0 pointer-events-none translate-x-2 scale-95 transition-all duration-300 ease-in-out" data-chat-sidebar-collapsed-shell>
                <button
                    type="button"
                    data-chat-sidebar-expand-button
                    class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-2xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary"
                    aria-label="Expandir panel lateral"
                    aria-expanded="false"
                    onclick="window.chatSetSidebarCollapsed?.(false)"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"></path>
                    </svg>
                </button>
            </div>
        </aside>

        <section class="relative flex min-w-0 flex-1 flex-col bg-slate-100" data-chat-conversation-pane>
            @if ($selectedConversation)
                <div class="pointer-events-none absolute inset-0 z-20 hidden rounded-none border-2 border-dashed border-sky-400/80 bg-sky-50/85 px-6 py-6 shadow-[inset_0_0_0_1px_rgba(186,230,253,0.65)] backdrop-blur-[1px]" data-chat-conversation-dropzone-hint aria-hidden="true">
                    <div class="flex h-full items-center justify-center">
                        <div class="max-w-xl rounded-[1.6rem] border border-sky-200 bg-white/95 px-6 py-5 text-center shadow-xl">
                            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-sky-100 text-sky-700 ring-1 ring-sky-200">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 10l5-5 5 5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v12" />
                                </svg>
                            </div>
                            <p class="text-sm font-semibold uppercase tracking-[0.24em] text-sky-700">Adjuntar archivos</p>
                            <p class="mt-2 text-base font-semibold text-brand-secondary">Suelta las imágenes o archivos aquí para añadirlos al mensaje</p>
                        </div>
                    </div>
                </div>

                <header class="flex min-h-[4.75rem] items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 py-2">
                    <div class="flex min-w-0 items-center gap-3 {{ $selectedConversationIsGroup ? '' : 'hidden' }}" data-chat-header-group-shell>
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary md:hidden"
                            aria-label="Abrir panel lateral"
                            aria-expanded="false"
                            data-chat-mobile-sidebar-toggle
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="block h-4 w-4 shrink-0 transition-transform duration-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" data-chat-mobile-sidebar-icon>
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"></path>
                            </svg>
                        </button>

                        <div class="group flex min-w-0 items-center gap-3">
                            <div class="{{ $selectedConversationGroupAvatarUrl ? '' : 'hidden' }}" data-chat-group-header-avatar-shell>
                                <button
                                    type="button"
                                    class="group/avatar relative inline-flex h-11 w-11 min-h-11 min-w-11 shrink-0 overflow-hidden rounded-2xl cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                                    aria-label="Ampliar imagen del grupo {{ $selectedConversationGroup?->name ?? 'Grupo de chat' }}"
                                    data-chat-group-header-avatar-button
                                    data-chat-group-header-avatar-src="{{ $selectedConversationGroupAvatarUrl }}"
                                    data-chat-group-header-avatar-alt="Avatar de {{ $selectedConversationGroup?->name ?? 'Grupo de chat' }}"
                                    data-chat-group-header-avatar-title="{{ $selectedConversationGroup?->name ?? 'Grupo de chat' }}"
                                    @click.stop="openImage({ src: $el.dataset.chatGroupHeaderAvatarSrc, alt: $el.dataset.chatGroupHeaderAvatarAlt, title: $el.dataset.chatGroupHeaderAvatarTitle })"
                                >
                                    <img
                                        src="{{ $selectedConversationGroupAvatarUrl }}"
                                        alt="Avatar de {{ $selectedConversationGroup?->name ?? 'Grupo de chat' }}"
                                        class="block h-full w-full min-h-full min-w-full shrink-0 rounded-2xl object-cover object-center transition duration-300 group-hover/avatar:scale-105 group-hover/avatar:brightness-75"
                                        data-chat-group-header-avatar
                                    >
                                    <span class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-brand-secondary/0 text-[10px] font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover/avatar:bg-brand-secondary/35 group-hover/avatar:opacity-100">
                                        Ver
                                    </span>
                                </button>
                            </div>
                            <div class="{{ $selectedConversationGroupAvatarUrl ? 'hidden' : '' }}" data-chat-group-header-icon-shell>
                                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-primary/10 text-brand-primary transition group-hover:bg-brand-primary/15" data-chat-group-header-icon>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M5 21C5 17.134 8.13401 14 12 14C15.866 14 19 17.134 19 21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="min-w-0 cursor-pointer text-left transition hover:opacity-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30 focus-visible:ring-offset-2 focus-visible:ring-offset-white"
                                aria-label="Ver detalles del grupo"
                                data-chat-group-header-button
                            >
                            <div class="min-w-0">
                                <h1 class="truncate text-base font-semibold text-brand-secondary" data-chat-group-header-name>{{ $selectedConversationGroup?->name ?? 'Grupo de chat' }}</h1>
                                @if ($selectedConversationGroup)
                                    <p class="mt-2 truncate text-xs text-slate-500" data-chat-group-header-participants>
                                        {{ $selectedConversationGroup->participants->pluck('name')->implode(', ') ?: 'Sin participantes' }}
                                    </p>
                                @endif
                            </div>
                            </button>
                        </div>
                    </div>
                    <div class="flex min-w-0 items-center gap-3 {{ $selectedConversationIsGroup ? 'hidden' : '' }}" data-chat-header-private-shell>
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl border border-slate-200 text-slate-500 transition hover:bg-slate-100 hover:text-brand-primary md:hidden"
                            aria-label="Abrir panel lateral"
                            aria-expanded="false"
                            data-chat-mobile-sidebar-toggle
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="block h-4 w-4 shrink-0 transition-transform duration-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" data-chat-mobile-sidebar-icon>
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"></path>
                            </svg>
                        </button>

                        <button
                            type="button"
                            data-chat-private-header-avatar-button
                            data-chat-private-header-avatar-src="{{ $selectedParticipantAvatarUrl }}"
                            data-chat-private-header-avatar-alt="Avatar de {{ $selectedParticipantName }}"
                            data-chat-private-header-avatar-title="{{ $selectedParticipantName }}"
                            @click.stop="openImage({ src: $el.dataset.chatPrivateHeaderAvatarSrc, alt: $el.dataset.chatPrivateHeaderAvatarAlt, title: $el.dataset.chatPrivateHeaderAvatarTitle })"
                            class="group relative cursor-pointer overflow-hidden rounded-2xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                            aria-label="Ampliar imagen de {{ $selectedParticipantName }}"
                        >
                            <img src="{{ $selectedParticipantAvatarUrl }}" alt="Avatar de {{ $selectedParticipantName }}" class="h-11 w-11 rounded-2xl object-cover transition duration-300 group-hover:scale-105 group-hover:brightness-75" data-chat-private-header-avatar>
                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-brand-secondary/0 text-[10px] font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover:bg-brand-secondary/35 group-hover:opacity-100">
                                Ver
                            </span>
                        </button>
                        <a
                            href="{{ $selectedParticipantProfileUrl }}"
                            class="min-w-0 transition hover:opacity-90"
                            aria-label="Ver perfil de {{ $selectedParticipantName }}"
                            data-chat-header-profile-link
                            data-chat-private-header-profile-link
                        >
                            <span class="flex min-w-0 items-center gap-2">
                                <h1 class="truncate text-base font-semibold text-brand-secondary" data-chat-private-header-name>{{ $selectedParticipantName }}</h1>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-amber-500 {{ $selectedParticipantIsFavorite ? '' : 'hidden' }}" viewBox="0 0 24 24" fill="none" aria-hidden="true" data-chat-favorite-star>
                                    <path d="M11.245 4.174C11.4765 3.50808 11.5922 3.17513 11.7634 3.08285C11.9115 3.00298 12.0898 3.00298 12.238 3.08285C12.4091 3.17513 12.5248 3.50808 12.7563 4.174L14.2866 8.57639C14.3525 8.76592 14.3854 8.86068 14.4448 8.93125C14.4972 8.99359 14.5641 9.04218 14.6396 9.07278C14.725 9.10743 14.8253 9.10947 15.0259 9.11356L19.6857 9.20852C20.3906 9.22288 20.743 9.23007 20.8837 9.36432C21.0054 9.48051 21.0605 9.65014 21.0303 9.81569C20.9955 10.007 20.7146 10.2199 20.1528 10.6459L16.4387 13.4616C16.2788 13.5829 16.1989 13.6435 16.1501 13.7217C16.107 13.7909 16.0815 13.8695 16.0757 13.9507C16.0692 14.0427 16.0982 14.1387 16.1563 14.3308L17.506 18.7919C17.7101 19.4667 17.8122 19.8041 17.728 19.9793C17.6551 20.131 17.5108 20.2358 17.344 20.2583C17.1513 20.2842 16.862 20.0829 16.2833 19.6802L12.4576 17.0181C12.2929 16.9035 12.2106 16.8462 12.1211 16.8239C12.042 16.8043 11.9593 16.8043 11.8803 16.8239C11.7908 16.8462 11.7084 16.9035 11.5437 17.0181L7.71805 19.6802C7.13937 20.0829 6.85003 20.2842 6.65733 20.2583C6.49056 20.2358 6.34626 20.131 6.27337 19.9793C6.18915 19.8041 6.29123 19.4667 6.49538 18.7919L7.84503 14.3308C7.90313 14.1387 7.93218 14.0427 7.92564 13.9507C7.91986 13.8695 7.89432 13.7909 7.85123 13.7217C7.80246 13.6435 7.72251 13.5829 7.56262 13.4616L3.84858 10.6459C3.28678 10.2199 3.00588 10.007 2.97101 9.81569C2.94082 9.65014 2.99594 9.48051 3.11767 9.36432C3.25831 9.23007 3.61074 9.22289 4.31559 9.20852L8.9754 9.11356C9.176 9.10947 9.27631 9.10743 9.36177 9.07278C9.43726 9.04218 9.50414 8.99359 9.55657 8.93125C9.61593 8.86068 9.64887 8.76592 9.71475 8.57639L11.245 4.174Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </span>
                            <p class="truncate text-xs text-slate-500" data-chat-private-header-role>
                                {{ $selectedParticipantChatRoleLabel }} &middot; {{ $selectedParticipantDealershipName }}
                                @if ($selectedParticipantIsDisabled)
                                    <span class="ml-2 inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-600">Desactivado</span>
                                @endif
                            </p>
                        </a>
                    </div>
                    </div>

                    <div class="flex items-center gap-1">
                        <span
                            class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-slate-300 transition-colors duration-300 cursor-help"
                            title="Tiempo real desactivado"
                            aria-label="Tiempo real desactivado"
                            role="status"
                            data-chat-realtime-indicator
                        ></span>

                        <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button
                            type="button"
                            data-chat-contact-menu-button
                            @click="open = !open"
                            class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-2xl text-slate-500 transition hover:bg-slate-50 hover:text-brand-secondary"
                            aria-label="Acciones del contacto"
                            :aria-expanded="open.toString()"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <circle cx="5" cy="12" r="1.8" />
                                <circle cx="12" cy="12" r="1.8" />
                                <circle cx="19" cy="12" r="1.8" />
                            </svg>
                        </button>

                        <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 translate-y-1"
                            class="absolute right-0 top-full z-20 mt-2 w-56 overflow-hidden rounded-2xl border border-brand-secondary/10 bg-white shadow-xl">
                            <form method="POST" action="{{ $selectedParticipantFavoriteToggleUrl }}" data-chat-favorite-toggle-form data-chat-favorite-toggle-url-template="{{ $selectedParticipant ? route('chat.beta.favorites.toggle', ['user' => '__USER_ID__']) : '' }}">
                                @csrf
                                <button type="submit"
                                    class="flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left text-sm font-medium text-brand-secondary transition hover:bg-brand-secondary/5">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-amber-500" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M11.245 4.174C11.4765 3.50808 11.5922 3.17513 11.7634 3.08285C11.9115 3.00298 12.0898 3.00298 12.238 3.08285C12.4091 3.17513 12.5248 3.50808 12.7563 4.174L14.2866 8.57639C14.3525 8.76592 14.3854 8.86068 14.4448 8.93125C14.4972 8.99359 14.5641 9.04218 14.6396 9.07278C14.725 9.10743 14.8253 9.10947 15.0259 9.11356L19.6857 9.20852C20.3906 9.22288 20.743 9.23007 20.8837 9.36432C21.0054 9.48051 21.0605 9.65014 21.0303 9.81569C20.9955 10.007 20.7146 10.2199 20.1528 10.6459L16.4387 13.4616C16.2788 13.5829 16.1989 13.6435 16.1501 13.7217C16.107 13.7909 16.0815 13.8695 16.0757 13.9507C16.0692 14.0427 16.0982 14.1387 16.1563 14.3308L17.506 18.7919C17.7101 19.4667 17.8122 19.8041 17.728 19.9793C17.6551 20.131 17.5108 20.2358 17.344 20.2583C17.1513 20.2842 16.862 20.0829 16.2833 19.6802L12.4576 17.0181C12.2929 16.9035 12.2106 16.8462 12.1211 16.8239C12.042 16.8043 11.9593 16.8043 11.8803 16.8239C11.7908 16.8462 11.7084 16.9035 11.5437 17.0181L7.71805 19.6802C7.13937 20.0829 6.85003 20.2842 6.65733 20.2583C6.49056 20.2358 6.34626 20.131 6.27337 19.9793C6.18915 19.8041 6.29123 19.4667 6.49538 18.7919L7.84503 14.3308C7.90313 14.1387 7.93218 14.0427 7.92564 13.9507C7.91986 13.8695 7.89432 13.7909 7.85123 13.7217C7.80246 13.6435 7.72251 13.5829 7.56262 13.4616L3.84858 10.6459C3.28678 10.2199 3.00588 10.007 2.97101 9.81569C2.94082 9.65014 2.99594 9.48051 3.11767 9.36432C3.25831 9.23007 3.61074 9.22289 4.31559 9.20852L8.9754 9.11356C9.176 9.10947 9.27631 9.10743 9.36177 9.07278C9.43726 9.04218 9.50414 8.99359 9.55657 8.93125C9.61593 8.86068 9.64887 8.76592 9.71475 8.57639L11.245 4.174Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <span data-chat-favorite-toggle-label>{{ $selectedParticipantIsFavorite ? 'Quitar de favoritos' : 'Marcar como favorito' }}</span>
                                </button>
                            </form>
                        </div>
                    </div>

                </header>

                <div
                    class="relative flex-1 min-h-0 overflow-y-auto px-4 py-5 sm:px-6"
                    data-chat-messages-wrapper
                    data-chat-messages-url-template="{{ route('chat.beta.messages.index', ['conversation' => '__CONVERSATION_ID__']) }}"
                    data-chat-store-url-template="{{ route('chat.beta.messages.store', ['conversation' => '__CONVERSATION_ID__']) }}"
                    data-chat-message-update-url-template="{{ route('chat.beta.messages.update', ['conversation' => '__CONVERSATION_ID__', 'message' => '__MESSAGE_ID__']) }}"
                    data-chat-message-destroy-url-template="{{ route('chat.beta.messages.destroy', ['conversation' => '__CONVERSATION_ID__', 'message' => '__MESSAGE_ID__']) }}"
                    data-poll-url="{{ route('chat.beta.messages.index', $selectedConversation) }}"
                    data-conversation-id="{{ $selectedConversation->id }}"
                    data-chat-messages-page-size="{{ $chatMessagesPageSize }}"
                    data-chat-messages-sync-overlap="{{ $chatMessagesSyncOverlap }}"
                >
                    <div class="pointer-events-none absolute left-1/2 top-3 z-20 hidden -translate-x-1/2" data-chat-history-loader>
                        <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white/95 px-3 py-2 text-xs font-medium text-slate-500 shadow-lg shadow-slate-200/70">
                            <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-slate-300 border-t-brand-primary"></span>
                            Cargando historial
                        </div>
                    </div>
                    <div class="mx-auto flex min-h-full max-w-5xl flex-col justify-end">
                        <div class="space-y-0" data-chat-messages>
                            @php
                                $chatDateToday = now()->startOfDay();
                                $chatDateYesterday = $chatDateToday->copy()->subDay();
                                $chatDateWeekStart = $chatDateToday->copy()->startOfWeek();

                                $chatDateLabel = static function ($date) use ($chatDateToday, $chatDateYesterday, $chatDateWeekStart) {
                                    if (! $date) {
                                        return '';
                                    }

                                    $date = $date->copy()->startOfDay();

                                    if ($date->equalTo($chatDateToday)) {
                                        return 'Hoy';
                                    }

                                    if ($date->equalTo($chatDateYesterday)) {
                                        return 'Ayer';
                                    }

                                    if ($date->greaterThanOrEqualTo($chatDateWeekStart)) {
                                        return mb_strtolower($date->translatedFormat('l'));
                                    }

                                    return $date->translatedFormat('d/m/Y');
                                };
                            @endphp
                            @forelse ($selectedConversationMessages as $message)
                                @php
                                    $isSystem = (bool) $message->is_system;
                                    $isMine = $message->sender_id === $authUser->id;
                                    $previousMessage = $selectedConversationMessages->get($loop->index - 1);
                                    $nextMessage = $selectedConversationMessages->get($loop->index + 1);
                                    $currentDateKey = $message->created_at?->format('Y-m-d');
                                    $previousDateKey = $previousMessage?->created_at?->format('Y-m-d');
                                    $nextDateKey = $nextMessage?->created_at?->format('Y-m-d');
                                    $currentTimeLabel = $message->created_at->translatedFormat('H:i');
                                    $previousTimeLabel = $previousMessage?->created_at?->translatedFormat('H:i');
                                    $nextTimeLabel = $nextMessage?->created_at?->translatedFormat('H:i');
                                    $showDateSeparator = $loop->first || $previousDateKey !== $currentDateKey;
                                    $showTime = $loop->last
                                        || $nextDateKey !== $currentDateKey
                                        || $nextTimeLabel !== $currentTimeLabel
                                        || $nextMessage?->sender_id !== $message->sender_id;
                                    $topMarginClass = 'mt-3';
                                    if ($loop->first) {
                                        $topMarginClass = 'mt-0';
                                    } elseif (
                                        $selectedConversationIsGroup
                                        && $previousMessage?->sender_id !== null
                                        && $previousMessage?->sender_id !== $message->sender_id
                                    ) {
                                        $topMarginClass = 'mt-4';
                                    } elseif (
                                        $previousDateKey === $currentDateKey
                                        && $previousTimeLabel === $currentTimeLabel
                                        && $previousMessage?->sender_id === $message->sender_id
                                    ) {
                                        $topMarginClass = 'mt-0.5';
                                    }
                                    $messageAttachments = collect($message->attachments ?? []);
                                    $isDeleted = $message->deleted_at !== null;
                                    $isEdited = $message->edited_at !== null && ! $isDeleted;
                                    $showSenderName = $selectedConversationIsGroup
                                        && ! $isDeleted
                                        && ! $isSystem
                                        && ! $isMine
                                        && (
                                            $loop->first
                                            || (
                                                $previousMessage?->sender_id !== null
                                                && $previousMessage?->sender_id !== $message->sender_id
                                            )
                                            || $previousTimeLabel !== $currentTimeLabel
                                        );
                                @endphp
                                @if ($showDateSeparator)
                                    <div class="my-5 flex justify-center" data-chat-date-separator>
                                        <span class="inline-flex rounded-full bg-sky-100 px-3 py-1 text-[11px] font-semibold text-sky-700 shadow-sm ring-1 ring-sky-200">
                                            {{ $chatDateLabel($message->created_at) }}
                                        </span>
                                    </div>
                                @endif
                                <div class="flex {{ $isSystem ? 'justify-center' : ($isMine ? 'justify-end' : 'justify-start') }} {{ $topMarginClass }}" data-message-id="{{ $message->id }}" data-chat-message-owner="{{ $isMine ? '1' : '0' }}">
                                    <div class="flex w-fit max-w-[78%] min-w-0 flex-col {{ $isSystem ? 'items-center' : ($isMine ? 'items-end' : 'items-start') }}">
                                        @if ($isSystem)
                                            <div class="max-w-full break-words [overflow-wrap:anywhere] rounded-full bg-slate-100 px-4 py-2 text-center text-[12px] leading-5 text-slate-500 shadow-sm ring-1 ring-slate-200" data-chat-message-content>
                                                {!! $message->rendered_body_html ?? e($message->body) !!}
                                            </div>
                                            <div class="{{ $showTime ? 'mt-1' : 'mt-0.5' }} flex items-center gap-1 text-[11px] justify-center text-slate-400">
                                                <span data-message-time @if (! $showTime) class="hidden" @endif>{{ $currentTimeLabel }}</span>
                                            </div>
                                        @else
                                            <div class="group relative min-w-[5rem] max-w-full overflow-x-hidden rounded-[1.1rem] px-3 py-2 shadow-sm transition {{ $isDeleted ? 'border border-dashed border-slate-300 bg-slate-100 text-slate-500' : ($isMine ? 'bg-[#d9fdd3] pb-4 pr-8 text-slate-800 hover:shadow-md' : 'border border-slate-200 bg-white text-brand-secondary') }} {{ (! $isDeleted && ! $isMine && (bool) ($message->mentions_auth_user ?? false)) ? 'ring-2 ring-sky-300 bg-sky-50/80 shadow-md' : '' }}">
                                                @if ($showSenderName)
                                                    <p class="mb-1 truncate text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                                        {{ $message->sender?->name ?? 'Usuario' }}
                                                    </p>
                                                @endif
                                                @if ($isMine && ! $isDeleted)
                                                    <button type="button"
                                                        class="absolute bottom-1 left-2 inline-flex h-5 w-5 cursor-pointer items-center justify-center rounded-full bg-white/75 text-slate-500 opacity-0 shadow-sm transition hover:bg-white hover:text-brand-secondary group-hover:opacity-100"
                                                        aria-label="Abrir opciones del mensaje"
                                                        data-chat-message-trigger>
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                            <path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z" />
                                                        </svg>
                                                    </button>
                                                @endif

                                                <div class="min-w-0 max-w-full overflow-x-hidden" data-chat-message-content>
                                                    @if ($isDeleted)
                                                        <div class="flex items-center gap-2 text-sm font-medium text-slate-500">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 2.82 18a2 2 0 0 0 1.75 3h15.86a2 2 0 0 0 1.75-3L14.71 3.86a2 2 0 0 0-3.42 0Z" />
                                                            </svg>
                                                            <span>Este mensaje ha sido eliminado.</span>
                                                        </div>
                                                    @elseif (filled($message->body))
                                                        <p class="whitespace-pre-line break-words [overflow-wrap:anywhere] text-[15px] leading-[1.45]">{!! $message->rendered_body_html ?? e($message->body) !!}</p>
                                                    @endif

                                                    @if (! $isDeleted && $messageAttachments->isNotEmpty())
                                                        <div class="{{ filled($message->body) ? 'mt-2' : '' }} space-y-2">
                                                            @foreach ($messageAttachments as $attachment)
                                                                @php
                                                                    $isImageAttachment = (bool) ($attachment['is_image'] ?? str_starts_with((string) ($attachment['mime_type'] ?? ''), 'image/'));
                                                                    $isJsonAttachment = (bool) ($attachment['is_json'] ?? str_ends_with(strtolower((string) ($attachment['original_name'] ?? '')), '.json') || (string) ($attachment['mime_type'] ?? '') === 'application/json');
                                                                    $attachmentName = $attachment['original_name'] ?? 'archivo';
                                                                    $attachmentSize = $attachment['size_label'] ?? '';
                                                                    $attachmentUrl = route('chat.beta.attachments.show', [
                                                                        'conversation' => $message->company_chat_conversation_id,
                                                                        'message' => $message->id,
                                                                        'attachmentIndex' => $loop->index,
                                                                    ]);
                                                                @endphp

                                                                @if ($isImageAttachment)
                                                                    <button
                                                                        type="button"
                                                                        data-chat-image-src="{{ $attachmentUrl }}"
                                                                        data-chat-image-alt="{{ $attachmentName }}"
                                                                        data-chat-image-title="{{ $attachmentName }}"
                                                                        @click="openImage({ src: $el.dataset.chatImageSrc, alt: $el.dataset.chatImageAlt, title: $el.dataset.chatImageTitle })"
                                                                        class="group/image relative block cursor-pointer overflow-hidden rounded-[1rem] border border-black/5 bg-white/50 text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                                                                        aria-label="Ver {{ $attachmentName }}"
                                                                    >
                                                                        <img src="{{ $attachmentUrl }}" alt="{{ $attachmentName }}" class="max-h-72 w-full object-cover transition duration-300 group-hover/image:scale-105 group-hover/image:brightness-75">
                                                                        <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-brand-secondary/0 text-xs font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover/image:bg-brand-secondary/30 group-hover/image:opacity-100">
                                                                            Ver
                                                                        </span>
                                                                    </button>
                                                                @else
                                                                    <a href="{{ $attachmentUrl }}" @if ($isJsonAttachment) download="{{ $attachmentName }}" @else target="_blank" rel="noopener" @endif class="flex items-center gap-3 rounded-[1rem] border border-black/5 bg-white/60 px-3 py-2 transition hover:bg-white">
                                                                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                                                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />
                                                                            </svg>
                                                                        </span>
                                                                        <span class="min-w-0 flex-1">
                                                                            <span class="block truncate text-sm font-semibold text-brand-secondary">{{ $attachmentName }}</span>
                                                                            @if ($attachmentSize !== '')
                                                                                <span class="block text-xs text-slate-500">{{ $attachmentSize }}</span>
                                                                            @endif
                                                                        </span>
                                                                    </a>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    @endif

                                                    @if ($isMine && ! $isDeleted)
                                                        <span class="absolute bottom-1.5 right-3 inline-flex items-center {{ $message->read_at ? 'text-sky-500' : 'text-slate-400' }}" data-message-checks>
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none">
                                                                <line x1="13.22" y1="16.5" x2="21" y2="7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                                                                <polyline points="3 11.88 7 16.5 14.78 7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" fill="none" />
                                                            </svg>
                                                        </span>
                                                    @endif
                                                </div>

                                                @if ($isMine)
                                                    <div class="hidden" data-chat-message-inline-editor>
                                                        <textarea
                                                            rows="1"
                                                            class="mt-1 min-w-[8rem] max-w-full resize-none overflow-hidden whitespace-pre-wrap break-words rounded-[1rem] border border-brand-primary/20 bg-white px-3 py-2 text-[15px] text-brand-secondary outline-none focus:border-brand-primary focus:ring-4 focus:ring-brand-primary/10"
                                                            data-chat-edit-input>{{ $message->body }}</textarea>
                                                        <div class="mt-3 flex items-center justify-end gap-2">
                                                            <button type="button" class="cursor-pointer rounded-full px-3 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-100" data-chat-edit-cancel>Cancelar</button>
                                                            <button type="button" class="cursor-pointer rounded-full bg-brand-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90" data-chat-edit-save>Guardar</button>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="hidden mt-0.5" data-chat-message-menu-slot></div>
                                            <div class="{{ $showTime ? 'mt-1' : 'mt-0.5' }} flex items-center gap-1 text-[11px] {{ $isMine ? 'justify-end text-slate-500' : 'justify-start text-slate-400' }}">
                                                @if ($isEdited)
                                                    <span class="text-[10px] italic text-slate-400">Editado</span>
                                                @endif
                                                <span data-message-time @if (! $showTime) class="hidden" @endif>{{ $currentTimeLabel }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="flex min-h-full items-center justify-center">
                                    <div class="max-w-md rounded-[2rem] border border-dashed border-slate-300 bg-white px-8 py-10 text-center shadow-sm">
                                        <p class="text-lg font-bold text-brand-secondary">
                                            {{ $selectedConversationIsGroup ? 'Grupo listo para empezar' : 'Chat listo para empezar' }}
                                        </p>
                                        <p class="mt-2 text-sm leading-6 text-slate-500">
                                            {{ $selectedConversationIsGroup ? 'Aquí verás los mensajes del grupo cuando alguien escriba el primero.' : 'Aquí verás la conversación cuando elijas un compañero.' }}
                                        </p>
                                    </div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <footer class="border-t border-slate-200 bg-white px-4 py-4">
                    <form
                        method="POST"
                        action="{{ route('chat.beta.messages.store', $selectedConversation) }}"
                        class="relative mx-auto max-w-5xl"
                        data-chat-form
                    >
                        @csrf
                        <input type="hidden" name="conversation_id" value="{{ $selectedConversation->id }}">
                        <input type="file" name="attachments[]" multiple class="hidden" data-chat-attachments-input accept="image/*,.svg,.pdf,.txt,.md,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.json,.rdp">

                        @if ($composerDisabled)
                            <div class="mb-3 rounded-[1.2rem] border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                Este usuario está desactivado. No puedes enviarle mensajes, emojis ni archivos.
                            </div>
                        @endif

                        <div class="absolute bottom-full right-16 mb-3 hidden w-72 rounded-[1.5rem] border border-slate-200 bg-white p-3 shadow-xl" data-chat-emoji-picker>
                            <div class="grid grid-cols-8 gap-1">
                                @foreach ([
                                    "\u{1F600}",
                                    "\u{1F601}",
                                    "\u{1F602}",
                                    "\u{1F923}",
                                    "\u{1F60D}",
                                    "\u{1F929}",
                                    "\u{1F973}",
                                    "\u{1F92A}",
                                    "\u{1F62E}",
                                    "\u{1F92D}",
                                    "\u{1F44D}",
                                    "\u{1F44E}",
                                    "\u{1F525}",
                                    "\u{2728}",
                                    "\u{2764}\u{FE0F}",
                                    "\u{1F4A1}",
                                    "\u{1F3AF}",
                                    "\u{1F680}",
                                    "\u{1F4AC}",
                                    "\u{1F92B}",
                                    "\u{1F910}",
                                    "\u{1F606}",
                                    "\u{1F973}",
                                    "\u{1F92F}",
                                ] as $emoji)
                                    <button type="button"
                                        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-xl text-lg transition hover:bg-slate-100"
                                        data-chat-emoji-option
                                        data-emoji="{{ $emoji }}"
                                        aria-label="Insertar {{ $emoji }}">
                                        {{ $emoji }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="absolute bottom-full left-4 right-4 z-20 mb-3 hidden max-w-2xl overflow-hidden rounded-[1.4rem] border border-slate-200 bg-white shadow-2xl" data-chat-mention-suggestions>
                            <div class="border-b border-slate-100 px-4 py-3">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Menciones sugeridas</p>
                            </div>
                            <div class="max-h-72 overflow-y-auto p-2" data-chat-mention-suggestions-list></div>
                        </div>

                        <div class="relative flex items-end gap-3 rounded-[1.75rem] border border-slate-200 bg-white px-4 py-3 shadow-sm transition {{ $composerDisabled ? 'cursor-not-allowed bg-slate-50 opacity-70' : '' }}" data-chat-composer-shell>
                            <div class="pointer-events-none absolute inset-0 z-10 hidden rounded-[1.75rem] border-2 border-dashed border-sky-400/80 bg-sky-50/85 px-4 py-3 shadow-lg ring-1 ring-sky-200/60 backdrop-blur-[1px]" data-chat-dropzone-hint aria-hidden="true">
                                <div class="flex h-full items-center justify-center">
                                    <div class="rounded-full bg-white/90 px-4 py-2 text-center text-[11px] font-semibold uppercase tracking-[0.24em] text-sky-700 shadow-sm ring-1 ring-sky-200">
                                        Suelta los archivos para adjuntarlos
                                    </div>
                                </div>
                            </div>

                            <button type="button"
                                class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl text-slate-400 transition hover:bg-slate-100 hover:text-brand-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                aria-label="Adjuntar archivo"
                                @disabled($composerDisabled)
                                data-chat-attachments-button>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.5 12.5 21a6.364 6.364 0 1 1-9-9L12 3.5a4.243 4.243 0 1 1 6 6L8.5 19a2.121 2.121 0 1 1-3-3L14 7.5" />
                                </svg>
                            </button>

                            <textarea
                                name="body"
                                rows="1"
                                placeholder="Escribir mensaje..."
                                class="max-h-40 flex-1 resize-none border-0 bg-transparent px-0 py-2 text-[16px] text-brand-secondary outline-none placeholder:text-slate-400 focus:ring-0 disabled:cursor-not-allowed disabled:bg-transparent disabled:text-slate-400 md:text-[15px]"
                                @disabled($composerDisabled)
                                data-chat-input
                            >{{ old('body') }}</textarea>

                            <button type="button"
                                class="hidden h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl text-slate-400 transition hover:bg-slate-100 hover:text-brand-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400 md:inline-flex"
                                aria-label="Emoticonos"
                                @disabled($composerDisabled)
                                data-chat-emoji-button>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <circle cx="12" cy="12" r="9" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 10h.01M15 10h.01" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 15c.8 1 1.8 1.5 3 1.5S14.2 16 15 15" />
                                </svg>
                            </button>

                            <button type="button"
                                class="inline-flex h-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl bg-brand-primary px-5 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 disabled:hover:opacity-100 md:px-5 md:text-sm"
                                @disabled($composerDisabled)
                                data-chat-submit-button>
                                <span class="hidden md:inline">Enviar</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M10.3009 13.6949L20.102 3.89742M10.5795 14.1355L12.8019 18.5804C13.339 19.6545 13.6075 20.1916 13.9458 20.3356C14.2394 20.4606 14.575 20.4379 14.8492 20.2747C15.1651 20.0866 15.3591 19.5183 15.7472 18.3818L19.9463 6.08434C20.2845 5.09409 20.4535 4.59896 20.3378 4.27142C20.2371 3.98648 20.013 3.76234 19.7281 3.66167C19.4005 3.54595 18.9054 3.71502 17.9151 4.05315L5.61763 8.2523C4.48114 8.64037 3.91289 8.83441 3.72478 9.15032C3.56153 9.42447 3.53891 9.76007 3.66389 10.0536C3.80791 10.3919 4.34498 10.6605 5.41912 11.1975L9.86397 13.42C10.041 13.5085 10.1295 13.5527 10.2061 13.6118C10.2742 13.6643 10.3352 13.7253 10.3876 13.7933C10.4468 13.87 10.491 13.9585 10.5795 14.1355Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>

                        <div class="mt-2 hidden px-1 text-xs text-slate-500" data-chat-attachments-preview></div>
                        <div class="mt-2 hidden px-1" data-chat-attachments-chips></div>
                        <p class="mt-2 hidden px-1 text-sm font-medium text-red-600" data-chat-error></p>

                        @error('body')
                            <p class="mt-2 px-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </form>
                </footer>
            @else
                <div class="flex flex-1 items-center justify-center px-6 py-12 sm:px-10" data-chat-empty-state>
                    <div class="flex max-w-lg flex-col items-center text-center">
                        <div class="relative h-52 w-64 sm:h-60 sm:w-72" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-1 top-1 h-16 w-20 rotate-[-12deg] text-brand-secondary/35 sm:left-2 sm:top-2" viewBox="0 0 80 64" fill="none">
                                <path d="M12 11.5C12 6.81 15.81 3 20.5 3h38C63.19 3 67 6.81 67 11.5v22C67 38.19 63.19 42 58.5 42H39L25 53v-11h-4.5C15.81 42 12 38.19 12 33.5v-22Z" fill="currentColor" fill-opacity=".22"/>
                                <path d="M26 18h27M26 27h17" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".7"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute bottom-1 right-0 h-14 w-20 rotate-[10deg] text-brand-primary/35 sm:bottom-2 sm:right-1" viewBox="0 0 80 64" fill="none">
                                <path d="M13 13.5C13 8.81 16.81 5 21.5 5h37C63.19 5 67 8.81 67 13.5v20C67 38.19 63.19 42 58.5 42H42L27 54V42h-5.5C16.81 42 13 38.19 13 33.5v-20Z" fill="currentColor" fill-opacity=".18"/>
                                <path d="M27 20h26M27 29h15" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity=".68"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-1/2 top-1/2 h-52 w-52 -translate-x-1/2 -translate-y-1/2 text-brand-secondary sm:h-60 sm:w-60" viewBox="0 0 160 160" fill="none">
                                <rect x="28" y="34" width="104" height="76" rx="14" fill="currentColor" fill-opacity=".06" stroke="currentColor" stroke-width="3"/>
                                <path d="m31 48 43.5 31.5a9.5 9.5 0 0 0 11 0L129 48" stroke="#E51A2E" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M31 101 61 74M129 101 99 74" stroke="currentColor" stroke-opacity=".65" stroke-width="3" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <p class="mt-1 text-xs font-semibold uppercase tracking-[0.22em] text-brand-primary">Tu espacio de comunicación</p>
                        <h2 class="mt-3 text-2xl font-bold tracking-tight text-brand-secondary sm:text-3xl">Selecciona una conversación</h2>
                        <p class="mt-3 max-w-sm text-sm leading-6 text-slate-500 sm:text-base">
                            Selecciona una conversación de la lista para empezar.
                        </p>
                    </div>
                </div>
            @endif

            <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 px-4" data-chat-group-modal-overlay>
                <div class="w-full max-w-2xl rounded-[1.6rem] bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="chat-group-modal-title">
                    <div class="flex items-start gap-3">
                        <button
                            type="button"
                            class="group relative hidden h-11 w-11 shrink-0 cursor-pointer overflow-hidden rounded-2xl focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                            aria-label="Ampliar imagen del grupo"
                            data-chat-group-modal-avatar-button
                            @click.stop="openImage({ src: $el.dataset.chatGroupModalAvatarSrc, alt: $el.dataset.chatGroupModalAvatarAlt, title: $el.dataset.chatGroupModalAvatarTitle })"
                        >
                            <img
                                src=""
                                alt=""
                                class="h-11 w-11 rounded-2xl object-cover"
                                data-chat-group-modal-avatar
                            >
                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-brand-secondary/0 text-[10px] font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover:bg-brand-secondary/35 group-hover:opacity-100">
                                Ver
                            </span>
                        </button>
                        <div class="min-w-0 flex-1">
                            <h3 id="chat-group-modal-title" class="truncate text-base font-semibold text-brand-secondary">{{ $selectedConversationGroup?->name ?? 'Grupo de chat' }}</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">Haz clic en cualquier miembro para abrir su perfil.</p>
                        </div>
                        <button type="button" class="cursor-pointer rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar" data-chat-group-modal-close>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="mt-5 max-h-[65vh] overflow-y-auto pr-1">
                        <div class="grid gap-2" data-chat-group-modal-members></div>
                    </div>
                </div>
            </div>
        </section>

        <div
            x-cloak
            x-show="isImageOpen"
            x-transition.opacity
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 px-6 py-8 backdrop-blur-sm"
            @click.self="closeImage()"
        >
            <div class="inline-flex max-w-[calc(100vw-3rem)] flex-col items-center">
                <div
                    x-ref="imageViewport"
                    class="relative touch-none overflow-hidden rounded-[2rem] border border-slate-200 bg-white shadow-2xl"
                    :class="imageScale > 1 ? (isDragging ? 'cursor-grabbing' : 'cursor-grab') : 'cursor-zoom-in'"
                    @wheel.prevent="handleWheel($event)"
                    @pointerdown="handlePointerDown($event)"
                    @pointermove="handlePointerMove($event)"
                    @pointerup="handlePointerUp($event)"
                    @pointercancel="handlePointerCancel($event)"
                >
                    <button
                        type="button"
                        @pointerdown.stop
                        @click.stop="closeImage()"
                        class="absolute right-3 top-3 z-10 inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Cerrar imagen ampliada"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <img
                        :src="imageUrl"
                        :alt="imageAlt"
                        @dblclick="toggleZoom($event.clientX, $event.clientY)"
                        draggable="false"
                        @dragstart.prevent
                        class="block max-h-[80vh] w-auto max-w-[calc(100vw-3rem)] select-none object-contain bg-white will-change-transform"
                        :class="isDragging ? 'transition-none' : 'transition-transform duration-200'"
                        :style="`transform: translate3d(${translateX}px, ${translateY}px, 0) scale(${imageScale}); transform-origin: center center;`"
                    >
                </div>

                <div class="mt-4 flex items-center justify-center gap-2">
                    <button
                        type="button"
                        @click="zoomOut()"
                        class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Reducir zoom"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        @click="resetZoom()"
                        class="inline-flex h-10 min-w-20 items-center justify-center rounded-full bg-white/90 px-3 text-sm font-semibold text-brand-secondary shadow-lg"
                        aria-label="Restablecer zoom"
                    >
                        <span x-text="`${imageScale.toFixed(2).replace(/\.00$/, '')}x`"></span>
                    </button>
                    <button
                        type="button"
                        @click="downloadImage()"
                        class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Descargar imagen"
                    >
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-5 w-5">
                            <path d="M12.5535 16.5061C12.4114 16.6615 12.2106 16.75 12 16.75C11.7894 16.75 11.5886 16.6615 11.4465 16.5061L7.44648 12.1311C7.16698 11.8254 7.18822 11.351 7.49392 11.0715C7.79963 10.792 8.27402 10.8132 8.55352 11.1189L11.25 14.0682V3C11.25 2.58579 11.5858 2.25 12 2.25C12.4142 2.25 12.75 2.58579 12.75 3V14.0682L15.4465 11.1189C15.726 10.8132 16.2004 10.792 16.5061 11.0715C16.8118 11.351 16.833 11.8254 16.5535 12.1311L12.5535 16.5061Z" fill="#1C274C"/>
                            <path d="M3.75 15C3.75 14.5858 3.41422 14.25 3 14.25C2.58579 14.25 2.25 14.5858 2.25 15V15.0549C2.24998 16.4225 2.24996 17.5248 2.36652 18.3918C2.48754 19.2919 2.74643 20.0497 3.34835 20.6516C3.95027 21.2536 4.70814 21.5125 5.60825 21.6335C6.47522 21.75 7.57754 21.75 8.94513 21.75H15.0549C16.4225 21.75 17.5248 21.75 18.3918 21.6335C19.2919 21.5125 20.0497 21.2536 20.6517 20.6516C21.2536 20.0497 21.5125 19.2919 21.6335 18.3918C21.75 17.5248 21.75 16.4225 21.75 15.0549V15C21.75 14.5858 21.4142 14.25 21 14.25C20.5858 14.25 20.25 14.5858 20.25 15C20.25 16.4354 20.2484 17.4365 20.1469 18.1919C20.0482 18.9257 19.8678 19.3142 19.591 19.591C19.3142 19.8678 18.9257 20.0482 18.1919 20.1469C17.4365 20.2484 16.4354 20.25 15 20.25H9C7.56459 20.25 6.56347 20.2484 5.80812 20.1469C5.07435 20.0482 4.68577 19.8678 4.40901 19.591C4.13225 19.3142 3.9518 18.9257 3.85315 18.1919C3.75159 17.4365 3.75 16.4354 3.75 15Z" fill="#1C274C"/>
                        </svg>
                    </button>
                    <button
                        type="button"
                        @click="zoomIn()"
                        class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white"
                        aria-label="Aumentar zoom"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                </div>

                <p class="mt-4 text-center text-sm font-medium text-white/80" x-text="imageTitle"></p>
            </div>
        </div>

    </section>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const root = document.querySelector('[data-chat-root]');
                const sidebar = document.querySelector('[data-chat-sidebar]');
                const wrapper = document.querySelector('[data-chat-messages-wrapper]');
                const messagesContainer = document.querySelector('[data-chat-messages]');
                const form = document.querySelector('[data-chat-form]');
                const input = document.querySelector('[data-chat-input]');
                const attachmentsInput = document.querySelector('[data-chat-attachments-input]');
                const attachmentsButton = document.querySelector('[data-chat-attachments-button]');
                const attachmentsPreview = document.querySelector('[data-chat-attachments-preview]');
                const attachmentsChips = document.querySelector('[data-chat-attachments-chips]');
                const composerShell = document.querySelector('[data-chat-composer-shell]');
                const composerDropzoneHint = document.querySelector('[data-chat-dropzone-hint]');
                const conversationPane = document.querySelector('[data-chat-conversation-pane]');
                const conversationDropzoneHint = document.querySelector('[data-chat-conversation-dropzone-hint]');
                const chatError = document.querySelector('[data-chat-error]');
                const emojiButton = document.querySelector('[data-chat-emoji-button]');
                const emojiPicker = document.querySelector('[data-chat-emoji-picker]');
                const mentionSuggestionsPanel = document.querySelector('[data-chat-mention-suggestions]');
                const mentionSuggestionsList = document.querySelector('[data-chat-mention-suggestions-list]');
                const searchInput = document.querySelector('[data-chat-search-input]');
                const searchResults = document.querySelector('[data-chat-search-results]');
                const clearChatSearch = () => {
                    clearTimeout(searchDebounce);

                    if (searchAbortController) {
                        searchAbortController.abort();
                        searchAbortController = null;
                    }

                    if (searchInput) {
                        searchInput.value = '';
                    }

                    if (searchResults) {
                        searchResults.innerHTML = '';
                    }

                    const nextUrl = new URL(window.location.href);

                    if (nextUrl.searchParams.has('search')) {
                        nextUrl.searchParams.delete('search');
                        window.history.replaceState(window.history.state, '', nextUrl.toString());
                    }
                };
                let pollUrl = wrapper?.dataset.pollUrl;
                const summaryRoot = document.querySelector('[data-chat-summary-url]');
                const sidebarList = document.querySelector('[data-chat-conversations-list]');
                const sidebarFavoritesList = document.querySelector('[data-chat-favorites-list]');
                const sidebarGroupsList = document.querySelector('[data-chat-groups-list]');
                const sidebarUnreadTotal = document.querySelector('[data-chat-unread-total]');
                const tabBadges = {
                    chats: document.querySelector('[data-chat-tab-badge="chats"]'),
                    groups: document.querySelector('[data-chat-tab-badge="groups"]'),
                };
                const formatTabBadgeLabel = (count) => (count > 9 ? '+9' : String(count));
                const syncTabBadge = (badgeElement, count) => {
                    if (!badgeElement) {
                        return;
                    }

                    const isVisible = count > 0;
                    badgeElement.textContent = formatTabBadgeLabel(count);
                    badgeElement.style.display = isVisible ? '' : 'none';
                    badgeElement.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
                };
                const summaryUrl = summaryRoot?.dataset.chatSummaryUrl;
                const messagesUrlTemplate = wrapper?.dataset.chatMessagesUrlTemplate;
                const storeUrlTemplate = wrapper?.dataset.chatStoreUrlTemplate;
                const composerDisabled = root?.dataset.chatComposerDisabled === '1';
                const historyLoader = document.querySelector('[data-chat-history-loader]');
                const headerGroupShell = document.querySelector('[data-chat-header-group-shell]');
                const headerPrivateShell = document.querySelector('[data-chat-header-private-shell]');
                const headerGroupName = document.querySelector('[data-chat-group-header-name]');
                const headerGroupParticipants = document.querySelector('[data-chat-group-header-participants]');
                const headerGroupButton = document.querySelector('[data-chat-group-header-button]');
                const headerGroupAvatarShell = document.querySelector('[data-chat-group-header-avatar-shell]');
                const headerGroupIconShell = document.querySelector('[data-chat-group-header-icon-shell]');
                const headerGroupAvatar = document.querySelector('[data-chat-group-header-avatar]');
                const headerGroupAvatarButton = document.querySelector('[data-chat-group-header-avatar-button]');
                const headerGroupIcon = document.querySelector('[data-chat-group-header-icon]');
                const headerPrivateName = document.querySelector('[data-chat-private-header-name]');
                const headerPrivateRole = document.querySelector('[data-chat-private-header-role]');
                const realtimeIndicator = document.querySelector('[data-chat-realtime-indicator]');
                const headerAvatar = document.querySelector('[data-chat-private-header-avatar]');
                const headerAvatarButton = document.querySelector('[data-chat-private-header-avatar-button]');
                const headerProfileLink = document.querySelector('[data-chat-private-header-profile-link]');
                const headerFavoriteStar = document.querySelector('[data-chat-favorite-star]');
                const headerFavoriteToggleLabel = document.querySelector('[data-chat-favorite-toggle-label]');
                const headerFavoriteToggleForm = document.querySelector('[data-chat-favorite-toggle-form]');
                const headerFavoriteMenuButton = document.querySelector('[data-chat-contact-menu-button]');
                const groupModalOverlay = document.querySelector('[data-chat-group-modal-overlay]');
                const groupModalTitle = document.querySelector('[data-chat-group-modal-title]');
                const groupModalMembers = document.querySelector('[data-chat-group-modal-members]');
                const groupModalCloseButton = document.querySelector('[data-chat-group-modal-close]');
                const groupModalAvatarButton = document.querySelector('[data-chat-group-modal-avatar-button]');
                const groupModalAvatar = document.querySelector('[data-chat-group-modal-avatar]');
                const mobileSidebarBackdrop = document.querySelector('[data-chat-mobile-sidebar-backdrop]');
                const mobileSidebarToggleButtons = Array.from(document.querySelectorAll('[data-chat-mobile-sidebar-toggle]'));
                const mobileSidebarIcons = Array.from(document.querySelectorAll('[data-chat-mobile-sidebar-icon]'));
                const sidebarExpandedShell = document.querySelector('[data-chat-sidebar-expanded-shell]');
                const sidebarCollapsedShell = document.querySelector('[data-chat-sidebar-collapsed-shell]');
                const sidebarCollapseButton = document.querySelector('[data-chat-sidebar-collapse-button]');
                const sidebarExpandButton = document.querySelector('[data-chat-sidebar-expand-button]');
                const sidebarTabButtons = Array.from(document.querySelectorAll('[data-chat-sidebar-tab]'));
                const sidebarPanels = Array.from(document.querySelectorAll('[data-chat-sidebar-panel]'));
                if (!root || !sidebar || !sidebarExpandedShell || !sidebarCollapsedShell || !sidebarCollapseButton || !sidebarExpandButton) {
                    return;
                }

                const hasComposer = Boolean(wrapper && messagesContainer && form && input && pollUrl && messagesUrlTemplate && storeUrlTemplate && attachmentsInput && attachmentsButton && attachmentsPreview && attachmentsChips && composerShell && composerDropzoneHint && conversationPane && conversationDropzoneHint && chatError && emojiButton && emojiPicker && mentionSuggestionsPanel && mentionSuggestionsList);
                const canCompose = hasComposer && !composerDisabled;
                let currentConversationIsGroup = Boolean(window.chatInitialConversationIsGroup);
                let currentConversationParticipants = Array.isArray(window.chatInitialConversationParticipants) ? window.chatInitialConversationParticipants : [];
                const historyPageSize = Number(wrapper?.dataset.chatMessagesPageSize || 30);
                const historySyncOverlap = Number(wrapper?.dataset.chatMessagesSyncOverlap || 20);
                const csrfToken = form?.querySelector('input[name="_token"]')?.value ?? '';
                const realtimeConfig = window.chatRealtimeConfig || {};
                let pollingLocked = false;
                let isLoadingOlderMessages = false;
                let hasMoreOlderMessages = Boolean(@js((bool) ($selectedConversation?->messages_has_more_older ?? false)));
                let attachmentSnapshot = [];
                let sidebarTab = 'chats';
                let searchAbortController = null;
                let searchDebounce = null;
                let latestMessageId = Number(messagesContainer?.querySelector('[data-message-id]')?.dataset.messageId ?? 0);
                let sidebarSelectedConversationId = Number(summaryRoot?.dataset.selectedConversationId ?? 0);
                let currentMessages = [];
                let currentMessagesFingerprint = '';
                let activeMessageMenuId = null;
                let editingMessageId = null;
                let editingMessageDraft = '';
                let pendingDeleteMessageId = null;
                let conversationRevision = 0;
                let currentGroupModalData = window.chatInitialGroupModalData || null;
                let composerMentionState = {
                    isOpen: false,
                    query: '',
                    start: null,
                    end: null,
                    selectedIndex: 0,
                    suggestions: [],
                };
                let composerMentionIds = [];
                let sidebarCollapsed = false;
                let mobileSidebarOpen = false;
                let composerDropDepth = 0;
                let composerDropActive = false;
                let conversationDropActive = false;
                const messageActionWindowMinutes = 2;
                const messageActionWindowMs = messageActionWindowMinutes * 60 * 1000;
                const messageActionWindowMessage = 'Solo puedes editar o eliminar un mensaje durante los 2 minutos posteriores a su envío.';
                let realtimeSocket = null;
                let realtimeSocketId = '';
                let realtimeConversationChannel = '';
                let realtimeUserChannel = '';
                let realtimeReconnectTimer = null;
                let realtimeReconnectAttempt = 0;
                let realtimeSyncTimer = null;
                let realtimeSidebarTimer = null;
                let realtimeHandshakeTimer = null;
                let realtimeSuppressed = false;
                let chatRealtimeConnected = false;
                const realtimeCurrentUserId = Number(window.chatCurrentUserId || 0);
                currentMessages = @js($selectedConversationMessages->values()->map(function ($message, $index) use ($authUser, $selectedConversationMessages) {
                    $nextMessage = $selectedConversationMessages->get($index + 1);
                    $currentTimeLabel = $message->created_at?->translatedFormat('H:i');
                    $nextTimeLabel = $nextMessage?->created_at?->translatedFormat('H:i');
                    $currentDateKey = $message->created_at?->format('Y-m-d');
                    $nextDateKey = $nextMessage?->created_at?->format('Y-m-d');

                    return [
                        'id' => $message->id,
                        'body' => $message->body,
                        'rendered_body_html' => $message->rendered_body_html ?? e($message->body ?? ''),
                        'attachments' => $message->attachments ?? [],
                        'is_mine' => $message->sender_id === $authUser->id,
                        'mentions_me' => (bool) ($message->mentions_auth_user ?? false),
                        'mentioned_user_ids' => $message->mentioned_user_ids ?? [],
                        'mentioned_users' => $message->mentioned_users ?? [],
                        'read_at' => $message->read_at?->toIso8601String(),
                        'created_at' => $message->created_at?->toIso8601String(),
                        'updated_at' => $message->updated_at?->toIso8601String(),
                        'edited_at' => $message->edited_at?->toIso8601String(),
                        'deleted_at' => $message->deleted_at?->toIso8601String(),
                        'created_at_label' => $currentTimeLabel,
                        'show_time' => $nextDateKey !== $currentDateKey || $nextTimeLabel !== $currentTimeLabel,
                    ];
                })->values());
                const buildMessagesFingerprint = (messages) => {
                    return JSON.stringify(
                        (Array.isArray(messages) ? messages : []).map((message) => ({
                            id: Number(message.id ?? 0),
                            body: String(message.body ?? ''),
                            read_at: String(message.read_at ?? ''),
                            updated_at: String(message.updated_at ?? ''),
                            edited_at: String(message.edited_at ?? ''),
                            deleted_at: String(message.deleted_at ?? ''),
                            mentioned_user_ids: Array.isArray(message.mentioned_user_ids) ? message.mentioned_user_ids.map((value) => Number(value)).sort((first, second) => first - second) : [],
                            mentions_me: Boolean(message.mentions_me),
                            attachments_count: Array.isArray(message.attachments) ? message.attachments.length : 0,
                        })),
                    );
                };
                const getMessageSortKey = (message) => {
                    const createdAt = Date.parse(message?.created_at || '');
                    return {
                        createdAt: Number.isNaN(createdAt) ? 0 : createdAt,
                        id: Number(message?.id || 0),
                    };
                };
                const compareMessagesChronologically = (first, second) => {
                    const firstKey = getMessageSortKey(first);
                    const secondKey = getMessageSortKey(second);

                    if (firstKey.createdAt !== secondKey.createdAt) {
                        return firstKey.createdAt - secondKey.createdAt;
                    }

                    return firstKey.id - secondKey.id;
                };
                const sortMessagesChronologically = (messages) => {
                    return Array.isArray(messages)
                        ? [...messages].sort(compareMessagesChronologically)
                        : [];
                };
                const mergeMessagesById = (baseMessages, incomingMessages) => {
                    const mergedMessages = new Map();

                    sortMessagesChronologically(baseMessages).forEach((message) => {
                        mergedMessages.set(Number(message?.id || 0), { ...message });
                    });

                    sortMessagesChronologically(incomingMessages).forEach((message) => {
                        const messageId = Number(message?.id || 0);

                        if (!messageId) {
                            return;
                        }

                        const currentMessage = mergedMessages.get(messageId) || {};
                        mergedMessages.set(messageId, { ...currentMessage, ...message });
                    });

                    return Array.from(mergedMessages.values()).sort(compareMessagesChronologically);
                };
                const buildPendingOutgoingMessage = ({ body, attachments = [], mentionedUserIds = [] }) => {
                    const now = new Date();
                    const isoNow = now.toISOString();
                    return {
                        id: `pending-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                        body,
                        rendered_body_html: escapeHtml(body),
                        attachments,
                        is_mine: true,
                        is_system: false,
                        mentions_me: false,
                        mentioned_user_ids: Array.isArray(mentionedUserIds) ? mentionedUserIds.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0) : [],
                        mentioned_users: [],
                        read_at: null,
                        created_at: isoNow,
                        updated_at: isoNow,
                        edited_at: null,
                        deleted_at: null,
                        created_at_label: now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }),
                        show_time: true,
                        is_pending: true,
                        pending_attachments: Array.isArray(attachments) ? attachments : [],
                    };
                };
                const getPersistedMessageIds = () => {
                    return (Array.isArray(currentMessages) ? currentMessages : [])
                        .map((message) => Number(message?.id || 0))
                        .filter((messageId) => Number.isInteger(messageId) && messageId > 0);
                };
                const getOldestMessageId = () => {
                    const persistedMessageIds = getPersistedMessageIds();
                    return Number(persistedMessageIds[0] || 0);
                };
                const getLatestMessageId = () => {
                    const persistedMessageIds = getPersistedMessageIds();
                    return Number(persistedMessageIds[persistedMessageIds.length - 1] || 0);
                };
                const getHistorySyncCursorMessageId = (overlap = historySyncOverlap) => {
                    const persistedMessageIds = getPersistedMessageIds();

                    if (persistedMessageIds.length === 0) {
                        return 0;
                    }

                    const safeOverlap = Math.max(0, Number(overlap) || 0);
                    const cursorIndex = Math.max(0, persistedMessageIds.length - safeOverlap - 1);

                    return Number(persistedMessageIds[cursorIndex] || 0);
                };
                const setHistoryLoaderVisible = (visible) => {
                    if (!historyLoader) {
                        return;
                    }

                    historyLoader.classList.toggle('hidden', !visible);
                };
                currentMessagesFingerprint = buildMessagesFingerprint(currentMessages);
                const favoriteUserIds = new Set(@js($favoriteUserIds ?? []));
                const allowedAttachmentExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'pdf', 'txt', 'md', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'json', 'rdp'];
                const maxAttachmentCount = 4;
                const maxAttachmentTotalBytes = 30 * 1024 * 1024;
                const pastedImageMimeToExtension = {
                    'image/png': 'png',
                    'image/jpeg': 'jpg',
                    'image/jpg': 'jpg',
                    'image/webp': 'webp',
                    'image/gif': 'gif',
                    'image/svg+xml': 'svg',
                };

                const buildRealtimeWsUrl = () => {
                    if (!realtimeConfig.enabled || !realtimeConfig.app_key) {
                        return null;
                    }

                    const currentLocation = window.location;
                    const schemeSource = String(realtimeConfig.public_scheme || currentLocation.protocol || 'https:').toLowerCase();
                    const scheme = schemeSource === 'https:' || schemeSource === 'https' ? 'wss' : 'ws';
                    const host = String(realtimeConfig.public_host || currentLocation.hostname || '').trim();
                    const publicPort = String(realtimeConfig.public_port || '').trim();
                    const currentPort = String(currentLocation.port || '').trim();
                    const port = publicPort !== '' ? publicPort : currentPort;
                    const path = String(realtimeConfig.public_path || realtimeConfig.path || '').trim().replace(/\/$/, '');
                    const basePath = path !== '' ? path : '';
                    const shouldOmitPort = (scheme === 'wss' && port === '443') || (scheme === 'ws' && port === '80');

                    return `${scheme}://${host}${port !== '' && !shouldOmitPort ? `:${port}` : ''}${basePath}/app/${encodeURIComponent(String(realtimeConfig.app_key))}?protocol=7&client=js&version=1.0.0&flash=false`;
                };

                const buildRealtimeChannelName = (conversationId) => {
                    const prefix = String(realtimeConfig.channel_prefix || 'private-');
                    return `${prefix}chat.conversations.${conversationId}`;
                };

                const buildRealtimeUserChannelName = (userId) => {
                    const prefix = String(realtimeConfig.channel_prefix || 'private-');
                    return `${prefix}chat.users.${userId}`;
                };

                const getRealtimeHeaders = () => {
                    if (!realtimeSocketId) {
                        return {};
                    }

                    return {
                        'X-Socket-ID': realtimeSocketId,
                    };
                };

                const updateChatRealtimeIndicator = (status) => {
                    const resolvedStatus = ['active', 'fallback', 'disabled'].includes(status) ? status : 'disabled';

                    if (!realtimeIndicator) {
                        return;
                    }

                    realtimeIndicator.dataset.status = resolvedStatus;
                    realtimeIndicator.classList.remove('bg-emerald-500', 'bg-amber-500', 'bg-slate-300');

                    if (resolvedStatus === 'active') {
                        realtimeIndicator.classList.add('bg-emerald-500');
                        realtimeIndicator.title = 'Tiempo real activo';
                        realtimeIndicator.setAttribute('aria-label', 'Tiempo real activo');
                        return;
                    }

                    if (resolvedStatus === 'fallback') {
                        realtimeIndicator.classList.add('bg-amber-500');
                        realtimeIndicator.title = 'Tiempo real en espera';
                        realtimeIndicator.setAttribute('aria-label', 'Tiempo real en espera');
                        return;
                    }

                    realtimeIndicator.classList.add('bg-slate-300');
                    realtimeIndicator.title = 'Tiempo real desactivado';
                    realtimeIndicator.setAttribute('aria-label', 'Tiempo real desactivado');
                };

                const queueRealtimeMessagesSync = () => {
                    if (!hasComposer || typeof syncMessages !== 'function') {
                        return;
                    }

                    if (realtimeSyncTimer) {
                        window.clearTimeout(realtimeSyncTimer);
                    }

                    realtimeSyncTimer = window.setTimeout(() => {
                        realtimeSyncTimer = null;
                        void syncMessages();
                    }, 75);
                };

                const queueRealtimeSidebarRefresh = () => {
                    if (typeof refreshSidebar !== 'function') {
                        return;
                    }

                    if (realtimeSidebarTimer) {
                        window.clearTimeout(realtimeSidebarTimer);
                    }

                    realtimeSidebarTimer = window.setTimeout(() => {
                        realtimeSidebarTimer = null;
                        void refreshSidebar();
                    }, 75);
                };

                const shouldRunChatPolling = () => document.visibilityState === 'visible' && !chatRealtimeConnected;

                const handleRealtimeChatEvent = (payload) => {
                    const conversationId = Number(payload?.conversation_id || 0);
                    const activeConversationId = Number(wrapper?.dataset.conversationId || sidebarSelectedConversationId || 0);

                    if (!conversationId) {
                        return;
                    }

                    if (conversationId === activeConversationId) {
                        queueRealtimeMessagesSync();
                    }

                    queueRealtimeSidebarRefresh();
                };

                const subscribeRealtimeConversation = async (conversationId) => {
                    if (!realtimeSocket || realtimeSocket.readyState !== WebSocket.OPEN || !conversationId) {
                        return;
                    }

                    const channelName = buildRealtimeChannelName(conversationId);

                    if (realtimeConversationChannel === channelName) {
                        return;
                    }

                    if (realtimeConversationChannel) {
                        realtimeSocket.send(JSON.stringify({
                            event: 'pusher:unsubscribe',
                            data: {
                                channel: realtimeConversationChannel,
                            },
                        }));
                    }

                    const authResponse = await fetch(String(realtimeConfig.auth_endpoint || '/broadcasting/auth'), {
                        method: 'POST',
                        headers: {
                            ...getRealtimeHeaders(),
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                            Accept: 'application/json',
                        },
                        body: new URLSearchParams({
                            socket_id: realtimeSocketId,
                            channel_name: channelName,
                        }),
                    });

                    if (!authResponse.ok) {
                        return;
                    }

                    const authPayload = await authResponse.json().catch(() => ({}));
                    const channelData = {
                        channel: channelName,
                        auth: authPayload.auth,
                    };

                    if (authPayload.channel_data) {
                        channelData.channel_data = authPayload.channel_data;
                    }

                    realtimeConversationChannel = channelName;
                    realtimeSocket.send(JSON.stringify({
                        event: 'pusher:subscribe',
                        data: channelData,
                    }));
                };

                const subscribeRealtimeUserChannel = async () => {
                    if (!realtimeSocket || realtimeSocket.readyState !== WebSocket.OPEN || !realtimeCurrentUserId) {
                        return;
                    }

                    const channelName = buildRealtimeUserChannelName(realtimeCurrentUserId);

                    if (realtimeUserChannel === channelName) {
                        return;
                    }

                    const authResponse = await fetch(String(realtimeConfig.auth_endpoint || '/broadcasting/auth'), {
                        method: 'POST',
                        headers: {
                            ...getRealtimeHeaders(),
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                            Accept: 'application/json',
                        },
                        body: new URLSearchParams({
                            socket_id: realtimeSocketId,
                            channel_name: channelName,
                        }),
                    });

                    if (!authResponse.ok) {
                        return;
                    }

                    const authPayload = await authResponse.json().catch(() => ({}));
                    realtimeUserChannel = channelName;
                    realtimeSocket.send(JSON.stringify({
                        event: 'pusher:subscribe',
                        data: {
                            channel: channelName,
                            auth: authPayload.auth,
                        },
                    }));
                };

                const disconnectChatRealtime = () => {
                    chatRealtimeConnected = false;

                    if (realtimeReconnectTimer) {
                        window.clearTimeout(realtimeReconnectTimer);
                        realtimeReconnectTimer = null;
                    }

                    if (realtimeHandshakeTimer) {
                        window.clearTimeout(realtimeHandshakeTimer);
                        realtimeHandshakeTimer = null;
                    }

                    if (realtimeSocket) {
                        try {
                            realtimeSocket.close();
                        } catch (error) {
                            console.error(error);
                        }
                    }

                    realtimeSocket = null;
                    realtimeSocketId = '';
                    realtimeConversationChannel = '';
                    realtimeUserChannel = '';

                    updateChatRealtimeIndicator(realtimeConfig.enabled && !realtimeSuppressed && typeof WebSocket !== 'undefined' ? 'fallback' : 'disabled');
                };

                const suppressChatRealtime = () => {
                    realtimeSuppressed = true;
                    disconnectChatRealtime();
                    updateChatRealtimeIndicator('disabled');
                };

                const connectChatRealtime = () => {
                    if (!hasComposer || !realtimeConfig.enabled || realtimeSuppressed || typeof WebSocket === 'undefined') {
                        updateChatRealtimeIndicator('disabled');
                        return;
                    }

                    const wsUrl = buildRealtimeWsUrl();

                    if (!wsUrl) {
                        updateChatRealtimeIndicator('disabled');
                        return;
                    }

                    if (realtimeSocket && (realtimeSocket.readyState === WebSocket.OPEN || realtimeSocket.readyState === WebSocket.CONNECTING)) {
                        updateChatRealtimeIndicator('fallback');
                        return;
                    }

                    disconnectChatRealtime();
                    updateChatRealtimeIndicator('fallback');

                    const socket = new WebSocket(wsUrl);
                    realtimeSocket = socket;

                    if (realtimeHandshakeTimer) {
                        window.clearTimeout(realtimeHandshakeTimer);
                    }

                    realtimeHandshakeTimer = window.setTimeout(() => {
                        if (realtimeSocket === socket && !realtimeSocketId) {
                            suppressChatRealtime();
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

                            if (realtimeHandshakeTimer) {
                                window.clearTimeout(realtimeHandshakeTimer);
                                realtimeHandshakeTimer = null;
                            }

                            realtimeSocketId = String(connectionData?.socket_id || '');
                            realtimeReconnectAttempt = 0;
                            chatRealtimeConnected = true;
                            updateChatRealtimeIndicator('active');

                            await subscribeRealtimeUserChannel();

                            const activeConversationId = Number(wrapper?.dataset.conversationId || sidebarSelectedConversationId || 0);
                            if (activeConversationId) {
                                await subscribeRealtimeConversation(activeConversationId);
                            }

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

                        if (payload.event === 'chat.message.created' || payload.event === 'chat.conversation.read') {
                            handleRealtimeChatEvent(eventData);
                        }
                    });

                    socket.addEventListener('close', () => {
                        if (realtimeHandshakeTimer) {
                            window.clearTimeout(realtimeHandshakeTimer);
                            realtimeHandshakeTimer = null;
                        }

                        realtimeSocket = null;
                        realtimeSocketId = '';
                        realtimeConversationChannel = '';
                        realtimeUserChannel = '';
                        chatRealtimeConnected = false;

                        if (!realtimeConfig.enabled || realtimeSuppressed) {
                            updateChatRealtimeIndicator('disabled');
                            return;
                        }

                        updateChatRealtimeIndicator('fallback');

                        const delay = Math.min(15000, 1000 * (realtimeReconnectAttempt + 1));
                        realtimeReconnectAttempt += 1;

                        if (realtimeReconnectTimer) {
                            window.clearTimeout(realtimeReconnectTimer);
                        }

                        realtimeReconnectTimer = window.setTimeout(() => {
                            realtimeReconnectTimer = null;
                            connectChatRealtime();
                        }, delay);
                    });

                    socket.addEventListener('error', () => {
                        updateChatRealtimeIndicator('fallback');
                        try {
                            socket.close();
                        } catch (error) {
                            console.error(error);
                        }
                    });
                };

                const setSidebarCollapsed = (collapsed) => {
                    sidebarCollapsed = Boolean(collapsed);

                    if (sidebarCollapsed) {
                        sidebar.style.width = '4.75rem';
                        sidebar.style.minWidth = '4.75rem';
                        sidebar.style.maxWidth = '4.75rem';
                    } else {
                        sidebar.style.removeProperty('width');
                        sidebar.style.removeProperty('min-width');
                        sidebar.style.removeProperty('max-width');
                    }

                    sidebarExpandedShell.classList.toggle('opacity-0', sidebarCollapsed);
                    sidebarExpandedShell.classList.toggle('translate-x-[-8px]', sidebarCollapsed);
                    sidebarExpandedShell.classList.toggle('pointer-events-none', sidebarCollapsed);
                    sidebarExpandedShell.classList.toggle('opacity-100', !sidebarCollapsed);
                    sidebarExpandedShell.classList.toggle('translate-x-0', !sidebarCollapsed);
                    sidebarExpandedShell.classList.toggle('pointer-events-auto', !sidebarCollapsed);

                    sidebarCollapsedShell.classList.toggle('opacity-100', sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('translate-x-0', sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('scale-100', sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('pointer-events-auto', sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('opacity-0', !sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('translate-x-2', !sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('scale-95', !sidebarCollapsed);
                    sidebarCollapsedShell.classList.toggle('pointer-events-none', !sidebarCollapsed);
                    sidebarCollapseButton.classList.toggle('hidden', sidebarCollapsed);
                    sidebarExpandButton.classList.toggle('hidden', !sidebarCollapsed);
                    sidebarCollapseButton.setAttribute('aria-expanded', sidebarCollapsed ? 'false' : 'true');
                    sidebarExpandButton.setAttribute('aria-expanded', sidebarCollapsed ? 'true' : 'false');
                };
                window.chatSetSidebarCollapsed = setSidebarCollapsed;

                const setMobileSidebarOpen = (open) => {
                    if (!mobileSidebarBackdrop || !mobileSidebarToggleButtons.length || !mobileSidebarIcons.length) {
                        return;
                    }

                    mobileSidebarOpen = Boolean(open);

                    if (window.matchMedia('(max-width: 767px)').matches) {
                        sidebarCollapsed = false;
                        sidebar.style.removeProperty('width');
                        sidebar.style.removeProperty('min-width');
                        sidebar.style.removeProperty('max-width');
                        sidebarExpandedShell.classList.add('opacity-100', 'translate-x-0', 'pointer-events-auto');
                        sidebarExpandedShell.classList.remove('opacity-0', 'translate-x-[-8px]', 'pointer-events-none');
                        sidebarCollapsedShell.classList.add('opacity-0', 'translate-x-2', 'scale-95', 'pointer-events-none');
                        sidebarCollapsedShell.classList.remove('opacity-100', 'translate-x-0', 'scale-100', 'pointer-events-auto');
                        sidebarCollapseButton.classList.add('hidden');
                        sidebarExpandButton.classList.add('hidden');
                        sidebar.classList.toggle('-translate-x-full', !mobileSidebarOpen);
                        sidebar.classList.toggle('translate-x-0', mobileSidebarOpen);
                        sidebar.classList.toggle('pointer-events-none', !mobileSidebarOpen);
                        sidebar.classList.toggle('pointer-events-auto', mobileSidebarOpen);
                    }

                    mobileSidebarBackdrop.classList.toggle('hidden', !mobileSidebarOpen);
                    mobileSidebarToggleButtons.forEach((button) => {
                        button.setAttribute('aria-expanded', mobileSidebarOpen ? 'true' : 'false');
                    });
                    mobileSidebarIcons.forEach((icon) => {
                        icon.classList.toggle('rotate-180', mobileSidebarOpen);
                    });
                };
                window.chatToggleMobileSidebar = (nextState = null) => {
                    setMobileSidebarOpen(nextState === null ? !mobileSidebarOpen : Boolean(nextState));
                };

                const escapeHtml = (value) => {
                    const span = document.createElement('span');
                    span.textContent = value ?? '';
                    return span.innerHTML;
                };

                const normalizeSearchText = (value) => {
                    return String(value ?? '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase();
                };

                const clearComposerMentions = () => {
                    composerMentionState = {
                        isOpen: false,
                        query: '',
                        start: null,
                        end: null,
                        selectedIndex: 0,
                        suggestions: [],
                    };

                    if (mentionSuggestionsPanel) {
                        mentionSuggestionsPanel.classList.add('hidden');
                    }

                    if (mentionSuggestionsList) {
                        mentionSuggestionsList.innerHTML = '';
                    }
                };

                const focusComposer = () => {
                    if (!input || composerDisabled) {
                        return;
                    }

                    try {
                        input.focus({ preventScroll: true });
                    } catch (error) {
                        input.focus();
                    }
                };

                const getComposerMentionContext = () => {
                    if (!currentConversationIsGroup || !input) {
                        return null;
                    }

                    const cursorPosition = input.selectionStart ?? input.value.length;
                    const textBeforeCursor = input.value.slice(0, cursorPosition);
                    const atIndex = textBeforeCursor.lastIndexOf('@');

                    if (atIndex === -1) {
                        return null;
                    }

                    const previousChar = atIndex > 0 ? textBeforeCursor[atIndex - 1] : '';
                    if (previousChar && !/[\s([{.,;:!?]/.test(previousChar)) {
                        return null;
                    }

                    const query = textBeforeCursor.slice(atIndex + 1);

                    if (/\s/.test(query) || /@/.test(query)) {
                        return null;
                    }

                    return {
                        start: atIndex,
                        end: cursorPosition,
                        query,
                    };
                };

                const getComposerMentionSuggestions = (query) => {
                    const normalizedQuery = normalizeSearchText(query);
                    const participants = Array.isArray(currentConversationParticipants)
                        ? currentConversationParticipants.filter((participant) => Number(participant?.id ?? 0) > 0)
                        : [];
                    const currentUserId = Number(@js($authUser->id ?? 0));

                    return participants
                        .filter((participant) => Number(participant.id) !== currentUserId)
                        .map((participant) => {
                            const name = String(participant.name || '');
                            const normalizedName = normalizeSearchText(name);
                            let score = 0;

                            if (normalizedQuery === '') {
                                score = 10;
                            } else if (normalizedName.startsWith(normalizedQuery)) {
                                score = 100 - Math.min(name.length, 40);
                            } else if (normalizedName.includes(normalizedQuery)) {
                                score = 50 - normalizedName.indexOf(normalizedQuery);
                            } else {
                                score = -1;
                            }

                            return score < 0 ? null : {
                                id: Number(participant.id),
                                name,
                                avatar_url: participant.avatar_url || '',
                                resolved_dealership_name: participant.resolved_dealership_name || 'Sin delegación',
                                extra_role_label: participant.extra_role_label || '',
                                chat_role_label: participant.chat_role_label || '',
                                is_disabled: Boolean(participant.is_disabled),
                                score,
                            };
                        })
                        .filter(Boolean)
                        .sort((first, second) => {
                            if (second.score !== first.score) {
                                return second.score - first.score;
                            }

                            return first.name.localeCompare(second.name, 'es');
                        })
                        .slice(0, 8);
                };

                const renderComposerMentionSuggestions = () => {
                    if (!mentionSuggestionsPanel || !mentionSuggestionsList) {
                        return;
                    }

                    const suggestions = Array.isArray(composerMentionState.suggestions) ? composerMentionState.suggestions : [];

                    if (!currentConversationIsGroup || suggestions.length === 0) {
                        mentionSuggestionsPanel.classList.add('hidden');
                        mentionSuggestionsList.innerHTML = '';
                        return;
                    }

                    mentionSuggestionsPanel.classList.remove('hidden');
                    mentionSuggestionsList.innerHTML = suggestions.map((suggestion, index) => {
                        const isActive = index === composerMentionState.selectedIndex;
                        const avatarUrl = suggestion.avatar_url || '{{ asset('images/users/hrmotor-default-user-avatar.png') }}';
                        const roleLabel = [suggestion.extra_role_label, suggestion.resolved_dealership_name].filter(Boolean).join(' · ') || suggestion.chat_role_label || 'Sin delegación';

                        return `
                            <button
                                type="button"
                                class="flex w-full cursor-pointer items-center gap-3 rounded-2xl px-3 py-2 text-left transition ${isActive ? 'bg-brand-primary/10 ring-1 ring-brand-primary/20' : 'hover:bg-slate-50'}"
                                data-chat-mention-option
                                data-mention-user-id="${escapeHtml(String(suggestion.id))}"
                                data-mention-user-name="${escapeHtml(suggestion.name)}"
                                data-mention-user-avatar="${escapeHtml(avatarUrl)}"
                                data-mention-user-role="${escapeHtml(roleLabel)}"
                                aria-selected="${isActive ? 'true' : 'false'}"
                            >
                                <img src="${escapeHtml(avatarUrl)}" alt="Avatar de ${escapeHtml(suggestion.name)}" class="h-10 w-10 shrink-0 rounded-2xl object-cover ${suggestion.is_disabled ? 'grayscale opacity-75' : ''}">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-brand-secondary">${escapeHtml(suggestion.name)}</p>
                                    <p class="truncate text-xs text-slate-500">${escapeHtml(roleLabel)}</p>
                                </div>
                            </button>
                        `;
                    }).join('');
                };

                const updateComposerMentionSuggestions = () => {
                    if (!currentConversationIsGroup) {
                        clearComposerMentions();
                        return;
                    }

                    const context = getComposerMentionContext();

                    if (!context) {
                        clearComposerMentions();
                        return;
                    }

                    const suggestions = getComposerMentionSuggestions(context.query);

                    composerMentionState = {
                        isOpen: suggestions.length > 0,
                        query: context.query,
                        start: context.start,
                        end: context.end,
                        selectedIndex: Math.min(composerMentionState.selectedIndex, Math.max(suggestions.length - 1, 0)),
                        suggestions,
                    };

                    if (!suggestions.length) {
                        clearComposerMentions();
                        return;
                    }

                    renderComposerMentionSuggestions();
                };

                const insertComposerMention = (suggestion) => {
                    if (!suggestion || !input) {
                        return;
                    }

                    const { start, end } = composerMentionState;

                    if (start === null || end === null) {
                        return;
                    }

                    const value = input.value;
                    const nextValue = `${value.slice(0, start)}@${suggestion.name} ${value.slice(end)}`;
                    const nextCursor = start + suggestion.name.length + 2;

                    input.value = nextValue;
                    input.focus();
                    input.setSelectionRange(nextCursor, nextCursor);

                    composerMentionIds = Array.from(new Set([...composerMentionIds, Number(suggestion.id)]));

                    composerMentionState = {
                        isOpen: false,
                        query: '',
                        start: null,
                        end: null,
                        selectedIndex: 0,
                        suggestions: [],
                    };

                    if (mentionSuggestionsPanel) {
                        mentionSuggestionsPanel.classList.add('hidden');
                    }

                    if (mentionSuggestionsList) {
                        mentionSuggestionsList.innerHTML = '';
                    }
                };

                const getLocalDateKey = (value) => {
                    if (!value) {
                        return '';
                    }

                    const date = new Date(value);

                    if (Number.isNaN(date.getTime())) {
                        return '';
                    }

                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');

                    return `${year}-${month}-${day}`;
                };

                const formatChatDateLabel = (value) => {
                    if (!value) {
                        return '';
                    }

                    const date = new Date(value);

                    if (Number.isNaN(date.getTime())) {
                        return '';
                    }

                    const today = new Date();
                    const startOfToday = new Date(today.getFullYear(), today.getMonth(), today.getDate());
                    const startOfMessage = new Date(date.getFullYear(), date.getMonth(), date.getDate());
                    const diffDays = Math.round((startOfToday.getTime() - startOfMessage.getTime()) / 86400000);

                    if (diffDays === 0) {
                        return 'Hoy';
                    }

                    if (diffDays === 1) {
                        return 'Ayer';
                    }

                    const startOfWeek = new Date(startOfToday);
                    const mondayOffset = (startOfWeek.getDay() + 6) % 7;
                    startOfWeek.setDate(startOfWeek.getDate() - mondayOffset);

                    if (startOfMessage >= startOfWeek) {
                        return date.toLocaleDateString('es-ES', { weekday: 'long' });
                    }

                    return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' });
                };

                const renderDateSeparatorMarkup = (label) => {
                    if (!label) {
                        return '';
                    }

                    return `
                        <div class="my-5 flex justify-center" data-chat-date-separator>
                            <span class="inline-flex rounded-full bg-sky-100 px-3 py-1 text-[11px] font-semibold text-sky-700 shadow-sm ring-1 ring-sky-200">
                                ${escapeHtml(label)}
                            </span>
                        </div>
                    `;
                };

                const autoScroll = () => {
                    wrapper.scrollTop = wrapper.scrollHeight;
                };

                const clearChatError = () => {
                    chatError.textContent = '';
                    chatError.classList.add('hidden');
                };

                const buildConversationMessagesUrl = (conversationId, params = {}) => {
                    if (!messagesUrlTemplate) {
                        return '';
                    }

                    const url = new URL(messagesUrlTemplate.replace('__CONVERSATION_ID__', encodeURIComponent(String(conversationId))), window.location.origin);

                    Object.entries(params || {}).forEach(([key, value]) => {
                        if (value === null || value === undefined || value === '') {
                            return;
                        }

                        url.searchParams.set(key, String(value));
                    });

                    return `${url.pathname}${url.search}`;
                };

                const buildConversationStoreUrl = (conversationId) => {
                    if (!storeUrlTemplate) {
                        return '';
                    }

                    return storeUrlTemplate.replace('__CONVERSATION_ID__', encodeURIComponent(String(conversationId)));
                };

                const buildConversationMessageUpdateUrl = (conversationId, messageId) => {
                    const template = wrapper?.dataset.chatMessageUpdateUrlTemplate || '';

                    return template
                        .replace('__CONVERSATION_ID__', encodeURIComponent(String(conversationId)))
                        .replace('__MESSAGE_ID__', encodeURIComponent(String(messageId)));
                };

                const buildConversationMessageDestroyUrl = (conversationId, messageId) => {
                    const template = wrapper?.dataset.chatMessageDestroyUrlTemplate || '';

                    return template
                        .replace('__CONVERSATION_ID__', encodeURIComponent(String(conversationId)))
                        .replace('__MESSAGE_ID__', encodeURIComponent(String(messageId)));
                };

                const renderAttachmentMarkup = (attachment) => {
                    const url = attachment.url ?? '';
                    const name = attachment.original_name ?? 'archivo';
                    const sizeLabel = attachment.size_label ?? '';
                    const isImageAttachment = Boolean(attachment.is_image ?? false);
                    const isJsonAttachment = Boolean(attachment.is_json ?? false) || name.toLowerCase().endsWith('.json') || String(attachment.mime_type ?? '') === 'application/json';

                    if (isImageAttachment) {
                        return `
                            <button
                                type="button"
                                data-chat-image-src="${escapeHtml(url)}"
                                data-chat-image-alt="${escapeHtml(name)}"
                                data-chat-image-title="${escapeHtml(name)}"
                                onclick="window.dispatchEvent(new CustomEvent('open-image', { detail: { src: this.dataset.chatImageSrc, alt: this.dataset.chatImageAlt, title: this.dataset.chatImageTitle } }))"
                                class="group/image relative block cursor-pointer overflow-hidden rounded-[1rem] border border-black/5 bg-white/50 text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                                aria-label="Ver ${escapeHtml(name)}"
                            >
                                <img src="${escapeHtml(url)}" alt="${escapeHtml(name)}" class="max-h-72 w-full object-cover transition duration-300 group-hover/image:scale-105 group-hover/image:brightness-75">
                                <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-brand-secondary/0 text-xs font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover/image:bg-brand-secondary/30 group-hover/image:opacity-100">
                                    Ver
                                </span>
                            </button>
                        `;
                    }

                    return `
                        <a href="${escapeHtml(url)}"${isJsonAttachment ? ` download="${escapeHtml(name)}"` : ' target="_blank" rel="noopener"'} class="flex items-center gap-3 rounded-[1rem] border border-black/5 bg-white/60 px-3 py-2 transition hover:bg-white">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-brand-secondary">${escapeHtml(name)}</span>
                                ${sizeLabel !== '' ? `<span class="block text-xs text-slate-500">${escapeHtml(sizeLabel)}</span>` : ''}
                            </span>
                    </a>
                `;
                };

                const isMessageActionLocked = (message) => {
                    if (!message?.created_at) {
                        return false;
                    }

                    const createdAt = new Date(message.created_at);

                    if (Number.isNaN(createdAt.getTime())) {
                        return false;
                    }

                    return (Date.now() - createdAt.getTime()) > messageActionWindowMs;
                };

                const renderMessageMenuMarkup = (message) => {
                    const isLocked = isMessageActionLocked(message);
                    const editButtonClass = isLocked
                        ? 'flex w-full cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-medium text-slate-300 transition'
                        : 'flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-medium text-brand-secondary transition hover:bg-slate-50';
                    const deleteButtonClass = isLocked
                        ? 'flex w-full cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-medium text-slate-300 transition'
                        : 'flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-2 text-left text-sm font-medium text-rose-600 transition hover:bg-rose-50';
                    const editIconClass = isLocked ? 'h-4 w-4 shrink-0 text-slate-300' : 'h-4 w-4 shrink-0 text-slate-400';
                    const deleteIconClass = isLocked ? 'h-4 w-4 shrink-0 text-slate-300' : 'h-4 w-4 shrink-0 text-rose-500';
                    const disabledAttributes = isLocked ? ' disabled aria-disabled="true"' : '';

                    return `
                    <div class="mt-0.5 grid gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg">
                        <button type="button" class="${editButtonClass}" data-chat-message-edit${disabledAttributes}>
                            <svg xmlns="http://www.w3.org/2000/svg" class="${editIconClass}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 3.487a2.5 2.5 0 0 1 3.536 3.536L7.5 19.92 3 21l1.08-4.5L16.862 3.487Z" />
                            </svg>
                            <span>Editar mensaje</span>
                        </button>
                        <button type="button" class="${deleteButtonClass}" data-chat-message-delete${disabledAttributes}>
                            <svg xmlns="http://www.w3.org/2000/svg" class="${deleteIconClass}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6m-8 0v13A2 2 0 0 0 10 21h4a2 2 0 0 0 2-2V6m-8 0h8" />
                            </svg>
                            <span>Eliminar mensaje</span>
                        </button>
                    </div>
                `;};

                const renderDeleteConfirmMarkup = (messageId) => `
                    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4" data-chat-delete-modal-overlay>
                        <div class="w-full max-w-sm rounded-[1.6rem] bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="chat-delete-title">
                            <div class="flex items-start gap-3">
                                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rose-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 2.82 18a2 2 0 0 0 1.75 3h15.86a2 2 0 0 0 1.75-3L14.71 3.86a2 2 0 0 0-3.42 0Z" />
                                    </svg>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 id="chat-delete-title" class="text-base font-semibold text-brand-secondary">Eliminar mensaje</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">Esta acción eliminará el mensaje para todos los participantes.</p>
                                </div>
                            </div>
                            <div class="mt-5 flex items-center justify-end gap-2">
                                <button type="button" class="cursor-pointer rounded-full px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100" data-chat-delete-cancel>Cancelar</button>
                                <button type="button" class="cursor-pointer rounded-full bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-500" data-chat-delete-confirm data-message-id="${escapeHtml(String(messageId ?? ''))}">Eliminar</button>
                            </div>
                        </div>
                    </div>
                `;

                const findMessageMenuSlot = (messageId) => messagesContainer.querySelector(`[data-message-id="${CSS.escape(String(messageId))}"] [data-chat-message-menu-slot]`);

                const setMessageMenuState = (messageId, open) => {
                    const slot = findMessageMenuSlot(messageId);
                    const message = currentMessages.find((item) => Number(item.id) === Number(messageId));

                    if (!slot) {
                        return;
                    }

                    if (open) {
                        slot.classList.remove('hidden');
                        slot.innerHTML = renderMessageMenuMarkup(message);
                        return;
                    }

                    slot.innerHTML = '';
                    slot.classList.add('hidden');
                };

                const refreshActiveMessageMenu = () => {
                    if (!activeMessageMenuId) {
                        return;
                    }

                    setMessageMenuState(activeMessageMenuId, true);
                };

                const closeDeleteConfirmModal = () => {
                    const existingOverlay = document.querySelector('[data-chat-delete-modal-overlay]');

                    if (existingOverlay) {
                        existingOverlay.remove();
                    }

                    pendingDeleteMessageId = null;
                };

                const openDeleteConfirmModal = (messageId) => {
                    closeDeleteConfirmModal();
                    pendingDeleteMessageId = Number(messageId);
                    document.body.insertAdjacentHTML('beforeend', renderDeleteConfirmMarkup(messageId));
                };

                const resizeChatEditInput = (textarea) => {
                    if (!textarea) {
                        return;
                    }

                    const computedStyle = window.getComputedStyle(textarea);
                    const canvas = resizeChatEditInput._canvas || (resizeChatEditInput._canvas = document.createElement('canvas'));
                    const context = canvas.getContext('2d');

                    if (!context) {
                        return;
                    }

                    context.font = `${computedStyle.fontWeight} ${computedStyle.fontSize} ${computedStyle.fontFamily}`;
                    const lines = String(textarea.value || '').split('\n');
                    const contentWidth = Math.max(
                        ...lines.map((line) => context.measureText(line || ' ').width),
                        0,
                    );
                    const paddingX = (parseFloat(computedStyle.paddingLeft || '0') || 0) + (parseFloat(computedStyle.paddingRight || '0') || 0);
                    const borderX = (parseFloat(computedStyle.borderLeftWidth || '0') || 0) + (parseFloat(computedStyle.borderRightWidth || '0') || 0);
                    const minWidth = 128;
                    const maxWidth = 640;
                    const nextWidth = Math.min(Math.max(Math.ceil(contentWidth + paddingX + borderX), minWidth), maxWidth);

                    textarea.style.width = `${nextWidth}px`;
                    textarea.style.maxWidth = `${maxWidth}px`;
                    textarea.style.height = 'auto';
                    textarea.style.height = `${textarea.scrollHeight}px`;
                };

                const renderMessage = (message, index, messages) => {
                    const isMine = Boolean(message.is_mine);
                    const isSystem = Boolean(message.is_system);
                    const previousMessage = messages[index - 1];
                    const nextMessage = messages[index + 1];
                    const currentDateKey = getLocalDateKey(message.created_at);
                    const previousDateKey = getLocalDateKey(previousMessage?.created_at);
                    const nextDateKey = getLocalDateKey(nextMessage?.created_at);
                    const currentTimeLabel = message.created_at_label || '';
                    const previousTimeLabel = previousMessage?.created_at_label || '';
                    const nextTimeLabel = nextMessage?.created_at_label || '';
                    const showTime = Boolean(index === messages.length - 1 || nextDateKey !== currentDateKey || nextTimeLabel !== currentTimeLabel);
                    const senderChanged = currentConversationIsGroup
                        && index > 0
                        && Number(previousMessage?.sender_id || 0) !== Number(message.sender_id || 0);
                    const topMarginClass = index === 0
                        ? 'mt-0'
                        : senderChanged
                            ? 'mt-4'
                            : ((previousDateKey === currentDateKey && previousTimeLabel === currentTimeLabel) ? 'mt-0.5' : 'mt-3');
                    const messageAttachments = Array.isArray(message.attachments) ? message.attachments : [];
                    const pendingAttachments = Array.isArray(message.pending_attachments) ? message.pending_attachments : [];
                    const body = message.body || '';
                    const attachmentsHtml = messageAttachments.map((attachment) => renderAttachmentMarkup(attachment)).join('');
                    const pendingAttachmentsHtml = pendingAttachments.length > 0
                        ? pendingAttachments.map((attachment) => `
                            <div class="rounded-[1rem] border border-dashed border-amber-200 bg-amber-50/70 px-3 py-2">
                                <p class="truncate text-sm font-medium text-amber-900">${escapeHtml(String(attachment.original_name || 'archivo'))}</p>
                                ${attachment.size_label ? `<p class="mt-0.5 text-xs text-amber-700">${escapeHtml(String(attachment.size_label))}</p>` : ''}
                            </div>
                        `).join('')
                        : '';
                    const isDeleted = Boolean(message.deleted_at);
                    const isEdited = Boolean(message.edited_at && !isDeleted);
                    const isPending = Boolean(message.is_pending);
                    const isEditing = isMine && Number(editingMessageId || 0) === Number(message.id);
                    const editableBody = editingMessageDraft !== '' ? editingMessageDraft : body;
                    const showSenderName = currentConversationIsGroup
                        && !isSystem
                        && !isMine
                        && !isDeleted
                        && (
                            index === 0
                            || senderChanged
                            || previousTimeLabel !== currentTimeLabel
                        );
                    const senderNameHtml = showSenderName
                        ? `<p class="mb-1 truncate text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">${escapeHtml(String(message.sender_name || 'Usuario'))}</p>`
                        : '';
                    const mentionHighlightClass = !isMine && !isSystem && !isDeleted && Boolean(message.mentions_me)
                        ? ' ring-2 ring-sky-300 bg-sky-50/80 shadow-md'
                        : '';
                    const renderedBodyHtml = String(message.rendered_body_html || '');

                    return `
                        <div class="flex ${isSystem ? 'justify-center' : (isMine ? 'justify-end' : 'justify-start')} ${topMarginClass}" data-message-id="${message.id}" data-chat-message-owner="${isMine ? '1' : '0'}">
                            <div class="flex w-fit max-w-[78%] min-w-0 flex-col ${isSystem ? 'items-center' : (isMine ? 'items-end' : 'items-start')}">
                                ${isSystem ? `
                                    <div class="max-w-full break-words [overflow-wrap:anywhere] rounded-full bg-slate-100 px-4 py-2 text-center text-[12px] leading-5 text-slate-500 shadow-sm ring-1 ring-slate-200" data-chat-message-content>
                                        ${escapeHtml(body)}
                                    </div>
                                    <div class="${showTime ? 'mt-1' : 'mt-0.5'} flex items-center gap-1 justify-center text-[11px] text-slate-400">
                                        <span data-message-time ${showTime ? '' : 'class="hidden"'}>${escapeHtml(currentTimeLabel)}</span>
                                    </div>
                                ` : `
                                <div class="group relative min-w-[5.5rem] max-w-full overflow-x-hidden rounded-[1.1rem] px-3 py-2 shadow-sm transition ${isDeleted ? 'border border-dashed border-slate-300 bg-slate-100 text-slate-500' : (isMine ? 'bg-[#d9fdd3] pb-4 pr-8 text-slate-800 hover:shadow-md' : 'border border-slate-200 bg-white text-brand-secondary')}${mentionHighlightClass}${isPending ? ' ring-1 ring-amber-200' : ''}">
                                    ${senderNameHtml}
                                    ${isEditing ? `
                                        <textarea rows="1" class="min-w-[8rem] max-w-full resize-none overflow-hidden whitespace-pre-wrap break-words rounded-[1rem] border border-brand-primary/20 bg-white px-3 py-2 text-[15px] text-brand-secondary outline-none focus:border-brand-primary focus:ring-4 focus:ring-brand-primary/10" data-chat-edit-input>${escapeHtml(editableBody)}</textarea>
                                        <div class="mt-3 flex items-center justify-end gap-2">
                                            <button type="button" class="cursor-pointer rounded-full px-3 py-1.5 text-xs font-semibold text-slate-500 transition hover:bg-slate-100" data-chat-edit-cancel>Cancelar</button>
                                            <button type="button" class="cursor-pointer rounded-full bg-brand-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90" data-chat-edit-save>Guardar</button>
                                        </div>
                                    ` : isDeleted ? `
                                        <div class="flex items-center gap-2 text-sm font-medium text-slate-500">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 2.82 18a2 2 0 0 0 1.75 3h15.86a2 2 0 0 0 1.75-3L14.71 3.86a2 2 0 0 0-3.42 0Z" />
                                            </svg>
                                            <span>Este mensaje ha sido eliminado.</span>
                                        </div>
                                    ` : `
                                        ${body !== '' ? `<p class="whitespace-pre-line break-words [overflow-wrap:anywhere] text-[15px] leading-[1.45]">${renderedBodyHtml || escapeHtml(body)}</p>` : ''}
                                        ${attachmentsHtml !== '' ? `<div class="${body !== '' ? 'mt-2' : ''} space-y-2">${attachmentsHtml}</div>` : ''}
                                        ${pendingAttachmentsHtml !== '' ? `<div class="${body !== '' || attachmentsHtml !== '' ? 'mt-2' : ''} space-y-2">${pendingAttachmentsHtml}</div>` : ''}
                                        ${isPending ? `
                                            <div class="mt-1 flex items-center gap-1.5 text-[11px] text-amber-700">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0 animate-pulse" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                    <path d="M12 8v5l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
                                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8"></circle>
                                                </svg>
                                                <span>Enviando...</span>
                                            </div>
                                        ` : ''}
                                        ${isMine && !isDeleted ? `<button type="button" class="absolute bottom-1 left-2 inline-flex h-5 w-5 cursor-pointer items-center justify-center rounded-full bg-white/75 text-slate-500 opacity-0 shadow-sm transition hover:bg-white hover:text-brand-secondary group-hover:opacity-100" aria-label="Abrir opciones del mensaje" data-chat-message-trigger>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                                <path d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z" />
                                            </svg>
                                        </button>` : ''}
                                        ${isMine && !isDeleted && !isPending ? `<span class="absolute bottom-1.5 right-3 inline-flex items-center ${message.read_at ? 'text-sky-500' : 'text-slate-400'}" data-message-checks>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none">
                                                <line x1="13.22" y1="16.5" x2="21" y2="7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                                                <polyline points="3 11.88 7 16.5 14.78 7.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" fill="none" />
                                            </svg>
                                        </span>` : ''}
                                    `}
                                </div>
                                <div class="hidden mt-0.5" data-chat-message-menu-slot></div>
                                <div class="${showTime ? 'mt-1' : 'mt-0.5'} flex items-center gap-1 text-[11px] ${isMine ? 'justify-end text-slate-500' : 'justify-start text-slate-400'}">
                                    ${isEdited ? '<span class="text-[10px] italic text-slate-400">Editado</span>' : ''}
                                    <span data-message-time ${showTime ? '' : 'class="hidden"'}>${escapeHtml(currentTimeLabel)}</span>
                                </div>
                                `}
                            </div>
                        </div>
                    `;
                };

                const renderMessages = (messages, { preserveScroll = 'none' } = {}) => {
                    const safeMessages = Array.isArray(messages) ? messages : [];
                    const previousScrollTop = wrapper.scrollTop;
                    const previousScrollHeight = wrapper.scrollHeight;
                    currentMessages = safeMessages;
                    currentMessagesFingerprint = buildMessagesFingerprint(safeMessages);

                    if (safeMessages.length === 0) {
                        activeMessageMenuId = null;
                        editingMessageId = null;
                        editingMessageDraft = '';
                        currentMessagesFingerprint = buildMessagesFingerprint(safeMessages);
                        const isGroupConversation = Boolean(currentConversationIsGroup);
                        messagesContainer.innerHTML = `
                            <div class="flex min-h-full items-center justify-center">
                                <div class="max-w-md rounded-[2rem] border border-dashed border-slate-300 bg-white px-8 py-10 text-center shadow-sm">
                                    <p class="text-lg font-bold text-brand-secondary">${isGroupConversation ? 'Grupo listo para empezar' : 'Chat listo para empezar'}</p>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">${isGroupConversation ? 'Aquí verás los mensajes del grupo cuando alguien escriba el primero.' : 'Aquí verás la conversación cuando elijas un compañero.'}</p>
                                </div>
                            </div>
                        `;
                        latestMessageId = 0;
                        return;
                    }

                    const renderedMessages = [];

                    safeMessages.forEach((message, index) => {
                        const previousMessage = safeMessages[index - 1];
                        const currentDateKey = getLocalDateKey(message.created_at);
                        const previousDateKey = getLocalDateKey(previousMessage?.created_at);

                        if (index === 0 || previousDateKey !== currentDateKey) {
                            renderedMessages.push(renderDateSeparatorMarkup(formatChatDateLabel(message.created_at)));
                        }

                        renderedMessages.push(renderMessage(message, index, safeMessages));
                    });

                    messagesContainer.innerHTML = renderedMessages.join('');
                    latestMessageId = Number(safeMessages[safeMessages.length - 1]?.id ?? 0);

                    if (preserveScroll === 'exact') {
                        wrapper.scrollTop = previousScrollTop;
                        return;
                    }

                    if (preserveScroll === 'delta') {
                        const nextScrollHeight = wrapper.scrollHeight;
                        wrapper.scrollTop = previousScrollTop + (nextScrollHeight - previousScrollHeight);
                        return;
                    }

                    autoScroll();
                };

                const applyMessagesPayload = (messages, { preserveScroll = 'none', replace = true } = {}) => {
                    const safeIncomingMessages = Array.isArray(messages) ? messages : [];
                    const nextMessages = replace
                        ? sortMessagesChronologically(safeIncomingMessages)
                        : mergeMessagesById(currentMessages, safeIncomingMessages);

                    renderMessages(nextMessages, { preserveScroll });
                };

                const loadOlderMessages = async () => {
                    if (!wrapper || !hasComposer || !hasMoreOlderMessages || isLoadingOlderMessages) {
                        return;
                    }

                    const conversationId = Number(wrapper.dataset.conversationId || sidebarSelectedConversationId || 0);
                    const requestRevision = conversationRevision;
                    const oldestMessageId = getOldestMessageId();

                    if (!conversationId || !oldestMessageId) {
                        hasMoreOlderMessages = false;
                        return;
                    }

                    isLoadingOlderMessages = true;
                    setHistoryLoaderVisible(true);

                    try {
                        const response = await fetch(buildConversationMessagesUrl(conversationId, {
                            before_message_id: oldestMessageId,
                            limit: historyPageSize,
                        }), {
                            headers: {
                                ...getRealtimeHeaders(),
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                        });

                        if (!response.ok) {
                            return;
                        }

                        if (requestRevision !== conversationRevision || Number(wrapper.dataset.conversationId || 0) !== conversationId) {
                            return;
                        }

                        const payload = await response.json();
                        const olderMessages = Array.isArray(payload.messages) ? payload.messages : [];

                        if (olderMessages.length === 0) {
                            hasMoreOlderMessages = Boolean(payload.has_more_older);
                            return;
                        }

                        hasMoreOlderMessages = Boolean(payload.has_more_older);
                        applyMessagesPayload([...olderMessages, ...currentMessages], {
                            preserveScroll: 'delta',
                            replace: true,
                        });
                    } catch (error) {
                        console.error(error);
                    } finally {
                        isLoadingOlderMessages = false;
                        setHistoryLoaderVisible(false);
                    }
                };

                const handleMessagesScroll = () => {
                    if (!wrapper || !hasComposer || isLoadingOlderMessages || !hasMoreOlderMessages) {
                        return;
                    }

                    if (wrapper.scrollTop > 80) {
                        return;
                    }

                    void loadOlderMessages();
                };

                const cancelInlineEdit = () => {
                    editingMessageId = null;
                    editingMessageDraft = '';
                };

                const beginInlineEdit = (message) => {
                    if (!message || !Boolean(message.is_mine)) {
                        return;
                    }

                    if (isMessageActionLocked(message)) {
                        showChatError(messageActionWindowMessage);
                        return;
                    }

                    editingMessageId = Number(message.id);
                    editingMessageDraft = String(message.body || '');
                    closeMessageMenu();
                    renderMessages(currentMessages);

                    requestAnimationFrame(() => {
                        const editInput = messagesContainer.querySelector('[data-chat-edit-input]');
                        if (!editInput) {
                            return;
                        }

                        resizeChatEditInput(editInput);
                        editInput.focus();
                        editInput.setSelectionRange(editInput.value.length, editInput.value.length);
                    });
                };

                const saveInlineEdit = async () => {
                    const conversationId = Number(wrapper.dataset.conversationId || sidebarSelectedConversationId || 0);
                    const message = currentMessages.find((item) => Number(item.id) === Number(editingMessageId));

                    if (!conversationId || !message) {
                        cancelInlineEdit();
                        renderMessages(currentMessages);
                        return;
                    }

                    if (isMessageActionLocked(message)) {
                        cancelInlineEdit();
                        renderMessages(currentMessages);
                        showChatError(messageActionWindowMessage);
                        return;
                    }

                    const editInput = messagesContainer.querySelector('[data-chat-edit-input]');
                    const body = editInput ? editInput.value : editingMessageDraft;

                    if (body.trim() === '' && (!Array.isArray(message.attachments) || message.attachments.length === 0)) {
                        showChatError('Escribe un mensaje o conserva un adjunto.');
                        return;
                    }

                    cancelInlineEdit();
                    renderMessages(currentMessages);

                    const formData = new FormData();
                    formData.append('_token', csrfToken);
                    formData.append('_method', 'PATCH');
                    formData.append('body', body);

                    try {
                        const response = await fetch(buildConversationMessageUpdateUrl(conversationId, message.id), {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                            body: formData,
                        });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            showChatError(payload?.message || 'No se pudo editar el mensaje.');
                            return;
                        }

                        const updatedMessage = payload?.message
                            ? {
                                ...payload.message,
                                is_pending: false,
                            }
                            : {
                                ...message,
                                body: body.trim(),
                                rendered_body_html: escapeHtml(body.trim()),
                                edited_at: new Date().toISOString(),
                                is_pending: false,
                            };

                        applyMessagesPayload(
                            mergeMessagesById(currentMessages, [updatedMessage]),
                            {
                                preserveScroll: 'exact',
                                replace: true,
                            }
                        );
                        await refreshSidebar();
                    } catch (error) {
                        console.error(error);
                        showChatError('No se pudo editar el mensaje.');
                    }
                };

                const closeMessageMenu = () => {
                    if (activeMessageMenuId) {
                        setMessageMenuState(activeMessageMenuId, false);
                    }

                    activeMessageMenuId = null;
                };

                const openMessageMenu = (messageElement) => {
                    const messageId = Number(messageElement?.dataset.messageId ?? 0);
                    const isMine = messageElement?.dataset.chatMessageOwner === '1';

                    if (!messageId || !isMine) {
                        return;
                    }

                    if (Number(activeMessageMenuId || 0) === messageId) {
                        closeMessageMenu();
                        return;
                    }

                    if (activeMessageMenuId) {
                        setMessageMenuState(activeMessageMenuId, false);
                    }

                    activeMessageMenuId = messageId;
                    editingMessageId = null;
                    editingMessageDraft = '';
                    setMessageMenuState(messageId, true);

                };

                const getActiveMessage = () => currentMessages.find((message) => Number(message.id) === Number(activeMessageMenuId));

                const applyMessageAction = async (action) => {
                    const conversationId = Number(wrapper.dataset.conversationId || sidebarSelectedConversationId || 0);
                    const message = getActiveMessage();

                    if (!conversationId || !message) {
                        closeMessageMenu();
                        return;
                    }

                    closeMessageMenu();

                    if (action === 'edit') {
                        if (isMessageActionLocked(message)) {
                            showChatError(messageActionWindowMessage);
                            return;
                        }

                        beginInlineEdit(message);
                        return;
                    }

                    if (action === 'delete') {
                        if (isMessageActionLocked(message)) {
                            showChatError(messageActionWindowMessage);
                            return;
                        }

                        openDeleteConfirmModal(message.id);
                        return;
                    }
                };

                const deleteActiveMessage = async () => {
                    const conversationId = Number(wrapper.dataset.conversationId || sidebarSelectedConversationId || 0);
                    const message = currentMessages.find((item) => Number(item.id) === Number(pendingDeleteMessageId));

                    if (!conversationId || !message) {
                        closeDeleteConfirmModal();
                        return;
                    }

                    if (isMessageActionLocked(message)) {
                        showChatError(messageActionWindowMessage);
                        closeDeleteConfirmModal();
                        return;
                    }

                    closeDeleteConfirmModal();

                    try {
                        const response = await fetch(buildConversationMessageDestroyUrl(conversationId, message.id), {
                                method: 'POST',
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest',
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                },
                                body: new URLSearchParams({ _method: 'DELETE' }),
                            });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            showChatError(payload?.message || 'No se pudo eliminar el mensaje.');
                            return;
                        }

                        applyMessagesPayload(
                            currentMessages.map((item) => {
                                if (Number(item.id) !== Number(message.id)) {
                                    return item;
                                }

                                return {
                                    ...item,
                                    deleted_at: new Date().toISOString(),
                                    edited_at: item.edited_at || null,
                                };
                            }),
                            {
                                preserveScroll: 'exact',
                                replace: true,
                            }
                        );
                        await refreshSidebar();
                    } catch (error) {
                        console.error(error);
                        showChatError('No se pudo eliminar el mensaje.');
                    }
                };

                const renderConversation = (conversation) => {
                    const isGroup = Boolean(conversation.conversation_is_group);
                    const isDealershipGroup = isGroup && conversation.conversation_system_group_type === '{{ \App\Models\CompanyChatGroup::SYSTEM_GROUP_TYPE_DEALERSHIP }}';
                    const selectedId = isGroup ? Number(conversation.conversation_id || 0) : Number(conversation.id);
                    const isSelected = selectedId === Number(sidebarSelectedConversationId);
                    const itemClass = isSelected ? 'bg-brand-primary/10' : 'hover:bg-slate-50';
                    const unreadBadge = Number(conversation.unread_messages_count || 0);
                    const participantCount = Number(conversation.conversation_participants_count || 0);
                    const nameClass = isGroup ? 'text-brand-secondary' : (conversation.partner_is_favorite ? 'text-amber-600' : 'text-brand-secondary');
                    const unreadHtml = unreadBadge > 0
                        ? `<span class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-primary px-1 text-[11px] font-semibold text-white" data-chat-unread-badge>${unreadBadge}</span>`
                        : `<span class="absolute -right-1 -top-1 hidden h-5 min-w-5 items-center justify-center rounded-full bg-brand-primary px-1 text-[11px] font-semibold text-white" data-chat-unread-badge></span>`;
                    const avatarHtml = isGroup
                        ? (conversation.conversation_avatar_url
                            ? `<img src="${escapeHtml(conversation.conversation_avatar_url)}" alt="Avatar de ${escapeHtml(conversation.conversation_name || 'Grupo')}" class="h-11 w-11 rounded-2xl object-cover">`
                            : `<div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-primary/10 text-brand-primary"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 21C5 17.134 8.13401 14 12 14C15.866 14 19 17.134 19 21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>`)
                        : `<img src="${escapeHtml(conversation.partner_avatar_url || '{{ asset('images/users/hrmotor-default-user-avatar.png') }}')}" alt="Avatar de ${escapeHtml(conversation.partner_name || 'Usuario')}" class="h-11 w-11 rounded-2xl object-cover">`;
                    const roleHtml = isGroup
                        ? `<span>${participantCount} participante${participantCount === 1 ? '' : 's'}</span>`
                        : `<span>${escapeHtml(conversation.partner_chat_role_label || '')}</span>${conversation.partner_is_disabled ? '<span class="ml-2 inline-flex align-middle text-amber-500" title="Usuario desactivado" aria-label="Usuario desactivado"><svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 15H12.01M12 12V9M4.98207 19H19.0179C20.5615 19 21.5233 17.3256 20.7455 15.9923L13.7276 3.96153C12.9558 2.63852 11.0442 2.63852 10.2724 3.96153L3.25452 15.9923C2.47675 17.3256 3.43849 19 4.98207 19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>' : ''}`;

                    const href = isGroup
                        ? `{{ route('chat.beta') }}?group=${encodeURIComponent(conversation.group_id || conversation.id)}`
                        : `{{ route('chat.beta') }}?conversation=${encodeURIComponent(conversation.id)}`;
                    const linkAttribute = isGroup ? 'data-chat-group-link' : 'data-chat-conversation-link';

                    return `
                        <a href="${href}"
                            ${linkAttribute}
                            ${isGroup ? '' : `data-chat-conversation-id="${conversation.id}"`}
                            class="group flex w-full cursor-pointer items-center gap-3 px-4 py-3 transition ${itemClass}">
                            <div class="relative shrink-0">
                                ${avatarHtml}
                                ${unreadHtml}
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold ${nameClass}" data-chat-partner-name>${escapeHtml(conversation.conversation_name || conversation.partner_name || 'Conversación')}</p>
                                        <p class="truncate text-xs text-slate-500" data-chat-partner-role>
                                            ${roleHtml}
                                        </p>
                                        <p class="truncate text-xs text-slate-500" data-chat-last-message>${escapeHtml(conversation.last_message_excerpt || 'Empieza la conversación')}</p>
                                    </div>
                                    <span class="shrink-0 text-[11px] text-slate-400" data-chat-last-message-at>${escapeHtml(conversation.last_message_at_label || '')}</span>
                                </div>
                            </div>
                        </a>
                    `;
                };

                const renderFavoriteContact = (contact) => {
                    return `
                        <a href="{{ route('chat.beta') }}?recipient=${encodeURIComponent(contact.id)}"
                            data-chat-recipient-link
                            class="group flex w-full cursor-pointer items-center gap-3 px-4 py-3 transition hover:bg-slate-50">
                            <div class="relative shrink-0">
                                <img src="${escapeHtml(contact.avatar_url || '{{ asset('images/users/hrmotor-default-user-avatar.png') }}')}"
                                    alt="Avatar de ${escapeHtml(contact.name || 'Usuario')}"
                                    class="h-11 w-11 rounded-2xl object-cover">
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-amber-600">${escapeHtml(contact.name || 'Usuario')}</p>
                                        <p class="truncate text-xs text-slate-500">${escapeHtml(contact.chat_role_label || "")}${contact.chat_role_label ? " &middot; " : ""}${escapeHtml(contact.resolved_dealership_name || "Sin delegación")}</p>
                                    </div>
                                </div>
                            </div>
                        </a>
                    `;
                };

                const setHeaderFavoriteState = (isFavorite) => {
                    if (headerFavoriteStar) {
                        headerFavoriteStar.classList.toggle('hidden', !isFavorite);
                    }

                    if (headerFavoriteToggleLabel) {
                        headerFavoriteToggleLabel.textContent = isFavorite ? 'Quitar de favoritos' : 'Marcar como favorito';
                    }
                };

                const renderHeaderRole = (payload) => {
                    if (!headerPrivateRole) {
                        return;
                    }

                    if (payload.conversation_is_group) {
                        headerPrivateRole.textContent = '';
                        headerPrivateRole.classList.add('hidden');
                        return;
                    }

                    headerPrivateRole.classList.remove('hidden');
                    const partnerRoleLabel = escapeHtml(payload.partner_chat_role_label || '');
                    const partnerDealershipName = escapeHtml(payload.partner_dealership_name || 'Sin delegación');
                    const disabledBadge = payload.partner_is_disabled
                        ? ' <span class="ml-2 inline-flex align-middle text-amber-500" title="Usuario desactivado" aria-label="Usuario desactivado"><svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 15H12.01M12 12V9M4.98207 19H19.0179C20.5615 19 21.5233 17.3256 20.7455 15.9923L13.7276 3.96153C12.9558 2.63852 11.0442 2.63852 10.2724 3.96153L3.25452 15.9923C2.47675 17.3256 3.43849 19 4.98207 19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
                        : '';

                    headerPrivateRole.innerHTML = `${partnerRoleLabel}${partnerRoleLabel ? ' &middot; ' : ''}${partnerDealershipName}${disabledBadge}`;
                };

                const closeGroupModal = () => {
                    if (!groupModalOverlay) {
                        return;
                    }

                    groupModalOverlay.classList.add('hidden');
                    groupModalOverlay.classList.remove('flex');
                    if (groupModalMembers) {
                        groupModalMembers.innerHTML = '';
                    }
                };

                const renderGroupModalMembers = (members = []) => {
                    if (!groupModalMembers) {
                        return;
                    }

                    if (!members.length) {
                        groupModalMembers.innerHTML = '<div class="rounded-2xl border border-dashed border-slate-200 px-4 py-5 text-sm text-slate-500">No hay participantes para mostrar.</div>';
                        return;
                    }

                    groupModalMembers.innerHTML = members.map((member) => `
                        <a href="${escapeHtml(member.profile_url || '#')}"
                            class="group flex items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 transition hover:border-brand-primary/30 hover:bg-slate-50">
                            <img src="${escapeHtml(member.avatar_url || '{{ asset('images/users/hrmotor-default-user-avatar.png') }}')}"
                                alt="Avatar de ${escapeHtml(member.name || 'Usuario')}"
                                class="h-11 w-11 shrink-0 rounded-2xl object-cover">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-brand-secondary">${escapeHtml(member.name || 'Usuario')}</p>
                                <p class="truncate text-xs text-slate-500">
                                    ${member.extra_role_label ? `${escapeHtml(member.extra_role_label)}` : 'Sin rol extra'}
                                    ·
                                    ${member.resolved_dealership_name ? `${escapeHtml(member.resolved_dealership_name)}` : 'Sin delegación'}
                                </p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-slate-400 transition group-hover:text-brand-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                            </svg>
                        </a>
                    `).join('');
                };

                const updateGroupModalAvatar = (data = null) => {
                    if (!groupModalAvatarButton || !groupModalAvatar) {
                        return;
                    }

                    const hasGroupAvatar = Boolean(data && data.conversation_avatar_url);

                    groupModalAvatarButton.classList.toggle('hidden', !hasGroupAvatar);
                    groupModalAvatar.src = hasGroupAvatar
                        ? data.conversation_avatar_url
                        : '{{ asset('images/users/hrmotor-default-user-avatar.png') }}';
                    groupModalAvatar.alt = `Avatar de ${data?.conversation_name || 'Grupo de chat'}`;
                    groupModalAvatarButton.dataset.chatGroupModalAvatarSrc = hasGroupAvatar ? data.conversation_avatar_url : '';
                    groupModalAvatarButton.dataset.chatGroupModalAvatarAlt = `Avatar de ${data?.conversation_name || 'Grupo de chat'}`;
                    groupModalAvatarButton.dataset.chatGroupModalAvatarTitle = data?.conversation_name || 'Grupo de chat';
                };

                const openGroupModal = (groupData = null) => {
                    if (!groupModalOverlay) {
                        return;
                    }

                    const data = groupData || currentGroupModalData;

                    if (!data) {
                        return;
                    }

                    currentGroupModalData = data;
                    updateGroupModalAvatar(data);

                    if (groupModalTitle) {
                        groupModalTitle.textContent = data.conversation_name || headerGroupName?.textContent || 'Grupo de chat';
                    }

                    renderGroupModalMembers(Array.isArray(data.conversation_participants) ? data.conversation_participants : []);
                    groupModalOverlay.classList.remove('hidden');
                    groupModalOverlay.classList.add('flex');
                };

                const updateHeader = (payload) => {
                    if (headerGroupShell && headerPrivateShell) {
                        const isGroupConversation = Boolean(payload.conversation_is_group);
                        headerGroupShell.classList.toggle('hidden', !isGroupConversation);
                        headerPrivateShell.classList.toggle('hidden', isGroupConversation);
                    }

                    if (payload.conversation_is_group) {
                        if (headerGroupName) {
                            headerGroupName.textContent = payload.conversation_name || 'Grupo de chat';
                        }

                        if (headerGroupParticipants) {
                            headerGroupParticipants.textContent = payload.conversation_participants_text || 'Sin participantes';
                            headerGroupParticipants.classList.remove('hidden');
                        }

                        const hasGroupAvatar = Boolean(payload.conversation_system_group_type === '{{ \App\Models\CompanyChatGroup::SYSTEM_GROUP_TYPE_DEALERSHIP }}' && payload.conversation_avatar_url);

                        if (headerGroupAvatarShell && headerGroupIconShell) {
                            headerGroupAvatarShell.classList.toggle('hidden', !hasGroupAvatar);
                            headerGroupIconShell.classList.toggle('hidden', hasGroupAvatar);
                        }

                        if (headerGroupAvatarButton && headerGroupAvatar) {
                            headerGroupAvatar.src = hasGroupAvatar
                                ? payload.conversation_avatar_url
                                : '{{ asset('images/users/hrmotor-default-user-avatar.png') }}';
                            headerGroupAvatar.alt = `Avatar de ${payload.conversation_name || 'Grupo de chat'}`;
                            headerGroupAvatarButton.dataset.chatGroupHeaderAvatarSrc = hasGroupAvatar
                                ? payload.conversation_avatar_url
                                : '';
                            headerGroupAvatarButton.dataset.chatGroupHeaderAvatarAlt = `Avatar de ${payload.conversation_name || 'Grupo de chat'}`;
                            headerGroupAvatarButton.dataset.chatGroupHeaderAvatarTitle = payload.conversation_name || 'Grupo de chat';
                        }

                        currentGroupModalData = {
                            conversation_name: payload.conversation_name || 'Grupo de chat',
                            conversation_avatar_url: payload.conversation_avatar_url || '',
                            conversation_system_group_type: payload.conversation_system_group_type || null,
                            conversation_participants: Array.isArray(payload.conversation_participants) ? payload.conversation_participants : [],
                        };
                        currentConversationParticipants = Array.isArray(payload.conversation_participants) ? payload.conversation_participants : [];
                        updateGroupModalAvatar(currentGroupModalData);
                    } else {
                        if (headerPrivateName) {
                            headerPrivateName.textContent = payload.partner_name || 'Conversación';
                        }

                        renderHeaderRole(payload);
                        currentGroupModalData = null;
                        currentConversationParticipants = [];
                        updateGroupModalAvatar(null);
                    }

                    if (headerAvatar && !payload.conversation_is_group) {
                        const avatarUrl = payload.partner_avatar_url || payload.conversation_avatar_url || '{{ asset('images/users/hrmotor-default-user-avatar.png') }}';
                        const avatarAlt = `Avatar de ${payload.partner_name || payload.conversation_name || 'Usuario'}`;

                        headerAvatar.src = avatarUrl;
                        headerAvatar.alt = avatarAlt;

                        if (headerAvatarButton) {
                            headerAvatarButton.dataset.chatPrivateHeaderAvatarSrc = avatarUrl;
                            headerAvatarButton.dataset.chatPrivateHeaderAvatarAlt = avatarAlt;
                            headerAvatarButton.dataset.chatPrivateHeaderAvatarTitle = payload.partner_name || payload.conversation_name || 'Usuario';
                            headerAvatarButton.setAttribute('aria-label', `Ampliar imagen de ${payload.partner_name || payload.conversation_name || 'Usuario'}`);
                        }
                    }

                    if (headerProfileLink) {
                        if (payload.conversation_is_group) {
                            headerProfileLink.href = '#';
                            headerProfileLink.classList.add('pointer-events-none');
                            headerProfileLink.setAttribute('aria-hidden', 'true');
                        } else if (payload.partner_profile_url) {
                            headerProfileLink.href = payload.partner_profile_url;
                            headerProfileLink.classList.remove('pointer-events-none');
                            headerProfileLink.removeAttribute('aria-hidden');
                            headerProfileLink.setAttribute('aria-label', `Ver perfil de ${payload.partner_name || 'Usuario'}`);
                        }
                    }

                    if (headerFavoriteToggleForm && payload.partner_id) {
                        headerFavoriteToggleForm.action = (headerFavoriteToggleForm.dataset.chatFavoriteToggleUrlTemplate || '').replace('__USER_ID__', String(payload.partner_id));
                    }

                    setHeaderFavoriteState(Boolean(payload.partner_is_favorite));
                };

                if (headerGroupButton) {
                    headerGroupButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        openGroupModal();
                    });
                }

                const loadConversation = async (conversationId, { pushState = true, preserveHistory = false } = {}) => {
                    if (!conversationId) {
                        return;
                    }

                    conversationRevision += 1;
                    const requestRevision = conversationRevision;
                    const url = buildConversationMessagesUrl(conversationId);

                    try {
                        const response = await fetch(url, {
                            headers: {
                                ...getRealtimeHeaders(),
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                        });

                        if (!response.ok) {
                            return;
                        }

                        if (requestRevision !== conversationRevision) {
                            return;
                        }

                        const payload = await response.json();
                        const messages = Array.isArray(payload.messages) ? payload.messages : [];
                        const activeConversationId = Number(payload.conversation_id || conversationId);
                        const shouldMergeHistory = preserveHistory && Number(wrapper?.dataset.conversationId || 0) === activeConversationId;
                        const preserveScroll = shouldMergeHistory && (wrapper.scrollTop < (wrapper.scrollHeight - wrapper.clientHeight - 120))
                            ? 'exact'
                            : 'none';

                        sidebarSelectedConversationId = activeConversationId;
                        pollUrl = buildConversationMessagesUrl(activeConversationId);
                        wrapper.dataset.pollUrl = pollUrl;
                        wrapper.dataset.conversationId = String(activeConversationId);
                        currentConversationIsGroup = Boolean(payload.conversation_is_group);
                        hasMoreOlderMessages = Boolean(payload.has_more_older);
                        isLoadingOlderMessages = false;
                        setHistoryLoaderVisible(false);
                        closeMessageMenu();
                        closeDeleteConfirmModal();
                        closeGroupModal();
                        closeEmojiPicker();
                        clearChatError();
                        activeMessageMenuId = null;
                        editingMessageId = null;
                        editingMessageDraft = '';
                        composerMentionIds = [];
                        clearComposerMentions();

                        updateHeader(payload);
                        setSidebarTab(currentConversationIsGroup ? 'groups' : 'chats');
                        applyMessagesPayload(messages, {
                            preserveScroll,
                            replace: !shouldMergeHistory,
                        });
                        void subscribeRealtimeConversation(activeConversationId);
                        refreshSidebar();

                if (pushState) {
                    const nextUrl = new URL(window.location.href);
                    nextUrl.searchParams.set('conversation', String(activeConversationId));
                    nextUrl.searchParams.delete('recipient');
                    window.history.pushState({ conversationId: activeConversationId }, '', nextUrl.toString());
                }

                window.chatActiveConversationId = activeConversationId;
            } catch (error) {
                console.error(error);
            }
        };

                const openConversationFromLink = async (url) => {
                    try {
                        const nextUrl = new URL(url, window.location.href);

                        if (nextUrl.searchParams.has('conversation')) {
                            await loadConversation(nextUrl.searchParams.get('conversation'), { pushState: true });
                            return;
                        }

                        const response = await fetch(nextUrl.toString(), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                        });

                        const redirectedUrl = new URL(response.url, window.location.href);
                        const conversationId = redirectedUrl.searchParams.get('conversation');

                        if (conversationId) {
                            await loadConversation(conversationId, { pushState: true });
                        }
                    } catch (error) {
                        console.error(error);
                    }
                };

                window.loadConversation = loadConversation;
                window.openConversationFromLink = openConversationFromLink;

                const renderAttachmentsPreview = () => {
                    if (!attachmentSnapshot.length) {
                        attachmentsPreview.classList.add('hidden');
                        attachmentsPreview.innerHTML = '';
                        attachmentsChips.classList.add('hidden');
                        attachmentsChips.innerHTML = '';
                        return;
                    }

                    const previewText = attachmentSnapshot.map((file) => `${file.name} (${Math.ceil(file.size / 1024)} KB)`).join(' · ');
                    attachmentsPreview.textContent = `${attachmentSnapshot.length} archivo${attachmentSnapshot.length === 1 ? '' : 's'} seleccionado${attachmentSnapshot.length === 1 ? '' : 's'}: ${previewText}`;
                    attachmentsPreview.classList.remove('hidden');

                    attachmentsChips.innerHTML = attachmentSnapshot.map((file, index) => `
                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-brand-secondary">
                            <span class="truncate max-w-[9rem]">${escapeHtml(file.name)}</span>
                            <button type="button" class="cursor-pointer text-slate-400 transition hover:text-rose-500" data-chat-remove-attachment-index="${index}" aria-label="Quitar ${escapeHtml(file.name)}">
                                ×
                            </button>
                        </span>
                    `).join('');
                    attachmentsChips.classList.remove('hidden');
                };

                const refreshSidebar = async () => {
                    if (!summaryUrl || (!sidebarList && !sidebarFavoritesList && !sidebarGroupsList)) {
                        return;
                    }

                    try {
                        const response = await fetch(summaryUrl, {
                            headers: {
                                ...getRealtimeHeaders(),
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                        });

                        if (!response.ok) {
                            return;
                        }

                        const payload = await response.json();
                        const conversations = Array.isArray(payload.conversations) ? payload.conversations : [];
                        const privateConversations = conversations.filter((conversation) => !conversation.conversation_is_group);
                        const favoriteContacts = Array.isArray(payload.favorite_contacts) ? payload.favorite_contacts : [];
                        const chatGroups = Array.isArray(payload.chat_groups) ? payload.chat_groups : [];
                        const unreadTotal = Number(payload.unread_messages_total || 0);
                        const chatsUnreadTotal = privateConversations
                            .reduce((total, conversation) => total + Number(conversation.unread_messages_count || 0), 0);
                        const groupsUnreadTotal = chatGroups
                            .reduce((total, conversation) => total + Number(conversation.unread_messages_count || 0), 0);

                        if (sidebarUnreadTotal) {
                            sidebarUnreadTotal.textContent = String(unreadTotal);
                        }

                        if (tabBadges.chats) {
                            syncTabBadge(tabBadges.chats, chatsUnreadTotal);
                        }

                        if (tabBadges.groups) {
                            syncTabBadge(tabBadges.groups, groupsUnreadTotal);
                        }

                        if (sidebarList) {
                            sidebarList.innerHTML = privateConversations.length === 0
                                ? `
                                    <div class="px-4 py-8 text-center text-sm text-slate-500">
                                        Sin conversaciones aún
                                    </div>
                                `
                                : privateConversations.map(renderConversation).join('');
                        }

                        if (sidebarFavoritesList) {
                            sidebarFavoritesList.innerHTML = favoriteContacts.length === 0
                                ? `
                                    <div class="border-y border-slate-100 px-4 py-8 text-center text-sm text-slate-500">
                                        Marca contactos como favoritos para verlos aquí.
                                    </div>
                                `
                                : `
                                    <div class="divide-y divide-slate-100 border-y border-slate-100">
                                        ${favoriteContacts.map(renderFavoriteContact).join('')}
                                    </div>
                                `;
                        }

                        if (sidebarGroupsList) {
                            const groupConversations = chatGroups;

                            sidebarGroupsList.innerHTML = groupConversations.length === 0
                                ? `
                                    <div class="px-4 py-8 text-center text-sm text-slate-500">
                                        Aún no participas en ningún grupo.
                                    </div>
                                `
                                : groupConversations.map(renderConversation).join('');
                        }
                    } catch (error) {
                        console.error(error);
                    }
                };

                const syncMessages = async () => {
                    if (!pollUrl || pollingLocked) {
                        return;
                    }

                    try {
                        const requestRevision = conversationRevision;
                        const latestId = getLatestMessageId();
                        const syncFromId = getHistorySyncCursorMessageId();
                        const response = await fetch(buildConversationMessagesUrl(sidebarSelectedConversationId || Number(wrapper?.dataset.conversationId || 0), {
                            after_message_id: syncFromId,
                            limit: Math.min(Math.max(historyPageSize, historySyncOverlap + 10), 100),
                        }), {
                            headers: {
                                ...getRealtimeHeaders(),
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                        });

                        if (!response.ok) {
                            return;
                        }

                        if (requestRevision !== conversationRevision) {
                            return;
                        }

                        const payload = await response.json();
                        const messages = Array.isArray(payload.messages) ? payload.messages : [];
                        const nextMessages = mergeMessagesById(currentMessages, messages);
                        const nextLatest = Number(nextMessages[nextMessages.length - 1]?.id ?? 0);
                        const nextFingerprint = buildMessagesFingerprint(nextMessages);

                        if (nextFingerprint !== currentMessagesFingerprint) {
                            const isNewTailMessage = nextLatest > latestId;
                            const isNearBottom = (wrapper.scrollHeight - wrapper.scrollTop - wrapper.clientHeight) < 120;
                            renderMessages(nextMessages, {
                                preserveScroll: isNewTailMessage && isNearBottom ? 'none' : 'exact',
                            });
                        } else {
                            refreshActiveMessageMenu();
                        }
                    } catch (error) {
                        console.error(error);
                    }
                };

                const syncAttachmentInputFiles = () => {
                    const dataTransfer = new DataTransfer();

                    attachmentSnapshot.forEach((file) => {
                        dataTransfer.items.add(file);
                    });

                    attachmentsInput.files = dataTransfer.files;
                };

                const isAllowedAttachment = (file) => {
                    const extension = (file?.name?.split('.').pop() || '').toLowerCase();
                    return allowedAttachmentExtensions.includes(extension);
                };

                const getAttachmentSnapshotTotalBytes = () => attachmentSnapshot.reduce((total, file) => total + Number(file?.size || 0), 0);

                const buildPastedAttachmentFile = (file) => {
                    if (!(file instanceof Blob)) {
                        return null;
                    }

                    const mimeType = String(file.type || '').toLowerCase();
                    const extension = pastedImageMimeToExtension[mimeType] || (mimeType.split('/')[1] || 'bin');
                    const fileName = String(file.name || '').trim();
                    const safeTimestamp = new Date().toISOString().replace(/[:.]/g, '-');

                    if (fileName !== '') {
                        return file;
                    }

                    return new File([file], `adjunto-portapapeles-${safeTimestamp}.${extension}`, {
                        type: mimeType || 'application/octet-stream',
                        lastModified: Date.now(),
                    });
                };

                const insertComposerText = (text) => {
                    const nextText = String(text || '');

                    if (!nextText) {
                        return;
                    }

                    const start = Number.isInteger(input.selectionStart) ? input.selectionStart : input.value.length;
                    const end = Number.isInteger(input.selectionEnd) ? input.selectionEnd : input.value.length;

                    input.setRangeText(nextText, start, end, 'end');
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.focus();
                };

                const setComposerDropActive = (active) => {
                    composerDropActive = Boolean(active);
                    conversationDropActive = Boolean(active);

                    if (conversationPane && conversationDropzoneHint) {
                        conversationDropzoneHint.classList.toggle('hidden', !conversationDropActive);
                        conversationPane.classList.toggle('ring-4', conversationDropActive);
                        conversationPane.classList.toggle('ring-sky-200', conversationDropActive);
                        conversationPane.classList.toggle('bg-sky-50/50', conversationDropActive);
                    }

                    if (composerShell && composerDropzoneHint) {
                        composerDropzoneHint.classList.toggle('hidden', !composerDropActive);
                        composerShell.classList.toggle('border-sky-300', composerDropActive);
                        composerShell.classList.toggle('bg-sky-50/70', composerDropActive);
                        composerShell.classList.toggle('ring-2', composerDropActive);
                        composerShell.classList.toggle('ring-sky-200', composerDropActive);
                        composerShell.classList.toggle('shadow-lg', composerDropActive);
                        composerShell.classList.toggle('shadow-sm', !composerDropActive);
                        composerShell.classList.toggle('scale-[1.005]', composerDropActive);
                    }
                };

                const isFileDragEvent = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');

                const handlePageDragEnter = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    event.preventDefault();
                };

                const handlePageDragOver = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'copy';
                };

                const handlePageDragLeave = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }
                };

                const handlePageDrop = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    event.preventDefault();
                };

                const handleComposerDragEnter = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    event.preventDefault();
                    composerDropDepth += 1;
                    setComposerDropActive(true);
                };

                const handleComposerDragOver = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'copy';

                    if (!composerDropActive) {
                        setComposerDropActive(true);
                    }
                };

                const handleComposerDragLeave = (event) => {
                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    composerDropDepth = Math.max(0, composerDropDepth - 1);

                    if (composerDropDepth === 0) {
                        setComposerDropActive(false);
                    }
                };

                const handleComposerDrop = (event) => {
                    if (composerDisabled) {
                        event.preventDefault();
                        return;
                    }

                    if (!isFileDragEvent(event)) {
                        return;
                    }

                    event.preventDefault();
                    composerDropDepth = 0;
                    setComposerDropActive(false);

                    const droppedFiles = Array.from(event.dataTransfer?.files || []);

                    if (droppedFiles.length > 0) {
                        appendAttachments(droppedFiles.map((file) => buildPastedAttachmentFile(file)).filter(Boolean));
                    }

                    input.focus();
                };

                const handleComposerPaste = (event) => {
                    if (composerDisabled) {
                        event.preventDefault();
                        return;
                    }

                    const clipboardItems = Array.from(event.clipboardData?.items || []);
                    const pastedFiles = clipboardItems
                        .filter((item) => item.kind === 'file')
                        .map((item) => item.getAsFile())
                        .filter((file) => Boolean(file));
                    const pastedText = String(event.clipboardData?.getData('text/plain') || '').replace(/\r\n/g, '\n');

                    if (!pastedFiles.length && !pastedText) {
                        return;
                    }

                    event.preventDefault();

                    if (pastedFiles.length > 0) {
                        appendAttachments(pastedFiles.map((file) => buildPastedAttachmentFile(file)).filter(Boolean));
                    }

                    if (pastedText) {
                        insertComposerText(pastedText);
                    }
                };

                const showChatError = (message) => {
                    if (!message) {
                        clearChatError();
                        return;
                    }

                    chatError.textContent = message;
                    chatError.classList.remove('hidden');
                };

                const appendAttachments = (files) => {
                    const incomingFiles = Array.from(files || []);

                    if (!incomingFiles.length) {
                        return;
                    }

                    const currentKeys = new Set(attachmentSnapshot.map((file) => `${file.name}:${file.size}:${file.lastModified}`));
                    const accepted = [];
                    const rejected = [];
                    let hitTotalLimit = false;
                    let nextTotalBytes = getAttachmentSnapshotTotalBytes();

                    incomingFiles.forEach((file) => {
                        if (!isAllowedAttachment(file)) {
                            rejected.push(file);
                            return;
                        }

                        const key = `${file.name}:${file.size}:${file.lastModified}`;

                        if (currentKeys.has(key) || (attachmentSnapshot.length + accepted.length) >= maxAttachmentCount) {
                            return;
                        }

                        const nextFileSize = Number(file?.size || 0);

                        if ((nextTotalBytes + nextFileSize) > maxAttachmentTotalBytes) {
                            rejected.push(file);
                            hitTotalLimit = true;
                            return;
                        }

                        currentKeys.add(key);
                        accepted.push(file);
                        nextTotalBytes += nextFileSize;
                    });

                    if (accepted.length > 0) {
                        attachmentSnapshot = [...attachmentSnapshot, ...accepted];
                        syncAttachmentInputFiles();
                        renderAttachmentsPreview();
                        clearChatError();
                    }

                    if (rejected.length > 0) {
                        const firstRejected = rejected[0];
                        const firstRejectedName = firstRejected?.name || 'el archivo';

                        if (hitTotalLimit) {
                            showChatError('El conjunto de archivos adjuntos supera el peso máximo permitido de 30 MB.');
                            return;
                        }

                        showChatError(`No se puede adjuntar ${firstRejectedName}. Tipo de archivo no permitido o demasiado pesado.`);
                    }
                };

                const removeAttachmentAtIndex = (index) => {
                    attachmentSnapshot = attachmentSnapshot.filter((_, currentIndex) => currentIndex !== index);
                    syncAttachmentInputFiles();
                    renderAttachmentsPreview();
                };

                const closeEmojiPicker = () => {
                    emojiPicker.classList.add('hidden');
                };

                const toggleEmojiPicker = () => {
                    emojiPicker.classList.toggle('hidden');
                };

                const insertEmoji = (emoji) => {
                    if (!emoji) {
                        return;
                    }

                    const start = input.selectionStart ?? input.value.length;
                    const end = input.selectionEnd ?? input.value.length;
                    const value = input.value;
                    const nextValue = `${value.slice(0, start)}${emoji}${value.slice(end)}`;

                    input.value = nextValue;
                    const nextCursor = start + emoji.length;
                    input.setSelectionRange(nextCursor, nextCursor);
                    input.focus();
                };

                const performLiveSearch = async (searchValue) => {
                    const search = String(searchValue ?? '').trim();

                    if (search === '') {
                        searchResults.innerHTML = '';
                        return;
                    }

                    if (searchAbortController) {
                        searchAbortController.abort();
                    }

                    searchAbortController = new AbortController();

                    try {
                        const nextUrl = new URL(window.location.href);
                        nextUrl.searchParams.set('search', search);
                        nextUrl.searchParams.set('ajax', '1');

                        const response = await fetch(nextUrl.toString(), {
                            signal: searchAbortController.signal,
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                        });

                        if (!response.ok) {
                            return;
                        }

                        const payload = await response.json();
                        searchResults.innerHTML = payload.html || '';
                    } catch (error) {
                        if (error?.name !== 'AbortError') {
                            console.error(error);
                        }
                    }
                };

                const sendMessage = async () => {
                    if (composerDisabled) {
                        return;
                    }

                    clearChatError();

                    const body = input.value.trim();
                    const conversationId = Number(wrapper.dataset.conversationId || sidebarSelectedConversationId || 0);

                    if (!conversationId) {
                        showChatError('No hay ninguna conversación activa.');
                        return;
                    }

                    if (body === '' && attachmentSnapshot.length === 0) {
                        showChatError('Escribe un mensaje o adjunta un archivo.');
                        return;
                    }

                    if (getAttachmentSnapshotTotalBytes() > maxAttachmentTotalBytes) {
                        showChatError('El conjunto de archivos adjuntos supera el peso máximo permitido de 30 MB.');
                        return;
                    }

                    const pendingMessage = buildPendingOutgoingMessage({
                        body,
                        attachments: attachmentSnapshot.map((file) => ({
                            original_name: file.name,
                            size_label: `${Math.ceil(Number(file.size || 0) / 1024)} KB`,
                        })),
                        mentionedUserIds: composerMentionIds,
                    });
                    const draftBody = input.value;
                    const draftAttachments = [...attachmentSnapshot];
                    const draftMentionIds = [...composerMentionIds];
                    const requestRevision = conversationRevision;
                    const pendingMessageId = pendingMessage.id;
                    const url = buildConversationStoreUrl(conversationId);
                    const formData = new FormData();
                    formData.append('_token', csrfToken);
                    formData.append('conversation_id', String(conversationId));
                    formData.append('body', body);
                    draftMentionIds.forEach((mentionedUserId) => {
                        formData.append('mentioned_user_ids[]', String(mentionedUserId));
                    });

                    draftAttachments.forEach((file) => {
                        formData.append('attachments[]', file, file.name);
                    });

                    input.value = '';
                    attachmentSnapshot = [];
                    composerMentionIds = [];
                    syncAttachmentInputFiles();
                    renderAttachmentsPreview();
                    closeEmojiPicker();
                    clearComposerMentions();
                    focusComposer();

                    applyMessagesPayload([...currentMessages, pendingMessage], {
                        preserveScroll: 'none',
                        replace: true,
                    });

                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                ...getRealtimeHeaders(),
                                'X-Requested-With': 'XMLHttpRequest',
                                Accept: 'application/json',
                            },
                            body: formData,
                        });

                        const payload = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            if (requestRevision === conversationRevision) {
                                applyMessagesPayload(currentMessages.filter((message) => String(message.id) !== String(pendingMessageId)), {
                                    preserveScroll: 'none',
                                    replace: true,
                                });
                            }

                            if (input.value === '' && attachmentSnapshot.length === 0 && composerMentionIds.length === 0) {
                                input.value = draftBody;
                                attachmentSnapshot = draftAttachments;
                                composerMentionIds = draftMentionIds;
                                syncAttachmentInputFiles();
                                renderAttachmentsPreview();
                            }

                            if (response.status === 413) {
                                showChatError('El conjunto de archivos adjuntos supera el peso máximo permitido de 30 MB.');
                                return;
                            }

                            if (response.status === 422 && payload?.errors) {
                                const firstError = Object.values(payload.errors).flat()[0];
                                showChatError(firstError || 'No se pudo enviar el mensaje.');
                            } else {
                                showChatError(payload?.message || 'No se pudo enviar el mensaje.');
                            }
                            return;
                        }

                        const serverMessage = payload.message ? {
                            ...payload.message,
                            is_pending: false,
                        } : null;

                        if (serverMessage) {
                            applyMessagesPayload(
                                mergeMessagesById(
                                    currentMessages.filter((message) => String(message.id) !== String(pendingMessageId)),
                                    [serverMessage]
                                ),
                                {
                                    preserveScroll: 'none',
                                    replace: true,
                                }
                            );
                        } else {
                            applyMessagesPayload(currentMessages.filter((message) => String(message.id) !== String(pendingMessageId)), {
                                preserveScroll: 'none',
                                replace: true,
                            });
                        }

                        await refreshSidebar();
                    } catch (error) {
                        console.error(error);
                        if (requestRevision === conversationRevision) {
                            applyMessagesPayload(currentMessages.filter((message) => String(message.id) !== String(pendingMessageId)), {
                                preserveScroll: 'none',
                                replace: true,
                            });
                        }
                        if (input.value === '' && attachmentSnapshot.length === 0 && composerMentionIds.length === 0) {
                            input.value = draftBody;
                            attachmentSnapshot = draftAttachments;
                            composerMentionIds = draftMentionIds;
                            syncAttachmentInputFiles();
                            renderAttachmentsPreview();
                        }
                        showChatError('No se pudo enviar el mensaje.');
                    } finally {
                        window.requestAnimationFrame(() => {
                            focusComposer();
                        });
                    }
                };

                window.renderAttachmentsPreview = renderAttachmentsPreview;
                window.refreshSidebar = refreshSidebar;
                window.syncMessages = syncMessages;

                const setSidebarTab = (tab) => {
                    sidebarTab = tab;

                    sidebarTabButtons.forEach((button) => {
                        const isActive = button.dataset.chatSidebarTab === tab;
                        const isFavoriteButton = button.dataset.chatSidebarTab === 'favorites';

                        button.classList.toggle('bg-brand-primary', isActive && !isFavoriteButton);
                        button.classList.toggle('text-white', isActive && !isFavoriteButton);
                        button.classList.toggle('shadow-sm', isActive && !isFavoriteButton);
                        button.classList.toggle('bg-brand-primary/10', isActive && isFavoriteButton);
                        button.classList.toggle('text-brand-primary', isActive && isFavoriteButton);
                        button.classList.toggle('shadow-sm', isActive && isFavoriteButton);
                        button.classList.toggle('text-slate-500', !isActive);
                        button.classList.toggle('hover:bg-slate-100', !isActive && !isFavoriteButton);
                        button.classList.toggle('hover:bg-brand-primary/10', isFavoriteButton && !isActive);
                        button.classList.toggle('hover:text-brand-primary', !isFavoriteButton && !isActive);
                        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                    });

                    sidebarPanels.forEach((panel) => {
                        const isActive = panel.dataset.chatSidebarPanel === tab;
                        panel.classList.toggle('hidden', !isActive);
                    });
                };

                setSidebarTab(currentConversationIsGroup ? 'groups' : 'chats');
                setSidebarCollapsed(false);
                setMobileSidebarOpen(false);

                sidebarTabButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const nextTab = button.dataset.chatSidebarTab === 'favorites' && sidebarTab === 'favorites' ? 'chats' : (button.dataset.chatSidebarTab || 'chats');
                        setSidebarTab(nextTab);
                    });
                });

                mobileSidebarToggleButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        setMobileSidebarOpen(!mobileSidebarOpen);
                    });
                });

                sidebarCollapseButton.addEventListener('click', () => {
                    setSidebarCollapsed(true);
                });

                sidebarExpandButton.addEventListener('click', () => {
                    setSidebarCollapsed(false);
                });

                window.addEventListener('resize', () => {
                    if (window.matchMedia('(min-width: 768px)').matches) {
                        setMobileSidebarOpen(false);
                    }
                });

                if (hasComposer) {
                wrapper.addEventListener('scroll', () => {
                    handleMessagesScroll();
                }, { passive: true });

                messagesContainer.addEventListener('click', (event) => {
                    const trigger = event.target.closest('[data-chat-message-trigger]');
                    if (trigger) {
                        event.preventDefault();
                        event.stopPropagation();
                        const messageElement = trigger.closest('[data-message-id]');
                        if (messageElement) {
                            openMessageMenu(messageElement);
                        }
                        return;
                    }

                    const editButton = event.target.closest('[data-chat-message-edit]');
                    if (editButton) {
                        event.preventDefault();
                        event.stopPropagation();
                        void applyMessageAction('edit');
                        return;
                    }

                    const deleteButton = event.target.closest('[data-chat-message-delete]');
                    if (deleteButton) {
                        event.preventDefault();
                        event.stopPropagation();
                        void applyMessageAction('delete');
                        return;
                    }

                    const saveButton = event.target.closest('[data-chat-edit-save]');
                    if (saveButton) {
                        event.preventDefault();
                        void saveInlineEdit();
                        return;
                    }

                    const cancelButton = event.target.closest('[data-chat-edit-cancel]');
                    if (cancelButton) {
                        event.preventDefault();
                        cancelInlineEdit();
                        renderMessages(currentMessages);
                        return;
                    }
                });

                document.addEventListener('click', (event) => {
                    const overlay = event.target.closest('[data-chat-delete-modal-overlay]');

                    if (!overlay) {
                        return;
                    }

                    const cancelButton = event.target.closest('[data-chat-delete-cancel]');
                    const confirmButton = event.target.closest('[data-chat-delete-confirm]');

                    if (cancelButton) {
                        event.preventDefault();
                        closeDeleteConfirmModal();
                        return;
                    }

                    if (confirmButton) {
                        event.preventDefault();
                        void deleteActiveMessage();
                        return;
                    }

                    if (event.target === overlay) {
                        closeDeleteConfirmModal();
                    }
                });

                document.addEventListener('click', (event) => {
                    const overlay = event.target.closest('[data-chat-group-modal-overlay]');

                    if (!overlay) {
                        return;
                    }

                    if (event.target.closest('[data-chat-group-modal-close]') || event.target === overlay) {
                        event.preventDefault();
                        closeGroupModal();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        closeDeleteConfirmModal();
                        closeGroupModal();
                    }
                });

                messagesContainer.addEventListener('input', (event) => {
                    const editInput = event.target.closest('[data-chat-edit-input]');

                    if (!editInput) {
                        return;
                    }

                    editingMessageDraft = editInput.value;
                    resizeChatEditInput(editInput);
                });

                messagesContainer.addEventListener('keydown', (event) => {
                    const editInput = event.target.closest('[data-chat-edit-input]');

                    if (!editInput) {
                        return;
                    }

                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        void saveInlineEdit();
                    }
                });

                if (canCompose) {
                    attachmentsButton.addEventListener('click', (event) => {
                        if (composerDisabled) {
                            event.preventDefault();
                            return;
                        }

                        event.preventDefault();
                        attachmentsInput.value = '';
                        attachmentsInput.click();
                    });

                    attachmentsInput.addEventListener('change', (event) => {
                        appendAttachments(event.target.files);
                        event.target.value = '';
                    });

                    document.addEventListener('dragenter', handlePageDragEnter);
                    document.addEventListener('dragover', handlePageDragOver);
                    document.addEventListener('dragleave', handlePageDragLeave);
                    document.addEventListener('drop', handlePageDrop);
                    conversationPane.addEventListener('dragenter', handleComposerDragEnter);
                    conversationPane.addEventListener('dragover', handleComposerDragOver);
                    conversationPane.addEventListener('dragleave', handleComposerDragLeave);
                    conversationPane.addEventListener('drop', handleComposerDrop);
                    input.addEventListener('paste', handleComposerPaste);
                    input.addEventListener('beforeinput', (event) => {
                        if (composerDisabled) {
                            event.preventDefault();
                        }
                    });
                    input.addEventListener('input', () => {
                        if (composerDisabled) {
                            return;
                        }

                        if (!String(input.value || '').includes('@')) {
                            composerMentionIds = [];
                        }

                        updateComposerMentionSuggestions();
                    });
                    input.addEventListener('keyup', updateComposerMentionSuggestions);
                    input.addEventListener('click', () => {
                        if (composerDisabled) {
                            return;
                        }

                        updateComposerMentionSuggestions();
                    });
                    input.addEventListener('focus', () => {
                        if (composerDisabled) {
                            return;
                        }

                        updateComposerMentionSuggestions();
                    });

                    attachmentsChips.addEventListener('click', (event) => {
                        const removeButton = event.target.closest('[data-chat-remove-attachment-index]');

                        if (!removeButton) {
                            return;
                        }

                        const index = Number(removeButton.dataset.chatRemoveAttachmentIndex);

                        if (Number.isNaN(index)) {
                            return;
                        }

                        removeAttachmentAtIndex(index);
                    });

                    emojiButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        toggleEmojiPicker();
                    });

                    emojiPicker.addEventListener('click', (event) => {
                        const emojiButton = event.target.closest('[data-chat-emoji-option]');

                        if (!emojiButton) {
                            return;
                        }

                        insertEmoji(emojiButton.dataset.emoji || emojiButton.textContent || '');
                    });

                    mentionSuggestionsList.addEventListener('click', (event) => {
                        const option = event.target.closest('[data-chat-mention-option]');

                        if (!option) {
                            return;
                        }

                        event.preventDefault();
                        insertComposerMention({
                            id: Number(option.dataset.mentionUserId || 0),
                            name: option.dataset.mentionUserName || '',
                        });
                    });

                    document.addEventListener('click', (event) => {
                        if (emojiPicker.contains(event.target) || emojiButton.contains(event.target)) {
                            return;
                        }

                        if (mentionSuggestionsPanel.contains(event.target) || input.contains(event.target)) {
                            return;
                        }

                        closeEmojiPicker();
                        clearComposerMentions();
                    });
                }

                }

                if (searchInput && searchResults) {
                    searchInput.addEventListener('input', () => {
                        clearTimeout(searchDebounce);
                        searchDebounce = setTimeout(() => {
                            void performLiveSearch(searchInput.value);
                        }, 250);
                    });

                    searchInput.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                        }
                    });
                }

                if (canCompose) {
                    const submitButton = form.querySelector('[data-chat-submit-button]');
                    let submitButtonPointerActive = false;
                    let submitButtonPointerHandled = false;

                    if (submitButton) {
                        submitButton.addEventListener('pointerdown', (event) => {
                            if (event.pointerType === 'mouse') {
                                return;
                            }

                            event.preventDefault();
                            submitButtonPointerActive = true;
                            submitButtonPointerHandled = false;
                            focusComposer();
                        });

                        submitButton.addEventListener('pointerup', (event) => {
                            if (!submitButtonPointerActive) {
                                return;
                            }

                            event.preventDefault();
                            submitButtonPointerActive = false;
                            submitButtonPointerHandled = true;
                            void sendMessage();

                            window.setTimeout(() => {
                                submitButtonPointerHandled = false;
                            }, 0);
                        });

                        submitButton.addEventListener('pointercancel', () => {
                            submitButtonPointerActive = false;
                        });

                        submitButton.addEventListener('click', (event) => {
                            if (submitButtonPointerHandled) {
                                event.preventDefault();
                                return;
                            }

                            event.preventDefault();
                            void sendMessage();
                        });
                    }

                    form.addEventListener('submit', (event) => {
                        event.preventDefault();
                        void sendMessage();
                    });

                    input.addEventListener('keydown', (event) => {
                    if (composerMentionState.isOpen && composerMentionState.suggestions.length > 0) {
                        if (event.key === 'Tab') {
                            event.preventDefault();
                            const selectedSuggestion = composerMentionState.suggestions[composerMentionState.selectedIndex] || composerMentionState.suggestions[0];
                            insertComposerMention(selectedSuggestion);
                            return;
                        }

                        if (event.key === 'ArrowDown') {
                            event.preventDefault();
                            composerMentionState.selectedIndex = (composerMentionState.selectedIndex + 1) % composerMentionState.suggestions.length;
                            renderComposerMentionSuggestions();
                            return;
                        }

                        if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            composerMentionState.selectedIndex = (composerMentionState.selectedIndex - 1 + composerMentionState.suggestions.length) % composerMentionState.suggestions.length;
                            renderComposerMentionSuggestions();
                            return;
                        }

                        if (event.key === 'Escape') {
                            clearComposerMentions();
                            return;
                        }
                    }

                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        void sendMessage();
                    }
                });

                autoScroll();
                window.renderAttachmentsPreview?.();
                setInterval(() => {
                    if (shouldRunChatPolling()) {
                        void syncMessages();
                    }
                }, 3000);
                }

                root.addEventListener('click', async (event) => {
                    const link = event.target.closest('[data-chat-conversation-link], [data-chat-recipient-link], [data-chat-group-link]');

                    if (!link) {
                        closeMessageMenu();
                        return;
                    }

                    if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                        return;
                    }

                    if (!hasComposer) {
                        return;
                    }

                    event.preventDefault();
                    if (link.matches('[data-chat-recipient-link]')) {
                        clearChatSearch();
                    }

                    if (window.matchMedia('(max-width: 767px)').matches) {
                        window.chatToggleMobileSidebar?.(false);
                    }
                    closeMessageMenu();
                    await window.openConversationFromLink?.(link.href);
                });

                document.addEventListener('click', (event) => {
                    if (event.target.closest('[data-message-id]')) {
                        return;
                    }

                    closeMessageMenu();
                });

                window.addEventListener('popstate', () => {
                    const conversationId = new URL(window.location.href).searchParams.get('conversation');

                    if (conversationId) {
                        void window.loadConversation?.(conversationId, { pushState: false });
                    }
                });

                setInterval(() => {
                    if (shouldRunChatPolling()) {
                        void refreshSidebar();
                    }
                }, 5000);
                refreshSidebar();
                document.addEventListener('visibilitychange', () => {
                    if (!shouldRunChatPolling()) {
                        return;
                    }

                    void refreshSidebar();
                    if (hasComposer) {
                        void syncMessages();
                    }
                });
                updateChatRealtimeIndicator(hasComposer && realtimeConfig.enabled && typeof WebSocket !== 'undefined' && !realtimeSuppressed ? 'fallback' : 'disabled');
                if (hasComposer) {
                    connectChatRealtime();
                    syncMessages();
                }
            });
        </script>
    @if (! $policyAccepted)
        <div class="fixed inset-x-0 bottom-0 top-[4.75rem] z-40 flex min-h-0 w-full overflow-hidden bg-slate-950/40 px-4 py-6 backdrop-blur-sm">
            <aside class="flex h-full w-[21rem] min-w-[21rem] max-w-[21rem] flex-col border-r border-slate-200 bg-white shadow-[12px_0_40px_rgba(15,23,42,0.04)] opacity-60">
                <div class="flex min-h-[4.75rem] items-center border-b border-slate-200 px-4 py-2">
                    <div class="flex w-full items-center gap-2">
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-transparent text-slate-300" aria-label="Favoritos bloqueados">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M11.245 4.174C11.4765 3.50808 11.5922 3.17513 11.7634 3.08285C11.9115 3.00298 12.0898 3.00298 12.238 3.08285C12.4091 3.17513 12.5248 3.50808 12.7563 4.174L14.2866 8.57639C14.3525 8.76592 14.3854 8.86068 14.4448 8.93125C14.4972 8.99359 14.5641 9.04218 14.6396 9.07278C14.725 9.10743 14.8253 9.10947 15.0259 9.11356L19.6857 9.20852C20.3906 9.22288 20.743 9.23007 20.8837 9.36432C21.0054 9.48051 21.0605 9.65014 21.0303 9.81569C20.9955 10.007 20.7146 10.2199 20.1528 10.6459L16.4387 13.4616C16.2788 13.5829 16.1989 13.6435 16.1501 13.7217C16.107 13.7909 16.0815 13.8695 16.0757 13.9507C16.0692 14.0427 16.0982 14.1387 16.1563 14.3308L17.506 18.7919C17.7101 19.4667 17.8122 19.8041 17.728 19.9793C17.6551 20.131 17.5108 20.2358 17.344 20.2583C17.1513 20.2842 16.862 20.0829 16.2833 19.6802L12.4576 17.0181C12.2929 16.9035 12.2106 16.8462 12.1211 16.8239C12.042 16.8043 11.9593 16.8043 11.8803 16.8239C11.7908 16.8462 11.7084 16.9035 11.5437 17.0181L7.71805 19.6802C7.13937 20.0829 6.85003 20.2842 6.65733 20.2583C6.49056 20.2358 6.34626 20.131 6.27337 19.9793C6.18915 19.8041 6.29123 19.4667 6.49538 18.7919L7.84503 14.3308C7.90313 14.1387 7.93218 14.0427 7.92564 13.9507C7.91986 13.8695 7.89432 13.7909 7.85123 13.7217C7.80246 13.6435 7.72251 13.5829 7.56262 13.4616L3.84858 10.6459C3.28678 10.2199 3.00588 10.007 2.97101 9.81569C2.94082 9.65014 2.99594 9.48051 3.11767 9.36432C3.25831 9.23007 3.61074 9.22289 4.31559 9.20852L8.9754 9.11356C9.176 9.10947 9.27631 9.10743 9.36177 9.07278C9.43726 9.04218 9.50414 8.99359 9.55657 8.93125C9.61593 8.86068 9.64887 8.76592 9.71475 8.57639L11.245 4.174Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <div class="relative flex-1">
                            <div class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pl-9 pr-4 text-sm text-slate-400">
                                Buscar...
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto">
                    <div class="border-b border-slate-200 px-4 py-3">
                        <div class="grid grid-cols-3 rounded-2xl bg-slate-100 p-1 text-xs font-semibold">
                            <div class="inline-flex items-center justify-center rounded-xl bg-brand-primary px-3 py-2 text-white shadow-sm">Chats</div>
                            <div class="inline-flex items-center justify-center rounded-xl px-3 py-2 text-slate-500">Equipo</div>
                            <div class="inline-flex items-center justify-center rounded-xl px-3 py-2 text-slate-500">Grupos</div>
                        </div>
                    </div>

                    <div class="px-4 py-10 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-sky-100 text-sky-600">
                            <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="h-8 w-8">
                                <circle opacity="0.5" cx="12" cy="12" r="10" stroke="#1C274C" stroke-width="1.5"/>
                                <path d="M9 17C9.85038 16.3697 10.8846 16 12 16C13.1154 16 14.1496 16.3697 15 17" stroke="#1C274C" stroke-width="1.5" stroke-linecap="round"/>
                                <ellipse cx="15" cy="10.5" rx="1" ry="1.5" fill="#1C274C"/>
                                <ellipse cx="9" cy="10.5" rx="1" ry="1.5" fill="#1C274C"/>
                            </svg>
                        </div>
                        <h2 class="mt-5 text-lg font-bold text-brand-secondary">Política de uso del chat corporativo</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-500">
                            Antes de continuar, acepta la política vigente para poder ver conversaciones, buscar compañeros y enviar mensajes.
                        </p>
                    </div>
                </div>
            </aside>

            <section class="flex min-w-0 flex-1 overflow-y-auto bg-slate-100 px-4 py-4 sm:px-6 sm:py-6">
                <div class="mx-auto w-full max-w-3xl min-h-[calc(100dvh-8rem)] px-2 pt-2 pb-20 sm:px-4 sm:pt-4 sm:pb-24">
                    <div class="flex items-start gap-4">
                        <div class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-sky-100 text-sky-700">
                            <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6">
                                <circle opacity="0.5" cx="12" cy="12" r="10" stroke="#1C274C" stroke-width="1.5"/>
                                <path d="M9 17C9.85038 16.3697 10.8846 16 12 16C13.1154 16 14.1496 16.3697 15 17" stroke="#1C274C" stroke-width="1.5" stroke-linecap="round"/>
                                <ellipse cx="15" cy="10.5" rx="1" ry="1.5" fill="#1C274C"/>
                                <ellipse cx="9" cy="10.5" rx="1" ry="1.5" fill="#1C274C"/>
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h1 class="text-2xl font-bold text-brand-secondary">Política de uso del chat corporativo</h1>
                            <p class="mt-2 text-sm leading-6 text-slate-500">
                                Este chat es una herramienta interna de HRMOTOR destinada exclusivamente a comunicaciones profesionales entre usuarios autorizados.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 space-y-4 text-sm leading-6 text-slate-600">
                        <p>No debe utilizarse para compartir contraseñas, credenciales, datos bancarios, documentación confidencial no necesaria, datos personales de clientes o empleados que no sean imprescindibles, datos de salud ni cualquier otra información especialmente sensible.</p>
                        <p>Los mensajes enviados a través del chat serán conservados por la empresa durante un plazo de 6 meses, salvo que exista una obligación legal, incidencia de seguridad o necesidad justificada que requiera conservar determinada información durante más tiempo.</p>
                        <p>Las conversaciones y ficheros asociados podrán formar parte de copias de seguridad cifradas y custodiadas por IT fuera del repositorio del proyecto, con retención operativa separada y sin publicar datos sensibles en GitHub ni en ubicaciones públicas.</p>
                        <p>El acceso al contenido de las conversaciones estará limitado a los usuarios participantes y, de forma excepcional, a personal autorizado de IT o dirección cuando exista una causa justificada relacionada con seguridad, cumplimiento normativo, investigación de incidencias, mantenimiento técnico o control laboral proporcionado. Todo acceso administrativo al contenido de conversaciones deberá quedar registrado.</p>
                        <p>Los logs técnicos de la aplicación no incluyen el contenido de los mensajes, sino únicamente eventos técnicos necesarios para seguridad, mantenimiento, errores y auditoría.</p>
                        <p>El uso de este chat no implica obligación de responder fuera del horario laboral, salvo situaciones excepcionales justificadas conforme a la política interna de la empresa y al derecho de desconexión digital.</p>
                        <p>Al pulsar "Aceptar y continuar", el usuario confirma que ha leído y entendido esta política de uso.</p>
                    </div>

                    <div class="mt-10 border-t border-slate-200 pt-6 pb-6 sm:pb-8">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-xs text-slate-400">
                                <span>Versión: {{ $policyVersion }}</span>
                            </div>

                            <form method="POST" action="{{ $policyAcceptUrl }}" class="sm:ml-auto">
                                @csrf
                                @if (filled($policyReturnRecipient))
                                    <input type="hidden" name="recipient" value="{{ $policyReturnRecipient }}">
                                @endif
                                @if (filled($policyReturnConversation))
                                    <input type="hidden" name="conversation" value="{{ $policyReturnConversation }}">
                                @endif
                                @if (filled($policyReturnGroup))
                                    <input type="hidden" name="group" value="{{ $policyReturnGroup }}">
                                @endif
                                <button type="submit" class="inline-flex cursor-pointer items-center justify-center rounded-2xl bg-brand-primary px-5 py-3 text-sm font-semibold text-white transition hover:opacity-90">
                                    Aceptar y continuar
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    @endif
@endsection
