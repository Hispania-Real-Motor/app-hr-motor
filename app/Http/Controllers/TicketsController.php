<?php

namespace App\Http\Controllers;

use App\Models\ItTicket;
use App\Models\ItTicketMessage;
use App\Models\TicketActivityLog;
use App\Models\TicketTool;
use App\Models\User;
use App\Mail\ItTicketAssignedMail;
use App\Notifications\ItTicketAssignedNotification;
use App\Notifications\ItTicketMessageNotification;
use App\Services\TicketActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketsController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        abort_unless(app_can_access_tickets($request->user()), 403);

        $canManageTickets = app_can_assign_tickets($request->user());
        $canAssignTickets = app_can_assign_tickets($request->user());
        $showAllTickets = $request->user()->role === User::ROLE_ADMIN || $canAssignTickets;
        $canViewTicketReports = app_can_view_ticket_reports($request->user());
        $ticketStatuses = $this->ticketStatuses();
        $ticketPriorities = $this->ticketPriorities();
        $managedSection = $showAllTickets ? $this->buildTicketSectionData($request, 'managed', true) : null;
        $assignedSection = $this->buildTicketSectionData($request, 'assigned', false);

        $viewData = [
            'managedSection' => $managedSection,
            'assignedSection' => $assignedSection,
            'ticketStatuses' => $ticketStatuses,
            'ticketPriorities' => $ticketPriorities,
            'assignableUsers' => $this->assignableUsers(),
            'canManageTickets' => $canManageTickets,
            'canAssignTickets' => $canAssignTickets,
            'showAllTickets' => $showAllTickets,
            'canViewTicketReports' => $canViewTicketReports,
        ];

        if ($request->boolean('ajax')) {
            return response()->json([
                'html' => view('tickets.partials.content', $viewData)->render(),
            ]);
        }

        return view('tickets.index', $viewData);
    }

    public function reports(Request $request): View|JsonResponse
    {
        abort_unless(app_can_view_ticket_reports($request->user()), 403);

        $ticketStatuses = $this->ticketStatuses();
        $openStatuses = $this->openTicketStatuses();
        $closedUsersRange = $this->normalizeClosedUsersRange((string) $request->input('closed_users_range', 'all'));
        $resolutionRange = $this->normalizeResolutionRange((string) $request->input('resolution_range', 'all'));
        $ticketToolRange = $this->normalizeTicketToolRange((string) $request->input('ticket_tool_range', 'all'));
        $dealershipRange = $this->normalizeDealershipRange((string) $request->input('dealership_range', 'all'));
        $closedUsersRangeOptions = $this->closedUsersRangeOptions();
        $resolutionRangeOptions = $this->resolutionRangeOptions();
        $ticketToolRangeOptions = $this->ticketToolRangeOptions();
        $dealershipRangeOptions = $this->dealershipRangeOptions();
        $closedReportRows = $this->buildClosedTicketsReportRows($closedUsersRange);
        $resolutionReport = $this->buildResolutionTimeReport($resolutionRange);
        $ticketToolReport = $this->buildTicketToolReport($ticketToolRange);
        $dealershipReport = $this->buildDealershipTicketReport($dealershipRange);

        $viewData = [
            'backUrl' => route('tickets.index'),
            'heroImageUrl' => asset('images/hero/hero-informes-tickets.webp'),
            'openTicketsHistoryReport' => $this->buildOpenTicketsHistoryReport(),
            'ticketToolReport' => $ticketToolReport,
            'ticketToolReportRows' => $ticketToolReport['rows'] ?? [],
            'ticketToolRange' => $ticketToolRange,
            'ticketToolRangeOptions' => $ticketToolRangeOptions,
            'dealershipReport' => $dealershipReport,
            'dealershipReportRows' => $dealershipReport['rows'] ?? [],
            'dealershipRange' => $dealershipRange,
            'dealershipRangeOptions' => $dealershipRangeOptions,
            'reportCards' => $this->buildCurrentIncidentsReportCards(),
            'closedReportRows' => $closedReportRows,
            'closedUsersRange' => $closedUsersRange,
            'closedUsersRangeOptions' => $closedUsersRangeOptions,
            'resolutionReport' => $resolutionReport,
            'resolutionRange' => $resolutionRange,
            'resolutionRangeOptions' => $resolutionRangeOptions,
            'openStatusMeta' => array_intersect_key($ticketStatuses, array_flip($openStatuses)),
            'openStatusOrder' => array_values(array_filter(
                $openStatuses,
                fn (string $statusKey): bool => $statusKey !== 'new'
            )),
        ];

        if ($request->boolean('ajax')) {
            $report = (string) $request->input('report', 'closed_users');
            $partialView = match ($report) {
                'resolution' => 'tickets.partials.resolution-time-report-body',
                'ticket_tool' => 'tickets.partials.ticket-tool-report-body',
                'dealership' => 'tickets.partials.dealership-report-body',
                default => 'tickets.partials.closed-users-report-body',
            };

            return response()->json([
                'html' => view($partialView, $viewData)->render(),
            ]);
        }

        return view('tickets.reports', $viewData);
    }

    public function show(Request $request, ItTicket $itTicket): View
    {
        $canManageTickets = app_can_assign_tickets($request->user());
        $canAssignTickets = app_can_assign_tickets($request->user());
        $canViewTicket = $canManageTickets || $canAssignTickets
            || $itTicket->user_id === $request->user()->id
            || $itTicket->assigned_to_user_id === $request->user()->id;

        abort_unless($canViewTicket, 403);

        $itTicket->load(['user.assignedDealership', 'assignedTo', 'ticketTool', 'messages.author', 'activityLogs.actor']);

        return view('tickets.show', [
            'ticket' => $itTicket,
            'ticketStatuses' => $this->ticketStatuses(),
            'ticketPriorities' => $this->ticketPriorities(),
            'ticketTools' => $this->ticketTools(),
            'assignableUsers' => $this->assignableUsers(),
            'requesterUsers' => $this->requesterUsers(),
            'canManageTickets' => $canManageTickets,
            'canAssignTickets' => $canAssignTickets,
            'canUpdateTicketTool' => $this->canUpdateTicketTool($request->user(), $itTicket, $canManageTickets),
            'canCloseTicket' => $this->canCloseTicket($request->user(), $itTicket, $canManageTickets),
            'canReplyToTicket' => $this->canReplyToTicket($request->user(), $itTicket, $canManageTickets),
            'canRequestReopen' => $this->canRequestReopen($request->user(), $itTicket),
            'backUrl' => ($canManageTickets || $itTicket->assigned_to_user_id === $request->user()->id)
                ? route('tickets.index')
                : route('it-tickets.index'),
        ]);
    }

    public function updateTool(Request $request, ItTicket $itTicket): RedirectResponse
    {
        $canManageTickets = app_can_assign_tickets($request->user());
        abort_unless($this->canUpdateTicketTool($request->user(), $itTicket, $canManageTickets), 403);

        $validated = $request->validate([
            'ticket_tool_id' => [
                'required',
                'integer',
                Rule::exists('ticket_tools', 'id'),
            ],
        ]);

        $tool = TicketTool::query()->findOrFail((int) $validated['ticket_tool_id']);
        $previousToolId = $itTicket->ticket_tool_id;
        $previousToolName = $itTicket->ticketTool?->name ?? $itTicket->tool;

        if ((int) $previousToolId === (int) $tool->id) {
            return back()->with('success', 'El tipo de incidencia ya estaba actualizado.');
        }

        $itTicket->ticket_tool_id = $tool->id;
        $itTicket->tool = $tool->name;
        $itTicket->save();

        app(TicketActivityLogger::class)->record(
            $request->user(),
            $itTicket,
            TicketActivityLog::EVENT_TOOL_CHANGED,
            'Tipo de incidencia cambiado a ' . $tool->name,
            [
                'previous_ticket_tool_id' => $previousToolId,
                'ticket_tool_id' => $tool->id,
                'previous_tool' => $previousToolName,
                'tool' => $tool->name,
            ]
        );

        return back()->with('success', 'El tipo de incidencia del ticket se ha actualizado correctamente.');
    }

    public function updateRequester(Request $request, ItTicket $itTicket): RedirectResponse
    {
        $canManageTickets = app_can_assign_tickets($request->user());
        abort_unless($canManageTickets, 403);

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('is_active', true)
                        ->whereNull('disabled_at');
                }),
            ],
        ]);

        $newRequester = User::query()->findOrFail((int) $validated['user_id']);
        $previousRequesterId = $itTicket->user_id;
        $previousRequesterName = $itTicket->user?->name ?? 'Sin nombre';

        if ((int) $previousRequesterId === (int) $newRequester->id) {
            return back()->with('success', 'El solicitante ya estaba actualizado.');
        }

        $itTicket->user_id = $newRequester->id;
        $itTicket->save();

        app(TicketActivityLogger::class)->record(
            $request->user(),
            $itTicket,
            TicketActivityLog::EVENT_REQUESTER_CHANGED,
            'Solicitante cambiado a ' . $newRequester->name,
            [
                'previous_user_id' => $previousRequesterId,
                'user_id' => $newRequester->id,
                'previous_user_name' => $previousRequesterName,
                'user_name' => $newRequester->name,
            ]
        );

        return back()->with('success', 'El solicitante del ticket se ha actualizado correctamente.');
    }

    public function assign(Request $request, ItTicket $itTicket): RedirectResponse|JsonResponse
    {
        abort_unless(app_can_assign_tickets($request->user()), 403);

        $validated = $request->validate([
            'priority' => ['required', Rule::in(array_keys($this->ticketPriorities()))],
            'assigned_to_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('is_active', true)
                        ->whereNull('disabled_at')
                        ->where('extra_role', User::ROLE_INFORMATION_TECHNOLOGY);
                }),
            ],
        ]);

        if (! $request->boolean('assignment_force') && $this->shouldBlockStaleAssignment($request, $itTicket)) {
            if ($request->expectsJson() || $request->boolean('ajax')) {
                return $this->assignmentConflictResponse($itTicket);
            }

            return back()->withErrors([
                'assigned_to_user_id' => 'Este ticket ya ha sido reasignado por otra persona. Recarga la tabla y vuelve a intentarlo.',
            ]);
        }

        $previousStatus = $itTicket->status;
        $previousAssigneeId = $itTicket->assigned_to_user_id;

        $itTicket->priority = $validated['priority'];
        $itTicket->assigned_to_user_id = (int) $validated['assigned_to_user_id'];

        if ($itTicket->status === 'new') {
            $itTicket->status = 'in_progress';
        }

        $itTicket->save();

        $logger = app(TicketActivityLogger::class);

        if ($previousAssigneeId !== $itTicket->assigned_to_user_id) {
            $assignedUser = User::query()->whereKey($itTicket->assigned_to_user_id)->first();

            $logger->record(
                $request->user(),
                $itTicket,
                TicketActivityLog::EVENT_ASSIGNED,
                'Asignado a ' . ($assignedUser?->name ?? 'Sin asignar'),
                [
                    'previous_assigned_to_user_id' => $previousAssigneeId,
                    'assigned_to_user_id' => $itTicket->assigned_to_user_id,
                ]
            );

            if ($assignedUser) {
                Notification::send($assignedUser, new ItTicketAssignedNotification($itTicket, $request->user()));

                Mail::to($assignedUser->email)->send(new ItTicketAssignedMail(
                    assigneeName: $assignedUser->name,
                    actorName: $request->user()->name,
                    ticketNumber: $itTicket->number,
                    ticketTitle: $itTicket->title,
                    priorityLabel: $this->ticketPriorities()[$itTicket->priority]['label'] ?? $itTicket->priority,
                    ticketTool: $itTicket->ticketTool?->name ?? $itTicket->tool,
                ));
            }
        }

        if ($previousStatus !== $itTicket->status) {
            $logger->record(
                $request->user(),
                $itTicket,
                TicketActivityLog::EVENT_STATUS_CHANGED,
                'Estado cambiado a ' . ($this->ticketStatuses()[$itTicket->status]['label'] ?? $itTicket->status),
                [
                    'previous_status' => $previousStatus,
                    'status' => $itTicket->status,
                ]
            );
        }

        if ($request->expectsJson() || $request->boolean('ajax')) {
            return response()->json([
                'success' => true,
                'ticket_id' => $itTicket->id,
                'assigned_to_user_id' => $itTicket->assigned_to_user_id,
            ]);
        }

        return back()->with('success', 'El ticket se ha asignado correctamente.');
    }

    private function shouldBlockStaleAssignment(Request $request, ItTicket $itTicket): bool
    {
        if (! $request->exists('assignment_snapshot_assigned_to_user_id')) {
            return false;
        }

        $snapshotAssigneeId = (int) $request->input('assignment_snapshot_assigned_to_user_id', 0);

        return (int) ($itTicket->assigned_to_user_id ?? 0) !== $snapshotAssigneeId;
    }

    private function assignmentConflictResponse(ItTicket $itTicket): JsonResponse
    {
        $lastAssignmentLog = $itTicket->activityLogs()
            ->where('event', TicketActivityLog::EVENT_ASSIGNED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['actor_name', 'details']);

        $assignedBy = (string) ($lastAssignmentLog?->actor_name ?? 'otro usuario');
        $assignedTo = (string) ($itTicket->assignedTo?->name ?? 'Sin asignar');

        return response()->json([
            'message' => "Este ticket ya ha sido asignado por {$assignedBy} a {$assignedTo}. ¿Estás seguro de que quieres reasignarlo?",
            'assigned_by_name' => $assignedBy,
            'assigned_to_name' => $assignedTo,
            'ticket_number' => $itTicket->number,
        ], 409);
    }

    public function destroy(Request $request, ItTicket $itTicket): RedirectResponse
    {
        abort_unless(app_can_assign_tickets($request->user()), 403);

        DB::transaction(function () use ($itTicket): void {
            $itTicket->loadMissing('messages');

            foreach ($itTicket->messages as $message) {
                $message->delete();
            }

            $this->deleteTicketScreenshots($itTicket);
            $itTicket->activityLogs()->delete();
            $itTicket->delete();
        });

        return redirect()
            ->route('tickets.index')
            ->with('success', 'La incidencia se ha eliminado correctamente.');
    }

    public function updatePriority(Request $request, ItTicket $itTicket): RedirectResponse
    {
        abort_unless(app_can_assign_tickets($request->user()), 403);

        $validated = $request->validate([
            'priority' => ['required', Rule::in(array_keys($this->ticketPriorities()))],
        ]);

        $itTicket->priority = $validated['priority'];
        $itTicket->save();

        return back()->with('success', 'La prioridad del ticket se ha actualizado correctamente.');
    }

    /**
     * @return array{
     *     search: string,
     *     statuses: array<int, string>,
     *     priorities: array<int, string>,
     *     pageName: string,
     *     tickets: \Illuminate\Contracts\Pagination\LengthAwarePaginator
     * }
     */
    private function buildTicketSectionData(Request $request, string $section, bool $managed): array
    {
        $search = trim((string) $request->input($section . '_search', ''));
        $statuses = $this->normalizeSectionFilters($request->input($section . '_status', []));
        if ($statuses === []) {
            $statuses = $this->defaultTicketSectionStatuses();
        }
        $assignedToUserIds = $this->normalizeSectionFilters($request->input($section . '_assigned_to', []));
        $priorities = $this->normalizeSectionFilters($request->input($section . '_priority', []));
        $sort = $managed ? 'updated_desc' : $this->normalizeSortOption((string) $request->input($section . '_sort', 'updated_desc'));
        $pageName = $section . '_page';

        $query = $this->orderedTicketsQuery()
            ->with(['user', 'assignedTo', 'ticketTool', 'messages.author']);

        if (! $managed) {
            $query->where('assigned_to_user_id', $request->user()->id);
        }

        if ($search !== '') {
            $searchTerm = '%' . $search . '%';

            $query->where(function (Builder $builder) use ($searchTerm, $managed): void {
                $builder->where('number', 'like', $searchTerm)
                    ->orWhereHas('user', function (Builder $userQuery) use ($searchTerm): void {
                        $userQuery->where('name', 'like', $searchTerm)
                            ->orWhere('email', 'like', $searchTerm);
                    });

                if ($managed) {
                    $builder->orWhereHas('assignedTo', function (Builder $assigneeQuery) use ($searchTerm): void {
                        $assigneeQuery->where('name', 'like', $searchTerm)
                            ->orWhere('email', 'like', $searchTerm);
                    });
                }
            });
        }

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($managed && $assignedToUserIds !== []) {
            $query->whereIn('assigned_to_user_id', array_map('intval', $assignedToUserIds));
        }

        if ($priorities !== []) {
            $query->whereIn('priority', $priorities);
        }

        if (! $managed) {
            $this->applyUpdatedAtSort($query, $sort);
        }

        return [
            'search' => $search,
            'statuses' => $statuses,
            'priorities' => $priorities,
            'sort' => $sort,
            'pageName' => $pageName,
            'tickets' => $query
                ->paginate(10, ['*'], $pageName)
                ->withQueryString(),
        ];
    }

    private function canUpdateTicketTool(?User $user, ItTicket $ticket, bool $canManageTickets): bool
    {
        if (! $user) {
            return false;
        }

        return $canManageTickets || (int) $ticket->assigned_to_user_id === (int) $user->id;
    }

    private function deleteTicketScreenshots(ItTicket $ticket): void
    {
        foreach ($ticket->screenshots ?? [] as $screenshot) {
            $path = (string) data_get($screenshot, 'path', '');

            if ($path === '') {
                continue;
            }

            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                continue;
            }

            $absolutePath = public_path('storage/' . ltrim($path, '/'));

            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }
        }
    }

    public function reply(Request $request, ItTicket $itTicket): RedirectResponse
    {
        $canManageTickets = app_can_assign_tickets($request->user());
        abort_unless($this->canReplyToTicket($request->user(), $itTicket, $canManageTickets), 403);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:3000'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'close_ticket' => ['nullable', 'boolean'],
        ]);

        if ($this->isConversationLocked($itTicket)) {
            abort(403);
        }

        $body = trim((string) ($validated['body'] ?? ''));
        $files = collect($request->file('attachments', []));
        $shouldCloseTicket = (bool) ($validated['close_ticket'] ?? false);

        if ($shouldCloseTicket && ! $this->canCloseTicket($request->user(), $itTicket, $canManageTickets)) {
            abort(403);
        }

        if ($body === '' && $files->isEmpty()) {
            return back()->withErrors([
                'body' => 'Escribe un mensaje o adjunta al menos una imagen.',
            ]);
        }

        $attachments = $files
            ->map(function (UploadedFile $file): array {
                $path = $file->storePublicly('it-tickets/messages', 'public');

                return [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                ];
            })
            ->values()
            ->all();

        DB::transaction(function () use ($request, $itTicket, $body, $attachments, $shouldCloseTicket): void {
            $replyingUserIsOwner = $request->user()->id === $itTicket->user_id;
            $previousStatus = $itTicket->status;
            $logger = app(TicketActivityLogger::class);

            $itTicket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $body !== '' ? $body : null,
                'attachments' => ItTicketMessage::normalizeAttachments($attachments),
            ]);

            $logger->record(
                $request->user(),
                $itTicket,
                TicketActivityLog::EVENT_COMMENT_ADDED,
                'Comentario añadido',
                [
                    'body' => $body !== '' ? $body : null,
                    'attachments_count' => count($attachments),
                ]
            );

            if ($shouldCloseTicket) {
                $itTicket->messages()->create([
                    'user_id' => $request->user()->id,
                    'body' => 'Ticket cerrado por ' . $request->user()->name . '.',
                    'attachments' => [],
                ]);

                $itTicket->status = 'closed';
                $itTicket->save();

                $logger->record(
                    $request->user(),
                    $itTicket,
                    TicketActivityLog::EVENT_CLOSED,
                    'Estado cambiado a Cerrado',
                    [
                        'previous_status' => $previousStatus,
                        'status' => $itTicket->status,
                    ]
                );

                return;
            }

            if ($replyingUserIsOwner) {
                $itTicket->status = 'in_progress';
                $itTicket->save();

                if ($previousStatus !== $itTicket->status) {
                    $logger->record(
                        $request->user(),
                        $itTicket,
                        TicketActivityLog::EVENT_STATUS_CHANGED,
                        'Estado cambiado a ' . ($this->ticketStatuses()[$itTicket->status]['label'] ?? $itTicket->status),
                        [
                            'previous_status' => $previousStatus,
                            'status' => $itTicket->status,
                        ]
                    );
                }

                return;
            }

            if ($request->user()->id !== $itTicket->user_id) {
                $itTicket->status = 'pending_user';
                $itTicket->save();

                if ($previousStatus !== $itTicket->status) {
                    $logger->record(
                        $request->user(),
                        $itTicket,
                        TicketActivityLog::EVENT_STATUS_CHANGED,
                        'Estado cambiado a ' . ($this->ticketStatuses()[$itTicket->status]['label'] ?? $itTicket->status),
                        [
                            'previous_status' => $previousStatus,
                            'status' => $itTicket->status,
                        ]
                    );
                }
            }
        });

        $recipient = $request->user()->id === $itTicket->user_id
            ? $itTicket->assignedTo
            : $itTicket->user;

        if ($recipient && $recipient->id !== $request->user()->id) {
            Notification::send($recipient, new ItTicketMessageNotification(
                $itTicket->fresh(['user', 'assignedTo']),
                $request->user(),
                $body,
                $shouldCloseTicket
            ));
        }

        return back()->with(
            'success',
            $shouldCloseTicket
                ? 'Tu respuesta se ha publicado y el ticket se ha cerrado correctamente.'
                : 'Tu respuesta se ha publicado correctamente.'
        );
    }

    public function requestReopen(Request $request, ItTicket $itTicket): RedirectResponse
    {
        abort_unless($this->canRequestReopen($request->user(), $itTicket), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
        ]);

        $body = trim((string) $validated['body']);

        if ($body === '') {
            return back()->withErrors([
                'body' => 'Escribe un motivo para solicitar la reapertura.',
            ]);
        }

        DB::transaction(function () use ($request, $itTicket, $body): void {
            $previousStatus = $itTicket->status;
            $logger = app(TicketActivityLogger::class);

            $itTicket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => $body,
                'attachments' => [],
            ]);

            $itTicket->status = 'reopen_requested';
            $itTicket->save();

            $logger->record(
                $request->user(),
                $itTicket,
                TicketActivityLog::EVENT_REOPEN_REQUESTED,
                'Reapertura solicitada',
                [
                    'previous_status' => $previousStatus,
                    'status' => $itTicket->status,
                    'body' => $body,
                ]
            );
        });

        $recipient = $itTicket->assignedTo;

        if ($recipient && $recipient->id !== $request->user()->id) {
            Notification::send($recipient, new ItTicketMessageNotification(
                $itTicket->fresh(['user', 'assignedTo']),
                $request->user(),
                $body,
                false
            ));
        }

        return back()->with('success', 'Has solicitado la reapertura del ticket.');
    }

    public function reopen(Request $request, ItTicket $itTicket): RedirectResponse
    {
        $canManageTickets = app_can_assign_tickets($request->user());
        abort_unless($canManageTickets, 403);

        if ($itTicket->status === 'in_progress') {
            return back()->with('success', 'El ticket ya estaba reabierto.');
        }

        abort_unless($itTicket->status === 'reopen_requested', 403);

        $validated = $request->validate([
            'priority' => ['required', Rule::in(array_keys($this->ticketPriorities()))],
            'assigned_to_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('is_active', true)
                        ->whereNull('disabled_at')
                        ->where('extra_role', User::ROLE_INFORMATION_TECHNOLOGY);
                }),
            ],
        ]);

        DB::transaction(function () use ($request, $itTicket, $validated): void {
            $previousStatus = $itTicket->status;
            $logger = app(TicketActivityLogger::class);
            $previousAssigneeId = $itTicket->assigned_to_user_id;
            $selectedAssignee = User::query()->whereKey((int) $validated['assigned_to_user_id'])->first();

            $itTicket->priority = $validated['priority'];
            $itTicket->assigned_to_user_id = (int) $validated['assigned_to_user_id'];

            $itTicket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => 'Ticket reabierto por ' . $request->user()->name . '. Vuelve a estar en curso con ' . ($selectedAssignee?->name ?? 'Sin asignar') . '.',
                'attachments' => [],
            ]);

            $itTicket->status = 'in_progress';
            $itTicket->save();

            if ($previousAssigneeId !== $itTicket->assigned_to_user_id) {
                $assignedUser = User::query()->whereKey($itTicket->assigned_to_user_id)->first();

                $logger->record(
                    $request->user(),
                    $itTicket,
                    TicketActivityLog::EVENT_ASSIGNED,
                    'Asignado a ' . ($assignedUser?->name ?? 'Sin asignar'),
                    [
                        'previous_assigned_to_user_id' => $previousAssigneeId,
                        'assigned_to_user_id' => $itTicket->assigned_to_user_id,
                    ]
                );

                if ($assignedUser) {
                    Notification::send($assignedUser, new ItTicketAssignedNotification($itTicket, $request->user()));

                    Mail::to($assignedUser->email)->send(new ItTicketAssignedMail(
                        assigneeName: $assignedUser->name,
                        actorName: $request->user()->name,
                        ticketNumber: $itTicket->number,
                        ticketTitle: $itTicket->title,
                        priorityLabel: $this->ticketPriorities()[$itTicket->priority]['label'] ?? $itTicket->priority,
                        ticketTool: $itTicket->ticketTool?->name ?? $itTicket->tool,
                    ));
                }
            }

            $logger->record(
                $request->user(),
                $itTicket,
                TicketActivityLog::EVENT_REOPENED,
                'Ticket reabierto',
                [
                    'previous_status' => $previousStatus,
                    'status' => $itTicket->status,
                    'assigned_to_user_id' => $itTicket->assigned_to_user_id,
                ]
            );

            $itTicket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => "Ticket reabierto",
                'attachments' => [],
            ]);
        });

        $recipient = $itTicket->user;

        if ($recipient && $recipient->id !== $request->user()->id) {
            Notification::send($recipient, new ItTicketMessageNotification(
                $itTicket->fresh(['user', 'assignedTo']),
                $request->user(),
                'Ticket reabierto',
                false
            ));
        }

        return back()->with('success', 'El ticket se ha reabierto correctamente.');
    }

    public function permanentlyClose(Request $request, ItTicket $itTicket): RedirectResponse
    {
        $canManageTickets = app_can_assign_tickets($request->user());
        abort_unless($canManageTickets, 403);

        if ($itTicket->status === 'clausurado') {
            return back()->with('success', 'El ticket ya estaba clausurado definitivamente.');
        }

        abort_unless($itTicket->status === 'reopen_requested', 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:3000'],
        ]);

        $reason = trim((string) $validated['reason']);

        if ($reason === '') {
            return back()->withErrors([
                'reason' => 'Indica un motivo para la clausura definitiva.',
            ]);
        }

        DB::transaction(function () use ($request, $itTicket, $reason): void {
            $previousStatus = $itTicket->status;
            $logger = app(TicketActivityLogger::class);

            $itTicket->messages()->create([
                'user_id' => $request->user()->id,
                'body' => "Ticket clausurado definitivamente\nMotivo: " . $reason,
                'attachments' => [],
            ]);

            $itTicket->status = 'clausurado';
            $itTicket->save();

            $logger->record(
                $request->user(),
                $itTicket,
                TicketActivityLog::EVENT_PERMANENTLY_CLOSED,
                'Ticket clausurado definitivamente',
                [
                    'previous_status' => $previousStatus,
                    'status' => $itTicket->status,
                    'reason' => $reason,
                ]
            );
        });

        $recipient = $itTicket->user;

        if ($recipient && $recipient->id !== $request->user()->id) {
            Notification::send($recipient, new ItTicketMessageNotification(
                $itTicket->fresh(['user', 'assignedTo']),
                $request->user(),
                "Ticket clausurado definitivamente\nMotivo: " . $reason,
                true
            ));
        }

        return back()->with('success', 'El ticket se ha clausurado definitivamente.');
    }

    private function orderedTicketsQuery(): Builder
    {
        return ItTicket::query()
            ->orderByRaw(
                "CASE status
                    WHEN 'reopen_requested' THEN 0
                    WHEN 'new' THEN 1
                    WHEN 'in_progress' THEN 2
                    WHEN 'pending_user' THEN 3
                    WHEN 'closed' THEN 4
                    WHEN 'clausurado' THEN 5
                    ELSE 99
                END"
            )
            ->orderByRaw('CASE WHEN assigned_to_user_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('updated_at');
    }

    private function applyUpdatedAtSort(Builder $query, string $sort): void
    {
        if ($sort === 'updated_asc') {
            $query->reorder()
                ->orderBy('updated_at')
                ->orderBy('id');
            return;
        }

        $query->reorder()
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    private function normalizeSortOption(string $sort): string
    {
        return in_array($sort, ['updated_asc', 'updated_desc'], true) ? $sort : 'updated_desc';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function ticketStatuses(): array
    {
        return [
            'new' => [
                'label' => 'Nuevo',
                'badge' => 'bg-sky-50 text-sky-700 ring-sky-200',
                'badgeColor' => '#0ea5e9',
            ],
            'in_progress' => [
                'label' => 'En curso',
                'badge' => 'bg-amber-50 text-amber-700 ring-amber-200',
                'badgeColor' => '#f59e0b',
            ],
            'pending_user' => [
                'label' => 'Pendiente usuario',
                'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
                'badgeColor' => '#8b5cf6',
            ],
            'reopen_requested' => [
                'label' => 'Reapertura',
                'badge' => 'bg-rose-50 text-rose-700 ring-rose-200',
                'badgeColor' => '#f43f5e',
            ],
            'closed' => [
                'label' => 'Cerrado',
                'badge' => 'bg-slate-100 text-slate-700 ring-slate-200',
                'badgeColor' => '#64748b',
            ],
            'clausurado' => [
                'label' => 'Clausurado',
                'badge' => 'bg-slate-900 text-white ring-slate-900',
                'badgeColor' => '#0f172a',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function defaultTicketSectionStatuses(): array
    {
        return [
            'new',
            'in_progress',
            'pending_user',
            'reopen_requested',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function ticketPriorities(): array
    {
        return [
            'low' => [
                'label' => 'Baja',
                'badge' => 'bg-slate-100 text-slate-700 ring-slate-200',
            ],
            'medium' => [
                'label' => 'Media',
                'badge' => 'bg-blue-50 text-blue-700 ring-blue-200',
            ],
            'high' => [
                'label' => 'Alta',
                'badge' => 'bg-orange-50 text-orange-700 ring-orange-200',
            ],
            'urgent' => [
                'label' => 'Urgente',
                'badge' => 'bg-rose-50 text-rose-700 ring-rose-200',
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function ticketTools(): array
    {
        return TicketTool::query()
            ->ordered()
            ->get()
            ->mapWithKeys(fn (TicketTool $tool): array => [
                (string) $tool->id => [
                    'label' => $tool->name,
                    'color' => $tool->color,
                ],
            ])
            ->all();
    }

    /**
     * @return array{
     *     totalTickets:int,
     *     rows:array<int, array{
     *         id:int,
     *         name:string,
     *         color:string,
     *         total:int
     *     }>
     * }
     */
    private function buildTicketToolReport(string $range = 'all'): array
    {
        $window = $this->ticketToolRangeWindow($range);
        $toolMeta = collect($this->ticketTools())
            ->map(fn (array $tool, string $toolId): array => [
                'id' => (int) $toolId,
                'name' => $tool['label'],
                'color' => $tool['color'] ?: '#94a3b8',
            ])
            ->values();

        $counts = ItTicket::query()
            ->selectRaw('COALESCE(ticket_tool_id, 0) as tool_bucket, COUNT(*) as total')
            ->when($window['start'], function (Builder $query) use ($window): void {
                $query->where('created_at', '>=', $window['start']);
            })
            ->when($window['end'], function (Builder $query) use ($window): void {
                $query->where('created_at', '<=', $window['end']);
            })
            ->groupByRaw('COALESCE(ticket_tool_id, 0)')
            ->pluck('total', 'tool_bucket')
            ->map(fn ($total): int => (int) $total);

        $rows = $toolMeta
            ->map(function (array $tool) use ($counts): array {
                return [
                    'id' => $tool['id'],
                    'name' => $tool['name'],
                    'color' => $tool['color'],
                    'total' => (int) ($counts[$tool['id']] ?? 0),
                ];
            })
            ->filter(fn (array $row): bool => $row['total'] > 0)
            ->values();

        $uncategorizedTotal = (int) ($counts[0] ?? 0);

        if ($uncategorizedTotal > 0) {
            $rows->push([
                'id' => 0,
                'name' => 'Sin tipo',
                'color' => '#94a3b8',
                'total' => $uncategorizedTotal,
            ]);
        }

        return [
            'totalTickets' => (int) $rows->sum('total'),
            'rows' => $rows->values()->all(),
        ];
    }

    /**
     * @return array<int, array{id:int,name:string,email:string}>
     */
    private function assignableUsers(): array
    {
        return User::query()
            ->select(['id', 'name', 'email'])
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->where('extra_role', User::ROLE_INFORMATION_TECHNOLOGY)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->all();
    }

    /**
     * @return array<string, array{id:int,label:string,name:string,email:string}>
     */
    private function requesterUsers(): array
    {
        return User::query()
            ->select(['id', 'name', 'email'])
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->id => [
                    'id' => $user->id,
                    'label' => $user->name,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ])
            ->all();
    }

    private function canReplyToTicket(?User $user, ItTicket $ticket, bool $canManageTickets): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->isConversationLocked($ticket)) {
            return false;
        }

        return $canManageTickets
            || $ticket->user_id === $user->id
            || $ticket->assigned_to_user_id === $user->id;
    }

    private function canCloseTicket(?User $user, ItTicket $ticket, bool $canManageTickets): bool
    {
        if (! $user || $this->isConversationLocked($ticket)) {
            return false;
        }

        return $canManageTickets
            || $ticket->user_id === $user->id
            || $ticket->assigned_to_user_id === $user->id;
    }

    private function canRequestReopen(?User $user, ItTicket $ticket): bool
    {
        return (bool) $user
            && (int) $ticket->user_id === (int) $user->id
            && $ticket->status === 'closed';
    }

    private function isConversationLocked(ItTicket $ticket): bool
    {
        return in_array($ticket->status, ['closed', 'reopen_requested', 'clausurado'], true);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeSectionFilters(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function openTicketStatuses(): array
    {
        return [
            'new',
            'in_progress',
            'pending_user',
            'reopen_requested',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function closedTicketStatuses(): array
    {
        return [
            'closed',
            'clausurado',
        ];
    }

    /**
     * @return array<int, array{
     *     id:int,
     *     name:string,
     *     total:int,
     *     segments:array<int, array{key:string,label:string,count:int,color:string,percentage:float,offset:float}>,
     *     totalOpenTickets:int
     * }>
     */
    private function buildCurrentIncidentsReportCards(): array
    {
        $openStatuses = $this->openTicketStatuses();
        $statusMeta = array_intersect_key($this->ticketStatuses(), array_flip($openStatuses));
        $assignableUsers = collect($this->assignableUsers());
        $assignableUserIds = $assignableUsers->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $ticketsByAssignee = ItTicket::query()
            ->select(['assigned_to_user_id', 'status'])
            ->whereIn('status', $openStatuses)
            ->whereIn('assigned_to_user_id', $assignableUserIds)
            ->get()
            ->groupBy('assigned_to_user_id');

        return $assignableUsers
            ->map(function (array $user) use ($ticketsByAssignee, $statusMeta, $openStatuses): array {
                $userTickets = $ticketsByAssignee->get($user['id'], collect());
                $counts = $userTickets->countBy('status');
                $total = (int) $userTickets->count();
                $segments = [];
                $offset = 0.0;

                foreach ($openStatuses as $statusKey) {
                    $count = (int) ($counts[$statusKey] ?? 0);

                    if ($count <= 0) {
                        continue;
                    }

                    $percentage = $total > 0 ? ($count / $total) * 100 : 0.0;
                    $segments[] = [
                        'key' => $statusKey,
                        'label' => $statusMeta[$statusKey]['label'] ?? $statusKey,
                        'count' => $count,
                        'color' => $statusMeta[$statusKey]['badgeColor'] ?? '#94a3b8',
                        'percentage' => $percentage,
                        'offset' => $offset,
                    ];
                    $offset += $percentage;
                }

                return [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'total' => $total,
                    'segments' => $segments,
                    'totalOpenTickets' => $total,
                ];
            })
            ->filter(fn (array $card): bool => $card['total'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     totalTickets:int,
     *     peakDayLabel:string,
     *     peakDayCount:int,
     *     series:array<int, array{date:string,label:string,dayInitial:string,count:int}>
     * }
     */
    private function buildOpenTicketsHistoryReport(): array
    {
        $startDate = now()->subDays(29)->startOfDay();
        $endDate = now()->endOfDay();
        $countsByDate = ItTicket::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as ticket_date, COUNT(*) as total')
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'ticket_date')
            ->map(fn ($total): int => (int) $total);

        $series = collect(range(0, 29))
            ->map(function (int $offset) use ($startDate, $countsByDate): array {
                $date = $startDate->copy()->addDays($offset);
                $key = $date->toDateString();
                $count = (int) ($countsByDate[$key] ?? 0);
                $dayInitials = [
                    0 => 'D',
                    1 => 'L',
                    2 => 'M',
                    3 => 'X',
                    4 => 'J',
                    5 => 'V',
                    6 => 'S',
                ];

                return [
                    'date' => $key,
                    'label' => $date->translatedFormat('d/m'),
                    'dayInitial' => $dayInitials[(int) $date->dayOfWeek] ?? '',
                    'count' => $count,
                ];
            })
            ->values();

        $peakDay = $series->sortByDesc('count')->first() ?? ['label' => 'Sin datos', 'count' => 0];

        return [
            'totalTickets' => (int) $series->sum('count'),
            'peakDayLabel' => (string) ($peakDay['label'] ?? 'Sin datos'),
            'peakDayCount' => (int) ($peakDay['count'] ?? 0),
            'series' => $series->all(),
        ];
    }

    /**
     * @return array<int, array{
     *     id:int,
     *     name:string,
     *     totalClosedTickets:int
     * }>
     */
    /**
     * @return array<string, array{label:string,start:?Carbon,end:?Carbon}>
     */
    private function closedUsersRangeOptions(): array
    {
        $now = now();

        return [
            'all' => [
                'label' => 'Histórico total',
                'start' => null,
                'end' => null,
            ],
            '6m' => [
                'label' => 'Últimos 6 meses',
                'start' => $now->copy()->subMonthsNoOverflow(6)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '3m' => [
                'label' => 'Últimos 3 meses',
                'start' => $now->copy()->subMonthsNoOverflow(3)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '1m' => [
                'label' => 'Último mes',
                'start' => $now->copy()->subMonthNoOverflow()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '2w' => [
                'label' => 'Últimas 2 semanas',
                'start' => $now->copy()->subWeeks(2)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '1w' => [
                'label' => 'Última semana',
                'start' => $now->copy()->subWeek()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            'today' => [
                'label' => 'Hoy',
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
        ];
    }

    private function normalizeClosedUsersRange(string $range): string
    {
        return array_key_exists($range, $this->closedUsersRangeOptions()) ? $range : 'all';
    }

    /**
     * @return array<string, array{label:string,start:?Carbon,end:?Carbon}>
     */
    private function resolutionRangeOptions(): array
    {
        return $this->closedUsersRangeOptions();
    }

    /**
     * @return array<string, array{label:string,start:?Carbon,end:?Carbon}>
     */
    private function ticketToolRangeOptions(): array
    {
        return $this->closedUsersRangeOptions();
    }

    /**
     * @return array<string, array{label:string,start:?Carbon,end:?Carbon}>
     */
    private function dealershipRangeOptions(): array
    {
        return $this->closedUsersRangeOptions();
    }

    private function normalizeResolutionRange(string $range): string
    {
        return array_key_exists($range, $this->resolutionRangeOptions()) ? $range : 'all';
    }

    private function normalizeTicketToolRange(string $range): string
    {
        return array_key_exists($range, $this->ticketToolRangeOptions()) ? $range : 'all';
    }

    private function normalizeDealershipRange(string $range): string
    {
        return array_key_exists($range, $this->dealershipRangeOptions()) ? $range : 'all';
    }

    /**
     * @return array{start:?Carbon,end:?Carbon}
     */
    private function closedUsersRangeWindow(string $range): array
    {
        $options = $this->closedUsersRangeOptions();

        return [
            'start' => $options[$range]['start'] ?? null,
            'end' => $options[$range]['end'] ?? null,
        ];
    }

    /**
     * @return array{start:?Carbon,end:?Carbon}
     */
    private function resolutionRangeWindow(string $range): array
    {
        $options = $this->resolutionRangeOptions();

        return [
            'start' => $options[$range]['start'] ?? null,
            'end' => $options[$range]['end'] ?? null,
        ];
    }

    /**
     * @return array{start:?Carbon,end:?Carbon}
     */
    private function ticketToolRangeWindow(string $range): array
    {
        $options = $this->ticketToolRangeOptions();

        return [
            'start' => $options[$range]['start'] ?? null,
            'end' => $options[$range]['end'] ?? null,
        ];
    }

    /**
     * @return array{start:?Carbon,end:?Carbon}
     */
    private function dealershipRangeWindow(string $range): array
    {
        $options = $this->dealershipRangeOptions();

        return [
            'start' => $options[$range]['start'] ?? null,
            'end' => $options[$range]['end'] ?? null,
        ];
    }

    /**
     * @return array<int, array{
     *     id:int,
     *     name:string,
     *     totalClosedTickets:int
     * }>
     */
    private function buildClosedTicketsReportRows(string $range = 'all'): array
    {
        $closedStatuses = $this->closedTicketStatuses();
        $assignableUsers = collect($this->assignableUsers());
        $assignableUserIds = $assignableUsers->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $window = $this->closedUsersRangeWindow($range);

        $tickets = ItTicket::query()
            ->select(['assigned_to_user_id', 'status'])
            ->whereIn('status', $closedStatuses)
            ->whereIn('assigned_to_user_id', $assignableUserIds)
            ->with(['activityLogs:id,it_ticket_id,actor_user_id,event,created_at,details'])
            ->when($window['start'] || $window['end'], function (Builder $query) use ($window): void {
                $query->whereHas('activityLogs', function (Builder $activityQuery) use ($window): void {
                    $activityQuery->whereIn('event', [
                        TicketActivityLog::EVENT_CLOSED,
                        TicketActivityLog::EVENT_PERMANENTLY_CLOSED,
                    ]);

                    if ($window['start']) {
                        $activityQuery->where('created_at', '>=', $window['start']);
                    }

                    if ($window['end']) {
                        $activityQuery->where('created_at', '<=', $window['end']);
                    }
                });
            })
            ->get()
            ->values();

        $ticketsByAssignee = $tickets->groupBy(function (ItTicket $ticket) use ($window): int {
            $timeline = $ticket->activityLogs
                ->sortBy(function (TicketActivityLog $log): array {
                    return [
                        (int) ($log->created_at?->timestamp ?? 0),
                        (int) $log->id,
                    ];
                })
                ->values();

            $resolvedLog = $timeline
                ->filter(function (TicketActivityLog $log) use ($window): bool {
                    if (! in_array($log->event, [
                        TicketActivityLog::EVENT_CLOSED,
                        TicketActivityLog::EVENT_PERMANENTLY_CLOSED,
                    ], true)) {
                        return false;
                    }

                    if ($window['start'] && $log->created_at && $log->created_at->lt($window['start'])) {
                        return false;
                    }

                    if ($window['end'] && $log->created_at && $log->created_at->gt($window['end'])) {
                        return false;
                    }

                    return true;
                })
                ->values()
                ->last();

            if (! $resolvedLog?->created_at) {
                return (int) $ticket->assigned_to_user_id;
            }

            $assignedLogs = $timeline
                ->filter(function (TicketActivityLog $log) use ($resolvedLog): bool {
                    return $log->event === TicketActivityLog::EVENT_ASSIGNED
                        && $log->created_at !== null
                        && $log->created_at->lessThanOrEqualTo($resolvedLog->created_at);
                })
                ->values();

            $assignedLog = $assignedLogs->last();

            return (int) data_get($assignedLog?->details, 'assigned_to_user_id', $ticket->assigned_to_user_id);
        });

        return $assignableUsers
            ->map(function (array $user) use ($ticketsByAssignee): array {
                $userTickets = $ticketsByAssignee->get($user['id'], collect());

                return [
                    'id' => (int) $user['id'],
                    'name' => $user['name'],
                    'totalClosedTickets' => (int) $userTickets->count(),
                ];
            })
            ->filter(fn (array $row): bool => $row['totalClosedTickets'] > 0)
            ->sortByDesc('totalClosedTickets')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     totalResolvedTickets:int,
     *     overallAverageMinutes:int,
     *     overallAverageLabel:string,
     *     rows:array<int, array{
     *         id:int,
     *         name:string,
     *         tickets:int,
     *         averageMinutes:int,
     *         averageLabel:string
     *     }>
     * }
     */
    private function buildResolutionTimeReport(string $range = 'all'): array
    {
        $closedStatuses = $this->closedTicketStatuses();
        $assignableUsers = collect($this->assignableUsers())->keyBy('id');
        $assignableUserIds = $assignableUsers->keys()->map(fn ($id): int => (int) $id)->all();
        $window = $this->resolutionRangeWindow($range);

        $tickets = ItTicket::query()
            ->select(['id', 'assigned_to_user_id', 'status'])
            ->whereIn('status', $closedStatuses)
            ->whereIn('assigned_to_user_id', $assignableUserIds)
            ->with(['activityLogs:id,it_ticket_id,actor_user_id,event,created_at,details'])
            ->when($window['start'], function (Builder $query) use ($window): void {
                $query->where('created_at', '>=', $window['start']);
            })
            ->when($window['end'], function (Builder $query) use ($window): void {
                $query->where('created_at', '<=', $window['end']);
            })
            ->get();

        $rows = [];
        $totalMinutes = 0;
        $totalTickets = 0;

        foreach ($tickets as $ticket) {
            $timeline = $ticket->activityLogs
                ->sortBy(function (TicketActivityLog $log): array {
                    return [
                        (int) ($log->created_at?->timestamp ?? 0),
                        (int) $log->id,
                    ];
                })
                ->values();

            $resolvedLog = $timeline
                ->filter(function (TicketActivityLog $log) use ($window): bool {
                    if (! in_array($log->event, [
                        TicketActivityLog::EVENT_CLOSED,
                        TicketActivityLog::EVENT_PERMANENTLY_CLOSED,
                    ], true)) {
                        return false;
                    }

                    if ($window['start'] && $log->created_at && $log->created_at->lt($window['start'])) {
                        return false;
                    }

                    if ($window['end'] && $log->created_at && $log->created_at->gt($window['end'])) {
                        return false;
                    }

                    return true;
                })
                ->values()
                ->last();

            if (! $resolvedLog?->created_at) {
                continue;
            }

            $assignedLogs = $timeline
                ->filter(function (TicketActivityLog $log) use ($resolvedLog): bool {
                    return $log->event === TicketActivityLog::EVENT_ASSIGNED
                        && $log->created_at !== null
                        && $log->created_at->lessThanOrEqualTo($resolvedLog->created_at);
                })
                ->values();

            if ($assignedLogs->isEmpty()) {
                continue;
            }

            $assignedLog = $assignedLogs->last();

            if (! $assignedLog?->created_at) {
                continue;
            }

            $assigneeId = (int) data_get($assignedLog->details, 'assigned_to_user_id', $ticket->assigned_to_user_id);

            if ($assigneeId <= 0) {
                continue;
            }

            if ((int) ($resolvedLog->actor_user_id ?? 0) !== $assigneeId) {
                continue;
            }

            $resolvedSchedule = $this->resolveTicketResolutionSchedule($resolvedLog);

            if ($resolvedSchedule === null) {
                continue;
            }

            $minutes = $this->businessMinutesWhileInProgress($timeline, $assignedLog, $resolvedLog->created_at, $resolvedSchedule);

            if ($minutes <= 0) {
                continue;
            }

            $user = $assignableUsers->get($assigneeId);

            if (! $user) {
                continue;
            }

            $rows[$assigneeId] ??= [
                'id' => $assigneeId,
                'name' => $user['name'],
                'tickets' => 0,
                'averageMinutes' => 0,
            ];

            $rows[$assigneeId]['tickets']++;
            $rows[$assigneeId]['averageMinutes'] += $minutes;

            $totalMinutes += $minutes;
            $totalTickets++;
        }

        $formattedRows = collect($rows)
            ->map(function (array $row): array {
                $averageMinutes = $row['tickets'] > 0
                    ? (int) round($row['averageMinutes'] / $row['tickets'])
                    : 0;

                return [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'tickets' => $row['tickets'],
                    'averageMinutes' => $averageMinutes,
                    'averageLabel' => $this->formatMinutesAsDurationLabel($averageMinutes),
                ];
            })
            ->sortByDesc('averageMinutes')
            ->values()
            ->all();

        $overallAverageMinutes = $totalTickets > 0
            ? (int) round($totalMinutes / $totalTickets)
            : 0;

        return [
            'totalResolvedTickets' => $totalTickets,
            'overallAverageMinutes' => $overallAverageMinutes,
            'overallAverageLabel' => $this->formatMinutesAsDurationLabel($overallAverageMinutes),
            'rows' => $formattedRows,
        ];
    }

    /**
     * @return array{
     *     totalTickets:int,
     *     rows:array<int, array{
     *         id:int,
     *         name:string,
     *         totalTickets:int
     *     }>
     * }
     */
    private function buildDealershipTicketReport(string $range = 'all'): array
    {
        $window = $this->dealershipRangeWindow($range);
        $rows = ItTicket::query()
            ->select([
                'it_tickets.id',
                'it_tickets.user_id',
            ])
            ->with(['user.assignedDealership:id,name'])
            ->when($window['start'], function (Builder $query) use ($window): void {
                $query->where('created_at', '>=', $window['start']);
            })
            ->when($window['end'], function (Builder $query) use ($window): void {
                $query->where('created_at', '<=', $window['end']);
            })
            ->get()
            ->groupBy(function (ItTicket $ticket): string {
                $dealershipId = (int) ($ticket->user?->assignedDealership?->id ?? 0);

                return $dealershipId > 0 ? 'dealership:' . $dealershipId : 'dealership:none';
            })
            ->map(function ($tickets, string $groupKey): array {
                $firstTicket = $tickets->first();
                $dealership = $firstTicket?->user?->assignedDealership;

                return [
                    'id' => $dealership?->id ? (int) $dealership->id : 0,
                    'name' => $dealership?->name ?: 'Sin delegación',
                    'totalTickets' => (int) $tickets->count(),
                ];
            })
            ->sortByDesc('totalTickets')
            ->values()
            ->all();

        return [
            'totalTickets' => (int) collect($rows)->sum('totalTickets'),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, array{start:string,end:string}>|null
     */
    private function resolveTicketResolutionSchedule(TicketActivityLog $resolvedLog): ?array
    {
        $snapshot = data_get($resolvedLog->details, 'assignee_schedule');

        if (is_array($snapshot) && $snapshot !== []) {
            return $this->normalizeItScheduleSnapshot($snapshot);
        }

        return null;
    }

    private function timelineStatus(TicketActivityLog $log): ?string
    {
        return match ($log->event) {
            TicketActivityLog::EVENT_STATUS_CHANGED,
            TicketActivityLog::EVENT_REOPENED => (string) data_get($log->details, 'status', ''),
            TicketActivityLog::EVENT_CLOSED => 'closed',
            TicketActivityLog::EVENT_PERMANENTLY_CLOSED => 'clausurado',
            default => null,
        };
    }

    /**
     * @param \Illuminate\Support\Collection<int, TicketActivityLog> $timeline
     * @param array<string, array{start:string,end:string}> $schedule
     */
    private function businessMinutesWhileInProgress($timeline, TicketActivityLog $assignedLog, \Illuminate\Support\Carbon $end, array $schedule): int
    {
        $start = $assignedLog->created_at;

        if (! $start || $end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $minutes = 0;
        $currentStatus = null;
        $segmentStart = null;

        foreach ($timeline as $log) {
            if ($log->id === $assignedLog->id) {
                break;
            }

            if (! $log->created_at || $log->created_at->greaterThan($start)) {
                break;
            }

            $nextStatus = $this->timelineStatus($log);

            if ($nextStatus === null || $nextStatus === '') {
                continue;
            }

            $currentStatus = $nextStatus;
        }

        if ($currentStatus === 'in_progress') {
            $segmentStart = $start;
        }

        foreach ($timeline as $log) {
            if ($log->id === $assignedLog->id) {
                continue;
            }

            if (! $log->created_at || $log->created_at->lessThan($start) || $log->created_at->greaterThan($end)) {
                continue;
            }

            $nextStatus = $this->timelineStatus($log);

            if ($nextStatus === null || $nextStatus === '') {
                continue;
            }

            if ($currentStatus === 'in_progress' && $nextStatus !== 'in_progress' && $segmentStart) {
                $minutes += $this->businessMinutesBetween($segmentStart, $log->created_at, $schedule);
                $segmentStart = null;
            }

            $currentStatus = $nextStatus;

            if ($currentStatus === 'in_progress' && $segmentStart === null) {
                $segmentStart = $log->created_at;
            }
        }

        if ($segmentStart) {
            $minutes += $this->businessMinutesBetween($segmentStart, $end, $schedule);
        }

        return $minutes;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, array{start:string,end:string}>|null
     */
    private function normalizeItScheduleSnapshot(array $snapshot): ?array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $normalized = [];

        foreach ($days as $day) {
            $daySchedule = $snapshot[$day] ?? null;

            if (! is_array($daySchedule)) {
                return null;
            }

            $start = trim((string) ($daySchedule['start'] ?? ''));
            $end = trim((string) ($daySchedule['end'] ?? ''));

            if ($start === '' || $end === '') {
                return null;
            }

            $normalized[$day] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, array{start:string,end:string}> $schedule
     */
    private function businessMinutesBetween(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end, array $schedule): int
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $minutes = 0;
        $cursor = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();
        $daysByIso = [
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
            7 => 'sunday',
        ];

        while ($cursor->lessThanOrEqualTo($endDay)) {
            $dayKey = $daysByIso[$cursor->dayOfWeekIso] ?? null;
            $daySchedule = $dayKey ? ($schedule[$dayKey] ?? null) : null;

            if ($daySchedule) {
                $dayStart = $cursor->copy()->setTimeFromTimeString($daySchedule['start']);
                $dayEnd = $cursor->copy()->setTimeFromTimeString($daySchedule['end']);
                $segmentStart = $start->greaterThan($dayStart) ? $start : $dayStart;
                $segmentEnd = $end->lessThan($dayEnd) ? $end : $dayEnd;

                if ($segmentEnd->greaterThan($segmentStart)) {
                    $minutes += (int) round($segmentStart->diffInMinutes($segmentEnd));
                }
            }

            $cursor->addDay();
        }

        return $minutes;
    }

    private function formatMinutesAsDurationLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours <= 0) {
            return $remainingMinutes . ' min';
        }

        if ($remainingMinutes <= 0) {
            return $hours . ' h';
        }

        return $hours . ' h ' . $remainingMinutes . ' min';
    }
}
