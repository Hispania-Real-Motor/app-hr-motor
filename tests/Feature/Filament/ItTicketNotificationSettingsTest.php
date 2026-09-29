<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ItTicketNotificationSettingsPage;
use App\Mail\ItTicketCreatedMail;
use App\Models\ItTicket;
use App\Models\ItTicketNotificationSetting;
use App\Models\ItTicketNotificationSettingActivityLog;
use App\Models\AdminPermissionGrant;
use App\Models\TicketTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ItTicketNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_admin_sees_the_default_recipients_in_the_filament_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)
            ->get(ItTicketNotificationSettingsPage::getUrl())
            ->assertOk()
            ->assertSee('Correo Tickets IT')
            ->assertSee('Destinatarios de los avisos de nuevos tickets.')
            ->assertSee('Escribe un correo y pulsa Enter')
            ->assertSee('Pulsa Enter para confirmar cada dirección.')
            ->assertDontSee('separándolas con comas')
            ->assertSee('Administración')
            ->assertDontSee('Destinatarios Tickets IT')
            ->assertSee(ItTicketNotificationSetting::DEFAULT_RECIPIENTS[0])
            ->assertSee(ItTicketNotificationSetting::DEFAULT_RECIPIENTS[1]);

        $this->assertStringContainsString('tickets-it-configuracion', ItTicketNotificationSettingsPage::getUrl());
        $this->assertStringNotContainsString('tickets-it-destinatarios', ItTicketNotificationSettingsPage::getUrl());

        $this->assertSame(ItTicketNotificationSetting::DEFAULT_RECIPIENTS, ItTicketNotificationSetting::recipients());
    }

    public function test_admin_can_normalize_edit_and_audit_unique_recipients(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->set('data.recipients', ['  New.One@Example.com ', 'new.two@example.com'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([
            'new.one@example.com',
            'new.two@example.com',
        ], ItTicketNotificationSetting::recipients());

        $this->assertDatabaseHas('it_ticket_notification_setting_activity_logs', [
            'action' => ItTicketNotificationSettingActivityLog::ACTION_UPDATED,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_invalid_duplicate_and_empty_recipient_lists_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        Livewire::actingAs($admin);

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->set('data.recipients', ['not-an-email'])
            ->call('save')
            ->assertHasErrors();

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->set('data.recipients', ['same@example.com', ' SAME@example.com '])
            ->call('save')
            ->assertHasErrors();

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->set('data.recipients', [])
            ->call('save')
            ->assertHasErrors();

        $this->assertSame(ItTicketNotificationSetting::DEFAULT_RECIPIENTS, ItTicketNotificationSetting::recipients());
    }

    public function test_new_ticket_uses_only_the_configured_recipients(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
        ItTicketNotificationSetting::query()->update([
            'recipients' => ['new.one@example.com', 'new.two@example.com'],
            'updated_by_user_id' => $admin->id,
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_COMMERCIAL,
            'name' => 'Usuario de prueba',
            'email' => 'usuario-ticket@example.com',
        ]);
        $tool = TicketTool::query()->create(['name' => 'Salesforce', 'color' => '#1d4ed8']);

        $this->actingAs($user)
            ->post(route('it-tickets.store'), [
                'submission_token' => (string) Str::uuid(),
                'tool' => (string) $tool->id,
                'priority' => 'high',
                'title' => 'Aviso configurable',
                'description' => 'Comprueba los destinatarios configurados.',
                'screenshots' => [],
            ])
            ->assertRedirect(route('it-tickets.index'));

        Mail::assertSent(ItTicketCreatedMail::class, function (ItTicketCreatedMail $mail): bool {
            $oldRecipients = ItTicketNotificationSetting::DEFAULT_RECIPIENTS;

            return $mail->hasTo('new.one@example.com')
                && $mail->hasCc('new.two@example.com')
                && ! $mail->hasTo($oldRecipients[0])
                && ! $mail->hasCc($oldRecipients[0])
                && ! $mail->hasTo($oldRecipients[1])
                && ! $mail->hasCc($oldRecipients[1]);
        });
    }

    public function test_non_admin_cannot_consult_or_modify_the_settings(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get(ItTicketNotificationSettingsPage::getUrl())
            ->assertForbidden();

        Livewire::actingAs($manager);

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->assertForbidden();

        $this->get('/backoffice')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->assertFalse(app_user_has_admin_permission($manager, 'tickets-it.assign'));
        $this->assertFalse(app_user_has_admin_permission($manager, 'tickets-it.reports.view'));
        $this->assertFalse($manager->canAccessPanel(app(\Filament\Panel::class)));
    }

    public function test_profile_grant_allows_backoffice_and_recipient_management_without_other_modules(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'backoffice.tickets-it-recipients.manage',
            'group_role' => User::ROLE_MANAGER,
            'user_id' => null,
            'group_id' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->assertTrue(app_user_has_admin_permission($manager, 'backoffice.tickets-it-recipients.manage'));
        $this->assertTrue(app_user_can_access_admin_panel($manager));
        $this->assertFalse(app_user_has_admin_permission($manager, 'tickets-it.assign'));
        $this->assertFalse(app_user_has_admin_permission($manager, 'tickets-it.reports.view'));

        $this->actingAs($manager)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('/backoffice')
            ->assertSee('Admin');

        $this->get('/backoffice')->assertOk();
        $this->get(ItTicketNotificationSettingsPage::getUrl())
            ->assertOk()
            ->assertSee('Correo Tickets IT');

        Livewire::actingAs($manager);

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->set('data.recipients', ['profile@example.com'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['profile@example.com'], ItTicketNotificationSetting::recipients());
        $this->get(\App\Filament\Resources\Users\UserResource::getUrl('index'))->assertForbidden();
    }

    public function test_direct_grant_allows_backoffice_and_recipient_management(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'backoffice.tickets-it-recipients.manage',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->assertTrue(app_user_has_admin_permission($manager, 'backoffice.tickets-it-recipients.manage'));

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertOk();

        Livewire::actingAs($manager);

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->set('data.recipients', ['direct@example.com'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['direct@example.com'], ItTicketNotificationSetting::recipients());
    }

    public function test_revoked_recipient_management_grant_blocks_page_and_save(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'backoffice.tickets-it-recipients.manage',
            'user_id' => $manager->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => true,
            'granted_by_user_id' => null,
        ]);

        $this->assertFalse(app_user_has_admin_permission($manager, 'backoffice.tickets-it-recipients.manage'));

        $this->actingAs($manager)
            ->get('/backoffice')
            ->assertForbidden();

        Livewire::actingAs($manager);

        Livewire::test(ItTicketNotificationSettingsPage::class)
            ->assertForbidden();
    }
}
