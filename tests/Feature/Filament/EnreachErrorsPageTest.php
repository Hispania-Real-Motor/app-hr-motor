<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\EnreachErrorsPage;
use App\Filament\Widgets\MotorflashMessageErrorsOverviewWidget;
use App\Models\AdminPermissionGrant;
use App\Models\EnreachError;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class EnreachErrorsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_view_permission_controls_the_enreach_card_and_page_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertForbidden();

        $this->grant($user, 'enreach-errors.view');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('Enreach')
            ->assertSee('Errores IT')
            ->assertSee('#8203F7')
            ->assertSee('Estado: Pendiente de integración')
            ->assertDontSee('Motorflash Message');

        $this->get(EnreachErrorsPage::getUrl())
            ->assertOk()
            ->assertSee('Todavía no hay conexión con n8n');
    }

    public function test_connected_enreach_shows_zero_and_real_counts(): void
    {
        config(['services.enreach.integration_status' => 'connected']);
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'enreach-errors.view');

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('Enreach')
            ->assertSee('0')
            ->assertDontSee('Pendiente de integración')
            ->assertDontSee('N/D');

        EnreachError::query()->create(['summary' => 'Error de Enreach', 'status' => 'open']);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('1')
            ->assertDontSee('Pendiente de integración')
            ->assertDontSee('N/D');
    }

    public function test_connected_enreach_shows_nd_when_its_query_fails(): void
    {
        config(['services.enreach.integration_status' => 'connected']);
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);
        $this->grant($user, 'enreach-errors.view');
        Schema::dropIfExists('enreach_errors');

        Livewire::actingAs($user);

        Livewire::test(MotorflashMessageErrorsOverviewWidget::class)
            ->assertSee('N/D')
            ->assertDontSee('Pendiente de integración');
    }

    public function test_manage_permission_is_independent_from_view_permission(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'is_active' => true]);

        $this->grant($user, 'enreach-errors.view');

        $this->assertTrue(app_user_has_admin_permission($user, 'enreach-errors.view'));
        $this->assertFalse(app_user_has_admin_permission($user, 'enreach-errors.manage'));

        $this->grant($user, 'enreach-errors.manage');

        $this->assertTrue(app_user_has_admin_permission($user, 'enreach-errors.manage'));
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
