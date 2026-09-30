<?php

namespace Tests\Feature;

use App\Models\AdminPermissionGrant;
use App\Models\Dealership;
use App\Models\ItTicket;
use App\Models\TicketTool;
use App\Models\User;
use App\Mail\ItTicketAssignedMail;
use App\Notifications\ItTicketAssignedNotification;
use App\Notifications\ItTicketMessageNotification;
use App\Models\TicketActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketsInteriorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_it_user_without_management_permission_sees_only_assigned_tickets(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $assignedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Asignado',
            'email' => 'tecnico.asignado@example.com',
        ]);

        $otherUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Otro Técnico',
            'email' => 'otro.tecnico@example.com',
        ]);

        $visibleTicket = ItTicket::query()->create([
            'user_id' => $otherUser->id,
            'assigned_to_user_id' => $assignedUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-123456',
            'tool' => $tool->name,
            'priority' => 'high',
            'status' => 'in_progress',
            'title' => 'Se ve este ticket',
            'description' => 'Descripción visible para el técnico.',
            'screenshots' => [],
        ]);

        ItTicket::query()->create([
            'user_id' => $otherUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-654321',
            'tool' => $tool->name,
            'priority' => 'low',
            'status' => 'new',
            'title' => 'No se ve este ticket',
            'description' => 'No está asignado al técnico.',
            'screenshots' => [],
        ]);

        $this->actingAs($assignedUser)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Gestión de tickets', false)
            ->assertSee($visibleTicket->number, false)
            ->assertSee('Mis tickets', false)
            ->assertDontSee('IT-654321', false)
            ->assertDontSee('Todos los tickets', false);
    }

    public function test_user_with_assign_permission_sees_all_tickets_and_assigns_them(): void
    {
        Notification::fake();
        Mail::fake();

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor IT',
            'email' => 'gestor.it@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $assignableUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Asignable',
            'email' => 'tecnico.asignable@example.com',
        ]);

        $otherTicket = ItTicket::query()->create([
            'user_id' => $assignableUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-222222',
            'tool' => $tool->name,
            'priority' => 'urgent',
            'status' => 'new',
            'title' => 'Ticket a asignar',
            'description' => 'Está pendiente de reparto.',
            'screenshots' => [],
        ]);

        $ownTicket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $manager->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-111111',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket propio',
            'description' => 'Asignado al gestor.',
            'screenshots' => [],
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Todos los tickets', false)
            ->assertSee(route('tickets.reports'), false)
            ->assertSee($otherTicket->number, false)
            ->assertSee($ownTicket->number, false)
            ->assertSee('Vista previa', false)
            ->assertSee(route('tickets.assign', $otherTicket), false);

        $this->actingAs($manager)
            ->post(route('tickets.assign', $otherTicket), [
                'priority' => 'high',
                'assigned_to_user_id' => $assignableUser->id,
            ])
            ->assertRedirect();

        Notification::assertSentTo(
            $assignableUser,
            ItTicketAssignedNotification::class,
            function (ItTicketAssignedNotification $notification, array $channels, User $notifiable) use ($otherTicket, $manager): bool {
                $payload = $notification->toDatabase($notifiable);

                return $payload['ticket_id'] === $otherTicket->id
                    && $payload['ticket_number'] === $otherTicket->number
                    && $payload['actor_name'] === $manager->name
                    && $channels === ['database'];
            }
        );

        Mail::assertSent(ItTicketAssignedMail::class, function (ItTicketAssignedMail $mail) use ($assignableUser, $manager, $otherTicket): bool {
            $this->assertTrue($mail->envelope()->hasSubject('Te han asignado el ticket ' . $otherTicket->number . ' (Alta - Web HR Motor)'));
            $rendered = $mail->render();
            $this->assertStringContainsString('Ticket IT asignado', $rendered);
            $this->assertStringContainsString($otherTicket->title, $rendered);
            $this->assertStringContainsString('Alta', $rendered);
            $this->assertStringContainsString('Web HR Motor', $rendered);

            return $mail->hasTo($assignableUser->email)
                && $mail->assigneeName === $assignableUser->name
                && $mail->actorName === $manager->name
                && $mail->ticketNumber === $otherTicket->number
                && $mail->ticketTitle === $otherTicket->title
                && $mail->priorityLabel === 'Alta'
                && $mail->ticketTool === 'Web HR Motor';
        });

        $this->assertDatabaseHas('it_tickets', [
            'id' => $otherTicket->id,
            'assigned_to_user_id' => $assignableUser->id,
            'status' => 'in_progress',
            'priority' => 'high',
        ]);
    }

    public function test_ticket_assignment_returns_conflict_when_the_ticket_was_already_reassigned(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Conflicto',
            'email' => 'gestor-conflicto@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $initialAssignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Inicial',
            'email' => 'tecnico-inicial@example.com',
        ]);

        $nextAssignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Nuevo',
            'email' => 'tecnico-nuevo@example.com',
        ]);

        $previousAssigner = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Anterior',
            'email' => 'gestor-anterior@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $initialAssignee->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-333333',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket con conflicto',
            'description' => 'Se reasignó antes de pulsar asignar.',
            'screenshots' => [],
        ]);

        $this->createTicketActivityLog($ticket, $previousAssigner, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', now()->subMinutes(10), [
            'previous_assigned_to_user_id' => null,
            'assigned_to_user_id' => $initialAssignee->id,
        ]);

        $response = $this->actingAs($manager)
            ->post(route('tickets.assign', $ticket), [
                'priority' => 'high',
                'assigned_to_user_id' => $nextAssignee->id,
                'assignment_snapshot_assigned_to_user_id' => 0,
                'ajax' => 1,
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'assigned_by_name' => $previousAssigner->name,
            'assigned_to_name' => $initialAssignee->name,
            'ticket_number' => $ticket->number,
        ]);

        $this->assertDatabaseHas('it_tickets', [
            'id' => $ticket->id,
            'assigned_to_user_id' => $initialAssignee->id,
            'priority' => 'medium',
        ]);
    }

    public function test_ticket_reports_page_shows_average_resolution_time_by_it_person_and_total(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Resolución',
            'email' => 'gestor-resolucion@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $itOne = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Resolución Uno',
            'email' => 'it-resolucion-uno@example.com',
            'it_monday_start' => '09:00',
            'it_monday_end' => '18:00',
            'it_tuesday_start' => '09:00',
            'it_tuesday_end' => '18:00',
            'it_wednesday_start' => '09:00',
            'it_wednesday_end' => '18:00',
            'it_thursday_start' => '09:00',
            'it_thursday_end' => '18:00',
            'it_friday_start' => '09:00',
            'it_friday_end' => '18:00',
        ]);

        $itTwo = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Resolución Dos',
            'email' => 'it-resolucion-dos@example.com',
            'it_monday_start' => '09:00',
            'it_monday_end' => '18:00',
            'it_tuesday_start' => '09:00',
            'it_tuesday_end' => '18:00',
            'it_wednesday_start' => '09:00',
            'it_wednesday_end' => '18:00',
            'it_thursday_start' => '09:00',
            'it_thursday_end' => '18:00',
            'it_friday_start' => '09:00',
            'it_friday_end' => '18:00',
        ]);

        $base = Carbon::parse('2026-07-10 17:50:00');

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $ticketA = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itOne->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800001',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Resolución 1',
            'description' => 'Resolución 1',
            'screenshots' => [],
        ]);

        $ticketB = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itOne->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800002',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'clausurado',
            'title' => 'Resolución 2',
            'description' => 'Resolución 2',
            'screenshots' => [],
        ]);

        $ticketC = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itTwo->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800003',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Resolución 3',
            'description' => 'Resolución 3',
            'screenshots' => [],
        ]);

        $reassignedTicket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itTwo->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800004',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Resolución reasignada',
            'description' => 'Debe quedar fuera del cálculo por cambio de responsable.',
            'screenshots' => [],
        ]);

        $this->createTicketActivityLog($ticketA, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $base->copy()->subHours(5), [
            'assigned_to_user_id' => $itOne->id,
        ]);
        $this->createTicketActivityLog($ticketA, $itOne, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $base->copy()->subHours(5)->addMinute(), [
            'previous_status' => 'new',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($ticketA, $itOne, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a Pendiente usuario', $base->copy()->subHours(4)->subMinutes(30), [
            'previous_status' => 'in_progress',
            'status' => 'pending_user',
        ]);
        $this->createTicketActivityLog($ticketA, $itOne, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $base->copy()->subHours(4), [
            'previous_status' => 'pending_user',
            'status' => 'closed',
        ]);

        $this->createTicketActivityLog($ticketB, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $base->copy()->subHours(3), [
            'assigned_to_user_id' => $itOne->id,
        ]);
        $this->createTicketActivityLog($ticketB, $itOne, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $base->copy()->subHours(3)->addMinute(), [
            'previous_status' => 'new',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($ticketB, $itOne, TicketActivityLog::EVENT_PERMANENTLY_CLOSED, 'Clausurado', $base->copy()->subHours(2), [
            'status' => 'clausurado',
        ]);

        $this->createTicketActivityLog($ticketC, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $base->copy()->subHours(2), [
            'assigned_to_user_id' => $itTwo->id,
        ]);
        $this->createTicketActivityLog($ticketC, $itTwo, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $base->copy()->subHours(2)->addMinute(), [
            'previous_status' => 'new',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($ticketC, $itTwo, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $base->copy()->subMinutes(30), [
            'status' => 'closed',
        ]);

        $this->createTicketActivityLog($reassignedTicket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado a IT Resolución Uno', $base->copy()->subHours(6), [
            'assigned_to_user_id' => $itOne->id,
        ]);
        $this->createTicketActivityLog($reassignedTicket, $itOne, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $base->copy()->subHours(6)->addMinute(), [
            'previous_status' => 'new',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($reassignedTicket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Reasignado a IT Resolución Dos', $base->copy()->subHours(4), [
            'previous_assigned_to_user_id' => $itOne->id,
            'assigned_to_user_id' => $itTwo->id,
        ]);
        $this->createTicketActivityLog($reassignedTicket, $itTwo, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $base->copy()->subHours(4)->addMinute(), [
            'previous_status' => 'in_progress',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($reassignedTicket, $itTwo, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $base->copy()->subHours(3), [
            'status' => 'closed',
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee('Tiempo medio de resolución', false)
            ->assertSee('Media total', false)
            ->assertSee('Tickets medidos', false)
            ->assertSee('IT Resolución Uno', false)
            ->assertSee('IT Resolución Dos', false)
            ->assertDontSee('Resolución reasignada', false);
    }

    public function test_ticket_reports_page_shows_a_live_filter_for_closed_tickets_by_user(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Cerrados',
            'email' => 'gestor-cerrados@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $itRecent = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Reciente',
            'email' => 'it-reciente@example.com',
        ]);

        $itOld = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Antiguo',
            'email' => 'it-antiguo@example.com',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $now = Carbon::parse('2026-07-21 12:00:00');
        Carbon::setTestNow($now);

        try {
            $recentTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'assigned_to_user_id' => $itRecent->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-920001',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Ticket reciente',
                'description' => 'Debe entrar en 1 mes.',
                'screenshots' => [],
            ]);

            $oldTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'assigned_to_user_id' => $itOld->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-920002',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Ticket antiguo',
                'description' => 'Debe quedar fuera de 1 mes.',
                'screenshots' => [],
            ]);

            $this->createTicketActivityLog($recentTicket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $now->copy()->subDays(10), [
                'assigned_to_user_id' => $itRecent->id,
            ]);
            $this->createTicketActivityLog($recentTicket, $itRecent, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $now->copy()->subDays(9), [
                'status' => 'closed',
            ]);

            $this->createTicketActivityLog($oldTicket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $now->copy()->subMonths(4), [
                'assigned_to_user_id' => $itOld->id,
            ]);
            $this->createTicketActivityLog($oldTicket, $itOld, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $now->copy()->subMonths(3)->subDays(2), [
                'status' => 'closed',
            ]);

            $this->actingAs($manager)
                ->get(route('tickets.reports'))
                ->assertOk()
                ->assertSee('data-closed-users-range-select', false)
                ->assertSee('Histórico total', false)
                ->assertSee('Últimos 6 meses', false)
                ->assertSee('Últimos 3 meses', false)
                ->assertSee('Último mes', false)
                ->assertSee('Últimas 2 semanas', false)
                ->assertSee('Última semana', false)
                ->assertSee('Hoy', false)
                ->assertSee('value="all"', false);

            $this->assertMatchesRegularExpression('/<option value="all"[^>]*selected/', $this->actingAs($manager)->get(route('tickets.reports'))->getContent());

            $ajaxResponse = $this->actingAs($manager)
                ->get(route('tickets.reports', [
                    'ajax' => 1,
                    'closed_users_range' => '1m',
                ]));

            $ajaxResponse->assertOk()->assertJsonStructure(['html']);
            $html = (string) $ajaxResponse->json('html');

            $this->assertStringContainsString('IT Reciente', $html);
            $this->assertStringNotContainsString('IT Antiguo', $html);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_ticket_reports_page_can_filter_resolution_time_in_real_time(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Resolución',
            'email' => 'gestor-resolucion@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $itRecent = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Resolución Reciente',
            'email' => 'it-resolucion-reciente@example.com',
            'it_monday_start' => '09:00',
            'it_monday_end' => '18:00',
            'it_tuesday_start' => '09:00',
            'it_tuesday_end' => '18:00',
            'it_wednesday_start' => '09:00',
            'it_wednesday_end' => '18:00',
            'it_thursday_start' => '09:00',
            'it_thursday_end' => '18:00',
            'it_friday_start' => '09:00',
            'it_friday_end' => '18:00',
        ]);

        $itOld = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Resolución Antiguo',
            'email' => 'it-resolucion-antiguo@example.com',
            'it_monday_start' => '09:00',
            'it_monday_end' => '18:00',
            'it_tuesday_start' => '09:00',
            'it_tuesday_end' => '18:00',
            'it_wednesday_start' => '09:00',
            'it_wednesday_end' => '18:00',
            'it_thursday_start' => '09:00',
            'it_thursday_end' => '18:00',
            'it_friday_start' => '09:00',
            'it_friday_end' => '18:00',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $now = Carbon::parse('2026-07-21 12:00:00');
        Carbon::setTestNow($now);

        try {
            $recentTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'assigned_to_user_id' => $itRecent->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-930001',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Ticket resolución reciente',
                'description' => 'Debe entrar en el filtro de un mes.',
                'screenshots' => [],
            ]);

            $oldTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'assigned_to_user_id' => $itOld->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-930002',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Ticket resolución antiguo',
                'description' => 'Debe quedarse fuera del filtro de un mes.',
                'screenshots' => [],
            ]);

            $recentTicket->forceFill([
                'created_at' => $now->copy()->subDays(12),
                'updated_at' => $now->copy()->subDays(12),
            ])->saveQuietly();

            $oldTicket->forceFill([
                'created_at' => $now->copy()->subMonths(4),
                'updated_at' => $now->copy()->subMonths(4),
            ])->saveQuietly();

            $this->createTicketActivityLog($recentTicket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $now->copy()->subDays(8), [
                'assigned_to_user_id' => $itRecent->id,
            ]);
            $this->createTicketActivityLog($recentTicket, $itRecent, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $now->copy()->subDays(8)->addMinute(), [
                'previous_status' => 'new',
                'status' => 'in_progress',
            ]);
            $this->createTicketActivityLog($recentTicket, $itRecent, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $now->copy()->subDays(7), [
                'status' => 'closed',
            ]);

            $this->createTicketActivityLog($oldTicket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $now->copy()->subMonths(4), [
                'assigned_to_user_id' => $itOld->id,
            ]);
            $this->createTicketActivityLog($oldTicket, $itOld, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $now->copy()->subMonths(4)->addMinute(), [
                'previous_status' => 'new',
                'status' => 'in_progress',
            ]);
            $this->createTicketActivityLog($oldTicket, $itOld, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $now->copy()->subMonths(3)->subDays(1), [
                'status' => 'closed',
            ]);

            $response = $this->actingAs($manager)
                ->get(route('tickets.reports', [
                    'ajax' => 1,
                    'report' => 'resolution',
                    'resolution_range' => '1m',
                ]));

            $response->assertOk()->assertJsonStructure(['html']);

            $html = (string) $response->json('html');
            $this->assertStringContainsString('IT Resolución Reciente', $html);
            $this->assertStringNotContainsString('IT Resolución Antiguo', $html);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_ticket_reports_page_shows_tickets_by_tool_with_legend_and_total(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Herramientas',
            'email' => 'gestor-herramientas@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $toolOne = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $toolTwo = TicketTool::query()->create([
            'name' => 'ERP',
            'color' => '#e11d48',
        ]);

        ItTicket::query()->create([
            'user_id' => $manager->id,
            'ticket_tool_id' => $toolOne->id,
            'number' => 'IT-700001',
            'tool' => $toolOne->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Ticket herramienta 1',
            'description' => 'Primero.',
            'screenshots' => [],
        ]);

        ItTicket::query()->create([
            'user_id' => $manager->id,
            'ticket_tool_id' => $toolOne->id,
            'number' => 'IT-700002',
            'tool' => $toolOne->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Ticket herramienta 1 bis',
            'description' => 'Segundo.',
            'screenshots' => [],
        ]);

        ItTicket::query()->create([
            'user_id' => $manager->id,
            'ticket_tool_id' => $toolTwo->id,
            'number' => 'IT-700003',
            'tool' => $toolTwo->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket herramienta 2',
            'description' => 'Tercero.',
            'screenshots' => [],
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee('Tickets por tipo de incidencia', false)
            ->assertSee('data-ticket-tool-range-select', false)
            ->assertSee('Histórico total', false)
            ->assertSee('Últimos 6 meses', false)
            ->assertSee('Últimos 3 meses', false)
            ->assertSee('Último mes', false)
            ->assertSee('Últimas 2 semanas', false)
            ->assertSee('Última semana', false)
            ->assertSee('Hoy', false)
            ->assertSee('Web HR Motor', false)
            ->assertSee('ERP', false)
            ->assertSee('3 incidencias en total', false)
            ->assertSee('2', false)
            ->assertSee('1', false);
    }

    public function test_ticket_reports_page_can_filter_ticket_tools_in_real_time(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Tipos',
            'email' => 'gestor-tipos@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $toolRecent = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $toolOld = TicketTool::query()->create([
            'name' => 'ERP',
            'color' => '#e11d48',
        ]);

        $now = Carbon::parse('2026-07-21 12:00:00');
        Carbon::setTestNow($now);

        try {
            $recentTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'ticket_tool_id' => $toolRecent->id,
                'number' => 'IT-940001',
                'tool' => $toolRecent->name,
                'priority' => 'medium',
                'status' => 'new',
                'title' => 'Tipo reciente',
                'description' => 'Debe entrar en el filtro de un mes.',
                'screenshots' => [],
            ]);

            $oldTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'ticket_tool_id' => $toolOld->id,
                'number' => 'IT-940002',
                'tool' => $toolOld->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Tipo antiguo',
                'description' => 'Debe quedarse fuera del filtro de un mes.',
                'screenshots' => [],
            ]);

            $recentTicket->forceFill([
                'created_at' => $now->copy()->subDays(12),
                'updated_at' => $now->copy()->subDays(12),
            ])->saveQuietly();

            $oldTicket->forceFill([
                'created_at' => $now->copy()->subMonths(4),
                'updated_at' => $now->copy()->subMonths(4),
            ])->saveQuietly();

            $response = $this->actingAs($manager)
                ->get(route('tickets.reports', [
                    'ajax' => 1,
                    'report' => 'ticket_tool',
                    'ticket_tool_range' => '1m',
                ]));

            $response->assertOk()->assertJsonStructure(['html']);

            $html = (string) $response->json('html');
            $this->assertStringContainsString('Web HR Motor', $html);
            $this->assertStringNotContainsString('ERP', $html);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_ticket_reports_page_shows_a_thirty_day_open_tickets_line_chart(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Histograma',
            'email' => 'gestor-histograma@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $now = Carbon::parse('2026-07-30 12:00:00');
        Carbon::setTestNow($now);

        try {
            $openDates = [
                $now->copy()->subDays(1),
                $now->copy()->subDays(1)->addHours(2),
                $now->copy()->subDays(2),
                $now->copy()->subDays(15),
            ];

            foreach ($openDates as $index => $date) {
                $ticket = ItTicket::query()->create([
                    'user_id' => $manager->id,
                    'ticket_tool_id' => $tool->id,
                    'number' => sprintf('IT-9100%02d', $index + 1),
                    'tool' => $tool->name,
                    'priority' => 'medium',
                    'status' => ['new', 'in_progress', 'pending_user', 'reopen_requested'][$index],
                    'title' => 'Ticket abierto ' . ($index + 1),
                    'description' => 'Debe entrar en la serie de 30 días.',
                    'screenshots' => [],
                ]);

                $ticket->forceFill([
                    'created_at' => $date,
                    'updated_at' => $date,
                ])->saveQuietly();
            }

            $oldTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-910005',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'new',
                'title' => 'Ticket fuera de ventana',
                'description' => 'No debe contarse.',
                'screenshots' => [],
            ]);

            $oldTicket->forceFill([
                'created_at' => $now->copy()->subDays(30),
                'updated_at' => $now->copy()->subDays(30),
            ])->saveQuietly();

            $closedTicket = ItTicket::query()->create([
                'user_id' => $manager->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-910006',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Ticket cerrado',
                'description' => 'Debe contarse igual.',
                'screenshots' => [],
            ]);

            $closedTicket->forceFill([
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveQuietly();

            $closedTicketLater = ItTicket::query()->create([
                'user_id' => $manager->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-910007',
                'tool' => $tool->name,
                'priority' => 'low',
                'status' => 'closed',
                'title' => 'Ticket cerrado hoy',
                'description' => 'Debe contarse igual en la gráfica.',
                'screenshots' => [],
            ]);

            $closedTicketLater->forceFill([
                'created_at' => $now,
                'updated_at' => $now,
            ])->saveQuietly();

            $this->actingAs($manager)
                ->get(route('tickets.reports'))
                ->assertOk()
                ->assertSee('Incidencias creadas por día', false)
                ->assertSee('data-ticket-open-history-total-value="6"', false)
                ->assertSee('data-ticket-open-history-peak-value="2"', false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_ticket_reports_page_shows_tickets_by_dealership(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Delegaciones',
            'email' => 'gestor-delegaciones@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $northDealership = Dealership::query()->create([
            'name' => 'Delegación Norte',
        ]);

        $southDealership = Dealership::query()->create([
            'name' => 'Delegación Sur',
        ]);

        $northUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante Norte',
            'email' => 'solicitante-norte@example.com',
            'dealership_id' => $northDealership->id,
        ]);

        $southUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante Sur',
            'email' => 'solicitante-sur@example.com',
            'dealership_id' => $southDealership->id,
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        ItTicket::query()->create([
            'user_id' => $northUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800001',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Ticket Norte 1',
            'description' => 'Primero.',
            'screenshots' => [],
        ]);

        ItTicket::query()->create([
            'user_id' => $northUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800002',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Ticket Norte 2',
            'description' => 'Segundo.',
            'screenshots' => [],
        ]);

        ItTicket::query()->create([
            'user_id' => $southUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-800003',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket Sur',
            'description' => 'Tercero.',
            'screenshots' => [],
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee('Tickets por delegaciones', false)
            ->assertSee('data-dealership-range-select', false)
            ->assertSee('Delegación Norte', false)
            ->assertSee('Delegación Sur', false)
            ->assertSee('3 incidencias en total', false);
    }

    public function test_ticket_reports_page_can_filter_tickets_by_dealership_in_real_time(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Delegaciones Filtro',
            'email' => 'gestor-delegaciones-filtro@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $northDealership = Dealership::query()->create([
            'name' => 'Delegación Norte Filtro',
        ]);

        $southDealership = Dealership::query()->create([
            'name' => 'Delegación Sur Filtro',
        ]);

        $northUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante Norte Filtro',
            'email' => 'solicitante-norte-filtro@example.com',
            'dealership_id' => $northDealership->id,
        ]);

        $southUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante Sur Filtro',
            'email' => 'solicitante-sur-filtro@example.com',
            'dealership_id' => $southDealership->id,
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $now = Carbon::parse('2026-07-21 12:00:00');
        Carbon::setTestNow($now);

        try {
            $recentNorthTicket = ItTicket::query()->create([
                'user_id' => $northUser->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-800101',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'new',
                'title' => 'Ticket Norte reciente',
                'description' => 'Debe entrar en 1 mes.',
                'screenshots' => [],
            ]);

            $oldNorthTicket = ItTicket::query()->create([
                'user_id' => $northUser->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-800102',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'closed',
                'title' => 'Ticket Norte antiguo',
                'description' => 'Debe quedar fuera de 1 mes.',
                'screenshots' => [],
            ]);

            $southTicket = ItTicket::query()->create([
                'user_id' => $southUser->id,
                'ticket_tool_id' => $tool->id,
                'number' => 'IT-800103',
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'in_progress',
                'title' => 'Ticket Sur antiguo',
                'description' => 'Debe quedar fuera de 1 mes.',
                'screenshots' => [],
            ]);

            $recentNorthTicket->forceFill([
                'created_at' => $now->copy()->subDays(12),
                'updated_at' => $now->copy()->subDays(12),
            ])->saveQuietly();

            $oldNorthTicket->forceFill([
                'created_at' => $now->copy()->subMonths(4),
                'updated_at' => $now->copy()->subMonths(4),
            ])->saveQuietly();

            $southTicket->forceFill([
                'created_at' => $now->copy()->subMonths(5),
                'updated_at' => $now->copy()->subMonths(5),
            ])->saveQuietly();

            $response = $this->actingAs($manager)
                ->get(route('tickets.reports', [
                    'ajax' => 1,
                    'report' => 'dealership',
                    'dealership_range' => '1m',
                ]));

            $response->assertOk()->assertJsonStructure(['html']);
            $html = (string) $response->json('html');

            $this->assertStringContainsString('Delegación Norte Filtro', $html);
            $this->assertStringNotContainsString('Delegación Sur Filtro', $html);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_open_ticket_report_segments_link_to_filtered_ticket_list(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Informe',
            'email' => 'gestor-informe@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $itUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'IT Rosco',
            'email' => 'it-rosco@example.com',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-700010',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket abierto clicable',
            'description' => 'Debe enlazar al listado filtrado.',
            'screenshots' => [],
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee(e(route('tickets.index', [
                'managed_search' => $itUser->name,
                'managed_status' => 'in_progress',
            ])), false);
    }

    public function test_resolution_report_counts_only_working_hours_across_weekends(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Horario',
            'email' => 'gestor-horario@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $itUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Horario Javi',
            'email' => 'horario-javi@example.com',
            'it_monday_start' => '09:00',
            'it_monday_end' => '18:00',
            'it_tuesday_start' => '09:00',
            'it_tuesday_end' => '18:00',
            'it_wednesday_start' => '09:00',
            'it_wednesday_end' => '18:00',
            'it_thursday_start' => '09:00',
            'it_thursday_end' => '18:00',
            'it_friday_start' => '09:00',
            'it_friday_end' => '18:00',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900001',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Incidencia horario',
            'description' => 'Debe contar viernes por la tarde y lunes por la mañana.',
            'screenshots' => [],
        ]);

        $friday1750 = Carbon::parse('2026-07-10 17:50:00');
        $monday0915 = Carbon::parse('2026-07-13 09:15:00');

        $this->createTicketActivityLog($ticket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $friday1750, [
            'assigned_to_user_id' => $itUser->id,
        ]);
        $this->createTicketActivityLog($ticket, $itUser, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $friday1750->copy()->addMinute(), [
            'previous_status' => 'new',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($ticket, $itUser, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $monday0915, [
            'status' => 'closed',
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee('24 min', false)
            ->assertSee('Tickets medidos', false);
    }

    public function test_resolution_report_skips_tickets_closed_before_the_assignee_has_a_schedule(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Sin Horario',
            'email' => 'gestor-sin-horario@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.reports.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $itUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Tecnico Sin Horario',
            'email' => 'tecnico-sin-horario@example.com',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $itUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900002',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'closed',
            'title' => 'Incidencia sin horario',
            'description' => 'No debe entrar en el informe.',
            'screenshots' => [],
        ]);

        $assignedAt = Carbon::parse('2026-07-10 16:00:00');
        $closedAt = Carbon::parse('2026-07-10 17:00:00');

        $this->createTicketActivityLog($ticket, $manager, TicketActivityLog::EVENT_ASSIGNED, 'Asignado', $assignedAt, [
            'assigned_to_user_id' => $itUser->id,
        ]);
        $this->createTicketActivityLog($ticket, $itUser, TicketActivityLog::EVENT_STATUS_CHANGED, 'Estado cambiado a En curso', $assignedAt->copy()->addMinute(), [
            'previous_status' => 'new',
            'status' => 'in_progress',
        ]);
        $this->createTicketActivityLog($ticket, $itUser, TicketActivityLog::EVENT_CLOSED, 'Cerrado', $closedAt, [
            'status' => 'closed',
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee('No hay tickets cerrados o clausurados suficientes para calcular el tiempo medio.', false)
            ->assertSee('Tickets medidos', false)
            ->assertSee('0', false);
    }

    public function test_user_with_assign_permission_can_delete_tickets_and_cleanup_files(): void
    {
        Storage::fake('public');

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor IT',
            'email' => 'gestor.it@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante',
            'email' => 'solicitante@example.com',
        ]);

        $ticketScreenshotPath = 'it-tickets/screenshots/ticket-shot.png';
        Storage::disk('public')->put($ticketScreenshotPath, 'ticket screenshot');

        $ticket = ItTicket::query()->create([
            'user_id' => $requester->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-333333',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Ticket a eliminar',
            'description' => 'Este ticket se debe borrar.',
            'screenshots' => [
                [
                    'name' => 'ticket-shot.png',
                    'path' => $ticketScreenshotPath,
                ],
            ],
        ]);

        $messageAttachment = UploadedFile::fake()->image('mensaje.png', 120, 120);

        $this->actingAs($manager)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'Mensaje con adjunto',
                'attachments' => [$messageAttachment],
            ])
            ->assertRedirect();

        $message = $ticket->fresh(['messages'])->messages->firstOrFail();
        $messageAttachmentPath = (string) data_get($message->attachments[0] ?? [], 'path', '');

        $this->assertNotSame('', $messageAttachmentPath);
        Storage::disk('public')->assertExists($ticketScreenshotPath);
        Storage::disk('public')->assertExists($messageAttachmentPath);

        $this->actingAs($manager)
            ->delete(route('tickets.destroy', $ticket))
            ->assertRedirect(route('tickets.index'));

        $this->assertDatabaseMissing('it_tickets', [
            'id' => $ticket->id,
        ]);
        $this->assertDatabaseMissing('it_ticket_messages', [
            'it_ticket_id' => $ticket->id,
        ]);
        Storage::disk('public')->assertMissing($ticketScreenshotPath);
        Storage::disk('public')->assertMissing($messageAttachmentPath);
    }

    private function createTicketActivityLog(ItTicket $ticket, User $actor, string $event, string $title, \Illuminate\Support\Carbon $createdAt, array $details = []): void
    {
        if (in_array($event, [
            TicketActivityLog::EVENT_ASSIGNED,
            TicketActivityLog::EVENT_CLOSED,
            TicketActivityLog::EVENT_PERMANENTLY_CLOSED,
        ], true) && ! array_key_exists('assignee_schedule', $details)) {
            $assigneeId = (int) ($details['assigned_to_user_id'] ?? $ticket->assigned_to_user_id ?? 0);

            if ($assigneeId > 0) {
                $assignee = User::query()->select([
                    'id',
                    'it_monday_start',
                    'it_monday_end',
                    'it_tuesday_start',
                    'it_tuesday_end',
                    'it_wednesday_start',
                    'it_wednesday_end',
                    'it_thursday_start',
                    'it_thursday_end',
                    'it_friday_start',
                    'it_friday_end',
                ])->find($assigneeId);

                if ($assignee) {
                    $schedule = [
                        'monday' => ['start' => $assignee->it_monday_start, 'end' => $assignee->it_monday_end],
                        'tuesday' => ['start' => $assignee->it_tuesday_start, 'end' => $assignee->it_tuesday_end],
                        'wednesday' => ['start' => $assignee->it_wednesday_start, 'end' => $assignee->it_wednesday_end],
                        'thursday' => ['start' => $assignee->it_thursday_start, 'end' => $assignee->it_thursday_end],
                        'friday' => ['start' => $assignee->it_friday_start, 'end' => $assignee->it_friday_end],
                    ];

                    if (! in_array(null, array_merge(
                        array_column($schedule, 'start'),
                        array_column($schedule, 'end')
                    ), true)) {
                        $details['assignee_schedule'] = $schedule;
                    }
                }
            }
        }

        $log = TicketActivityLog::query()->create([
            'it_ticket_id' => $ticket->id,
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'event' => $event,
            'title' => $title,
            'details' => $details,
        ]);

        $log->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();
    }

    public function test_regular_user_cannot_open_the_tickets_interior(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'email' => 'no-access@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('tickets.index'))
            ->assertForbidden();
    }

    public function test_ticket_creator_can_open_the_ticket_detail_interior(): void
    {
        $dealership = Dealership::query()->create([
            'name' => 'Delegación Norte',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador',
            'email' => 'creador@example.com',
            'dealership_id' => $dealership->id,
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-333333',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'El usuario crea la incidencia',
            'description' => 'Aqui se ve todo lo que ha escrito.',
            'screenshots' => [],
        ]);

        $this->actingAs($creator)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('El usuario crea la incidencia', false)
            ->assertSee('Aqui se ve todo lo que ha escrito.', false)
            ->assertSee('Delegación', false)
            ->assertSee('Delegación Norte', false);
    }

    public function test_ticket_detail_shows_requester_extra_role_next_to_the_dealership(): void
    {
        $dealership = Dealership::query()->create([
            'name' => 'Delegación Sur',
        ]);

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador Perfil',
            'email' => 'creador-perfil@example.com',
            'dealership_id' => $dealership->id,
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-333334',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Ticket con perfil',
            'description' => 'El solicitante tiene rol extra.',
            'screenshots' => [],
        ]);

        $this->actingAs($creator)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Delegación Sur', false)
            ->assertSee('Comercial', false);
    }

    public function test_manager_can_change_ticket_requester_from_the_detail_view(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Solicitante',
            'email' => 'gestor-solicitante@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $currentRequester = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante Original',
            'email' => 'solicitante-original@example.com',
        ]);

        $nextRequester = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Solicitante Nuevo',
            'email' => 'solicitante-nuevo@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $currentRequester->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-333335',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Ticket con solicitante editable',
            'description' => 'El solicitante se puede corregir.',
            'screenshots' => [],
        ]);

        $this->actingAs($manager)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee(route('tickets.requester.update', $ticket), false)
            ->assertSee('Solicitante Original', false);

        $this->actingAs($manager)
            ->post(route('tickets.requester.update', $ticket), [
                'user_id' => $nextRequester->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('it_tickets', [
            'id' => $ticket->id,
            'user_id' => $nextRequester->id,
        ]);

        $this->assertDatabaseHas('ticket_activity_logs', [
            'it_ticket_id' => $ticket->id,
            'event' => TicketActivityLog::EVENT_REQUESTER_CHANGED,
        ]);
    }

    public function test_ticket_participants_can_reply_with_images(): void
    {
        Storage::fake('public');
        Notification::fake();

        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador',
            'email' => 'creador-reply@example.com',
        ]);

        $itUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico',
            'email' => 'tecnico-reply@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'assigned_to_user_id' => $itUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-444444',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket con conversación',
            'description' => 'Hay que poder responder al hilo.',
            'screenshots' => [],
        ]);

        $response = $this->actingAs($creator)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'Te paso una captura.',
                'attachments' => [
                    UploadedFile::fake()->image('captura-ticket.png'),
                ],
            ]);

        $response->assertRedirect();

        $message = $ticket->fresh()->messages()->firstOrFail();

        $this->assertSame('Te paso una captura.', $message->body);
        $this->assertCount(1, $message->attachments ?? []);
        Storage::disk('public')->assertExists($message->attachments[0]['path']);
        Notification::assertSentTo(
            $itUser,
            ItTicketMessageNotification::class,
            function (ItTicketMessageNotification $notification, array $channels, User $notifiable) use ($ticket, $creator): bool {
                $payload = $notification->toDatabase($notifiable);

                return $channels === ['database']
                    && $payload['type'] === 'it-ticket.reply.received'
                    && $payload['ticket_id'] === $ticket->id
                    && $payload['actor_name'] === $creator->name;
            }
        );

        $response = $this->actingAs($itUser)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'Lo reviso ahora.',
            ]);

        $response->assertRedirect();

        $ticket->refresh();

        $this->assertSame('pending_user', $ticket->status);
        $this->assertSame(2, $ticket->messages()->count());
        Notification::assertSentTo(
            $creator,
            ItTicketMessageNotification::class,
            function (ItTicketMessageNotification $notification, array $channels, User $notifiable) use ($ticket, $itUser): bool {
                $payload = $notification->toDatabase($notifiable);

                return $channels === ['database']
                    && $payload['type'] === 'it-ticket.reply.received'
                    && $payload['ticket_id'] === $ticket->id
                    && $payload['actor_name'] === $itUser->name;
            }
        );
    }

    public function test_it_assignee_can_close_ticket_and_block_future_replies(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador',
            'email' => 'creador-close@example.com',
        ]);

        $itUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Cierre',
            'email' => 'tecnico-close@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'assigned_to_user_id' => $itUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-555555',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket cerrable',
            'description' => 'Se puede cerrar desde el hilo.',
            'screenshots' => [],
        ]);

        $this->actingAs($itUser)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'Queda resuelto, lo cierro.',
                'close_ticket' => '1',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $messages = $ticket->messages()->get();

        $this->assertSame('closed', $ticket->status);
        $this->assertSame(2, $messages->count());
        $this->assertSame('Queda resuelto, lo cierro.', $messages->first()->body);
        $this->assertSame('Ticket cerrado por Técnico Cierre.', $messages->last()->body);

        $this->actingAs($creator)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'Intento responder después del cierre.',
            ])
            ->assertForbidden();
    }

    public function test_ticket_reopen_is_idempotent_when_double_submitted(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Reapertura',
            'email' => 'gestor-reapertura@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $assignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Reapertura',
            'email' => 'tecnico-reapertura@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $assignee->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-777888',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'reopen_requested',
            'title' => 'Ticket para reabrir',
            'description' => 'La reapertura no debe fallar si se repite el envío.',
            'screenshots' => [],
        ]);

        $payload = [
            'priority' => 'medium',
            'assigned_to_user_id' => $assignee->id,
        ];

        $this->actingAs($manager)
            ->post(route('tickets.reopen', $ticket), $payload)
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status);

        $this->actingAs($manager)
            ->post(route('tickets.reopen', $ticket), $payload)
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('in_progress', $ticket->status);
    }

    public function test_ticket_permanent_close_is_idempotent_when_double_submitted(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Clausura',
            'email' => 'gestor-clausura@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $assignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Clausura',
            'email' => 'tecnico-clausura@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'assigned_to_user_id' => $assignee->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-888999',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'reopen_requested',
            'title' => 'Ticket para clausurar',
            'description' => 'La clausura no debe fallar si se repite el envío.',
            'screenshots' => [],
        ]);

        $payload = [
            'reason' => 'Cierre definitivo.',
        ];

        $this->actingAs($manager)
            ->post(route('tickets.permanently-close', $ticket), $payload)
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('clausurado', $ticket->status);

        $this->actingAs($manager)
            ->post(route('tickets.permanently-close', $ticket), $payload)
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame('clausurado', $ticket->status);
    }

    public function test_ticket_creator_can_close_ticket_with_comment(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador Cierre',
            'email' => 'creador-close-owner@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-666666',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'in_progress',
            'title' => 'Ticket del creador',
            'description' => 'El creador también puede cerrarlo.',
            'screenshots' => [],
        ]);

        $this->actingAs($creator)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'Lo he revisado y ya está resuelto.',
                'close_ticket' => '1',
            ])
            ->assertRedirect();

        $ticket->refresh();
        $messages = $ticket->messages()->get();

        $this->assertSame('closed', $ticket->status);
        $this->assertSame(2, $messages->count());
        $this->assertSame('Lo he revisado y ya está resuelto.', $messages->first()->body);
        $this->assertSame('Ticket cerrado por Creador Cierre.', $messages->last()->body);
    }

    public function test_ticket_creator_reply_sets_status_back_to_in_progress(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador En Curso',
            'email' => 'creador-in-progress@example.com',
        ]);

        $itUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico En Curso',
            'email' => 'tecnico-in-progress@example.com',
        ]);

        $ticket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'assigned_to_user_id' => $itUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-777777',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'pending_user',
            'title' => 'Ticket reabierto',
            'description' => 'El creador responde y debe volver a curso.',
            'screenshots' => [],
        ]);

        $this->actingAs($creator)
            ->post(route('tickets.messages.store', $ticket), [
                'body' => 'He añadido la información que faltaba.',
            ])
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame('in_progress', $ticket->status);
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_tickets_index_paginates_each_section_at_ten_items(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Paginado',
            'email' => 'gestor-paginado@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador Paginado',
            'email' => 'creador-paginado@example.com',
        ]);

        foreach (range(1, 11) as $index) {
            $ticket = ItTicket::query()->create([
                'user_id' => $creator->id,
                'assigned_to_user_id' => $manager->id,
                'ticket_tool_id' => $tool->id,
                'number' => sprintf('IT-%06d', $index),
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'in_progress',
                'title' => 'Ticket ' . $index,
                'description' => 'Ticket de prueba ' . $index,
                'screenshots' => [],
            ]);

            $ticket->forceFill([
                'created_at' => now()->subMinutes(11 - $index),
                'updated_at' => now()->subMinutes(11 - $index),
            ])->saveQuietly();
        }

        $this->actingAs($manager)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('IT-000011', false)
            ->assertDontSee('IT-000001', false);
    }

    public function test_tickets_index_uses_open_statuses_by_default_when_paginating(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Gestor Filtro',
            'email' => 'gestor-filtro@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $requester = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Solicitante Filtro',
            'email' => 'solicitante-filtro@example.com',
        ]);

        foreach (range(1, 12) as $index) {
            $ticket = ItTicket::query()->create([
                'user_id' => $requester->id,
                'assigned_to_user_id' => $manager->id,
                'ticket_tool_id' => $tool->id,
                'number' => sprintf('IT-100%03d', $index),
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'new',
                'title' => 'Ticket abierto ' . $index,
                'description' => 'Ticket abierto de prueba ' . $index,
                'screenshots' => [],
            ]);

            $ticket->forceFill([
                'created_at' => now()->subMinutes(12 - $index),
                'updated_at' => now()->subMinutes(12 - $index),
            ])->saveQuietly();
        }

        foreach (['closed', 'clausurado', 'closed'] as $offset => $status) {
            $ticket = ItTicket::query()->create([
                'user_id' => $requester->id,
                'assigned_to_user_id' => $manager->id,
                'ticket_tool_id' => $tool->id,
                'number' => sprintf('IT-200%03d', $offset + 1),
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => $status,
                'title' => 'Ticket cerrado ' . ($offset + 1),
                'description' => 'Ticket cerrado de prueba ' . ($offset + 1),
                'screenshots' => [],
            ]);

            $ticket->forceFill([
                'created_at' => now()->subHours($offset + 1),
                'updated_at' => now()->subHours($offset + 1),
            ])->saveQuietly();
        }

        $response = $this->actingAs($manager)
            ->get(route('tickets.index', [
                'managed_page' => 2,
                'assigned_page' => 2,
                'ajax' => 1,
            ]));

        $response->assertOk();

        $html = (string) $response->json('html');

        $this->assertStringContainsString('IT-100001', $html);
        $this->assertStringContainsString('IT-100002', $html);
        $this->assertStringNotContainsString('IT-200001', $html);
        $this->assertStringNotContainsString('IT-200002', $html);
        $this->assertStringNotContainsString('IT-200003', $html);
    }

    public function test_assigned_tickets_search_works_beyond_first_page(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $assignedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Buscar',
            'email' => 'tecnico-buscar@example.com',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador Buscar',
            'email' => 'creador-buscar@example.com',
        ]);

        foreach (range(1, 11) as $index) {
            $ticket = ItTicket::query()->create([
                'user_id' => $creator->id,
                'assigned_to_user_id' => $assignedUser->id,
                'ticket_tool_id' => $tool->id,
                'number' => sprintf('IT-%06d', $index),
                'tool' => $tool->name,
                'priority' => 'medium',
                'status' => 'in_progress',
                'title' => 'Ticket de búsqueda ' . $index,
                'description' => 'Ticket de prueba ' . $index,
                'screenshots' => [],
            ]);

            $ticket->forceFill([
                'created_at' => now()->subMinutes(11 - $index),
                'updated_at' => now()->subMinutes(11 - $index),
            ])->saveQuietly();
        }

        $response = $this->actingAs($assignedUser)
            ->get(route('tickets.index', [
                'assigned_search' => 'IT-000001',
                'ajax' => 1,
            ]));

        $response->assertOk();

        $html = (string) $response->json('html');

        $this->assertStringContainsString('IT-000001', $html);
        $this->assertStringNotContainsString('IT-000011', $html);
    }

    public function test_assigned_tickets_can_be_sorted_by_last_update_while_combining_filters(): void
    {
        $tool = TicketTool::query()->create([
            'name' => 'Web HR Motor',
            'color' => '#1d4ed8',
        ]);

        $assignedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Técnico Orden',
            'email' => 'tecnico-orden@example.com',
        ]);

        $creator = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Creador Orden',
            'email' => 'creador-orden@example.com',
        ]);

        $olderTicket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'assigned_to_user_id' => $assignedUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900001',
            'tool' => $tool->name,
            'priority' => 'high',
            'status' => 'in_progress',
            'title' => 'Ticket más antiguo',
            'description' => 'Debe quedar antes en orden ascendente.',
            'screenshots' => [],
        ]);

        $newerTicket = ItTicket::query()->create([
            'user_id' => $creator->id,
            'assigned_to_user_id' => $assignedUser->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900002',
            'tool' => $tool->name,
            'priority' => 'high',
            'status' => 'in_progress',
            'title' => 'Ticket más reciente',
            'description' => 'Debe quedar después en orden ascendente.',
            'screenshots' => [],
        ]);

        $olderTicket->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();

        $newerTicket->forceFill([
            'created_at' => now()->subHours(6),
            'updated_at' => now()->subHours(6),
        ])->saveQuietly();

        $response = $this->actingAs($assignedUser)
            ->get(route('tickets.index', [
                'assigned_status' => 'in_progress',
                'assigned_priority' => 'high',
                'assigned_sort' => 'updated_asc',
                'ajax' => 1,
            ]));

        $response->assertOk();

        $html = (string) $response->json('html');
        $olderPosition = strpos($html, $olderTicket->number);
        $newerPosition = strpos($html, $newerTicket->number);

        $this->assertNotFalse($olderPosition);
        $this->assertNotFalse($newerPosition);
        $this->assertTrue($olderPosition < $newerPosition);
    }
}
