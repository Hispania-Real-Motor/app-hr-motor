<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\RankingsPage;
use App\Filament\Pages\ConversationAccessPage;
use App\Models\AdminPermissionGrant;
use App\Services\LeaderboardSyncService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class RankingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_only_admins_can_see_and_open_the_rankings_page(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(RankingsPage::getUrl())
            ->assertOk()
            ->assertSee('href="' . RankingsPage::getUrl() . '"', false)
            ->assertSee('Rankings')
            ->assertSee('Actualizar rankings')
            ->assertSee('wire:loading.attr="disabled"', false)
            ->assertSeeInOrder([
                'href="' . ConversationAccessPage::getUrl() . '"',
                'href="' . RankingsPage::getUrl() . '"',
            ], false);

        $this->actingAs($manager)
            ->get(RankingsPage::getUrl())
            ->assertForbidden();

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(RankingsPage::getUrl(), false);
        $this->post(route('leaderboard.sync'))->assertForbidden();
    }

    public function test_manager_with_inherited_permission_sees_rankings_and_can_sync_them(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        $this->grantPermissionToRole(User::ROLE_MANAGER);
        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldReceive('hasSalesforceConnection')->once()->andReturnTrue();
        $service->shouldReceive('sync')->once();

        $this->actingAs($manager)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('/backoffice')
            ->assertSee('Admin');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee(RankingsPage::getUrl(), false)
            ->assertSee('Rankings')
            ->assertDontSee('/backoffice/usuarios');

        $this->get(RankingsPage::getUrl())
            ->assertOk()
            ->assertSee('Actualizar rankings');

        Livewire::actingAs($manager);

        Livewire::test(RankingsPage::class)
            ->call('syncRankings')
            ->assertNotified('Rankings de ventas, compras y coches actualizados correctamente.');
    }

    public function test_user_with_direct_permission_can_open_and_sync_rankings(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'backoffice.rankings.manage',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldReceive('hasSalesforceConnection')->once()->andReturnFalse();
        $service->shouldNotReceive('sync');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Admin');

        $this->get(RankingsPage::getUrl())
            ->assertOk()
            ->assertSee('Rankings');

        Livewire::actingAs($user);

        Livewire::test(RankingsPage::class)
            ->call('syncRankings')
            ->assertNotified('Salesforce todavia no esta conectado. La app sigue operativa, pero el leaderboard no puede sincronizarse hasta completar la autorizacion.');
    }

    public function test_admin_can_update_rankings_and_receives_a_success_notification(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldReceive('hasSalesforceConnection')->once()->andReturnTrue();
        $service->shouldReceive('sync')->once();

        Livewire::actingAs($admin);

        Livewire::test(RankingsPage::class)
            ->call('syncRankings')
            ->assertNotified('Rankings de ventas, compras y coches actualizados correctamente.');
    }

    public function test_rankings_update_shows_a_controlled_error_notification_when_it_fails(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldReceive('hasSalesforceConnection')->once()->andReturnTrue();
        $service->shouldReceive('sync')->once()->andThrow(new RuntimeException('Salesforce unavailable'));

        Livewire::actingAs($admin);

        Livewire::test(RankingsPage::class)
            ->call('syncRankings')
            ->assertNotified('No se han podido sincronizar los rankings con Salesforce. Revisa la conexion o las consultas configuradas.');
    }

    public function test_rankings_update_does_not_run_again_while_the_page_is_syncing(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldNotReceive('hasSalesforceConnection');
        $service->shouldNotReceive('sync');

        Livewire::actingAs($admin);

        Livewire::test(RankingsPage::class)
            ->set('isSyncing', true)
            ->call('syncRankings');
    }

    public function test_legacy_admin_rankings_button_still_uses_the_shared_sync_operation(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldReceive('hasSalesforceConnection')->once()->andReturnTrue();
        $service->shouldReceive('sync')->once();

        $this->from('/backoffice')
            ->actingAs($admin)
            ->post(route('leaderboard.sync'))
            ->assertRedirect('/backoffice')
            ->assertSessionHas('success', 'Rankings de ventas, compras y coches actualizados correctamente.');
    }

    public function test_rankings_page_does_not_start_a_second_update_when_the_shared_lock_is_held(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldNotReceive('hasSalesforceConnection');
        $service->shouldNotReceive('sync');

        $lock = Cache::lock('leaderboard-sync', 600);
        $this->assertTrue($lock->get());

        try {
            Livewire::actingAs($admin);

            Livewire::test(RankingsPage::class)
                ->call('syncRankings')
                ->assertNotified('Ya hay una actualización de rankings en curso.');
        } finally {
            $lock->release();
        }
    }

    private function grantPermissionToRole(string $role): AdminPermissionGrant
    {
        return AdminPermissionGrant::query()->create([
            'permission_key' => 'backoffice.rankings.manage',
            'user_id' => null,
            'group_id' => null,
            'group_role' => $role,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
