<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\AdminOverviewWidget;
use App\Filament\Pages\AdminPermissionsPage;
use App\Models\CompanyChatGroup;
use App\Models\Dealership;
use App\Models\ItTicket;
use App\Models\User;
use App\Models\AdminPermissionGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_dashboard_shows_the_four_administrative_metrics(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        User::factory()->create(['is_active' => true, 'disabled_at' => null]);
        User::factory()->create(['is_active' => false, 'disabled_at' => null]);
        User::factory()->create(['is_active' => true, 'disabled_at' => now()]);

        CompanyChatGroup::query()->create(['name' => 'Grupo KPI 1']);
        CompanyChatGroup::query()->create(['name' => 'Grupo KPI 2']);
        Dealership::factory()->count(3)->create();
        $expectedGroupCount = CompanyChatGroup::query()->count();
        foreach (['closed', 'clausurado', 'in_progress'] as $index => $status) {
            ItTicket::query()->create([
                'user_id' => $admin->id,
                'number' => 'KPI-' . ($index + 1),
                'tool' => 'general',
                'priority' => 'medium',
                'status' => $status,
                'title' => 'Incidencia KPI ' . ($index + 1),
                'description' => 'Incidencia de prueba para el dashboard.',
            ]);
        }

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk()
            ->assertDontSee('Bienvenido')
            ->assertDontSee('fi-account-widget')
            ->assertSee(AdminOverviewWidget::class, false)
            ->assertSee('data-count-up-value="2"', false)
            ->assertSee('data-count-up-value="' . $expectedGroupCount . '"', false)
            ->assertSee('data-count-up-value="3"', false);

        Livewire::actingAs($admin);

        Livewire::test(AdminOverviewWidget::class)
            ->assertSee('Usuarios activos')
            ->assertSee('Grupos activos')
            ->assertSee('Delegaciones activas')
            ->assertSee('Incidencias resueltas')
            ->assertDontSee('Usuarios con acceso activo')
            ->assertDontSee('Todos los grupos existentes')
            ->assertDontSee('Todas las delegaciones existentes')
            ->assertDontSee('Total acumulado')
            ->assertSee('data-count-up-value="2"', false)
            ->assertSee('data-count-up-value="' . $expectedGroupCount . '"', false)
            ->assertSee('data-count-up-value="3"', false)
            ->assertSee('requestAnimationFrame', false)
            ->assertSee('prefers-reduced-motion', false)
            ->assertSee('countUpAnimated', false);
    }

    public function test_zero_counts_are_rendered_as_zero(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'dashboard.summary.view',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee('data-count-up-value="0"', false)
            ->assertDontSee('data-count-up-value="N/D"', false);
    }

    public function test_dashboard_is_not_accessible_without_backoffice_role(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertForbidden();
    }

    public function test_summary_cards_are_hidden_without_the_summary_permission(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'users.manage',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk()
            ->assertDontSee(AdminOverviewWidget::class, false)
            ->assertDontSee('Usuarios activos')
            ->assertDontSee('Grupos activos')
            ->assertDontSee('Delegaciones activas')
            ->assertDontSee('Incidencias resueltas');

        $this->assertFalse(AdminOverviewWidget::canView());
    }

    public function test_summary_cards_remain_available_with_the_summary_permission(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'dashboard.summary.view',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(AdminOverviewWidget::class, false)
            ->assertSee('Usuarios activos')
            ->assertSee('Grupos activos')
            ->assertSee('Delegaciones activas')
            ->assertSee('Incidencias resueltas')
            ->assertSee('Resumen general')
            ->assertDontSee('Errores IT')
            ->assertDontSee('Errores de Motorflash Message');
    }

    public function test_summary_permission_is_registered_under_the_escritorio_group(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $definition = app_admin_permission_definitions()['dashboard.summary.view'];

        $this->assertSame('Ver resumen del escritorio', $definition['label']);
        $this->assertSame('backoffice', $definition['scope']);
        $this->assertSame([User::ROLE_ADMIN], $definition['default_roles']);

        Livewire::actingAs($admin);

        $groups = Livewire::test(AdminPermissionsPage::class)->instance()->permissionGroups();

        $this->assertArrayHasKey('Escritorio', $groups);
        $this->assertArrayHasKey(
            'dashboard.summary.view',
            collect($groups['Escritorio'])->keyBy('key')->all(),
        );
    }
}
