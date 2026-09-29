<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Dealerships\DealershipResource;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_admin_can_always_access_the_backoffice(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk();
    }

    public function test_non_admin_without_permissions_cannot_access_the_backoffice(): void
    {
        foreach ([User::ROLE_MANAGER, User::ROLE_USER] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'extra_role' => null,
            ]);

            $this->actingAs($user)
                ->get('/backoffice')
                ->assertForbidden();
        }
    }

    public function test_clean_manager_without_direct_or_role_grants_is_denied_everywhere(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);

        $this->assertFalse(app_user_has_any_admin_permission($manager));
        $this->assertFalse(app_user_can_access_admin_panel($manager));
        $this->assertFalse($manager->canAccessPanel(app(Panel::class)));

        $this->actingAs($manager);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringNotContainsString('/backoffice', $navbarHtml);
        $this->get('/backoffice')->assertForbidden();
    }

    public function test_roles_view_alone_allows_only_the_role_viewer_not_backoffice(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);

        $this->grantToUser($user, 'roles.view');

        $this->assertFalse(app_user_has_any_admin_permission($user));
        $this->assertFalse(app_user_can_access_admin_panel($user));

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Visor de roles')
            ->assertDontSee('>Admin</a>');

        $this->get('/backoffice')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_zones_permission_allows_backoffice_without_enabling_the_role_viewer(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->grantToUser($user, 'zones.manage');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Visor de roles')
            ->assertSee('/backoffice');

        $this->get('/backoffice')->assertOk();
    }

    public function test_notifications_permission_allows_backoffice_without_enabling_the_role_viewer(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->grantToUser($user, 'notifications.manage');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Visor de roles')
            ->assertSee('/backoffice');

        $this->get('/backoffice')->assertOk();
    }

    public function test_one_explicit_user_permission_grants_backoffice_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->grantToUser($user, 'users.manage');

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk();
    }

    public function test_recipient_management_permission_grants_only_the_recipient_settings_page(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->grantToUser($user, 'backoffice.tickets-it-recipients.manage');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('/backoffice')
            ->assertSee('Admin');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('Correo Tickets IT');
        $this->get('/admin')->assertRedirect('/backoffice');
        $this->get(\App\Filament\Pages\ItTicketNotificationSettingsPage::getUrl())->assertOk();
        $this->get(DealershipResource::getUrl('index'))->assertForbidden();
        $this->assertFalse(app_user_has_admin_permission($user, 'tickets-it.assign'));
        $this->assertFalse(app_user_has_admin_permission($user, 'tickets-it.reports.view'));
    }

    public function test_the_legacy_admin_alias_uses_the_same_global_access_rule(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->grantToUser($user, 'users.manage');

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect('/backoffice');
    }

    public function test_one_extra_role_permission_grants_backoffice_access(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);

        $this->grantToRole(User::ROLE_INFORMATION_TECHNOLOGY, 'zones.manage');

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk();
    }

    public function test_a_user_with_panel_access_remains_blocked_from_ungranted_modules(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->grantToUser($user, 'users.manage');

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk();

        $this->get(DealershipResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_revoking_the_last_permission_blocks_panel_and_navbar_again(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $grant = $this->grantToUser($user, 'users.manage');

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk();

        $navbarWithAccess = view('components.layout.navbar')->render();
        $this->assertStringContainsString('/backoffice', $navbarWithAccess);

        $grant->update(['is_revoked' => true]);

        $this->get('/backoffice')->assertForbidden();

        $navbarWithoutAccess = view('components.layout.navbar')->render();
        $this->assertStringNotContainsString('/backoffice', $navbarWithoutAccess);
    }

    private function grantToUser(User $user, string $permissionKey): AdminPermissionGrant
    {
        return AdminPermissionGrant::query()->create([
            'permission_key' => $permissionKey,
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }

    private function grantToRole(string $role, string $permissionKey): AdminPermissionGrant
    {
        return AdminPermissionGrant::query()->create([
            'permission_key' => $permissionKey,
            'user_id' => null,
            'group_id' => null,
            'group_role' => $role,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
