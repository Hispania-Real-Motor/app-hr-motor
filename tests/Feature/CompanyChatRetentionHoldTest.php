<?php

namespace Tests\Feature;

use App\Filament\Pages\ChatRetentionHoldsPage;
use App\Filament\Pages\ChatRetentionHoldLogsPage;
use App\Models\CompanyChatConversation;
use App\Models\CompanyChatMessage;
use App\Models\CompanyChatRetentionHoldAudit;
use App\Models\User;
use App\Models\AdminPermissionGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompanyChatRetentionHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_admin_can_activate_update_and_deactivate_a_retention_hold_with_audit_entries(): void
    {
        Carbon::setTestNow('2026-05-28 10:00:00');

        try {
            $admin = User::factory()->create([
                'role' => 'admin',
                'name' => 'Admin Principal',
                'email' => 'admin@example.com',
            ]);
            $firstUser = User::factory()->create([
                'name' => 'Primer Usuario',
            ]);
            $secondUser = User::factory()->create([
                'name' => 'Segundo Usuario',
            ]);

            $this->actingAs($admin)
                ->get(ChatRetentionHoldsPage::getUrl())
                ->assertOk()
                ->assertSee('Conservación excepcional');

            $conversation = CompanyChatConversation::query()->create([
                'user_one_id' => min($firstUser->id, $secondUser->id),
                'user_two_id' => max($firstUser->id, $secondUser->id),
            ]);

            $this->actingAs($admin)
                ->post(route('backoffice.chat-retention-holds.store'), [
                    'conversation_id' => $conversation->id,
                    'reason' => 'Motivo legal de conservación',
                    'expires_at' => '2026-06-28',
                ])
                ->assertRedirect(ChatRetentionHoldsPage::getUrl());

            $conversation->refresh();

            $this->assertTrue($conversation->retention_hold);
            $this->assertSame('Motivo legal de conservación', $conversation->retention_hold_reason);
            $this->assertSame($admin->id, $conversation->retention_hold_created_by);
            $this->assertSame('2026-06-28 23:59:59', $conversation->retention_hold_expires_at?->format('Y-m-d H:i:s'));

            $this->assertDatabaseHas('company_chat_retention_hold_audits', [
                'company_chat_conversation_id' => $conversation->id,
                'admin_user_id' => $admin->id,
                'action' => 'activated',
                'reason' => 'Motivo legal de conservación',
                'source' => 'web-admin',
            ]);

            $this->actingAs($admin)
                ->patch(route('backoffice.chat-retention-holds.update', $conversation), [
                    'reason' => 'Motivo legal actualizado',
                    'expires_at' => '2026-07-15',
                ])
                ->assertRedirect(ChatRetentionHoldsPage::getUrl());

            $this->assertDatabaseHas('company_chat_retention_hold_audits', [
                'company_chat_conversation_id' => $conversation->id,
                'action' => 'reason_updated',
                'reason' => 'Motivo legal actualizado',
            ]);

            $this->assertDatabaseHas('company_chat_retention_hold_audits', [
                'company_chat_conversation_id' => $conversation->id,
                'action' => 'expires_at_updated',
                'reason' => 'Motivo legal actualizado',
            ]);

            $this->actingAs($admin)
                ->delete(route('backoffice.chat-retention-holds.destroy', $conversation), [
                    'reason' => 'Ya no es necesario conservarla',
                ])
                ->assertRedirect(ChatRetentionHoldsPage::getUrl());

            $conversation->refresh();

            $this->assertFalse($conversation->retention_hold);
            $this->assertNull($conversation->retention_hold_reason);
            $this->assertNull($conversation->retention_hold_created_at);
            $this->assertNull($conversation->retention_hold_created_by);
            $this->assertNull($conversation->retention_hold_expires_at);

            $this->assertDatabaseHas('company_chat_retention_hold_audits', [
                'company_chat_conversation_id' => $conversation->id,
                'action' => 'deactivated',
                'reason' => 'Ya no es necesario conservarla',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_chat_retention_hold_page_is_forbidden_to_non_admin_users(): void
    {
        $manager = User::factory()->create([
            'role' => 'gestor',
            'name' => 'Gestor Principal',
            'email' => 'gestor@example.com',
        ]);

        $this->actingAs($manager)
            ->get(ChatRetentionHoldsPage::getUrl())
            ->assertForbidden();
    }

    public function test_manager_with_inherited_retention_permission_sees_navigation_and_can_manage_holds(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'name' => 'Gestor con conservación',
            'email' => 'gestor-retention@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'chat-retention-holds.manage',
            'group_role' => User::ROLE_MANAGER,
            'user_id' => null,
            'group_id' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($firstUser->id, $secondUser->id),
            'user_two_id' => max($firstUser->id, $secondUser->id),
        ]);

        $response = $this->actingAs($manager)
            ->get(ChatRetentionHoldsPage::getUrl())
            ->assertOk()
            ->assertSee('Conservación excepcional')
            ->assertSee('href="'.ChatRetentionHoldsPage::getUrl().'"', false);

        $this->assertTrue(app_user_has_admin_permission($manager, 'chat-retention-holds.manage'));
        $this->assertStringContainsString('Conservación excepcional', $response->getContent());

        $this->post(route('backoffice.chat-retention-holds.store'), [
            'conversation_id' => $conversation->id,
            'reason' => 'Conservación autorizada por perfil',
        ])->assertRedirect(ChatRetentionHoldsPage::getUrl());

        $this->assertDatabaseHas('company_chat_conversations', [
            'id' => $conversation->id,
            'retention_hold' => true,
            'retention_hold_created_by' => $manager->id,
        ]);
    }

    public function test_user_with_direct_retention_permission_can_open_the_page_and_logs(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Usuario con conservación',
            'email' => 'usuario-retention@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'chat-retention-holds.manage',
            'group_role' => null,
            'user_id' => $user->id,
            'group_id' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get(ChatRetentionHoldsPage::getUrl())
            ->assertOk()
            ->assertSee('Conservación excepcional');

        $this->get(ChatRetentionHoldLogsPage::getUrl())->assertOk();
    }

    public function test_chat_purge_skips_messages_from_conversations_with_active_retention_hold(): void
    {
        Carbon::setTestNow('2026-05-28 05:30:00');

        try {
            $sender = User::factory()->create([
                'name' => 'Usuario Antiguo',
                'email' => 'antiguo@example.com',
            ]);
            $recipient = User::factory()->create([
                'name' => 'Usuario Receptor',
                'email' => 'receptor@example.com',
            ]);
            $admin = User::factory()->create([
                'role' => 'admin',
                'name' => 'Admin Principal',
                'email' => 'admin@example.com',
            ]);

            $conversation = CompanyChatConversation::query()->create([
                'user_one_id' => min($sender->id, $recipient->id),
                'user_two_id' => max($sender->id, $recipient->id),
            ]);

            $oldMessage = CompanyChatMessage::query()->forceCreate([
                'company_chat_conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => 'Mensaje protegido por retención excepcional',
                'created_at' => Carbon::parse('2025-11-27 08:00:00'),
                'updated_at' => Carbon::parse('2025-11-27 08:00:00'),
            ]);

            $this->actingAs($admin)
                ->post(route('backoffice.chat-retention-holds.store'), [
                    'conversation_id' => $conversation->id,
                    'reason' => 'Conservación excepcional activa',
                ])
                ->assertRedirect(ChatRetentionHoldsPage::getUrl());

            $this->artisan('chat:purge-expired-messages')
                ->assertExitCode(0);

            $this->assertDatabaseHas('company_chat_messages', [
                'id' => $oldMessage->id,
            ]);
            $this->assertDatabaseCount('company_chat_retention_hold_audits', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_chat_purge_skips_messages_from_users_with_active_retention_hold(): void
    {
        Carbon::setTestNow('2026-05-28 05:30:00');

        try {
            $sender = User::factory()->create([
                'name' => 'Usuario Protegido',
                'email' => 'protegido@example.com',
            ]);
            $recipient = User::factory()->create([
                'name' => 'Usuario Receptor',
                'email' => 'receptor@example.com',
            ]);
            $admin = User::factory()->create([
                'role' => 'admin',
                'name' => 'Admin Principal',
                'email' => 'admin@example.com',
            ]);

            $conversation = CompanyChatConversation::query()->create([
                'user_one_id' => min($sender->id, $recipient->id),
                'user_two_id' => max($sender->id, $recipient->id),
            ]);

            $oldMessage = CompanyChatMessage::query()->forceCreate([
                'company_chat_conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => 'Mensaje protegido por retención de usuario',
                'created_at' => Carbon::parse('2025-11-27 08:00:00'),
                'updated_at' => Carbon::parse('2025-11-27 08:00:00'),
            ]);

            $this->actingAs($admin)
                ->post(route('backoffice.chat-retention-holds.users.store'), [
                    'user_id' => $sender->id,
                    'reason' => 'Retención legal sobre el usuario',
                ])
                ->assertRedirect(ChatRetentionHoldsPage::getUrl());

            $this->assertDatabaseHas('company_chat_retention_user_holds', [
                'user_id' => $sender->id,
                'retention_hold' => true,
                'retention_hold_reason' => 'Retención legal sobre el usuario',
            ]);

            $this->artisan('chat:purge-expired-messages')
                ->assertExitCode(0);

            $this->assertDatabaseHas('company_chat_messages', [
                'id' => $oldMessage->id,
            ]);
            $this->assertDatabaseCount('company_chat_retention_user_hold_audits', 1);
        } finally {
            Carbon::setTestNow();
        }
    }
}
