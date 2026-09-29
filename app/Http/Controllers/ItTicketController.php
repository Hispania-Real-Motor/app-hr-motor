<?php

namespace App\Http\Controllers;

use App\Mail\ItTicketCreatedMail;
use App\Models\ItTicketNotificationSetting;
use App\Models\ItTicket;
use App\Models\TicketTool;
use App\Services\TicketActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ItTicketController extends Controller
{
    public function index(Request $request): View
    {
        return view('it-tickets.index', [
            'heroImageUrl' => asset('images/hero/hero-tickets-it.webp'),
            'tickets' => $this->ticketsForUser($request->user()->id),
            'ticketStatuses' => $this->ticketStatuses(),
            'ticketPriorities' => $this->ticketPriorities(),
            'ticketTools' => $this->ticketTools(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('it-tickets.create', [
            'ticketPriorities' => $this->ticketPriorities(),
            'ticketTools' => $this->ticketTools(),
            'afterHoursWarningEnabled' => $this->afterHoursWarningEnabled(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $ticketTools = $this->ticketTools();
        $ticketPriorities = $this->ticketPriorities();

        $validated = $request->validate([
            'tool' => ['required', Rule::in(array_keys($ticketTools))],
            'priority' => ['required', Rule::in(array_keys($ticketPriorities))],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'screenshots' => ['nullable', 'array', 'max:4'],
            'screenshots.*' => ['image', 'max:5120'],
            'submission_token' => ['required', 'string', 'max:100'],
            'after_hours_acknowledged' => ['nullable', 'boolean'],
        ]);

        if ($this->afterHoursWarningEnabled() && now()->hour >= 15 && ! $request->boolean('after_hours_acknowledged')) {
            return back()
                ->withInput()
                ->withErrors([
                    'after_hours_acknowledged' => 'Debes revisar el aviso de horario de verano antes de enviar la incidencia.',
                ]);
        }

        $submissionToken = trim((string) $validated['submission_token']);
        $submissionKey = 'it-ticket-submission:' . $request->user()->id . ':' . sha1($submissionToken);
        $existingTicketId = Cache::get($submissionKey);

        if (is_numeric($existingTicketId)) {
            return redirect()
                ->route('it-tickets.index')
                ->with('success', 'Ya habíamos recibido esa incidencia.');
        }

        $submissionLockKey = $submissionKey . ':lock';

        if (! Cache::add($submissionLockKey, true, now()->addMinutes(5))) {
            return back()
                ->withInput()
                ->with('success', 'La incidencia ya se estaba procesando. Si no aparece en tu listado, vuelve a intentarlo en unos segundos.');
        }

        $uploadedScreenshots = collect($request->file('screenshots', []))
            ->map(function ($file): array {
                $path = $file->storePublicly('it-tickets/screenshots', 'public');

                return [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                ];
            })
            ->values()
            ->all();

        try {
            $ticket = ItTicket::query()->create([
                'user_id' => $request->user()->id,
                'ticket_tool_id' => (int) $validated['tool'],
                'number' => 'IT-' . strtoupper(Str::random(6)),
                'tool' => $ticketTools[$validated['tool']]['label'],
                'priority' => $validated['priority'],
                'status' => 'new',
                'title' => $validated['title'],
                'description' => $validated['description'],
                'screenshots' => $uploadedScreenshots,
            ]);

            Cache::put($submissionKey, $ticket->id, now()->addDay());

            app(TicketActivityLogger::class)->record(
                $request->user(),
                $ticket,
                'created',
                'Ticket creado',
                [
                    'tool' => $ticket->tool,
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                ]
            );

            $priorityLabel = $ticketPriorities[$validated['priority']]['label'] ?? $validated['priority'];

            $recipients = ItTicketNotificationSetting::recipients();

            if ($recipients !== []) {
                $primaryRecipient = array_shift($recipients);
                $mailer = Mail::to($primaryRecipient);

                if ($recipients !== []) {
                    $mailer->cc($recipients);
                }

                $mailer->send(new ItTicketCreatedMail(
                    reporterName: $request->user()->name,
                    priorityLabel: $priorityLabel,
                    ticketNumber: $ticket->number,
                    ticketTitle: $ticket->title,
                    ticketTool: $ticket->tool,
                ));
            }
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            Cache::forget($submissionLockKey);
        }

        return redirect()
            ->route('it-tickets.index')
            ->with('success', 'La incidencia se ha preparado correctamente.');
    }

    private function ticketsForUser(int $userId)
    {
        return ItTicket::query()
            ->with(['user', 'ticketTool'])
            ->where('user_id', $userId)
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
            ->orderByDesc('updated_at')
            ->get();
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
            ],
            'in_progress' => [
                'label' => 'En curso',
                'badge' => 'bg-amber-50 text-amber-700 ring-amber-200',
            ],
            'pending_user' => [
                'label' => 'Pendiente usuario',
                'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
            ],
            'reopen_requested' => [
                'label' => 'Reapertura',
                'badge' => 'bg-rose-50 text-rose-700 ring-rose-200',
            ],
            'closed' => [
                'label' => 'Cerrado',
                'badge' => 'bg-slate-100 text-slate-700 ring-slate-200',
            ],
            'clausurado' => [
                'label' => 'Clausurado',
                'badge' => 'bg-slate-900 text-white ring-slate-900',
            ],
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

    private function afterHoursWarningEnabled(): bool
    {
        return (bool) config('support.it_ticket_after_hours_warning_enabled', false);
    }
}
