<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AdminPermissionsPage;
use App\Models\AdminPermissionActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPermissionsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_only_base_admin_can_access_the_permissions_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $response = $this->actingAs($admin)
            ->get(AdminPermissionsPage::getUrl());

        $response
            ->assertOk()
            ->assertSee('Permisos por perfil')
            ->assertSee('Permisos directos por usuario')
            ->assertSee('Buscar perfil')
            ->assertSee('Buscar permiso')
            ->assertSee('wire:model.live.debounce.300ms="profileSearch"', false)
            ->assertSee('wire:model.live.debounce.300ms="permissionSearch"', false)
            ->assertSee('fi-input-wrp-prefix', false)
            ->assertSee('Roles base')
            ->assertSee('Roles adicionales')
            ->assertSee('Gestión de usuarios')
            ->assertSee('Usuarios')
            ->assertSee('Tipos de incidencia')
            ->assertSee('fi-checkbox-input', false)
            ->assertDontSee('fi-toggle', false)
            ->assertSee('flex flex-wrap gap-3', false)
            ->assertSee('grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-4 gap-y-1', false)
            ->assertSee('row-span-2 pt-0.5', false)
            ->assertSee('flex min-w-0 flex-wrap items-center gap-2', false)
            ->assertSee('<p class="col-start-2 text-xs leading-5 text-gray-500 dark:text-gray-400">', false)
            ->assertSee('mt-10 flex justify-end border-t', false)
            ->assertSeeInOrder([
                'Gestión de usuarios',
                'Altas, edición de perfiles y seguimiento del equipo.',
                'Acceso total',
            ], false)
            ->assertSee('Gestión de zonas')
            ->assertSee('Agrupa delegaciones en zonas y controla su reparto.')
            ->assertSee('Acceso total');

        foreach (array_merge(User::baseRoleLabels(), User::extraRoleLabels()) as $label) {
            $response->assertSee($label);
        }

        $this->actingAs($manager)
            ->get(AdminPermissionsPage::getUrl())
            ->assertForbidden();

        Livewire::actingAs($manager);

        Livewire::test(AdminPermissionsPage::class)
            ->assertForbidden();
    }

    public function test_admin_can_consult_profiles_and_defined_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY]);

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class)
            ->assertSee('Admin')
            ->assertSee('Informática')
            ->assertSee('Gestión de usuarios')
            ->assertSee('Gestión de zonas')
            ->assertSee('Acceso total')
            ->call('selectProfile', User::ROLE_INFORMATION_TECHNOLOGY)
            ->assertSet('selectedProfileRole', User::ROLE_INFORMATION_TECHNOLOGY)
            ->call('switchTab', 'users')
            ->assertSet('activeTab', 'users')
            ->call('switchTab', 'profiles')
            ->assertSet('activeTab', 'profiles')
            ->assertSee('Admin')
            ->assertSee('Gestor')
            ->assertSee('Usuario')
            ->assertSee('Informática')
            ->assertSee('Marketing')
            ->assertDontSee('Admin ·', false)
            ->assertDontSee('Gestor ·', false)
            ->assertDontSee('Usuario ·', false)
            ->assertDontSee('Informática ·', false)
            ->assertDontSee('Marketing ·', false)
            ->assertSee('usuario asociado');

        $this->assertFalse($component->instance()->profilePermissionsDirty());

        $this->assertCount(3, $component->instance()->baseProfileOptions());
        $this->assertCount(19, $component->instance()->extraProfileOptions());
        $this->assertArrayHasKey(User::ROLE_ADMIN, $component->instance()->baseProfileOptions());
        $this->assertArrayHasKey(User::ROLE_INFORMATION_TECHNOLOGY, $component->instance()->extraProfileOptions());

        $component->set('profileSearch', 'Informática');
        $this->assertArrayHasKey(User::ROLE_INFORMATION_TECHNOLOGY, $component->instance()->profileOptions());
        $this->assertCount(1, $component->instance()->profileOptions());
    }

    public function test_ticket_permission_catalog_contains_only_the_two_application_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->assertArrayNotHasKey('tickets-it.manage', app_admin_permission_definitions());
        $this->assertSame(
            ['tickets-it.assign', 'tickets-it.reports.view'],
            collect(app_admin_permission_definitions())
                ->filter(fn (array $definition, string $key): bool => str_starts_with($key, 'tickets-it.'))
                ->keys()
                ->all(),
        );

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->assertSee('Asignar tickets IT')
            ->assertSee('Ver informes de tickets IT')
            ->assertDontSee('Gestionar tickets IT');
    }

    public function test_videos_permission_is_an_application_permission_and_is_grouped_under_empresa(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $definition = app_admin_permission_definitions()['videos.view'];

        $this->assertSame('Ver vídeos de formación', $definition['label']);
        $this->assertSame('application', $definition['scope']);
        $this->assertSame([], $definition['default_roles']);
        $this->assertNotContains('videos.view', app_backoffice_permission_keys());

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);
        $groups = $component->instance()->permissionGroups();

        $this->assertArrayHasKey('Vídeos formación', $groups);
        $this->assertArrayHasKey('videos.view', collect($groups['Vídeos formación'])->keyBy('key')->all());
        $this->assertNotContains('videos.view', collect($groups['Tickets IT'])->pluck('key')->all());
    }

    public function test_hr_reports_permission_is_application_scoped_and_grouped_under_empresa(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $definition = app_admin_permission_definitions()['reports.hr.view'];

        $this->assertSame('Ver Informes HR', $definition['label']);
        $this->assertSame('application', $definition['scope']);
        $this->assertSame([], $definition['default_roles']);
        $this->assertNotContains('reports.hr.view', app_backoffice_permission_keys());

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);
        $groups = $component->instance()->permissionGroups();

        $this->assertArrayHasKey('Informes HR', $groups);
        $this->assertArrayHasKey('reports.hr.view', collect($groups['Informes HR'])->keyBy('key')->all());
        $this->assertNotContains('reports.hr.view', collect($groups['Rankings'])->pluck('key')->all());
    }

    public function test_reviews_permission_is_in_its_own_panel_group(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        $groups = Livewire::test(AdminPermissionsPage::class)->instance()->permissionGroups();

        $this->assertArrayHasKey('Reseñas', $groups);
        $this->assertArrayHasKey('reviews.view', collect($groups['Reseñas'])->keyBy('key')->all());
        $this->assertNotContains('reviews.view', collect($groups['Informes HR'])->pluck('key')->all());
        $this->assertNotContains('reviews.view', collect($groups['Empresa'] ?? [])->pluck('key')->all());
    }

    public function test_curricula_permission_is_application_scoped_and_grouped_under_human_resources(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $definition = app_admin_permission_definitions()['curricula.view'];

        $this->assertSame('Analizar currículums', $definition['label']);
        $this->assertSame('application', $definition['scope']);
        $this->assertSame([], $definition['default_roles']);
        $this->assertNotContains('curricula.view', app_backoffice_permission_keys());

        Livewire::actingAs($admin);

        $groups = Livewire::test(AdminPermissionsPage::class)->instance()->permissionGroups();

        $this->assertArrayHasKey('Analizador de currículums', $groups);
        $this->assertArrayHasKey('curricula.view', collect($groups['Analizador de currículums'])->keyBy('key')->all());
        $this->assertNotContains('curricula.view', collect($groups['Empresa'] ?? [])->pluck('key')->all());
    }

    public function test_rankings_permission_is_in_its_own_panel_group_and_counts_for_backoffice(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->assertArrayHasKey('backoffice.rankings.manage', app_admin_permission_definitions());
        $this->assertContains('backoffice.rankings.manage', app_backoffice_permission_keys());
        $this->assertNotContains('backoffice.rankings.manage', collect(app_admin_permission_definitions())
            ->filter(fn (array $definition, string $key): bool => str_starts_with($key, 'tickets-it.'))
            ->keys()
            ->all());

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);
        $groups = $component->instance()->permissionGroups();

        $this->assertArrayHasKey('Rankings', $groups);
        $this->assertArrayHasKey(
            'backoffice.rankings.manage',
            collect($groups['Rankings'])->keyBy('key')->all(),
        );
        $this->assertArrayNotHasKey('Backoffice', $groups);
    }

    public function test_recipient_management_permission_is_listed_under_tickets_it_as_backoffice_access(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $definition = app_admin_permission_definitions()['backoffice.tickets-it-recipients.manage'];

        $this->assertSame('backoffice', $definition['scope']);
        $this->assertSame([User::ROLE_ADMIN], $definition['default_roles']);
        $this->assertSame('Gestionar destinatarios de avisos de Tickets IT', $definition['label']);
        $this->assertSame(
            'Permite acceder al backoffice y gestionar los destinatarios de los avisos enviados al crear nuevos tickets IT.',
            $definition['description'],
        );
        $this->assertContains('backoffice.tickets-it-recipients.manage', app_backoffice_permission_keys());
        $this->assertNotContains('tickets-it.assign', app_backoffice_permission_keys());
        $this->assertNotContains('tickets-it.reports.view', app_backoffice_permission_keys());

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER);

        $ticketsPermissions = collect($component->instance()->permissionGroups()['Tickets IT'])
            ->keyBy('key');

        $this->assertArrayHasKey('backoffice.tickets-it-recipients.manage', $ticketsPermissions->all());
        $this->assertSame(
            'Gestionar destinatarios de avisos de Tickets IT',
            $ticketsPermissions['backoffice.tickets-it-recipients.manage']['label'],
        );
        $this->assertSame(
            'Permite acceder al backoffice y gestionar los destinatarios de los avisos enviados al crear nuevos tickets IT.',
            $ticketsPermissions['backoffice.tickets-it-recipients.manage']['description'],
        );
    }

    public function test_ticket_tool_management_is_grouped_under_tickets_it_without_changing_its_key(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->assertArrayHasKey('ticket-tools.manage', app_admin_permission_definitions());
        $this->assertSame(
            'Gestionar tipos de incidencia',
            app_admin_permission_definitions()['ticket-tools.manage']['label'],
        );
        $this->assertSame(
            'Crea, edita y elimina los tipos de incidencia que se muestran al abrir un ticket.',
            app_admin_permission_definitions()['ticket-tools.manage']['description'],
        );

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);
        $groups = $component->instance()->permissionGroups();

        $this->assertArrayHasKey('Tickets IT', $groups);
        $this->assertArrayNotHasKey('Tipos de incidencia', $groups);
        $this->assertArrayHasKey(
            'ticket-tools.manage',
            collect($groups['Tickets IT'])->keyBy('key')->all(),
        );
        $ticketPermissionKeys = collect($groups['Tickets IT'])->pluck('key')->all();
        $this->assertCount(4, $ticketPermissionKeys);
        $this->assertContains('tickets-it.assign', $ticketPermissionKeys);
        $this->assertContains('tickets-it.reports.view', $ticketPermissionKeys);
        $this->assertContains('backoffice.tickets-it-recipients.manage', $ticketPermissionKeys);
        $this->assertSame([
            'tickets-it.assign',
            'backoffice.tickets-it-recipients.manage',
            'ticket-tools.manage',
            'tickets-it.reports.view',
        ], $ticketPermissionKeys);

        $component
            ->set('permissionSearch', 'Gestionar tipos de incidencia')
            ->assertSee('Gestionar tipos de incidencia')
            ->assertSee('Crea, edita y elimina los tipos de incidencia que se muestran al abrir un ticket.')
            ->assertDontSee('Tipos de incidencia');
    }

    public function test_conversation_permissions_share_one_visual_group_and_remain_independent(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $definitions = app_admin_permission_definitions();
        $conversationKeys = [
            'chat-retention-holds.manage',
            'conversation-access.manage',
            'chat-groups.manage',
        ];

        foreach ($conversationKeys as $key) {
            $this->assertArrayHasKey($key, $definitions);
        }

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);
        $groups = $component->instance()->permissionGroups();

        $this->assertArrayHasKey('Conversaciones', $groups);
        $this->assertArrayNotHasKey('Conservación excepcional', $groups);
        $this->assertArrayNotHasKey('Acceso a conversaciones', $groups);
        $this->assertArrayNotHasKey('Grupos del chat', $groups);
        $this->assertSame(
            collect($conversationKeys)
                ->sortBy(fn (string $key): string => Str::lower(Str::ascii($definitions[$key]['label'])))
                ->values()
                ->all(),
            collect($groups['Conversaciones'])->pluck('key')->values()->all(),
        );

        $groupLabels = collect($groups)->keys()->values()->all();
        $this->assertSame(
            collect($groupLabels)
                ->sortBy(fn (string $label): string => Str::lower(Str::ascii($label)))
                ->values()
                ->all(),
            $groupLabels,
        );

        foreach ($groups as $permissions) {
            $labels = collect($permissions)->pluck('label')->all();
            $this->assertSame(
                collect($labels)
                    ->sortBy(fn (string $label): string => Str::lower(Str::ascii($label)))
                    ->values()
                    ->all(),
                $labels,
            );
        }

        $component = Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'chat-retention-holds.manage')
            ->call('toggleProfilePermission', 'conversation-access.manage')
            ->call('toggleProfilePermission', 'chat-groups.manage');

        $this->assertSame(
            collect($conversationKeys)->sort()->values()->all(),
            collect($component->instance()->profileGrantKeys)->sort()->values()->all(),
        );

        $component->call('toggleProfilePermission', 'conversation-access.manage');

        $this->assertContains('chat-retention-holds.manage', $component->instance()->profileGrantKeys);
        $this->assertContains('chat-groups.manage', $component->instance()->profileGrantKeys);
        $this->assertNotContains('conversation-access.manage', $component->instance()->profileGrantKeys);
    }

    public function test_profile_grants_can_be_added_and_removed_without_changing_defaults(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_INFORMATION_TECHNOLOGY)
            ->set('permissionSearch', 'Zonas')
            ->call('toggleProfilePermission', 'users.manage');

        $this->assertTrue($component->instance()->profilePermissionsDirty());
        $component->call('saveProfilePermissions')->assertNotified('Permisos del perfil actualizados.');

        $this->assertFalse($component->instance()->profilePermissionsDirty());

        $this->assertDatabaseHas('admin_permission_grants', [
            'group_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'permission_key' => 'users.manage',
            'user_id' => null,
        ]);

        $this->assertDatabaseHas('admin_permission_activity_logs', [
            'action' => AdminPermissionActivityLog::ACTION_PERMISSION_SYNCED,
            'target_type' => 'profile',
            'scope' => 'group_role',
        ]);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_INFORMATION_TECHNOLOGY)
            ->set('profileGrantKeys', [])
            ->call('saveProfilePermissions');

        $this->assertDatabaseMissing('admin_permission_grants', [
            'group_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'permission_key' => 'users.manage',
        ]);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_ADMIN)
            ->set('profileGrantKeys', ['zones.manage'])
            ->call('saveProfilePermissions');

        $this->assertDatabaseMissing('admin_permission_grants', [
            'group_role' => User::ROLE_ADMIN,
            'permission_key' => 'zones.manage',
        ]);
        $this->assertTrue(app_role_has_admin_permission(User::ROLE_ADMIN, 'zones.manage'));
        $this->assertTrue(app_user_can_access_admin_panel($admin));
    }

    public function test_profile_and_permission_searches_are_independent_and_filter_the_view_only(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class)
            ->set('profileSearch', 'Informática')
            ->assertSet('selectedProfileRole', User::ROLE_ADMIN)
            ->set('permissionSearch', 'Zonas')
            ->assertSet('profileSearch', 'Informática')
            ->assertSet('permissionSearch', 'Zonas')
            ->assertSet('selectedProfileRole', User::ROLE_ADMIN);

        $this->assertCount(0, $component->instance()->baseProfileOptions());
        $this->assertCount(1, $component->instance()->extraProfileOptions());
        $this->assertArrayHasKey('Zonas', $component->instance()->permissionGroups());

        $component->set('permissionSearch', 'seguimiento');
        $this->assertArrayHasKey('Usuarios', $component->instance()->permissionGroups());

        $component->set('permissionSearch', 'no existe este permiso');
        $this->assertSame([], $component->instance()->permissionGroups());
        $component->assertSee('No se han encontrado permisos.');

        $component->set('profileSearch', 'no existe este perfil');
        $this->assertSame([], $component->instance()->baseProfileOptions());
        $this->assertSame([], $component->instance()->extraProfileOptions());
        $component->assertSee('No se han encontrado perfiles.');
        $component->assertSet('selectedProfileRole', User::ROLE_ADMIN);
    }

    public function test_profile_selection_is_preserved_while_there_are_unsaved_changes(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_INFORMATION_TECHNOLOGY)
            ->call('toggleProfilePermission', 'users.manage')
            ->call('selectProfile', User::ROLE_COMMERCIAL)
            ->assertSet('selectedProfileRole', User::ROLE_INFORMATION_TECHNOLOGY)
            ->assertNotified('Guarda o descarta los cambios antes de cambiar de perfil.');
    }

    public function test_admin_has_every_catalogued_permission_and_cannot_edit_admin_profile(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);

        foreach (app_admin_permission_keys() as $permissionKey) {
            $this->assertTrue(app_user_has_admin_permission($admin, $permissionKey));
        }

        foreach ($component->instance()->profilePermissionRows() as $permission) {
            $this->assertTrue($permission['is_checked']);
            $this->assertTrue($permission['is_locked']);
        }

        $component
            ->call('toggleProfilePermission', 'users.manage')
            ->assertStatus(422);
    }

    public function test_non_admin_profiles_start_without_default_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'is_active' => true,
        ]);
        $commercial = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class);
        $nonAdminRoles = array_merge(
            [User::ROLE_MANAGER, User::ROLE_USER],
            array_keys(User::extraRoleLabels()),
        );
        $legacyVideoRoles = [
            User::ROLE_COMMERCIAL,
            User::ROLE_STORE_MANAGER,
            User::ROLE_AREA_MANAGER,
        ];
        $legacyHrReportRoles = [
            User::ROLE_MANAGEMENT,
            User::ROLE_AREA_MANAGER,
        ];
        $legacyReviewRoles = [
            User::ROLE_MARKETING,
            User::ROLE_MANAGEMENT,
        ];
        $legacyCurriculaRoles = [User::ROLE_HUMAN_RESOURCES];
        $legacyRankingsRoles = [
            User::ROLE_COMMERCIAL,
            User::ROLE_STORE_MANAGER,
            User::ROLE_AREA_MANAGER,
            User::ROLE_HR_NEWCARS,
            User::ROLE_MANAGEMENT,
        ];

        foreach ($nonAdminRoles as $role) {
            $component->call('selectProfile', $role);

            foreach ($component->instance()->profilePermissionRows() as $permission) {
                $this->assertFalse($permission['is_default']);
                $this->assertSame(
                    ($permission['key'] === 'videos.view' && in_array($role, $legacyVideoRoles, true))
                        || ($permission['key'] === 'reports.hr.view' && in_array($role, $legacyHrReportRoles, true))
                        || ($permission['key'] === 'reviews.view' && in_array($role, $legacyReviewRoles, true))
                        || ($permission['key'] === 'curricula.view' && in_array($role, $legacyCurriculaRoles, true))
                        || ($permission['key'] === 'rankings.view' && in_array($role, $legacyRankingsRoles, true)),
                    $permission['is_checked'],
                );
            }
        }

        foreach (app_admin_permission_definitions() as $permissionKey => $definition) {
            $this->assertSame(
                ($definition['scope'] ?? 'backoffice') === 'application' ? [] : [User::ROLE_ADMIN],
                $definition['default_roles'],
            );
        }

        foreach ([$manager, $user] as $nonAdminUser) {
            foreach (app_admin_permission_keys() as $permissionKey) {
                $this->assertFalse(app_user_has_admin_permission($nonAdminUser, $permissionKey));
            }
        }

        $this->assertTrue(app_user_has_admin_permission($commercial, 'videos.view'));
    }

    public function test_base_and_extra_profiles_can_grant_and_revoke_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $user = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $commercial = User::factory()->create(['role' => User::ROLE_USER, 'extra_role' => User::ROLE_COMMERCIAL, 'is_active' => true]);

        Livewire::actingAs($admin);

        $managerComponent = Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER);

        $managerRows = collect($managerComponent->instance()->profilePermissionRows())->keyBy('key');
        $this->assertFalse($managerRows['zones.manage']['is_checked']);
        $this->assertFalse($managerRows['zones.manage']['is_locked']);
        $this->assertFalse(app_user_has_admin_permission($manager, 'zones.manage'));

        $managerComponent
            ->call('toggleProfilePermission', 'zones.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->assertDatabaseHas('admin_permission_grants', [
            'group_role' => User::ROLE_MANAGER,
            'permission_key' => 'zones.manage',
            'is_revoked' => false,
        ]);
        $this->assertTrue(app_user_has_admin_permission($manager, 'zones.manage'));
        $this->assertTrue(app_role_has_admin_permission(User::ROLE_MANAGER, 'zones.manage'));

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'zones.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->assertDatabaseMissing('admin_permission_grants', [
            'group_role' => User::ROLE_MANAGER,
            'permission_key' => 'zones.manage',
            'is_revoked' => false,
        ]);
        $this->assertFalse(app_user_has_admin_permission($manager, 'zones.manage'));

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_USER)
            ->call('toggleProfilePermission', 'users.manage')
            ->call('saveProfilePermissions');

        $this->assertTrue(app_user_has_admin_permission($user, 'users.manage'));

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_COMMERCIAL)
            ->call('toggleProfilePermission', 'users.manage')
            ->call('saveProfilePermissions');

        $this->assertTrue(app_user_has_admin_permission($commercial, 'users.manage'));
        $this->assertDatabaseHas('admin_permission_activity_logs', [
            'target_type' => 'profile',
            'scope' => 'group_role',
        ]);
    }

    public function test_direct_user_grants_can_be_added_and_removed(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        $target = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        Livewire::actingAs($admin);

        $component = Livewire::test(AdminPermissionsPage::class)
            ->call('switchTab', 'users')
            ->call('selectUser', $target->id)
            ->call('toggleUserPermission', 'users.manage');

        $this->assertTrue($component->instance()->userPermissionsDirty());
        $component->call('saveUserPermissions')->assertNotified('Permisos directos actualizados.');

        $this->assertFalse($component->instance()->userPermissionsDirty());

        $target->refresh();
        $this->assertTrue(app_user_has_admin_permission($target, 'users.manage'));
        $this->assertDatabaseHas('admin_permission_activity_logs', [
            'target_type' => 'user',
            'target_id' => $target->id,
            'scope' => 'user',
        ]);

        Livewire::test(AdminPermissionsPage::class)
            ->call('switchTab', 'users')
            ->call('selectUser', $target->id)
            ->set('userGrantKeys', [])
            ->call('saveUserPermissions');

        $target->refresh();
        $this->assertFalse(app_user_has_admin_permission($target, 'users.manage'));
        $this->assertDatabaseMissing('admin_permission_grants', [
            'user_id' => $target->id,
            'permission_key' => 'users.manage',
        ]);
    }

    public function test_an_admin_cannot_modify_own_direct_permissions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('switchTab', 'users')
            ->call('selectUser', $admin->id)
            ->set('userGrantKeys', [])
            ->call('saveUserPermissions')
            ->assertStatus(422);
    }
}
