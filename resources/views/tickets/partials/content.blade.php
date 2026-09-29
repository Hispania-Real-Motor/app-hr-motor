@php
    $authUser = auth()->user();
@endphp

<div class="space-y-6">
    <section class="overflow-hidden rounded-[2rem] border border-brand-secondary/10 bg-white shadow-sm">
        <div
            class="relative bg-cover bg-no-repeat px-6 py-8 sm:px-8 sm:py-10"
            style="background-image: url('{{ asset('images/hero/hero-tickets-it.webp') }}'); background-position: center 48%;"
        >
            <div class="absolute inset-0 bg-slate-950/55"></div>
            <div class="relative max-w-3xl space-y-4">
                <div class="inline-flex items-center rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-white backdrop-blur-sm">
                    Tickets IT
                </div>
                <div class="space-y-2">
                    <h1 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Gestión de tickets
                    </h1>
                    <p class="max-w-2xl text-sm leading-6 text-white/80 sm:text-base">
                        Revisa los tickets que tienes asignados y, si cuentas con permiso de asignación, ve también el listado global para repartir nuevas incidencias al equipo de informática.
                    </p>
                    @if ($canAssignTickets || $canViewTicketReports)
                        @if ($canAssignTickets)
                        <p class="text-sm font-medium text-white/90">
                            Se pueden asignar tickets a usuarios de IT si tu propio usuario lo permite.
                        </p>
                        @endif
                        @if ($canViewTicketReports)
                        <div class="pt-2">
                            <a
                                href="{{ route('tickets.reports') }}"
                                class="inline-flex items-center justify-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-brand-secondary shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-50"
                            >
                                Ver informes de ticketing
                            </a>
                        </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </section>

    @if (session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($showAllTickets && $managedSection)
        @include('tickets.partials.section', [
            'sectionKey' => 'managed',
            'title' => 'Todos los tickets',
            'description' => 'Puedes asignar cada ticket a cualquier empleado de IT.',
            'searchPlaceholder' => 'Buscar por ticket, solicitante o asignado',
            'searchFields' => 'number requester assignee',
            'section' => $managedSection,
            'canManageTickets' => $canManageTickets,
            'canAssignTickets' => $canAssignTickets,
            'ticketStatuses' => $ticketStatuses,
            'ticketPriorities' => $ticketPriorities,
            'assignableUsers' => $assignableUsers,
            'isManaged' => true,
            'jumpTargetId' => 'mis-tickets',
            'jumpTargetLabel' => 'Bajar a mis tickets',
        ])
    @endif

    @include('tickets.partials.section', [
        'sectionKey' => 'assigned',
        'title' => 'Mis tickets',
        'description' => 'Estos son los tickets que están asignados a tu usuario.',
        'searchPlaceholder' => 'Buscar por ticket o solicitante',
        'searchFields' => 'number requester',
        'section' => $assignedSection,
        'canManageTickets' => $canManageTickets,
        'canAssignTickets' => $canAssignTickets,
        'ticketStatuses' => $ticketStatuses,
        'ticketPriorities' => $ticketPriorities,
        'assignableUsers' => $assignableUsers,
        'isManaged' => false,
        'sectionId' => 'mis-tickets',
    ])
</div>
