<?php

namespace Tests\Feature\Notifications;

use App\Filament\Pages\AdminNotificationsPage;
use App\Filament\Pages\AdminNotificationsLogsPage;
use App\Models\AdminPermissionGrant;
use App\Models\User;
use App\Notifications\AdminPriorityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminNotificationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_users_without_notifications_permission_cannot_access_the_filament_notification_creator(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get(AdminNotificationsPage::getUrl())
            ->assertForbidden();
    }

    public function test_informatica_extra_role_grant_shows_and_allows_notifications(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'notifications.manage',
            'user_id' => null,
            'group_id' => null,
            'group_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user);

        $this->assertTrue(app_user_has_admin_permission($user, 'notifications.manage'));
        $this->assertTrue(AdminNotificationsPage::canAccess());

        $this->get('/backoffice')
            ->assertOk()
            ->assertSee('Notificaciones');

        $this->get(AdminNotificationsPage::getUrl())
            ->assertOk();
    }

    public function test_user_with_backoffice_access_but_without_notifications_permission_cannot_see_or_open_notifications(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'extra_role' => null,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'users.manage',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get('/backoffice')
            ->assertOk()
            ->assertDontSee('Notificaciones');

        $this->get(AdminNotificationsPage::getUrl())
            ->assertForbidden();
    }

    public function test_admin_can_create_a_notification_for_selected_extra_roles(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'extra_role' => null,
            'is_active' => true,
        ]);
        $commercial = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_COMMERCIAL,
            'is_active' => true,
        ]);
        $marketing = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => User::ROLE_MARKETING,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin);

        Livewire::test(AdminNotificationsPage::class)
            ->set('data.title', 'Aviso importante')
            ->set('data.description', 'Mensaje para comerciales.')
            ->set('data.link_url', 'https://example.com/aviso')
            ->set('data.target_roles', [User::ROLE_COMMERCIAL])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($commercial, AdminPriorityNotification::class);
        Notification::assertNotSentTo($marketing, AdminPriorityNotification::class);

        $this->assertDatabaseHas('notification_activity_logs', [
            'title' => 'Aviso importante',
            'recipient_count' => 1,
        ]);
    }

    public function test_all_users_is_available_before_the_extra_roles(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(AdminNotificationsPage::getUrl())
            ->assertOk()
            ->assertSeeInOrder([
                'Todos los usuarios',
                User::extraRoleLabels()[User::ROLE_COMMERCIAL],
            ]);
    }

    public function test_admin_can_open_the_notification_log_from_its_filament_interior(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(AdminNotificationsPage::getUrl())
            ->assertOk()
            ->assertSee(AdminNotificationsLogsPage::getUrl());

        $this->actingAs($admin)
            ->get(AdminNotificationsLogsPage::getUrl())
            ->assertOk();
    }

    public function test_admin_can_send_a_notification_to_all_users(): void
    {
        Notification::fake();

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'extra_role' => null,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin);

        Livewire::test(AdminNotificationsPage::class)
            ->set('data.title', 'Aviso general')
            ->set('data.description', 'Mensaje para toda la aplicación.')
            ->set('data.target_roles', [AdminNotificationsPage::TARGET_ALL_USERS])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertSentTo($recipient, AdminPriorityNotification::class);
    }
}
