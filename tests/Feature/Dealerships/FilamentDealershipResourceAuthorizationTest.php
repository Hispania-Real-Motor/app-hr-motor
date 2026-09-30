<?php

namespace Tests\Feature\Dealerships;

use App\Filament\Pages\AdminPermissionsPage;
use App\Filament\Resources\Dealerships\DealershipResource;
use App\Models\Dealership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDealershipResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_manager_without_dealership_permission_cannot_see_or_open_dealerships(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);
        $dealership = Dealership::factory()->create();

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        $this->assertFalse(DealershipResource::canViewAny());
        $this->assertFalse(DealershipResource::canCreate());
        $this->assertFalse(DealershipResource::canEdit($dealership));
        $this->assertFalse(DealershipResource::canDelete($dealership));

        $this->get(DealershipResource::getUrl())->assertForbidden();
        $this->get(DealershipResource::getUrl('create'))->assertForbidden();
        $this->get(DealershipResource::getUrl('edit', ['record' => $dealership]))->assertForbidden();
    }

    public function test_admin_can_access_dealerships_without_an_explicit_grant(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(DealershipResource::getUrl(), false);

        $this->assertTrue(DealershipResource::canViewAny());
        $this->get(DealershipResource::getUrl())->assertOk();
    }

    public function test_granting_and_revoking_dealership_permission_updates_navigation_and_access(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);

        $this->actingAs($manager);
        $this->get(DealershipResource::getUrl())->assertForbidden();

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'dealerships.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(DealershipResource::getUrl(), false);

        $this->get(DealershipResource::getUrl())->assertOk();

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'dealerships.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        $this->get(DealershipResource::getUrl())->assertForbidden();
    }
}
