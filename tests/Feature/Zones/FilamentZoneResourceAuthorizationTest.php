<?php

namespace Tests\Feature\Zones;

use App\Filament\Pages\AdminPermissionsPage;
use App\Filament\Resources\Zones\ZoneResource;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentZoneResourceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_manager_without_zones_manage_cannot_see_or_open_zones(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);
        $zone = Zone::query()->create(['name' => 'Zona de prueba']);

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        $this->assertFalse(ZoneResource::canViewAny());
        $this->assertFalse(ZoneResource::canCreate());
        $this->assertFalse(ZoneResource::canEdit($zone));
        $this->assertFalse(ZoneResource::canDelete($zone));

        $this->get(ZoneResource::getUrl())->assertForbidden();
        $this->get(ZoneResource::getUrl('create'))->assertForbidden();
        $this->get(ZoneResource::getUrl('edit', ['record' => $zone]))->assertForbidden();
    }

    public function test_admin_can_access_zones_without_an_explicit_grant(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(ZoneResource::getUrl(), false);

        $this->assertTrue(ZoneResource::canViewAny());
        $this->get(ZoneResource::getUrl())->assertOk();
    }

    public function test_granting_and_revoking_zones_manage_updates_navigation_and_access(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
        ]);

        $this->actingAs($manager);
        $this->get(ZoneResource::getUrl())->assertForbidden();

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'zones.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertOk()
            ->assertSee(ZoneResource::getUrl(), false);

        $this->get(ZoneResource::getUrl())->assertOk();

        Livewire::actingAs($admin);

        Livewire::test(AdminPermissionsPage::class)
            ->call('selectProfile', User::ROLE_MANAGER)
            ->call('toggleProfilePermission', 'zones.manage')
            ->call('saveProfilePermissions')
            ->assertNotified('Permisos del perfil actualizados.');

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        $this->get(ZoneResource::getUrl())->assertForbidden();
    }
}
