<?php

namespace Tests\Feature\Users;

use App\Filament\Pages\AdminPermissionsPage;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentUserResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_manager_without_users_manage_cannot_see_or_open_users_resource(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);
        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
        ]);

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(UserResource::canEdit($target));
        $this->assertFalse(UserResource::canDelete($target));

        $this->get(UserResource::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $target]))->assertForbidden();
    }

    public function test_admin_sees_and_can_open_users_resource_without_an_explicit_grant(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(UserResource::getUrl(), false);

        $this->assertTrue(UserResource::canViewAny());
        $this->get(UserResource::getUrl())->assertOk();
    }

    public function test_granting_and_revoking_users_manage_from_permissions_updates_navigation_and_access(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);

        $this->actingAs($manager);
        $this->get(UserResource::getUrl())->assertForbidden();

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'users.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(UserResource::getUrl(), false);

        $this->get(UserResource::getUrl())->assertOk();

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'users.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        $this->get(UserResource::getUrl())->assertForbidden();
    }
}
