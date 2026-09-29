<?php

namespace Tests\Feature;

use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_marketing_user_sees_reviews_in_the_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MARKETING,
            'email' => 'marketing@example.com',
        ]);

        $this->actingAs($user);

        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString(route('reviews.index'), $footerHtml);
    }

    public function test_admin_user_keeps_the_global_reviews_bypass_in_the_navbar(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringContainsString(route('reviews.index'), $navbarHtml);
    }

    public function test_navbar_does_not_include_the_web_interior_anymore(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'web-nav-check@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringNotContainsString(route('tools.web'), $navbarHtml);
        $this->assertStringNotContainsString('Web HR Motor', $navbarHtml);
    }

    public function test_admin_in_role_viewer_mode_sees_admin_nav_when_the_visible_role_has_admin_access(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin-viewer@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'notifications.manage',
            'user_id' => null,
            'group_id' => null,
            'group_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($admin)
            ->withSession(['role_viewer.active_role' => User::ROLE_INFORMATION_TECHNOLOGY]);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringNotContainsString('/admin', $navbarHtml);
        $this->assertStringContainsString('/backoffice', $navbarHtml);
    }

    public function test_manager_with_a_backoffice_permission_sees_the_admin_backoffice_link_in_the_navbar(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'manager-backoffice@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'users.manage',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($manager);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringContainsString('/backoffice', $navbarHtml);
        $this->assertStringNotContainsString('/admin', $navbarHtml);
        $this->assertStringNotContainsString(route('reviews.index'), $navbarHtml);
    }

    public function test_manager_without_backoffice_permissions_does_not_see_the_admin_link(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'manager-without-backoffice@example.com',
        ]);

        $this->actingAs($manager);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringNotContainsString('/backoffice', $navbarHtml);
    }

    public function test_hr_report_permission_shows_informes_hr_in_the_navbar_and_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGEMENT,
            'email' => 'gerencia@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString(route('tools.informes'), $navbarHtml);
        $this->assertStringContainsString(route('tools.informes'), $footerHtml);
        $this->assertStringContainsString('Informes HR', $navbarHtml);
        $this->assertStringNotContainsString('>Informes</a>', $navbarHtml);
    }

    public function test_regular_user_does_not_see_informes_in_the_navbar_or_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'email' => 'comercial@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringNotContainsString(route('tools.informes'), $navbarHtml);
        $this->assertStringNotContainsString(route('tools.informes'), $footerHtml);
    }

    public function test_all_users_see_the_it_support_interior_link_in_the_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'email' => 'it-support-link@example.com',
        ]);

        $this->actingAs($user);

        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString(route('it-tickets.index'), $footerHtml);
    }

    public function test_it_extra_role_users_see_tickets_in_the_navbar_and_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'email' => 'it-tickets@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString(route('tickets.index'), $navbarHtml);
        $this->assertStringContainsString(route('tickets.index'), $footerHtml);
    }

    public function test_admin_sees_tickets_without_an_explicit_ticket_permission(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin-no-ticket-permission@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString('href="' . route('tickets.index') . '"', $navbarHtml);
        $this->assertStringContainsString('href="' . route('tickets.index') . '"', $footerHtml);
        $this->get(route('tickets.index'))->assertOk();
    }

    public function test_admin_with_ticket_permission_but_without_it_role_can_see_tickets(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin-with-ticket-permission@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'tickets-it.assign',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString('href="' . route('tickets.index') . '"', $navbarHtml);
        $this->assertStringContainsString('href="' . route('tickets.index') . '"', $footerHtml);
        $this->get(route('tickets.index'))->assertOk();
    }

    public function test_regular_users_do_not_see_tickets_in_the_navbar_or_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'email' => 'regular-tickets@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringNotContainsString(route('tickets.index'), $navbarHtml);
        $this->assertStringNotContainsString(route('tickets.index'), $footerHtml);
    }

    public function test_curricula_permission_shows_curriculums_in_the_navbar_and_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_HUMAN_RESOURCES,
            'email' => 'rrhh@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString(route('curriculums.index'), $navbarHtml);
        $this->assertStringContainsString(route('curriculums.index'), $footerHtml);
        $this->assertStringContainsString('Currículums', $navbarHtml);
        $this->assertStringContainsString('Currículums', $footerHtml);

        $directUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'email' => 'direct-curriculums@example.com',
        ]);
        AdminPermissionGrant::query()->create([
            'permission_key' => 'curricula.view',
            'user_id' => $directUser->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);

        $this->actingAs($directUser);
        $directNavbar = view('components.layout.navbar')->render();

        $this->assertStringContainsString(route('curriculums.index'), $directNavbar);
        $this->get(route('curriculums.index'))->assertOk();

        $noExtraRoleUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'email' => 'no-extra-curriculums@example.com',
        ]);
        AdminPermissionGrant::query()->create([
            'permission_key' => 'curricula.view',
            'user_id' => $noExtraRoleUser->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);

        $this->actingAs($noExtraRoleUser);
        $this->assertStringContainsString(route('curriculums.index'), view('components.layout.navbar')->render());
        $this->get(route('curriculums.index'))->assertOk();
    }

    public function test_regular_user_does_not_see_curriculums_in_the_navbar_or_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'email' => 'comercial-curriculums@example.com',
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();
        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringNotContainsString(route('curriculums.index'), $navbarHtml);
        $this->assertStringNotContainsString(route('curriculums.index'), $footerHtml);
    }

    public function test_all_users_see_quienes_somos_in_the_footer(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'footer-empresa@example.com',
            'extra_role' => null,
        ]);

        $this->actingAs($user);

        $footerHtml = view('components.layout.footer')->render();

        $this->assertStringContainsString(route('empresa.index'), $footerHtml);
        $this->assertStringContainsString('Quiénes somos', $footerHtml);
    }

    public function test_users_with_video_access_see_videos_and_quienes_somos_under_empresa_in_the_navbar(): void
    {
        foreach ([User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'email' => strtolower(str_replace(' ', '-', $role)) . '@example.com',
            ]);

            $this->actingAs($user);

            $navbarHtml = view('components.layout.navbar')->render();

            $this->assertStringContainsString('Empresa', $navbarHtml);
            $this->assertStringContainsString(route('videos'), $navbarHtml);
            $this->assertStringContainsString(route('empresa.index'), $navbarHtml);
            $this->assertStringContainsString('Quiénes somos', $navbarHtml);
        }
    }

    public function test_users_without_video_access_see_quienes_somos_under_empresa_in_the_navbar(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'empresa@example.com',
            'extra_role' => null,
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringContainsString('Empresa', $navbarHtml);
        $this->assertStringContainsString(route('empresa.index'), $navbarHtml);
        $this->assertStringContainsString('Quiénes somos', $navbarHtml);
        $this->assertStringNotContainsString(route('videos'), $navbarHtml);
    }

    public function test_videos_permission_grant_controls_real_navbar_and_route_without_granting_backoffice(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'videos-direct@example.com',
            'extra_role' => null,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'videos.view',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertTrue(app_can_access_videos($user));
        $this->assertStringContainsString('Vídeos', $navbarHtml);
        $this->assertStringContainsString(route('videos'), $navbarHtml);
        $this->get(route('videos'))->assertOk()->assertSee('Vídeos');
        $this->assertFalse(app_user_has_any_admin_permission($user));
        $this->assertStringNotContainsString('/backoffice', $navbarHtml);
        $this->get('/backoffice')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_videos_route_and_navbar_are_denied_without_effective_permission(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'videos-denied@example.com',
            'extra_role' => null,
        ]);

        $this->actingAs($user);

        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertFalse(app_can_access_videos($user));
        $this->assertStringNotContainsString(route('videos'), $navbarHtml);
        $this->get(route('videos'))->assertForbidden();
    }

    public function test_empresa_page_is_visible_for_authenticated_users(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'empresa-page@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('empresa.index'))
            ->assertOk()
            ->assertSee('Quiénes somos HR Motor', false)
            ->assertSee('Mapa', false);
    }

    public function test_hr_report_permission_controls_real_page_access_and_stays_out_of_backoffice(): void
    {
        $allowedUser = User::factory()->create([
            'role' => User::ROLE_MANAGEMENT,
            'email' => 'gerencia2@example.com',
        ]);

        $this->actingAs($allowedUser)
            ->get(route('tools.informes'))
            ->assertOk()
            ->assertSee('Informes HR');

        $areaManager = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_AREA_MANAGER,
            'email' => 'area-manager-reports@example.com',
        ]);

        $this->actingAs($areaManager)
            ->get(route('tools.informes'))
            ->assertOk();

        $directUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'email' => 'direct-reports@example.com',
        ]);
        AdminPermissionGrant::query()->create([
            'permission_key' => 'reports.hr.view',
            'user_id' => $directUser->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);

        $this->actingAs($directUser);
        $navbarHtml = view('components.layout.navbar')->render();

        $this->assertStringContainsString('Informes HR', $navbarHtml);
        $this->get(route('tools.informes'))->assertOk();
        $this->get('/backoffice')->assertForbidden();
        $this->get('/admin')->assertForbidden();

        $extraRoleUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'email' => 'extra-role-reviews@example.com',
        ]);
        AdminPermissionGrant::query()->create([
            'permission_key' => 'reviews.view',
            'user_id' => $extraRoleUser->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);

        $this->actingAs($extraRoleUser);
        $extraRoleNavbar = view('components.layout.navbar')->render();

        $this->assertStringContainsString('Reseñas', $extraRoleNavbar);
        $this->get(route('reviews.index'))->assertOk();

        $deniedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'email' => 'comercial2@example.com',
        ]);

        $this->actingAs($deniedUser)
            ->get(route('tools.informes'))
            ->assertForbidden();

        $deniedNavbar = view('components.layout.navbar')->render();
        $this->assertStringNotContainsString(route('tools.informes'), $deniedNavbar);
        $this->assertStringNotContainsString('Informes HR', $deniedNavbar);
    }

    public function test_curricula_permission_controls_page_access_and_stays_out_of_backoffice(): void
    {
        $allowedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_HUMAN_RESOURCES,
            'email' => 'rrhh2@example.com',
        ]);

        $this->actingAs($allowedUser)
            ->get(route('curriculums.index'))
            ->assertOk();

        $this->assertStringContainsString(route('curriculums.index'), view('components.layout.navbar')->render());
        $this->get('/backoffice')->assertForbidden();
        $this->get('/admin')->assertForbidden();

        $deniedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'email' => 'comercial3@example.com',
        ]);

        $this->actingAs($deniedUser)
            ->get(route('curriculums.index'))
            ->assertForbidden();

        $this->assertStringNotContainsString(route('curriculums.index'), view('components.layout.navbar')->render());

        $backofficeUser = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
            'email' => 'backoffice-without-curriculums@example.com',
        ]);
        AdminPermissionGrant::query()->create([
            'permission_key' => 'users.manage',
            'user_id' => $backofficeUser->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
        ]);

        $this->actingAs($backofficeUser);
        $this->get('/backoffice')->assertOk();
        $this->assertStringNotContainsString(route('curriculums.index'), view('components.layout.navbar')->render());
    }
}
