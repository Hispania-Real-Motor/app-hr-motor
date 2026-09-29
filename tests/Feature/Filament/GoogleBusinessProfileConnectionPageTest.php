<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\GoogleBusinessProfileConnectionPage;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GoogleBusinessProfileConnectionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('services.google_business_profile.client_id', 'test-client-id');
        config()->set('services.google_business_profile.authorize_url', 'https://accounts.google.test/o/oauth2/auth');
    }

    public function test_reviews_view_only_can_read_reviews_but_cannot_manage_google_connection(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $this->grant($user, 'reviews.view');

        $this->actingAs($user)
            ->get(route('reviews.index'))
            ->assertOk()
            ->assertDontSee('Conectar Google')
            ->assertDontSee('Reconectar con Google');

        $this->get('/backoffice')->assertForbidden();
        $this->get(GoogleBusinessProfileConnectionPage::getUrl())->assertForbidden();
        $this->get(route('google-business-profile.connect'))->assertForbidden();
        $this->get(route('google-business-profile.callback', ['state' => 'invalid', 'code' => 'test']))->assertForbidden();
    }

    public function test_direct_google_connection_permission_grants_backoffice_access_and_page_navigation(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $this->grant($user, 'reviews.google.manage');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Admin');

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee(GoogleBusinessProfileConnectionPage::getUrl(), false)
            ->assertSee('Conexión Google Reseñas');

        $this->get(GoogleBusinessProfileConnectionPage::getUrl())
            ->assertOk()
            ->assertSee('Conectar con Google');

        $this->get(route('google-business-profile.connect'))
            ->assertRedirect()
            ->assertRedirectContains('accounts.google.test/o/oauth2/auth');
    }

    public function test_profile_grant_and_revocation_are_respected_for_google_connection(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);
        $this->grantToRole(User::ROLE_MANAGER, 'reviews.google.manage');

        $this->actingAs($user)
            ->get(GoogleBusinessProfileConnectionPage::getUrl())
            ->assertOk();

        AdminPermissionGrant::query()
            ->where('group_role', User::ROLE_MANAGER)
            ->where('permission_key', 'reviews.google.manage')
            ->update(['is_revoked' => true]);

        $this->get('/backoffice')->assertForbidden();
        $this->get(GoogleBusinessProfileConnectionPage::getUrl())->assertForbidden();
        $this->get(route('google-business-profile.connect'))->assertForbidden();
    }

    public function test_google_connection_permission_is_backoffice_scoped_and_reviews_view_is_not(): void
    {
        $definitions = app_admin_permission_definitions();

        $this->assertSame('backoffice', $definitions['reviews.google.manage']['scope']);
        $this->assertSame('application', $definitions['reviews.view']['scope']);
        $this->assertContains('reviews.google.manage', app_backoffice_permission_keys());
        $this->assertNotContains('reviews.view', app_backoffice_permission_keys());
    }

    public function test_google_connection_permission_is_listed_under_reviews_in_the_permissions_panel(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin);

        $groups = Livewire::test(\App\Filament\Pages\AdminPermissionsPage::class)
            ->instance()
            ->permissionGroups();

        $this->assertArrayHasKey('Reseñas', $groups);
        $this->assertArrayHasKey('reviews.view', collect($groups['Reseñas'])->keyBy('key')->all());
        $this->assertArrayHasKey('reviews.google.manage', collect($groups['Reseñas'])->keyBy('key')->all());
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

    private function grantToRole(string $role, string $permissionKey): void
    {
        AdminPermissionGrant::query()->create([
            'permission_key' => $permissionKey,
            'user_id' => null,
            'group_id' => null,
            'group_role' => $role,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
