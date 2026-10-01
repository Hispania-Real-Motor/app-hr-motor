@extends('layouts.app')

@section('content')
    @php
        $priorityMeta = $ticketPriorities[$ticket->priority] ?? ['label' => $ticket->priority, 'badge' => 'bg-slate-100 text-slate-700 ring-slate-200'];
        $statusMeta = $ticketStatuses[$ticket->status] ?? ['label' => $ticket->status, 'badge' => 'bg-slate-100 text-slate-700 ring-slate-200'];
        $messages = $ticket->messages ?? collect();
        $conversationMessagesCount = $messages->count() + 1;
        $activityLogs = ($ticket->activityLogs ?? collect())->reject(fn ($activity) => $activity->event === 'priority_changed');
        $isVisibleItRole = app_visible_role(auth()->user()) === \App\Models\User::ROLE_INFORMATION_TECHNOLOGY;
        $currentToolKey = filled($ticket->ticket_tool_id) ? (string) $ticket->ticket_tool_id : '';
        $currentToolLabel = $ticket->ticketTool?->name ?? $ticket->tool ?? '';
        $currentRequesterKey = filled($ticket->user_id) ? (string) $ticket->user_id : '';
        $currentRequesterLabel = $ticket->user?->name ?? '';
        $isClosed = $ticket->status === 'closed';
        $isReopenRequested = $ticket->status === 'reopen_requested';
        $isPermanentlyClosed = $ticket->status === 'clausurado';
        $ticketOpeningAttachments = collect($ticket->screenshots ?? []);
        $requesterExtraRoleLabel = filled($ticket->user?->extra_role) ? ($ticket->user?->chat_role_label ?? ucfirst((string) $ticket->user->extra_role)) : null;
    @endphp

    <main
        x-data="imageLightbox()"
        x-effect="document.body.classList.toggle('overflow-hidden', isImageOpen)"
        @keydown.escape.window="closeImage()"
        @keydown.window="handleKeydown($event)"
        class="mx-auto w-full max-w-7xl flex-1 px-6 py-8 lg:px-8"
    >
        <div class="space-y-6">
            <section class="overflow-visible rounded-[2rem] border border-brand-secondary/10 bg-white shadow-sm">
                <div
                    class="relative overflow-hidden rounded-[2rem] bg-cover bg-no-repeat px-6 py-8 sm:px-8 sm:py-10"
                    style="background-image: url('{{ asset('images/hero/hero-interior-ticket.webp') }}'); background-position: center 68%;"
                >
                    <div class="absolute inset-0 bg-slate-950/70"></div>
                    <div class="relative flex flex-col gap-6">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                            <div class="space-y-3">
                                <div class="inline-flex items-center rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-white backdrop-blur-sm">
                                    Detalle del ticket
                                </div>
                                <div class="space-y-2">
                                    <h1 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                                        <span>{{ $ticket->number }}</span>
                                        <span class="mx-2 text-white/60">·</span>
                                        <span>{{ $ticket->title }}</span>
                                    </h1>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-white/20 {{ $statusMeta['badge'] }}">
                                    {{ $statusMeta['label'] }}
                                </span>
                                @if ($isVisibleItRole)
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-white/20 {{ $priorityMeta['badge'] }}">
                                        {{ $priorityMeta['label'] }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto] xl:items-end">
                            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <div class="relative z-40 rounded-[1.25rem] border border-white/18 bg-slate-950/30 px-4 py-4 text-white shadow-[0_18px_36px_rgba(15,23,42,0.18)] backdrop-blur-md ring-1 ring-white/10">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/70">Tipo de incidencia</dt>
                                    <dd class="mt-2 text-lg font-semibold text-white sm:text-xl">
                                        @if ($canUpdateTicketTool)
                                            <form
                                                method="POST"
                                                action="{{ route('tickets.tool.update', $ticket) }}"
                                                class="relative z-50"
                                                x-data="itTicketToolSelector(@js($ticketTools), @js($currentToolKey), @js($currentToolLabel))"
                                                @click.outside="open = false"
                                                @keydown.escape.window="open = false"
                                                x-ref="toolForm"
                                            >
                                                @csrf
                                                <input type="hidden" name="ticket_tool_id" :value="selected">

                                                <div class="relative">
                                                    <input
                                                        type="text"
                                                        x-model="query"
                                                        @focus="open = true"
                                                        @click="open = true"
                                                        @input="open = true; clearSelection()"
                                                        @blur="syncQuery()"
                                                        @keydown.enter.prevent="if (filteredOptions.length) { select(filteredOptions[0][0]); $nextTick(() => $refs.toolForm.requestSubmit()); }"
                                                        placeholder="Selecciona un tipo de incidencia"
                                                        autocomplete="off"
                                                        aria-label="Cambiar tipo de incidencia"
                                                        class="flex w-full items-center justify-between gap-3 rounded-[1rem] border border-white/15 bg-white/15 px-3 py-2 pr-10 text-left text-white placeholder:text-white/55 transition hover:cursor-text hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                                                    >

                                                    <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/70" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                        <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
                                                    </svg>
                                                </div>

                                                <div
                                                    x-cloak
                                                    x-show="open"
                                                    x-transition
                                                    class="absolute bottom-full left-0 right-0 z-[999] mb-2 overflow-hidden rounded-[1.25rem] border border-white/15 bg-white text-brand-secondary shadow-2xl"
                                                >
                                                    <div class="flex max-h-72 flex-col overflow-hidden">
                                                        <div class="order-1 max-h-[15.5rem] overflow-auto">
                                                            <template x-for="option in filteredOptions" :key="option[0]">
                                                                <button
                                                                    type="button"
                                                                    @pointerdown.prevent="select(option[0]); $nextTick(() => $refs.toolForm.requestSubmit())"
                                                                    class="flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left text-sm transition last:border-b-0 hover:bg-slate-50"
                                                                >
                                                                    <span
                                                                        class="mt-0.5 inline-flex h-2.5 w-2.5 shrink-0 rounded-full"
                                                                        :style="`background-color: ${option[1].color || '#1d4ed8'}`"
                                                                    ></span>
                                                                    <span class="block font-medium text-brand-secondary" x-text="option[1].label"></span>
                                                                </button>
                                                            </template>

                                                            <div x-show="filteredOptions.length === 0" class="px-4 py-3 text-sm text-brand-secondary/60">
                                                                No hay coincidencias.
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        @else
                                            <span class="inline-flex items-center gap-2">
                                                <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $ticket->ticketTool?->color ?? '#1d4ed8' }}"></span>
                                                <span>{{ $ticket->ticketTool?->name ?? $ticket->tool }}</span>
                                            </span>
                                        @endif
                                    </dd>
                                </div>

                                <div class="rounded-[1.25rem] border border-white/18 bg-slate-950/30 px-4 py-4 text-white shadow-[0_18px_36px_rgba(15,23,42,0.18)] backdrop-blur-md ring-1 ring-white/10">
                                    <dt class="flex items-center justify-between gap-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-white/70">
                                        <span>Solicitante</span>

                                        @if ($requesterExtraRoleLabel)
                                            <span class="inline-flex shrink-0 items-center rounded-full bg-white/12 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/75 ring-1 ring-white/15">
                                                {{ $requesterExtraRoleLabel }}
                                            </span>
                                        @endif
                                    </dt>
                                    <dd class="mt-2 space-y-1">
                                        @if ($canManageTickets)
                                            <form
                                                method="POST"
                                                action="{{ route('tickets.requester.update', $ticket) }}"
                                                class="relative z-50"
                                                x-data="itTicketToolSelector(@js($requesterUsers), @js($currentRequesterKey), @js($currentRequesterLabel))"
                                                @click.outside="open = false"
                                                @keydown.escape.window="open = false"
                                                x-ref="requesterForm"
                                            >
                                                @csrf
                                                <input type="hidden" name="user_id" :value="selected">

                                                <div class="relative">
                                                    <input
                                                        type="text"
                                                        x-model="query"
                                                        @focus="open = true"
                                                        @click="open = true"
                                                        @input="open = true; clearSelection()"
                                                        @blur="syncQuery()"
                                                        @keydown.enter.prevent="if (filteredOptions.length) { select(filteredOptions[0][0]); $nextTick(() => $refs.requesterForm.requestSubmit()); }"
                                                        placeholder="Selecciona un solicitante"
                                                        autocomplete="off"
                                                        aria-label="Cambiar solicitante"
                                                        class="flex w-full items-center justify-between gap-3 rounded-[1rem] border border-white/15 bg-white/15 px-3 py-2 pr-10 text-left text-white placeholder:text-white/55 transition hover:cursor-text hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                                                    >

                                                    <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/70" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                        <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"></path>
                                                    </svg>
                                                </div>

                                                <div
                                                    x-cloak
                                                    x-show="open"
                                                    x-transition
                                                    class="absolute bottom-full left-0 right-0 z-[999] mb-2 overflow-hidden rounded-[1.25rem] border border-white/15 bg-white text-brand-secondary shadow-2xl"
                                                >
                                                    <div class="flex max-h-72 flex-col overflow-hidden">
                                                        <div class="order-1 max-h-[15.5rem] overflow-auto">
                                                            <template x-for="option in filteredOptions" :key="option[0]">
                                                                <button
                                                                    type="button"
                                                                    @pointerdown.prevent="select(option[0]); $nextTick(() => $refs.requesterForm.requestSubmit())"
                                                                    class="flex w-full items-start gap-3 border-b border-slate-100 px-4 py-3 text-left text-sm transition last:border-b-0 hover:bg-slate-50"
                                                                >
                                                                    <span class="block font-medium text-brand-secondary" x-text="option[1].label"></span>
                                                                </button>
                                                            </template>

                                                            <div x-show="filteredOptions.length === 0" class="px-4 py-3 text-sm text-brand-secondary/60">
                                                                No hay coincidencias.
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>

                                            <div class="text-xs font-medium uppercase tracking-[0.14em] text-white/55">
                                                @if ($ticket->user?->assignedDealership)
                                                    <a href="{{ route('dealerships.show', $ticket->user->assignedDealership) }}" class="transition hover:text-white/85">
                                                        {{ $ticket->user?->resolved_dealership_name ?? 'Sin delegación' }}
                                                    </a>
                                                @else
                                                    {{ $ticket->user?->resolved_dealership_name ?? 'Sin delegación' }}
                                                @endif
                                            </div>
                                        @else
                                            <div class="text-lg font-semibold text-white sm:text-xl">
                                                {{ $ticket->user?->name ?? 'Sin nombre' }}
                                            </div>

                                            <div class="text-xs font-medium uppercase tracking-[0.14em] text-white/55">
                                                @if ($ticket->user?->assignedDealership)
                                                    <a href="{{ route('dealerships.show', $ticket->user->assignedDealership) }}" class="transition hover:text-white/85">
                                                        {{ $ticket->user?->resolved_dealership_name ?? 'Sin delegación' }}
                                                    </a>
                                                @else
                                                    {{ $ticket->user?->resolved_dealership_name ?? 'Sin delegación' }}
                                                @endif
                                            </div>
                                        @endif
                                    </dd>
                                </div>

                                <div class="rounded-[1.25rem] border border-white/18 bg-slate-950/30 px-4 py-4 text-white shadow-[0_18px_36px_rgba(15,23,42,0.18)] backdrop-blur-md ring-1 ring-white/10">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/70">Asignado a</dt>
                                    <dd class="mt-2 text-lg font-semibold text-white sm:text-xl">
                                        {{ $ticket->assignedTo?->name ?? 'Sin asignar' }}
                                    </dd>
                                </div>

                                <div class="rounded-[1.25rem] border border-white/18 bg-slate-950/30 px-4 py-4 text-white shadow-[0_18px_36px_rgba(15,23,42,0.18)] backdrop-blur-md ring-1 ring-white/10">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-white/70">Actualizado</dt>
                                    <dd class="mt-2 text-lg font-semibold text-white sm:text-xl">
                                        {{ $ticket->updated_at?->format('d/m/Y H:i') }}
                                    </dd>
                                </div>
                            </dl>

                            @if ($isVisibleItRole)
                                <a href="{{ $backUrl }}"
                                    class="inline-flex items-center justify-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-brand-secondary shadow-sm transition hover:-translate-y-0.5">
                                    Volver
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            @if ($isReopenRequested)
                <section class="rounded-[2rem] border border-brand-secondary/10 bg-white p-5 shadow-sm sm:p-6" x-data="{ closeReasonOpen: false }">
                    @if ($canManageTickets)
                        <div class="flex flex-col gap-4 border-b border-brand-secondary/10 pb-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-600">Reapertura pendiente</p>
                                <p class="mt-1 text-sm text-brand-secondary/65">Solo el equipo de IT con permiso puede decidir si se reabre o se clausura definitivamente.</p>
                            </div>

                            <span class="inline-flex rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-rose-700 ring-1 ring-rose-200">
                                Reapertura
                            </span>
                        </div>

                        <form method="POST" action="{{ route('tickets.reopen', $ticket) }}" class="mt-5 space-y-4 rounded-[1.5rem] border border-slate-200 bg-slate-50/70 p-4" data-ticket-final-action-form>
                            @csrf

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="space-y-2">
                                    <label for="reopen-priority" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/50">
                                        Prioridad
                                    </label>
                                    <select
                                        id="reopen-priority"
                                        name="priority"
                                        class="w-full rounded-xl border border-brand-secondary/15 bg-white px-3 py-2 text-sm text-brand-secondary shadow-sm focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/15"
                                    >
                                        @foreach ($ticketPriorities as $priorityKey => $priorityMetaOption)
                                            <option value="{{ $priorityKey }}" @selected($ticket->priority === $priorityKey)>
                                                {{ $priorityMetaOption['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="space-y-2">
                                    <label for="reopen-assignee" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/50">
                                        Selecciona responsable
                                    </label>
                                    <select
                                        id="reopen-assignee"
                                        name="assigned_to_user_id"
                                        class="w-full rounded-xl border border-brand-secondary/15 bg-white px-3 py-2 text-sm text-brand-secondary shadow-sm focus:border-brand-primary focus:outline-none focus:ring-2 focus:ring-brand-primary/15"
                                    >
                                        <option value="">Selecciona responsable</option>
                                        @foreach ($assignableUsers as $user)
                                            <option value="{{ $user['id'] }}" @selected($ticket->assigned_to_user_id === $user['id'])>
                                                {{ $user['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    class="inline-flex h-11 items-center justify-center rounded-2xl bg-emerald-600 px-5 text-sm font-semibold text-white transition hover:bg-emerald-500"
                                >
                                    Reabrir
                                </button>

                                <button
                                    type="button"
                                    @click="closeReasonOpen = true"
                                    class="inline-flex h-11 items-center justify-center rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white transition hover:bg-slate-800"
                                >
                                    Clausurar
                                </button>
                            </div>
                        </form>

                        <div
                            x-cloak
                            x-show="closeReasonOpen"
                            x-transition.opacity
                            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 px-4 py-8 backdrop-blur-sm"
                            @click.self="closeReasonOpen = false"
                        >
                            <div class="w-full max-w-2xl rounded-[2rem] bg-white p-6 shadow-2xl">
                                <div class="flex items-start justify-between gap-4 border-b border-brand-secondary/10 pb-4">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Clausurar definitivamente</p>
                                        <h3 class="mt-1 text-xl font-bold tracking-tight text-brand-secondary">Escribe el motivo</h3>
                                    </div>

                                    <button
                                        type="button"
                                        @click="closeReasonOpen = false"
                                        class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-500 transition hover:bg-slate-200 hover:text-slate-700"
                                        aria-label="Cerrar modal"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <form method="POST" action="{{ route('tickets.permanently-close', $ticket) }}" class="mt-5 space-y-4" data-ticket-final-action-form>
                                    @csrf
                                    <div>
                                        <label for="permanent-close-reason" class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-secondary/50">
                                            Motivo de clausura definitiva
                                        </label>
                                        <textarea
                                            id="permanent-close-reason"
                                            name="reason"
                                            rows="5"
                                            required
                                            placeholder="Explica por qué este ticket se clausura definitivamente..."
                                            class="mt-2 w-full rounded-[1.1rem] border border-slate-200 bg-white px-4 py-3 text-sm text-brand-secondary outline-none transition placeholder:text-slate-400 focus:border-brand-primary/30 focus:ring-2 focus:ring-brand-primary/15"
                                        >{{ old('reason') }}</textarea>
                                    </div>

                                    <div class="flex flex-wrap justify-end gap-3">
                                        <button
                                            type="button"
                                            @click="closeReasonOpen = false"
                                            class="inline-flex h-11 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-brand-secondary transition hover:bg-slate-50"
                                        >
                                            Cancelar
                                        </button>

                                        <button
                                            type="submit"
                                            class="inline-flex h-11 items-center justify-center rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white transition hover:bg-slate-800"
                                        >
                                            Clausurar definitivamente
                                        </button>
                                    </div>

                                    @error('reason')
                                        <p class="text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                </form>
                            </div>
                        </div>
                    @else
                        <div class="rounded-[1.75rem] border border-amber-200 bg-amber-50/70 px-5 py-4 text-sm text-brand-secondary/75">
                            Tu solicitud de reapertura ya ha sido enviada y está pendiente de revisión por IT.
                        </div>
                    @endif
                </section>
            @endif

            <section class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(20rem,0.65fr)]">
                <div class="space-y-6">
                    <article class="flex max-h-[calc(100vh-7rem)] xl:max-h-[calc(100vh-10rem)] flex-col overflow-hidden rounded-[2rem] border border-brand-secondary/10 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-3 border-b border-brand-secondary/10 pb-4">
                            <h2 class="text-xl font-bold tracking-tight text-brand-secondary">Hilo de conversación</h2>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-secondary/40">
                                    {{ $conversationMessagesCount }} mensajes
                                </span>
                                <button
                                    type="button"
                                    aria-label="Recargar conversación"
                                    title="Recargar conversación"
                                    data-ticket-conversation-reload
                                    onclick="reloadTicketPage(this)"
                                    class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-xl border border-brand-secondary/15 bg-slate-50 text-brand-secondary/55 transition-colors hover:border-brand-secondary/25 hover:bg-slate-100 hover:text-brand-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30"
                                >
                                    <svg class="ticket-refresh-icon h-4 w-4" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path fill="currentColor" d="M4,12a1,1,0,0,1-2,0A9.983,9.983,0,0,1,18.242,4.206V2.758a1,1,0,1,1,2,0v4a1,1,0,0,1-1,1h-4a1,1,0,0,1,0-2h1.743A7.986,7.986,0,0,0,4,12Zm17-1a1,1,0,0,0-1,1A7.986,7.986,0,0,1,7.015,18.242H8.757a1,1,0,1,0,0-2h-4a1,1,0,0,0-1,1v4a1,1,0,0,0,2,0V19.794A9.984,9.984,0,0,0,22,12,1,1,0,0,0,21,11Z" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div
                            x-ref="ticketThread"
                            x-init="$nextTick(() => { if ($refs.ticketThread) { $refs.ticketThread.scrollTop = $refs.ticketThread.scrollHeight; } })"
                            class="mt-5 flex-1 space-y-3 overflow-y-auto pr-2"
                        >
                            @php
                                $openingAttachments = $ticketOpeningAttachments;
                                $openingIsMine = (int) ($ticket->user_id ?? 0) === (int) auth()->id();
                                $openingAlignClass = $openingIsMine ? 'justify-end' : 'justify-start';
                                $openingStackClass = $openingIsMine ? 'items-end' : 'items-start';
                                $openingToneClass = $openingIsMine
                                    ? 'bg-[#d9fdd3] text-slate-800 shadow-sm'
                                    : 'border border-slate-200 bg-white text-brand-secondary shadow-sm';
                                $openingMetaClass = $openingIsMine ? 'justify-end text-slate-500' : 'justify-start text-slate-400';
                            @endphp

                            <div class="flex {{ $openingAlignClass }}">
                                <div class="flex w-fit max-w-[82%] min-w-0 flex-col {{ $openingStackClass }}">
                                    <div class="relative inline-flex w-fit max-w-full flex-col items-start overflow-x-hidden rounded-[1.1rem] px-4 pt-2.5 pb-3 {{ $openingToneClass }}">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $openingIsMine ? 'text-slate-700' : 'text-brand-secondary' }}">
                                                {{ $ticket->user?->name ?? 'Usuario' }}
                                            </span>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] bg-brand-primary/10 text-brand-primary">
                                                Incidencia
                                            </span>
                                        </div>

                                        <div class="mt-1.5 inline-block w-fit max-w-full whitespace-pre-line break-words [overflow-wrap:anywhere] text-[15px] leading-[1.25]">{{ $ticket->description }}</div>

                                        @if ($openingAttachments->isNotEmpty())
                                            <div class="mt-3 space-y-2">
                                                @foreach ($openingAttachments as $screenshot)
                                                    @php
                                                        $screenshotPath = data_get($screenshot, 'path');
                                                        $screenshotName = data_get($screenshot, 'name', basename((string) $screenshotPath));
                                                        $screenshotUrl = $screenshotPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($screenshotPath) : '';
                                                        $screenshotExtension = strtolower((string) pathinfo((string) $screenshotPath, PATHINFO_EXTENSION));
                                                        $isImageScreenshot = in_array($screenshotExtension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                                                    @endphp
                                                    @if ($screenshotPath && $isImageScreenshot)
                                                        <button
                                                            type="button"
                                                            @click="openImage({ src: @js($screenshotUrl), alt: @js($screenshotName), title: @js($screenshotName) })"
                                                            class="group/image relative block cursor-pointer overflow-hidden rounded-[1rem] border border-black/5 bg-white/50 text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                                                            aria-label="Ver imagen {{ $screenshotName }}"
                                                        >
                                                            <img src="{{ $screenshotUrl }}" alt="{{ $screenshotName }}" class="max-h-72 w-full object-cover transition duration-300 group-hover/image:scale-105 group-hover/image:brightness-75">
                                                            <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-brand-secondary/0 text-xs font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover/image:bg-brand-secondary/30 group-hover/image:opacity-100">
                                                                Ver
                                                            </span>
                                                        </button>
                                                    @elseif ($screenshotPath)
                                                        <a href="{{ $screenshotUrl }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-[1rem] border border-black/5 bg-white/60 px-3 py-2 transition hover:bg-white">
                                                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />
                                                                </svg>
                                                            </span>
                                                            <span class="min-w-0 flex-1">
                                                                <span class="block truncate text-sm font-semibold text-brand-secondary">{{ $screenshotName }}</span>
                                                            </span>
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div class="mt-1 flex items-center gap-1 text-[11px] {{ $openingMetaClass }}">
                                        <span>{{ $ticket->created_at?->translatedFormat('d/m/Y H:i') }}</span>
                                    </div>
                                </div>
                            </div>

                            @forelse ($messages as $message)
                                @php
                                    $messageAttachments = collect($message->attachments ?? []);
                                    $isTicketOwnerMessage = $message->user_id === $ticket->user_id;
                                    $isMineMessage = (int) $message->user_id === (int) auth()->id();
                                    $messageBodyText = (string) ($message->body ?? '');
                                    $isSystemClosureMessage = filled($messageBodyText) && str_starts_with($messageBodyText, 'Ticket clausurado definitivamente');
                                    $isSystemReopenMessage = filled($messageBodyText) && $messageBodyText === 'Ticket reabierto';
                                    $isSystemMessage = $isSystemClosureMessage || $isSystemReopenMessage;
                                    $messageAlignClass = $isMineMessage ? 'justify-end' : 'justify-start';
                                    $messageStackClass = $isMineMessage ? 'items-end' : 'items-start';
                                    $messageToneClass = $isSystemMessage
                                        ? 'border border-dashed border-slate-300 bg-slate-50 text-slate-700 shadow-sm'
                                        : ($isMineMessage
                                        ? 'bg-[#d9fdd3] text-slate-800 shadow-sm'
                                        : 'border border-slate-200 bg-white text-brand-secondary shadow-sm');
                                    $messageMetaClass = $isSystemMessage
                                        ? 'justify-center text-slate-400'
                                        : ($isMineMessage ? 'justify-end text-slate-500' : 'justify-start text-slate-400');
                                @endphp

                                <div class="flex {{ $isSystemMessage ? 'justify-center' : $messageAlignClass }}">
                                    <div class="flex w-fit max-w-[82%] min-w-0 flex-col {{ $isSystemMessage ? 'items-center' : $messageStackClass }}">
                                        <div class="relative inline-flex w-fit max-w-full flex-col {{ $isSystemMessage ? 'items-center text-center' : 'items-start' }} overflow-x-hidden rounded-[1.1rem] px-4 pt-2.5 pb-3 {{ $messageToneClass }}">
                                            @if ($isSystemMessage)
                                                <span class="inline-flex rounded-full bg-slate-900 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white">
                                                    Sistema
                                                </span>
                                                @if ($isSystemClosureMessage)
                                                    @php
                                                        $messageLines = preg_split("/\r\n|\r|\n/", $messageBodyText) ?: [];
                                                        $messageHeadline = trim((string) ($messageLines[0] ?? ''));
                                                        $messageReason = trim((string) (preg_replace('/^Motivo:\s*/u', 'Motivo: ', (string) ($messageLines[1] ?? ''))));
                                                    @endphp
                                                    <div class="mt-2 text-[15px] font-semibold leading-[1.3] text-slate-800">{{ $messageHeadline }}</div>
                                                    @if ($messageReason !== '')
                                                        <div class="mt-1 whitespace-pre-line break-words [overflow-wrap:anywhere] text-[14px] leading-[1.35] text-slate-600">{{ $messageReason }}</div>
                                                    @endif
                                                @else
                                                    <div class="mt-2 text-[15px] font-semibold leading-[1.3] text-slate-800">Ticket reabierto</div>
                                                @endif
                                            @else
                                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                    <span class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $isMineMessage ? 'text-slate-700' : 'text-brand-secondary' }}">
                                                        {{ $message->author?->name ?? 'Usuario' }}
                                                    </span>
                                                    <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $isTicketOwnerMessage ? 'bg-brand-primary/10 text-brand-primary' : 'bg-amber-100 text-amber-700' }}">
                                                        {{ $isTicketOwnerMessage ? 'Solicitante' : 'IT' }}
                                                    </span>
                                                </div>

                                                @if (filled($message->body))
                                                    <div class="mt-1.5 inline-block w-fit max-w-full whitespace-pre-line break-words [overflow-wrap:anywhere] text-[15px] leading-[1.25]">{{ $message->body }}</div>
                                                @endif
                                            @endif

                                            @if ($messageAttachments->isNotEmpty())
                                                <div class="mt-3 space-y-2">
                                                    @foreach ($messageAttachments as $attachment)
                                                        @php
                                                            $attachmentPath = data_get($attachment, 'path');
                                                            $attachmentName = data_get($attachment, 'name', basename((string) $attachmentPath));
                                                            $attachmentUrl = $attachmentPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($attachmentPath) : '';
                                                            $attachmentExtension = strtolower((string) pathinfo((string) $attachmentPath, PATHINFO_EXTENSION));
                                                            $isImageAttachment = in_array($attachmentExtension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                                                        @endphp

                                                        @if ($attachmentPath && $isImageAttachment)
                                                            <button
                                                                type="button"
                                                                @click="openImage({ src: @js($attachmentUrl), alt: @js($attachmentName), title: @js($attachmentName) })"
                                                                class="group/image relative block cursor-pointer overflow-hidden rounded-[1rem] border border-black/5 bg-white/50 text-left transition hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40"
                                                                aria-label="Ver imagen {{ $attachmentName }}"
                                                            >
                                                                <img src="{{ $attachmentUrl }}" alt="{{ $attachmentName }}" class="max-h-72 w-full object-cover transition duration-300 group-hover/image:scale-105 group-hover/image:brightness-75">
                                                                <span class="pointer-events-none absolute inset-0 flex items-center justify-center bg-brand-secondary/0 text-xs font-semibold uppercase tracking-[0.18em] text-white opacity-0 transition duration-300 group-hover/image:bg-brand-secondary/30 group-hover/image:opacity-100">
                                                                    Ver
                                                                </span>
                                                            </button>
                                                        @elseif ($attachmentPath)
                                                            <a href="{{ $attachmentUrl }}" target="_blank" rel="noopener" class="flex items-center gap-3 rounded-[1rem] border border-black/5 bg-white/60 px-3 py-2 transition hover:bg-white">
                                                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 3v5h5" />
                                                                    </svg>
                                                                </span>
                                                                <span class="min-w-0 flex-1">
                                                                    <span class="block truncate text-sm font-semibold text-brand-secondary">{{ $attachmentName }}</span>
                                                                </span>
                                                            </a>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>

                                        <div class="mt-1 flex items-center gap-1 text-[11px] {{ $messageMetaClass }}">
                                            <span>{{ $message->created_at?->translatedFormat('d/m/Y H:i') }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if ($isPermanentlyClosed)
                            <div class="mt-6 rounded-[1.75rem] border border-slate-200 bg-white px-5 py-4 text-sm text-brand-secondary/70">
                                Este ticket ha sido clausurado definitivamente y ya no admite nuevas respuestas ni reaperturas.
                            </div>
                        @elseif ($isClosed && (int) auth()->id() === (int) $ticket->user_id)
                            <form method="POST" action="{{ route('tickets.reopen.request', $ticket) }}" class="mt-6 space-y-4 rounded-[1.75rem] border border-amber-200 bg-amber-50/60 px-5 py-5">
                                @csrf

                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-700">Solicitar reapertura</p>
                                        <p class="mt-1 text-sm text-brand-secondary/70">Escribe por qué quieres reabrir este ticket y lo revisará el equipo de IT.</p>
                                    </div>
                                    <span class="inline-flex rounded-full bg-white px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-amber-700 ring-1 ring-amber-200">
                                        Cerrado
                                    </span>
                                </div>

                                <div>
                                    <label for="reopen-request-body" class="sr-only">Motivo de reapertura</label>
                                    <textarea
                                        id="reopen-request-body"
                                        name="body"
                                        rows="4"
                                        placeholder="Cuéntanos qué sigue fallando o qué ha cambiado para pedir la reapertura..."
                                        class="w-full rounded-[1.35rem] border border-slate-200 bg-white px-4 py-3 text-sm text-brand-secondary outline-none transition placeholder:text-slate-400 focus:border-brand-primary/30 focus:ring-2 focus:ring-brand-primary/15"
                                    >{{ old('body') }}</textarea>
                                </div>

                                <div class="flex justify-end">
                                    <button
                                        type="submit"
                                        class="inline-flex h-11 items-center justify-center rounded-2xl bg-amber-500 px-5 text-sm font-semibold text-white transition hover:bg-amber-400"
                                    >
                                        Solicitar reapertura
                                    </button>
                                </div>

                                @error('body')
                                    <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </form>
                        @elseif ($isClosed)
                            <div class="mt-6 rounded-[1.75rem] border border-slate-200 bg-white px-5 py-4 text-sm text-brand-secondary/70">
                                Este ticket está cerrado y ya no admite nuevas respuestas.
                            </div>
                        @elseif ($canReplyToTicket)
                            <form method="POST" action="{{ route('tickets.messages.store', $ticket) }}" enctype="multipart/form-data" class="mt-6" x-data="{ closeAndSend: false }" x-ref="ticketReplyForm" data-ticket-reply-form>
                                @csrf

                                <div class="relative">
                                    <div class="absolute bottom-full left-4 right-4 z-20 mb-3 hidden max-w-2xl overflow-hidden rounded-[1.4rem] border border-slate-200 bg-white shadow-2xl" data-ticket-attachments-hint>
                                        <div class="border-b border-slate-100 px-4 py-3">
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Adjuntos</p>
                                        </div>
                                        <div class="px-4 py-3 text-sm text-slate-500">
                                            Puedes adjuntar hasta 4 imágenes en JPG, PNG o WEBP.
                                        </div>
                                    </div>

                                    <div class="relative flex items-end gap-3 rounded-[1.75rem] border border-slate-200 bg-white px-4 py-3 shadow-sm transition">
                                        <label
                                            for="ticket-message-attachments"
                                            class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl text-slate-400 transition hover:bg-slate-100 hover:text-brand-primary"
                                            aria-label="Adjuntar archivo"
                                            title="Adjuntar archivo"
                                            data-ticket-attachments-button
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.5 12.5 21a6.364 6.364 0 1 1-9-9L12 3.5a4.243 4.243 0 1 1 6 6L8.5 19a2.121 2.121 0 1 1-3-3L14 7.5" />
                                            </svg>
                                        </label>

                                        <textarea
                                            x-ref="ticketReplyBody"
                                            @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $refs.ticketReplyForm.requestSubmit(); }"
                                            name="body"
                                            rows="1"
                                            placeholder="Escribe tu mensaje..."
                                            class="max-h-40 flex-1 resize-none border-0 bg-transparent px-0 py-2 text-[16px] text-brand-secondary outline-none placeholder:text-slate-400 focus:ring-0 md:text-[15px]"
                                        >{{ old('body') }}</textarea>

                                        <input
                                            id="ticket-message-attachments"
                                            type="file"
                                            name="attachments[]"
                                            multiple
                                            accept=".jpg,.jpeg,.png,.webp"
                                            data-ticket-attachments-input
                                            class="sr-only"
                                        >

                                        <div class="flex shrink-0 items-center gap-2">
                                            <button
                                                type="submit"
                                                @click="closeAndSend = true"
                                                name="close_ticket"
                                                value="1"
                                                class="inline-flex h-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl border border-brand-secondary/15 bg-white px-4 text-sm font-semibold text-brand-secondary transition hover:border-brand-primary/25 hover:bg-brand-primary/5 hover:text-brand-primary md:px-5 md:text-sm"
                                            >
                                                <span class="hidden md:inline">Enviar y cerrar</span>
                                                <span class="md:hidden">Cerrar</span>
                                            </button>

                                            <button
                                                type="submit"
                                                class="inline-flex h-10 shrink-0 cursor-pointer items-center justify-center rounded-2xl bg-brand-primary px-5 text-sm font-semibold text-white transition hover:opacity-90 md:px-5 md:text-sm"
                                            >
                                                <span class="hidden md:inline">Enviar</span>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M10.3009 13.6949L20.102 3.89742M10.5795 14.1355L12.8019 18.5804C13.339 19.6545 13.6075 20.1916 13.9458 20.3356C14.2394 20.4606 14.575 20.4379 14.8492 20.2747C15.1651 20.0866 15.3591 19.5183 15.7472 18.3818L19.9463 6.08434C20.2845 5.09409 20.4535 4.59896 20.3378 4.27142C20.2371 3.98648 20.013 3.76234 19.7281 3.66167C19.4005 3.54595 18.9054 3.71502 17.9151 4.05315L5.61763 8.2523C4.48114 8.64037 3.91289 8.83441 3.72478 9.15032C3.56153 9.42447 3.53891 9.76007 3.66389 10.0536C3.80791 10.3919 4.34498 10.6605 5.41912 11.1975L9.86397 13.42C10.041 13.5085 10.1295 13.5527 10.2061 13.6118C10.2742 13.6643 10.3352 13.7253 10.3876 13.7933C10.4468 13.87 10.491 13.9585 10.5795 14.1355Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-2 hidden px-1 text-xs text-slate-500" data-ticket-attachments-preview></div>
                                <div class="mt-2 hidden px-1" data-ticket-attachments-chips></div>

                                <input type="hidden" name="close_ticket" :value="closeAndSend ? '1' : '0'">

                                @error('body')
                                    <p class="mt-2 px-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                                @error('attachments')
                                    <p class="mt-2 px-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                                @error('attachments.*')
                                    <p class="mt-2 px-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </form>
                        @else
                            <div class="mt-6 rounded-[1.75rem] border border-brand-secondary/10 bg-white px-4 py-4 text-sm text-brand-secondary/70">
                                No tienes permiso para responder a este ticket.
                            </div>
                        @endif
                    </article>
                </div>

                <aside class="flex max-h-[calc(100vh-7rem)] xl:max-h-[calc(100vh-10rem)] flex-col overflow-hidden rounded-[2rem] border border-brand-secondary/10 bg-white p-6 shadow-sm lg:sticky lg:top-6">
                    <div class="flex items-center justify-between gap-3 border-b border-brand-secondary/10 pb-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-secondary/45">Actualizaciones</p>
                            <h2 class="mt-1 text-xl font-bold tracking-tight text-brand-secondary">Log del ticket</h2>
                        </div>
                        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-secondary/40">
                            {{ $activityLogs->count() }} eventos
                        </span>
                    </div>

                    <div
                        x-ref="ticketLog"
                        x-init="$nextTick(() => { if ($refs.ticketLog) { $refs.ticketLog.scrollTop = $refs.ticketLog.scrollHeight; } })"
                        class="relative mt-5 flex-1 space-y-4 overflow-y-auto pl-4 pr-2"
                    >
                        @forelse ($activityLogs as $activity)
                                @php
                                    $eventPillClass = match ($activity->event) {
                                        'created' => 'bg-sky-50 text-sky-700 ring-sky-200',
                                        'assigned' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                        'comment_added' => 'bg-violet-50 text-violet-700 ring-violet-200',
                                        'status_changed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                        'tool_changed' => 'bg-cyan-50 text-cyan-700 ring-cyan-200',
                                        'reopen_requested' => 'bg-rose-50 text-rose-700 ring-rose-200',
                                        'reopened' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                        'permanently_closed' => 'bg-slate-100 text-slate-700 ring-slate-200',
                                        'closed' => 'bg-slate-100 text-slate-700 ring-slate-200',
                                        default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                    };
                                $eventAccentClass = match ($activity->event) {
                                    'created' => 'bg-sky-500',
                                    'assigned' => 'bg-amber-500',
                                    'comment_added' => 'bg-violet-500',
                                    'status_changed' => 'bg-emerald-500',
                                    'tool_changed' => 'bg-cyan-500',
                                    'reopen_requested' => 'bg-rose-500',
                                    'reopened' => 'bg-emerald-500',
                                    'permanently_closed' => 'bg-slate-500',
                                    'closed' => 'bg-slate-500',
                                    default => 'bg-brand-primary',
                                };
                                $eventCardClass = match ($activity->event) {
                                    'created' => 'border-sky-200/70 bg-sky-50/70',
                                    'assigned' => 'border-amber-200/70 bg-amber-50/60',
                                    'comment_added' => 'border-violet-200/70 bg-violet-50/60',
                                    'status_changed' => 'border-emerald-200/70 bg-emerald-50/60',
                                    'tool_changed' => 'border-cyan-200/70 bg-cyan-50/60',
                                    'reopen_requested' => 'border-rose-200/70 bg-rose-50/60',
                                    'reopened' => 'border-emerald-200/70 bg-emerald-50/60',
                                    'permanently_closed' => 'border-slate-200 bg-slate-50/70',
                                    'closed' => 'border-slate-200 bg-slate-50/70',
                                    default => 'border-brand-secondary/10 bg-slate-50/80',
                                };
                            @endphp
                            <article class="relative rounded-[1.25rem] border p-4 shadow-[inset_0_0_0_1px_rgba(15,23,42,0.03)] {{ $eventCardClass }}">
                                <span class="pointer-events-none absolute left-[1.1875rem] top-[-0.9rem] bottom-[-0.9rem] w-0.5 bg-gradient-to-b from-transparent via-slate-300/90 to-transparent"></span>
                                <span class="absolute left-[-0.125rem] top-5 z-10 inline-flex h-10 w-10 items-center justify-center rounded-full border-4 border-white bg-white shadow-sm {{ $eventAccentClass }}">
                                    @if ($activity->event === 'created')
                                        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-5 w-5 shrink-0 text-sky-500">
                                            <path d="M28 31h-24c-1.657 0-3-1.344-3-3v-14c0-1.657 1.343-3 3-3h24c1.656 0 3 1.343 3 3v14c0 1.656-1.344 3-3 3zM8 18.041c-1.657 0-3 1.567-3 3.5 0 1.934 1.343 3.5 3 3.5s3-1.566 3-3.5c0-1.933-1.344-3.5-3-3.5zM15.78 18.898c-0.115-0.237-0.269-0.422-0.458-0.553-0.19-0.132-0.426-0.221-0.707-0.268-0.2-0.037-0.49-0.055-0.87-0.055h-1.783v6.914h1.008v-2.016h0.842c0.81 0 1.368-0.356 1.679-0.693 0.309-0.338 0.464-1.812 0.464-2.299-0.001-0.282-0.059-0.793-0.175-1.030zM21.024 23.945h-3.058v-2.008h2.956v-0.93h-2.956v-2.055h3.05v-0.93h-3.995v6.914h4.003v-0.991zM26.954 18.023h-0.914v5.31l-3.012-5.31h-1.027v6.914h0.977v-5.061l3.012 5.061h0.965v-6.914zM13.823 21.93h-0.853v-2.977h0.838c0.343 0 0.578 0.017 0.706 0.051 0.197 0.055 0.357 0.166 0.478 0.336s0.182 0.375 0.182 0.613c0 0.33-0.103 1.522-0.309 1.704s-0.553 0.273-1.042 0.273zM8 24.125c-1.104 0-2-1.119-2-2.5s0.896-2.5 2-2.5 2 1.119 2 2.5-0.896 2.5-2 2.5zM25 11l-7.322-5.45c-0.344 0.277-0.775 0.45-1.25 0.45-0.662 0-1.244-0.325-1.607-0.821l-7.821 5.821h-1l8.493-6.518c-0.038-0.155-0.065-0.315-0.065-0.482 0-1.104 0.896-2 2-2 1.105 0 2 0.896 2 2 0 0.359-0.103 0.692-0.269 0.984l7.841 6.016h-1z" fill="currentColor"></path>
                                        </svg>
                                    @elseif ($activity->event === 'assigned')
                                        <svg fill="none" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-8 w-8 shrink-0 text-amber-500">
                                            <path d="M28.5,68.5v-34H26.9a4.89,4.89,0,0,0-4.8,4.9V74.8a4.89,4.89,0,0,0,4.8,4.9H62.5a4.89,4.89,0,0,0,4.8-4.9V73.4h-34A4.89,4.89,0,0,1,28.5,68.5Z" fill="currentColor"/><path d="M46.4,30.2H64.1a1.58,1.58,0,0,0,1.6-1.6V25.3a4.89,4.89,0,0,0-4.8-4.9H49.6a4.82,4.82,0,0,0-4.8,4.9v3.3A1.64,1.64,0,0,0,46.4,30.2Z" fill="currentColor"/><path d="M73,24.4H71.4a.74.74,0,0,0-.8.8v3.3a6.57,6.57,0,0,1-6.5,6.6H46.4a6.64,6.64,0,0,1-6.5-6.6V25.2a.74.74,0,0,0-.8-.8H37.5a4.82,4.82,0,0,0-4.8,4.9V64.6a4.89,4.89,0,0,0,4.8,4.9H73a4.82,4.82,0,0,0,4.8-4.9V29.4A4.85,4.85,0,0,0,73,24.4ZM60.9,55.5a1.58,1.58,0,0,1-1.6,1.6H43.1a1.58,1.58,0,0,1-1.6-1.6V53.9a1.58,1.58,0,0,1,1.6-1.6H59.3a1.58,1.58,0,0,1,1.6,1.6ZM69,47.3a1.58,1.58,0,0,1-1.6,1.6H43.1a1.58,1.58,0,0,1-1.6-1.6V45.7a1.58,1.58,0,0,1,1.6-1.6H67.4A1.58,1.58,0,0,1,69,45.7Z" fill="currentColor"/>
                                        </svg>
                                    @elseif ($activity->event === 'comment_added')
                                        <svg width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-violet-600">
                                            <path fill="currentColor" d="M493.332,386.691c11.748-16.658,18.686-36.368,18.668-57.342c0.018-26.135-10.753-50.179-28.138-69.036c-17.411-18.91-41.393-33.001-68.928-40.504L407.7,246.41c22.994,6.247,42.461,17.95,55.86,32.562c13.436,14.665,20.85,31.862,20.868,50.377c-0.018,14.898-4.811,28.882-13.652,41.483c-8.831,12.565-21.792,23.613-37.722,31.772l-11.64,5.96l15.087,33.737c-45.719-7.962-88.134-22.806-109.585-31.162l-0.422-0.161l-0.431-0.135c-28.765-9.02-51.202-26.754-62.627-47.702l-24.179,13.265c15.473,28.11,43.547,49.453,77.679,60.429v0.009c26.531,10.277,82.274,29.824,140.837,36.376l23.945,2.657l-24.753-55.358C471.487,411.362,483.962,400.009,493.332,386.691z"/>
                                            <path fill="currentColor" d="M359.056,286.789c22.365-25.526,35.918-57.261,35.9-91.564c0.009-22.429-5.78-43.852-16.065-63.068c-15.446-28.855-40.819-52.809-72.214-69.593c-31.404-16.774-68.937-26.432-109.199-26.441c-53.67,0.018-102.513,17.143-138.44,45.53c-17.959,14.207-32.687,31.27-42.973,50.503C5.78,151.374-0.009,172.797,0,195.226c-0.018,28.621,9.433,55.52,25.57,78.415c13.795,19.61,32.472,36.367,54.488,49.48l-33.163,74.16l23.946-2.656c82.688-9.245,161.874-36.968,199.461-51.526C306.04,331.691,336.86,312.152,359.056,286.789z M260.743,317.233l-0.422,0.161c-32.534,12.656-98.366,35.641-168.309,46.536l23.703-53.007l-11.641-5.959c-23.487-12.026-42.73-28.379-55.96-47.191c-13.238-18.838-20.526-40.001-20.544-62.546c0.009-17.699,4.497-34.509,12.808-50.072c12.439-23.298,33.647-43.717,60.887-58.266c27.229-14.558,60.375-23.2,96.212-23.192c47.783-0.018,90.782,15.384,121.342,39.58c15.285,12.098,27.455,26.342,35.757,41.877c8.302,15.563,12.798,32.374,12.807,50.072c-0.017,26.979-10.473,52.082-29.079,73.415c-18.587,21.28-45.396,38.477-77.131,48.457L260.743,317.233z"/>
                                            <path fill="currentColor" d="M119.583,177.679c-11.838,0-21.441,9.603-21.441,21.45c0,11.838,9.603,21.442,21.441,21.442c11.848,0,21.451-9.603,21.451-21.442C141.034,187.283,131.431,177.679,119.583,177.679z"/>
                                            <path fill="currentColor" d="M202.298,177.679c-11.838,0-21.442,9.603-21.442,21.45c0,11.838,9.603,21.442,21.442,21.442c11.847,0,21.45-9.603,21.45-21.442C223.748,187.283,214.145,177.679,202.298,177.679z"/>
                                            <path fill="currentColor" d="M285.012,177.679c-11.838,0-21.442,9.603-21.442,21.45c0,11.838,9.603,21.442,21.442,21.442c11.847,0,21.45-9.603,21.45-21.442C306.462,187.283,296.859,177.679,285.012,177.679z"/>
                                        </svg>
                                    @elseif ($activity->event === 'reopen_requested')
                                        <svg version="1.1" id="Uploaded to svgrepo.com" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="800px" height="800px" viewBox="0 0 32 32" xml:space="preserve" aria-hidden="true" class="block h-6 w-6 shrink-0 text-rose-700">
                                            <path fill="currentColor" d="M16,2C8.268,2,2,8.268,2,16s6.268,14,14,14s14-6.268,14-14S23.732,2,16,2z M16,26 c-0.552,0-1-0.448-1-1c0-0.552,0.448-1,1-1s1,0.448,1,1C17,25.552,16.552,26,16,26z M17,18.917V21c0,0.552-0.447,1-1,1s-1-0.448-1-1 v-3c0-0.552,0.447-1,1-1c2.206,0,4-1.794,4-4s-1.794-4-4-4s-4,1.794-4,4c0,0.552-0.447,1-1,1s-1-0.448-1-1c0-3.309,2.691-6,6-6 s6,2.691,6,6C22,15.968,19.834,18.439,17,18.917z"/>
                                        </svg>
                                    @elseif ($activity->event === 'status_changed')
                                        <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-emerald-700">
                                            <path fill-rule="evenodd" clip-rule="evenodd" d="M1 12C1 5.92487 5.92487 1 12 1C18.0751 1 23 5.92487 23 12C23 18.0751 18.0751 23 12 23C5.92487 23 1 18.0751 1 12ZM10.25 11C10.25 10.4477 10.6977 10 11.25 10H12.75C13.3023 10 13.75 10.4477 13.75 11V18C13.75 18.5523 13.3023 19 12.75 19H11.25C10.6977 19 10.25 18.5523 10.25 18V11ZM14 7C14 5.89543 13.1046 5 12 5C10.8954 5 10 5.89543 10 7C10 8.10457 10.8954 9 12 9C13.1046 9 14 8.10457 14 7Z" fill="currentColor"/>
                                        </svg>
                                    @elseif ($activity->event === 'tool_changed')
                                        <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-cyan-700">
                                            <path fill-rule="evenodd" clip-rule="evenodd" d="M8.70711 4.70711C9.09763 4.31658 9.09763 3.68342 8.70711 3.29289C8.31658 2.90237 7.68342 2.90237 7.29289 3.29289L3.29289 7.29289C2.90237 7.68342 2.90237 8.31658 3.29289 8.70711L7.29289 12.7071C7.68342 13.0976 8.31658 13.0976 8.70711 12.7071C9.09763 12.3166 9.09763 11.6834 8.70711 11.2929L6.41421 9H16C16.5523 9 17 8.55228 17 8C17 7.44772 16.5523 7 16 7H6.41421L8.70711 4.70711ZM20.7071 15.2929L16.7071 11.2929C16.3166 10.9024 15.6834 10.9024 15.2929 11.2929C14.9024 11.6834 14.9024 12.3166 15.2929 12.7071L17.5858 15H8C7.44772 15 7 15.4477 7 16C7 16.5523 7.44772 17 8 17H17.5858L15.2929 19.2929C14.9024 19.6834 14.9024 20.3166 15.2929 20.7071C15.6834 21.0976 16.3166 21.0976 16.7071 20.7071L20.7071 16.7071C21.0976 16.3166 21.0976 15.6834 20.7071 15.2929Z" fill="currentColor"/>
                                        </svg>
                                    @elseif ($activity->event === 'requester_changed')
                                        <svg width="800px" height="800px" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-black">
                                            <g id="Layer_2" data-name="Layer 2">
                                                <g id="invisible_box" data-name="invisible box">
                                                    <rect width="48" height="48" fill="none"></rect>
                                                </g>
                                                <g id="Medical">
                                                    <g>
                                                        <path d="M12.1,12A5,5,0,1,0,7,7.1,5,5,0,0,0,12.1,12Z" fill="currentColor"></path>
                                                        <path d="M16,36a2,2,0,0,0-2,2v4H10V32H6V20a2,2,0,0,1,2-2h6a2,2,0,0,0,0-4H8a6,6,0,0,0-6,6V33a2.9,2.9,0,0,0,3,3H6v7a2.9,2.9,0,0,0,3,3h6a2.9,2.9,0,0,0,3-3V38A2,2,0,0,0,16,36Z" fill="currentColor"></path>
                                                        <path d="M36.1,12A5,5,0,1,0,31,7.1,5,5,0,0,0,36.1,12Z" fill="currentColor"></path>
                                                        <path d="M40,14H33a2,2,0,0,0,0,4h7a2,2,0,0,1,2,2V32H38V42H34V38a2,2,0,0,0-4,0v5a2.9,2.9,0,0,0,3,3h6a2.9,2.9,0,0,0,3-3V36h1a2.9,2.9,0,0,0,3-3V20A6,6,0,0,0,40,14Z" fill="currentColor"></path>
                                                        <path d="M31.4,33.4,37.8,27l-6.4-6.4a2,2,0,0,0-2.8,2.8L30.2,25H17.8l1.6-1.6a2,2,0,0,0-2.8-2.8L10.2,27l6.4,6.4a1.9,1.9,0,0,0,2.8,0,1.9,1.9,0,0,0,0-2.8L17.8,29H30.2l-1.6,1.6a1.9,1.9,0,0,0,0,2.8,1.9,1.9,0,0,0,2.8,0Z" fill="currentColor"></path>
                                                    </g>
                                                </g>
                                            </g>
                                        </svg>
                                    @elseif ($activity->event === 'reopened')
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-emerald-600">
                                            <path d="M3 3L21 21M17 10V8C17 5.23858 14.7614 3 12 3C11.0283 3 10.1213 3.27719 9.35386 3.75681M7.08383 7.08338C7.02878 7.38053 7 7.6869 7 8V10.0288M19.5614 19.5618C19.273 20.0348 18.8583 20.4201 18.362 20.673C17.7202 21 16.8802 21 15.2 21H8.8C7.11984 21 6.27976 21 5.63803 20.673C5.07354 20.3854 4.6146 19.9265 4.32698 19.362C4 18.7202 4 17.8802 4 16.2V14.8C4 13.1198 4 12.2798 4.32698 11.638C4.6146 11.0735 5.07354 10.6146 5.63803 10.327C5.99429 10.1455 6.41168 10.0647 7 10.0288M19.9998 14.4023C19.9978 12.9831 19.9731 12.227 19.673 11.638C19.3854 11.0735 18.9265 10.6146 18.362 10.327C17.773 10.0269 17.0169 10.0022 15.5977 10.0002M10 10H8.8C8.05259 10 7.47142 10 7 10.0288" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    @elseif ($activity->event === 'permanently_closed')
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-slate-700">
                                            <path d="M7 10.0288C7.47142 10 8.05259 10 8.8 10H15.2C15.9474 10 16.5286 10 17 10.0288M7 10.0288C6.41168 10.0647 5.99429 10.1455 5.63803 10.327C5.07354 10.6146 4.6146 11.0735 4.32698 11.638C4 12.2798 4 13.1198 4 14.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V14.8C20 13.1198 20 12.2798 19.673 11.638C19.3854 11.0735 18.9265 10.6146 18.362 10.327C18.0057 10.1455 17.5883 10.0647 17 10.0288M7 10.0288V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10.0288" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    @elseif ($activity->event === 'closed')
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="block h-6 w-6 shrink-0 text-slate-700">
                                            <path d="M7 10.0288C7.47142 10 8.05259 10 8.8 10H15.2C15.9474 10 16.5286 10 17 10.0288M7 10.0288C6.41168 10.0647 5.99429 10.1455 5.63803 10.327C5.07354 10.6146 4.6146 11.0735 4.32698 11.638C4 12.2798 4 13.1198 4 14.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V14.8C20 13.1198 20 12.2798 19.673 11.638C19.3854 11.0735 18.9265 10.6146 18.362 10.327C18.0057 10.1455 17.5883 10.0647 17 10.0288M7 10.0288V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10.0288" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    @endif
                                </span>
                                <div class="min-w-0 pl-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $eventPillClass }}">
                                            {{ $activity->event_label }}
                                        </span>
                                        <span class="text-xs text-brand-secondary/50">
                                            {{ $activity->created_at?->translatedFormat('d/m/Y H:i') }}
                                        </span>
                                    </div>

                                    <p class="mt-2 text-sm font-semibold text-brand-secondary">
                                        {{ $activity->title }}
                                    </p>
                                    <p class="mt-1 text-xs text-brand-secondary/60">
                                        Por {{ $activity->actor_name }}
                                    </p>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-2xl border border-dashed border-brand-secondary/15 bg-slate-50 px-4 py-8 text-center text-sm text-brand-secondary/60">
                                Todavía no hay movimientos en este ticket.
                            </div>
                        @endforelse
                    </div>
                </aside>
            </section>
        </div>

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
                    class="relative touch-none overflow-hidden rounded-[2rem] border border-white/10 bg-white/5 shadow-2xl"
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
                        class="block max-h-[80vh] w-auto max-w-[calc(100vw-3rem)] select-none object-contain bg-slate-900 will-change-transform"
                        :class="isDragging ? 'transition-none' : 'transition-transform duration-200'"
                        :style="`transform: translate3d(${translateX}px, ${translateY}px, 0) scale(${imageScale}); transform-origin: center center;`"
                    >
                </div>

                <div class="mt-4 flex items-center justify-center gap-2">
                    <button type="button" @click="zoomOut()" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white" aria-label="Reducir zoom">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4" />
                        </svg>
                    </button>
                    <button type="button" @click="resetZoom()" class="inline-flex h-10 min-w-20 items-center justify-center rounded-full bg-white/90 px-3 text-sm font-semibold text-brand-secondary shadow-lg transition hover:bg-white" aria-label="Restablecer zoom">
                        <span x-text="`${imageScale.toFixed(2).replace(/\.00$/, '')}x`"></span>
                    </button>
                    <button type="button" @click="downloadImage()" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white" aria-label="Descargar imagen">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-5 w-5">
                            <path d="M12.5535 16.5061C12.4114 16.6615 12.2106 16.75 12 16.75C11.7894 16.75 11.5886 16.6615 11.4465 16.5061L7.44648 12.1311C7.16698 11.8254 7.18822 11.351 7.49392 11.0715C7.79963 10.792 8.27402 10.8132 8.55352 11.1189L11.25 14.0682V3C11.25 2.58579 11.5858 2.25 12 2.25C12.4142 2.25 12.75 2.58579 12.75 3V14.0682L15.4465 11.1189C15.726 10.8132 16.2004 10.792 16.5061 11.0715C16.8118 11.351 16.833 11.8254 16.5535 12.1311L12.5535 16.5061Z" fill="#1C274C"/>
                            <path d="M3.75 15C3.75 14.5858 3.41422 14.25 3 14.25C2.58579 14.25 2.25 14.5858 2.25 15V15.0549C2.24998 16.4225 2.24996 17.5248 2.36652 18.3918C2.48754 19.2919 2.74643 20.0497 3.34835 20.6516C3.95027 21.2536 4.70814 21.5125 5.60825 21.6335C6.47522 21.75 7.57754 21.75 8.94513 21.75H15.0549C16.4225 21.75 17.5248 21.75 18.3918 21.6335C19.2919 21.5125 20.0497 21.2536 20.6517 20.6516C21.2536 20.0497 21.5125 19.2919 21.6335 18.3918C21.75 17.5248 21.75 16.4225 21.75 15.0549V15C21.75 14.5858 21.4142 14.25 21 14.25C20.5858 14.25 20.25 14.5858 20.25 15C20.25 16.4354 20.2484 17.4365 20.1469 18.1919C20.0482 18.9257 19.8678 19.3142 19.591 19.591C19.3142 19.8678 18.9257 20.0482 18.1919 20.1469C17.4365 20.2484 16.4354 20.25 15 20.25H9C7.56459 20.25 6.56347 20.2484 5.80812 20.1469C5.07435 20.0482 4.68577 19.8678 4.40901 19.591C4.13225 19.3142 3.9518 18.9257 3.85315 18.1919C3.75159 17.4365 3.75 16.4354 3.75 15Z" fill="#1C274C"/>
                        </svg>
                    </button>
                    <button type="button" @click="zoomIn()" class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-brand-secondary shadow-lg transition hover:bg-white" aria-label="Aumentar zoom">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                    </button>
                </div>

                <p class="mt-4 text-center text-sm font-medium text-white/80" x-text="imageTitle"></p>
            </div>
        </div>
    </main>

    <style>
        .ticket-refresh-icon.is-reloading {
            animation: ticket-refresh-spin 500ms linear 1;
        }

        @keyframes ticket-refresh-spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>

    <script>
        window.reloadTicketPage = (button) => {
            if (!button || button.disabled) {
                return;
            }

            const icon = button.querySelector('.ticket-refresh-icon');

            button.disabled = true;
            icon?.classList.add('is-reloading');

            window.setTimeout(() => window.location.reload(), 500);
        };

        document.addEventListener('DOMContentLoaded', () => {
            const scrollStorageKey = `ticket-page-scroll:{{ $ticket->id }}`;
            const restoreStoredScroll = () => {
                const storedPosition = Number(sessionStorage.getItem(scrollStorageKey));

                if (!Number.isFinite(storedPosition) || storedPosition < 0) {
                    return;
                }

                requestAnimationFrame(() => {
                    window.scrollTo({ top: storedPosition, behavior: 'auto' });
                    sessionStorage.removeItem(scrollStorageKey);
                });
            };

            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }

            restoreStoredScroll();

            const form = document.querySelector('[data-ticket-reply-form]');
            const attachmentsInput = form?.querySelector('[data-ticket-attachments-input]');
            const attachmentsButton = form?.querySelector('[data-ticket-attachments-button]');
            const attachmentsPreview = form?.querySelector('[data-ticket-attachments-preview]');
            const attachmentsChips = form?.querySelector('[data-ticket-attachments-chips]');

            if (!form || !attachmentsInput || !attachmentsButton || !attachmentsPreview || !attachmentsChips) {
                return;
            }

            let attachmentSnapshot = [];
            let attachmentObjectUrls = [];
            const maxAttachmentCount = 4;

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            }[character]));

            const syncAttachmentInputFiles = () => {
                const dataTransfer = new DataTransfer();

                attachmentSnapshot.forEach((file) => dataTransfer.items.add(file));
                attachmentsInput.files = dataTransfer.files;
            };

            const renderAttachmentsPreview = () => {
                attachmentObjectUrls.forEach((url) => URL.revokeObjectURL(url));
                attachmentObjectUrls = [];

                if (!attachmentSnapshot.length) {
                    attachmentsPreview.classList.add('hidden');
                    attachmentsPreview.innerHTML = '';
                    attachmentsChips.classList.add('hidden');
                    attachmentsChips.innerHTML = '';
                    return;
                }

                const previewText = attachmentSnapshot.map((file) => `${file.name} (${Math.ceil(file.size / 1024)} KB)`).join(' · ');
                attachmentsPreview.textContent = `${attachmentSnapshot.length} imagen${attachmentSnapshot.length === 1 ? '' : 'es'} seleccionada${attachmentSnapshot.length === 1 ? '' : 's'}: ${previewText}`;
                attachmentsPreview.classList.remove('hidden');

                attachmentsChips.innerHTML = attachmentSnapshot.map((file, index) => {
                    const objectUrl = URL.createObjectURL(file);
                    attachmentObjectUrls.push(objectUrl);

                    return `
                        <span class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-brand-secondary shadow-sm">
                            <span class="inline-flex h-9 w-9 shrink-0 overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
                                <img src="${objectUrl}" alt="${escapeHtml(file.name)}" class="h-full w-full object-cover">
                            </span>
                            <span class="max-w-[11rem] truncate">${escapeHtml(file.name)}</span>
                            <button type="button" class="cursor-pointer text-slate-400 transition hover:text-rose-500" data-ticket-remove-attachment-index="${index}" aria-label="Quitar ${escapeHtml(file.name)}">
                                ×
                            </button>
                        </span>
                    `;
                }).join('');

                attachmentsChips.classList.remove('hidden');
            };

            const appendAttachments = (files) => {
                const incomingFiles = Array.from(files || []).filter((file) => String(file?.type || '').startsWith('image/'));

                if (!incomingFiles.length) {
                    return;
                }

                const currentKeys = new Set(attachmentSnapshot.map((file) => `${file.name}:${file.size}:${file.lastModified}`));
                const accepted = [];

                incomingFiles.forEach((file) => {
                    if ((attachmentSnapshot.length + accepted.length) >= maxAttachmentCount) {
                        return;
                    }

                    const key = `${file.name}:${file.size}:${file.lastModified}`;

                    if (currentKeys.has(key)) {
                        return;
                    }

                    currentKeys.add(key);
                    accepted.push(file);
                });

                if (!accepted.length) {
                    return;
                }

                attachmentSnapshot = [...attachmentSnapshot, ...accepted];
                syncAttachmentInputFiles();
                renderAttachmentsPreview();
            };

            const removeAttachmentAtIndex = (index) => {
                attachmentSnapshot = attachmentSnapshot.filter((_, currentIndex) => currentIndex !== index);
                syncAttachmentInputFiles();
                renderAttachmentsPreview();
            };

            form.addEventListener('submit', () => {
                sessionStorage.setItem(scrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
            });

            attachmentsButton.addEventListener('click', (event) => {
                event.preventDefault();
                attachmentsInput.value = '';
                attachmentsInput.click();
            });

            attachmentsInput.addEventListener('change', (event) => {
                appendAttachments(event.target.files);
            });

            attachmentsChips.addEventListener('click', (event) => {
                const removeButton = event.target.closest('[data-ticket-remove-attachment-index]');

                if (!removeButton) {
                    return;
                }

                const index = Number(removeButton.dataset.ticketRemoveAttachmentIndex);

                if (Number.isNaN(index)) {
                    return;
                }

                removeAttachmentAtIndex(index);
            });

            document.querySelectorAll('[data-ticket-final-action-form]').forEach((finalActionForm) => {
                let submitted = false;

                finalActionForm.addEventListener('submit', (event) => {
                    if (submitted) {
                        event.preventDefault();
                        return;
                    }

                    submitted = true;

                    finalActionForm.querySelectorAll('button[type="submit"]').forEach((button) => {
                        button.disabled = true;
                        button.classList.add('cursor-not-allowed', 'opacity-80');
                    });
                });
            });
        });
    </script>
@endsection
