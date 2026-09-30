<?php

namespace Tests\Feature;

use App\Filament\Pages\RankingsPage;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use App\Services\LeaderboardSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RankingsPermissionSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_rankings_permission_controls_real_public_html_and_routes_without_backoffice_access(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'is_active' => true,
        ]);
        $this->grantToUser($user, 'rankings.view');

        $this->actingAs($user)
            ->get(route('leaderboard.sales'))
            ->assertOk();

        $navbar = view('components.layout.navbar')->render();
        $this->assertStringContainsString(route('leaderboard.sales'), $navbar);
        $this->assertStringContainsString('Rankings', $navbar);

        $this->get('/backoffice')->assertForbidden();
        $this->get(RankingsPage::getUrl())->assertForbidden();
        $this->post(route('leaderboard.sync'))->assertForbidden();
    }

    public function test_backoffice_permission_controls_filament_and_salesforce_without_granting_public_rankings(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'is_active' => true,
        ]);
        $this->grantToUser($user, 'backoffice.rankings.manage');

        $service = $this->mock(LeaderboardSyncService::class);
        $service->shouldReceive('hasSalesforceConnection')->once()->andReturnFalse();
        $service->shouldNotReceive('sync');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Admin');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee(RankingsPage::getUrl(), false)
            ->assertSee('Rankings');

        $this->get(RankingsPage::getUrl())
            ->assertOk()
            ->assertSee('Actualizar rankings');

        Livewire::actingAs($user);
        Livewire::test(RankingsPage::class)
            ->call('syncRankings')
            ->assertNotified('Salesforce todavia no esta conectado. La app sigue operativa, pero el leaderboard no puede sincronizarse hasta completar la autorizacion.');

        $this->get(route('leaderboard.sales'))->assertForbidden();
        $this->assertStringNotContainsString(route('leaderboard.sales'), view('components.layout.navbar')->render());
    }

    public function test_user_with_both_permissions_can_use_public_and_backoffice_rankings(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $this->grantToUser($user, 'rankings.view');
        $this->grantToUser($user, 'backoffice.rankings.manage');

        $this->actingAs($user)
            ->get(route('leaderboard.sales'))
            ->assertOk();

        $this->get(RankingsPage::getUrl())->assertOk();
        $this->assertStringContainsString(route('leaderboard.sales'), view('components.layout.navbar')->render());
    }

    public function test_user_without_rankings_permissions_cannot_open_either_surface(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->get(route('leaderboard.sales'))->assertForbidden();
        $this->get(RankingsPage::getUrl())->assertForbidden();
        $this->get('/backoffice')->assertForbidden();

        $navbar = view('components.layout.navbar')->render();
        $this->assertStringNotContainsString(route('leaderboard.sales'), $navbar);
        $this->assertStringNotContainsString('Admin', $navbar);
    }

    public function test_revocation_overrides_an_inherited_public_rankings_grant(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'is_active' => true,
        ]);
        $this->grantToRole(User::ROLE_COMMERCIAL, 'rankings.view');
        $this->grantToRole(User::ROLE_MANAGER, 'rankings.view', true);

        $this->actingAs($user)
            ->get(route('leaderboard.sales'))
            ->assertForbidden();

        $this->assertStringNotContainsString(route('leaderboard.sales'), view('components.layout.navbar')->render());
    }

    public function test_permission_definitions_keep_public_and_backoffice_rankings_separate(): void
    {
        $definitions = app_admin_permission_definitions();

        $this->assertSame('application', $definitions['rankings.view']['scope']);
        $this->assertSame('backoffice', $definitions['backoffice.rankings.manage']['scope']);
        $this->assertNotContains('rankings.view', app_backoffice_permission_keys());
        $this->assertContains('backoffice.rankings.manage', app_backoffice_permission_keys());
        $this->assertSame(
            'Permite consultar los rankings públicos de la aplicación.',
            $definitions['rankings.view']['description'],
        );
        $this->assertSame(
            'Permite acceder a Rankings en el backoffice y recargar los datos desde Salesforce.',
            $definitions['backoffice.rankings.manage']['description'],
        );
    }

    private function grantToUser(User $user, string $permissionKey): void
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

    private function grantToRole(string $role, string $permissionKey, bool $revoked = false): void
    {
        AdminPermissionGrant::query()->updateOrCreate(
            [
                'permission_key' => $permissionKey,
                'group_role' => $role,
            ],
            [
                'user_id' => null,
                'group_id' => null,
                'is_revoked' => $revoked,
                'granted_by_user_id' => null,
            ],
        );
    }
}
