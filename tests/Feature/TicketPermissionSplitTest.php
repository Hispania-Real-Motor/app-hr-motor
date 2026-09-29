<?php

namespace Tests\Feature;

use App\Models\AdminPermissionGrant;
use App\Models\ItTicket;
use App\Models\TicketTool;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TicketPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_ticket_application_permissions_do_not_grant_backoffice_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);

        foreach (['tickets-it.assign', 'tickets-it.reports.view'] as $permission) {
            AdminPermissionGrant::query()->create([
                'permission_key' => $permission,
                'user_id' => $user->id,
                'group_id' => null,
                'group_role' => null,
                'granted_by_user_id' => null,
            ]);
        }

        $this->assertTrue(app_user_has_admin_permission($user, 'tickets-it.assign'));
        $this->assertTrue(app_user_has_admin_permission($user, 'tickets-it.reports.view'));
        $this->assertFalse(app_user_has_any_admin_permission($user));
        $this->assertFalse(app_user_can_access_admin_panel($user));

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertForbidden();
    }

    public function test_manager_with_only_assign_permission_sees_the_table_but_not_the_backoffice(): void
    {
        Notification::fake();
        Mail::fake();

        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $assignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $tool = TicketTool::query()->create(['name' => 'Web HR Motor', 'color' => '#1d4ed8']);
        $ticket = ItTicket::query()->create([
            'user_id' => $manager->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900003',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Tabla para gestor',
            'description' => 'Caso real de permiso de asignación.',
            'screenshots' => [],
        ]);
        $otherTicket = ItTicket::query()->create([
            'user_id' => $assignee->id,
            'assigned_to_user_id' => $assignee->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900004',
            'tool' => $tool->name,
            'priority' => 'low',
            'status' => 'in_progress',
            'title' => 'Ticket de otro técnico',
            'description' => 'Debe aparecer en la tabla completa.',
            'screenshots' => [],
        ]);
        $this->grantRole(User::ROLE_MANAGER, 'tickets-it.assign');

        $this->assertFalse(app_user_has_any_admin_permission($manager));
        $this->assertFalse(app_user_can_access_admin_panel($manager));
        $this->assertFalse($manager->canAccessPanel(app(Panel::class)));

        $this->actingAs($manager)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Todos los tickets', false)
            ->assertSee('data-ticket-section="managed"', false)
            ->assertSee($ticket->number, false)
            ->assertSee($otherTicket->number, false)
            ->assertSee(route('tickets.assign', $ticket), false)
            ->assertSee('Asignar', false)
            ->assertSee('Mis tickets', false);

        $this->assertTrue(app_user_has_admin_permission($manager, 'tickets-it.assign'));
        $this->assertTrue(app_can_access_tickets($manager));
        $this->assertTrue(app_can_assign_tickets($manager));

        // The same persisted profile must not depend on the base Admin bypass
        // for the tickets UI. Admin changes the backoffice bypass, not the
        // application permission that the route and Blade consume.
        $manager->role = User::ROLE_ADMIN;

        $this->actingAs($manager)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Todos los tickets', false)
            ->assertSee('data-ticket-section="managed"', false)
            ->assertSee($otherTicket->number, false)
            ->assertSee('Asignar', false);

        $manager->role = User::ROLE_MANAGER;

        $navbar = view('components.layout.navbar')->render();
        $this->assertStringNotContainsString('>Admin</a>', $navbar);

        $this->get('/backoffice')->assertForbidden();
        $this->get('/admin')->assertForbidden();

        $this->post(route('tickets.assign', $ticket), [
            'priority' => 'high',
            'assigned_to_user_id' => $assignee->id,
        ])->assertRedirect();

        $this->assertSame($assignee->id, $ticket->fresh()->assigned_to_user_id);
    }

    public function test_reports_permission_controls_route_and_empresa_navigation_without_it_role(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertDontSee('Informes Tickets', false);

        $this->actingAs($user)
            ->get(route('tickets.reports'))
            ->assertForbidden();

        $this->assertFalse(app_can_assign_tickets($user));

        $this->actingAs($user)
            ->get(route('tickets.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertDontSee(route('tickets.index'), false);

        $this->grant($user, 'tickets-it.reports.view');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertSee('Informes Tickets', false)
            ->assertSee(route('tickets.reports'), false);

        $this->actingAs($user)
            ->get(route('tickets.reports'))
            ->assertOk()
            ->assertSee('Tickets IT', false);
    }

    public function test_reports_permission_controls_the_real_empresa_navbar_for_every_extra_role_state(): void
    {
        foreach ([User::ROLE_INFORMATION_TECHNOLOGY, User::ROLE_COMMERCIAL, null] as $extraRole) {
            $user = User::factory()->create([
                'role' => User::ROLE_USER,
                'extra_role' => $extraRole,
            ]);
            $this->grant($user, 'tickets-it.reports.view');

            $home = $this->actingAs($user)->get(route('home'));

            $home
                ->assertOk()
                ->assertSee('Informes Tickets', false)
                ->assertSee(route('tickets.reports'), false)
                ->assertDontSee('>Admin</a>', false);

            $this->actingAs($user)
                ->get(route('tickets.reports'))
                ->assertOk()
                ->assertSee('Tickets IT', false);

            $this->assertTrue(app_user_has_admin_permission($user, 'tickets-it.reports.view'));
            $this->assertFalse(app_can_assign_tickets($user));
            $this->assertFalse(app_user_can_access_admin_panel($user));
            $this->get('/backoffice')->assertForbidden();
            $this->get('/admin')->assertForbidden();
        }
    }

    public function test_informatica_without_reports_permission_has_no_reports_navbar_entry_or_route_access(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Informes Tickets', false)
            ->assertDontSee(route('tickets.reports'), false);

        $this->actingAs($user)
            ->get(route('tickets.reports'))
            ->assertForbidden();
    }

    public function test_assign_permission_controls_assignment_endpoint_without_granting_ticket_tab(): void
    {
        Notification::fake();
        Mail::fake();

        $assigner = User::factory()->create(['role' => User::ROLE_MANAGEMENT]);
        $assignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $tool = TicketTool::query()->create(['name' => 'Web HR Motor', 'color' => '#1d4ed8']);
        $ticket = ItTicket::query()->create([
            'user_id' => $assigner->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900001',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Asignación independiente',
            'description' => 'Prueba de autorización.',
            'screenshots' => [],
        ]);

        $this->actingAs($assigner)
            ->post(route('tickets.assign', $ticket), [
                'priority' => 'high',
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertForbidden();

        $this->actingAs($assigner)
            ->get(route('home'))
            ->assertDontSee(route('tickets.index'), false);

        $this->grant($assigner, 'tickets-it.assign');

        $this->actingAs($assigner)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Todos los tickets', false)
            ->assertSee($ticket->number, false)
            ->assertSee(route('tickets.assign', $ticket), false);

        $this->actingAs($assigner)
            ->get(route('home'))
            ->assertSee(route('tickets.index'), false);

        $this->actingAs($assigner)
            ->post(route('tickets.assign', $ticket), [
                'priority' => 'high',
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertRedirect();

        $this->assertSame($assignee->id, $ticket->fresh()->assigned_to_user_id);
    }

    public function test_group_grants_and_revocations_are_respected_for_reports(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => 'informatica',
        ]);

        $this->grantRole('informatica', 'tickets-it.reports.view');
        $this->assertTrue(app_user_has_admin_permission($user, 'tickets-it.reports.view'));

        AdminPermissionGrant::query()
            ->where('group_role', 'informatica')
            ->where('permission_key', 'tickets-it.reports.view')
            ->update(['is_revoked' => true]);

        $this->assertFalse(app_user_has_admin_permission($user, 'tickets-it.reports.view'));
        $this->actingAs($user)->get(route('tickets.reports'))->assertForbidden();
    }

    public function test_informatica_keeps_the_tab_without_assign_permission_but_cannot_assign(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $assignee = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $tool = TicketTool::query()->create(['name' => 'Web HR Motor', 'color' => '#1d4ed8']);
        $ticket = ItTicket::query()->create([
            'user_id' => $user->id,
            'ticket_tool_id' => $tool->id,
            'number' => 'IT-900002',
            'tool' => $tool->name,
            'priority' => 'medium',
            'status' => 'new',
            'title' => 'Ticket IT sin permiso de asignación',
            'description' => 'Prueba de visibilidad innata.',
            'screenshots' => [],
        ]);

        $this->actingAs($user)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertDontSee('Todos los tickets', false);

        $this->actingAs($user)
            ->post(route('tickets.assign', $ticket), [
                'priority' => 'high',
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertForbidden();
    }

    private function grant(User $user, string $permission): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permission,
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);
    }

    private function grantRole(string $role, string $permission): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permission,
            'user_id' => null,
            'group_id' => null,
            'group_role' => $role,
            'granted_by_user_id' => null,
        ]);
    }
}
