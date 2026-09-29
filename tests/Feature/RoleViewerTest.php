<?php

namespace Tests\Feature;

use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_admin_can_switch_role_view_and_return_to_admin(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Visor de roles')
            ->assertSee(route('role-viewer.store'), false)
            ->assertDontSee('Volver a admin')
            ->getContent();

        foreach ([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_USER] as $baseRole) {
            $this->assertStringNotContainsString('name="role" value="' . $baseRole . '"', $html);
        }

        $this->assertSame(array_keys(User::extraRoleLabels()), array_keys(app_role_viewer_options($admin)));
        $this->assertNotContains(User::ROLE_ADMIN, array_keys(app_role_viewer_options($admin)));
        $this->assertNotContains(User::ROLE_MANAGER, array_keys(app_role_viewer_options($admin)));
        $this->assertNotContains(User::ROLE_USER, array_keys(app_role_viewer_options($admin)));

        $this->from(route('home'))
            ->post(route('role-viewer.store'), [
                'role' => User::ROLE_COMMERCIAL,
            ])
            ->assertRedirect(route('home'));

        $this->assertSame(User::ROLE_COMMERCIAL, session('role_viewer.active_role'));

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Volver a mi rol')
            ->assertSee('Comercial');

        $this->from(route('home'))
            ->delete(route('role-viewer.destroy'))
            ->assertRedirect(route('home'));

        $this->assertNull(session('role_viewer.active_role'));

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Visor de roles')
            ->assertDontSee('Volver a admin')
            ->assertDontSee('Volver a mi rol');
    }

    public function test_admin_role_viewer_places_hr_newcars_last(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin-order@example.com',
        ]);

        $html = $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $rentingPosition = strrpos($html, 'Renting');
        $hrNewcarsPosition = strrpos($html, 'HR NewCars');

        $this->assertNotFalse($rentingPosition);
        $this->assertNotFalse($hrNewcarsPosition);
        $this->assertGreaterThan($rentingPosition, $hrNewcarsPosition);
    }

    public function test_non_admin_users_do_not_see_or_use_the_role_viewer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'email' => 'commercial@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Visor de roles');

        $this->actingAs($user)
            ->post(route('role-viewer.store'), [
                'role' => User::ROLE_COMMERCIAL,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('role-viewer.destroy'))
            ->assertForbidden();
    }

    public function test_manager_with_an_informatica_role_grant_can_use_the_role_viewer(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'roles.view',
            'user_id' => null,
            'group_id' => null,
            'group_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($manager)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Visor de roles');

        $this->assertSame(array_keys(User::extraRoleLabels()), array_keys(app_role_viewer_options($manager)));
        $this->assertNotContains(User::ROLE_ADMIN, array_keys(app_role_viewer_options($manager)));
        $this->assertNotContains(User::ROLE_MANAGER, array_keys(app_role_viewer_options($manager)));
        $this->assertNotContains(User::ROLE_USER, array_keys(app_role_viewer_options($manager)));

        $this->post(route('role-viewer.store'), ['role' => User::ROLE_MANAGER])
            ->assertRedirect(route('home'));

        $this->post(route('role-viewer.store'), ['role' => User::ROLE_ADMIN])
            ->assertSessionHasErrors('role');
    }

    public function test_user_with_direct_roles_view_permission_can_view_extra_roles_but_not_base_roles(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'roles.view',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertSee('Visor de roles');

        $this->post(route('role-viewer.store'), ['role' => User::ROLE_COMMERCIAL])
            ->assertRedirect(route('home'));

        $this->post(route('role-viewer.store'), ['role' => User::ROLE_MANAGER])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_COMMERCIAL, session('role_viewer.active_role'));
    }

    public function test_revoking_roles_view_hides_and_blocks_the_role_viewer(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $grant = AdminPermissionGrant::query()->create([
            'permission_key' => 'roles.view',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($manager)
            ->get(route('home'))
            ->assertSee('Visor de roles');

        $grant->update(['is_revoked' => true]);

        $this->get(route('home'))
            ->assertDontSee('Visor de roles');

        $this->post(route('role-viewer.store'), ['role' => User::ROLE_MANAGER])
            ->assertForbidden();
    }
}
