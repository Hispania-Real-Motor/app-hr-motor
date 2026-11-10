<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MotorflashMessageErrorsPage;
use App\Filament\Pages\AdminPermissionsPage;
use App\Filament\Widgets\AdminOverviewWidget;
use App\Filament\Widgets\MotorflashMessageErrorsOverviewWidget;
use App\Models\AdminPermissionGrant;
use App\Models\MotorflashLineIncident;
use App\Models\MotorflashLineReportReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Livewire\Livewire;

class MotorflashMessageErrorsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_view_permission_controls_dashboard_card_and_page_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertForbidden();

        $this->grant($user, 'motorflash-message-errors.view');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('Motorflash Message')
            ->assertSee('Errores IT')
            ->assertSee('#85C86D')
            ->assertSee('Estado: Pendiente de integración')
            ->assertDontSee('data-count-up-value="Pendiente de integración"', false)
            ->assertDontSee('Resumen general')
            ->assertDontSee('Usuarios activos')
            ->assertDontSee('Grupos activos')
            ->assertDontSee('Delegaciones activas')
            ->assertDontSee('Incidencias resueltas');

        $this->assertFalse(AdminOverviewWidget::canView());

        $this->get(MotorflashMessageErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('Líneas desconectadas de Meta')
            ->assertSee('Pendiente de integración')
            ->assertSee('Escritorio')
            ->assertDontSee('Recibido');
    }

    public function test_processed_report_shows_zero_when_there_are_no_open_line_errors(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        MotorflashLineReportReceipt::query()->create([
            'fingerprint' => str_repeat('a', 64),
            'total_lines' => 62,
            'problem_lines' => 0,
            'received_at' => now(),
        ]);

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('0')
            ->assertDontSee('Pendiente de integración')
            ->assertDontSee('N/D');
    }

    public function test_processed_report_shows_distinct_active_line_count(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');

        MotorflashLineReportReceipt::query()->create([
            'fingerprint' => str_repeat('b', 64),
            'total_lines' => 62,
            'problem_lines' => 2,
            'received_at' => now(),
        ]);
        MotorflashLineIncident::query()->createMany([
            ['error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED, 'phone_normalized' => '34600000001', 'phone_original' => '+34600000001', 'status' => 'open'],
            ['error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED, 'phone_normalized' => '34600000002', 'phone_original' => '+34600000002', 'status' => 'open'],
            ['error_type' => MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED, 'phone_normalized' => '34600000003', 'phone_original' => '+34600000003', 'status' => 'closed'],
        ]);

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('2')
            ->assertDontSee('Pendiente de integración')
            ->assertDontSee('N/D');
    }

    public function test_processed_report_shows_nd_when_the_line_query_fails(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'motorflash-message-errors.view');
        MotorflashLineReportReceipt::query()->create([
            'fingerprint' => str_repeat('c', 64),
            'total_lines' => 62,
            'problem_lines' => 1,
            'received_at' => now(),
        ]);
        Schema::dropIfExists('motorflash_line_incidents');

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('N/D')
            ->assertDontSee('Pendiente de integración');
    }

    public function test_manage_permission_is_separate_and_controls_mutating_actions(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->grant($user, 'motorflash-message-errors.view');

        $this->assertTrue(app_user_has_admin_permission($user, 'motorflash-message-errors.view'));
        $this->assertFalse(app_user_has_admin_permission($user, 'motorflash-message-errors.manage'));

        $this->actingAs($user)
            ->get(MotorflashMessageErrorsPage::getUrl())
            ->assertOk();

        $this->grant($user, 'motorflash-message-errors.manage');

        $this->assertTrue(app_user_has_admin_permission($user, 'motorflash-message-errors.manage'));
    }

    public function test_permissions_are_grouped_under_errores_it_without_changing_keys(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        $groups = Livewire::test(AdminPermissionsPage::class)->instance()->permissionGroups();
        $permissions = collect($groups['Errores IT'])->keyBy('key');

        $this->assertSame([
            'enreach-errors.manage',
            'enreach-errors.view',
            'motorflash-message-errors.manage',
            'motorflash-message-errors.view',
        ], $permissions->keys()->sort()->values()->all());
        $this->assertSame('Consultar errores de Motorflash Message', $permissions['motorflash-message-errors.view']['label']);
        $this->assertSame('Gestionar errores de Motorflash Message', $permissions['motorflash-message-errors.manage']['label']);
        $this->assertSame('Consultar errores de Enreach', $permissions['enreach-errors.view']['label']);
        $this->assertSame('Gestionar errores de Enreach', $permissions['enreach-errors.manage']['label']);
    }

    private function grant(User $user, string $permissionKey): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permissionKey,
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
