<?php

namespace Tests\Feature;

use App\Filament\Pages\AdminPermissionsPage;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ToolsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_permission_is_registered_and_listed_under_informatica(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->assertSame('application', app_admin_permission_definitions()['tools.manage']['scope']);
        $this->assertContains('tools.manage', app_admin_permission_keys());

        Livewire::actingAs($admin);

        $groups = Livewire::test(AdminPermissionsPage::class)->instance()->permissionGroups();

        $this->assertArrayHasKey('Altas y bajas', $groups);
        $this->assertSame(
            'tools.manage',
            collect($groups['Altas y bajas'])->firstWhere('key', 'tools.manage')['key'],
        );
    }

    public function test_tickets_only_user_sees_only_tickets_inside_informatica(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);

        $this->actingAs($user);

        $navbar = view('components.layout.navbar')->render();

        $this->assertStringContainsString('Informática', $navbar);
        $this->assertStringContainsString(route('tickets.index'), $navbar);
        $this->assertStringNotContainsString(route('tools.altas-bajas'), $navbar);
        $this->get(route('tools.altas-bajas'))->assertForbidden();
    }

    public function test_tools_permission_user_sees_only_altas_y_bajas_inside_informatica(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $this->grant($user, 'tools.manage');

        $this->actingAs($user);

        $navbar = view('components.layout.navbar')->render();

        $this->assertStringContainsString('Informática', $navbar);
        $this->assertStringNotContainsString(route('tickets.index'), $navbar);
        $this->assertStringContainsString(route('tools.altas-bajas'), $navbar);
        $this->get(route('tools.altas-bajas'))->assertOk()->assertSee('Últimas solicitudes');
    }

    public function test_user_with_both_permissions_sees_both_informatica_options(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $this->grant($user, 'tools.manage');

        $this->actingAs($user);
        $navbar = view('components.layout.navbar')->render();

        $this->assertStringContainsString('Informática', $navbar);
        $this->assertStringContainsString(route('tickets.index'), $navbar);
        $this->assertStringContainsString(route('tools.altas-bajas'), $navbar);
    }

    public function test_user_without_either_permission_does_not_see_informatica(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user);

        $navbar = view('components.layout.navbar')->render();

        $this->assertStringNotContainsString('Informática', $navbar);
        $this->assertStringNotContainsString(route('tools.altas-bajas'), $navbar);
    }

    public function test_admin_keeps_global_access_to_tools_module(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin);

        $this->assertTrue(app_can_access_tools_management($admin));
        $this->get(route('tools.altas-bajas'))->assertOk();
    }

    private function grant(User $user, string $permission): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permission,
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);
    }
}
