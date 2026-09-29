<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ConversationAccessPage;
use App\Filament\Pages\ConversationAccessLogsPage;
use App\Models\CompanyChatConversation;
use App\Models\CompanyChatConversationAccessAudit;
use App\Models\CompanyChatMessage;
use App\Models\CompanyChatMessageRevision;
use App\Models\AdminPermissionGrant;
use App\Models\Dealership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ConversationAccessPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_users_without_the_permission_cannot_access_the_filament_conversation_access_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($admin)
            ->get(ConversationAccessPage::getUrl())
            ->assertOk()
            ->assertSee('Acceso justificado a conversaciones');

        $this->actingAs($manager)
            ->get(ConversationAccessPage::getUrl())
            ->assertForbidden();

        $this->get(ConversationAccessLogsPage::getUrl())->assertForbidden();
        $this->get(route('admin.conversation-access.logs.export'))->assertForbidden();
    }

    public function test_manager_with_inherited_permission_sees_navigation_and_can_request_conversation_access(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'email' => 'gestor-conversation-access@example.com',
        ]);
        $this->grantPermissionToRole(User::ROLE_MANAGER);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);
        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje accesible con permiso',
        ]);

        $response = $this->actingAs($manager)
            ->get(ConversationAccessPage::getUrl())
            ->assertOk()
            ->assertSee('Acceso justificado a conversaciones')
            ->assertSee('href="'.ConversationAccessPage::getUrl().'"', false);

        $this->assertStringContainsString('Acceso justificado a conversaciones', $response->getContent());
        $this->assertTrue(app_user_has_admin_permission($manager, 'conversation-access.manage'));

        Livewire::actingAs($manager);

        Livewire::test(ConversationAccessPage::class)
            ->mountTableAction('access', $conversation->getKey())
            ->setTableActionData(['reason' => 'Revisión autorizada'])
            ->callMountedTableAction()
            ->assertSet('contentUnlocked', true)
            ->assertSee('Mensaje accesible con permiso');

        $this->assertDatabaseHas('company_chat_conversation_access_audits', [
            'company_chat_conversation_id' => $conversation->id,
            'admin_user_id' => $manager->id,
            'reason' => 'Revisión autorizada',
            'result' => 'granted',
        ]);
    }

    public function test_user_with_direct_permission_can_access_the_page_and_logs(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'usuario-conversation-access@example.com',
        ]);

        AdminPermissionGrant::query()->create([
            'permission_key' => 'conversation-access.manage',
            'user_id' => $user->id,
            'group_id' => null,
            'group_role' => null,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);

        $this->actingAs($user)
            ->get(ConversationAccessPage::getUrl())
            ->assertOk()
            ->assertSee('Acceso justificado a conversaciones');

        $this->get(ConversationAccessLogsPage::getUrl())->assertOk();
        $this->get(route('admin.conversation-access.logs.export'))->assertOk();
    }

    public function test_admin_can_open_the_conversation_access_log_from_the_access_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(ConversationAccessPage::getUrl())
            ->assertOk()
            ->assertSee('Ver log')
            ->assertSee(ConversationAccessLogsPage::getUrl(), false);
    }

    public function test_conversation_access_log_is_read_only_and_requires_the_permission(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $sender = User::factory()->create(['name' => 'Remitente del log']);
        $recipient = User::factory()->create(['name' => 'Destinatario del log']);
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        CompanyChatConversationAccessAudit::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'admin_user_id' => $admin->id,
            'admin_email' => $admin->email,
            'action' => 'conversation_content_access',
            'conversation_type' => 'Privada',
            'affected_user_ids' => [$sender->id, $recipient->id],
            'reason' => 'Revisión de incidencia de seguridad',
            'accessed_at' => now(),
            'result' => 'granted',
        ]);

        $this->actingAs($admin)
            ->get(ConversationAccessLogsPage::getUrl())
            ->assertOk()
            ->assertSee('Log de accesos a conversaciones')
            ->assertSee('Remitente del log')
            ->assertSee('Destinatario del log')
            ->assertSee('Revisión de incidencia de seguridad')
            ->assertSee('Acceso a conversación')
            ->assertSee('Concedido')
            ->assertDontSee('Editar')
            ->assertDontSee('Eliminar');

        Livewire::actingAs($admin);

        Livewire::test(ConversationAccessLogsPage::class)
            ->assertSee('Revisión de incidencia de seguridad')
            ->assertDontSee('Editar')
            ->assertDontSee('Eliminar');

        $this->actingAs($manager)
            ->get(ConversationAccessLogsPage::getUrl())
            ->assertForbidden();
    }

    public function test_page_renders_the_table_and_never_loads_messages_from_a_conversation_url(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sender = User::factory()->create(['name' => 'Ana Conversación', 'email' => 'ana@example.com']);
        $recipient = User::factory()->create(['name' => 'Luis Conversación', 'email' => 'luis@example.com']);
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);
        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Contenido que debe permanecer bloqueado',
        ]);

        $this->actingAs($admin)
            ->get(ConversationAccessPage::getUrl())
            ->assertOk()
            ->assertSee('ID')
            ->assertSee('Tipo')
            ->assertSee('Seleccionar conversación')
            ->assertSee('Ana Conversación')
            ->assertSee('Luis Conversación');

        $this->actingAs($admin)
            ->get(ConversationAccessPage::getUrl(['conversation' => $conversation->id]))
            ->assertOk()
            ->assertDontSee('Contenido que debe permanecer bloqueado');

        Livewire::actingAs($admin);

        Livewire::test(ConversationAccessPage::class)
            ->set('tableSearch', 'ana@example.com')
            ->assertSee('Ana Conversación')
            ->assertSee((string) $conversation->id);
    }

    public function test_selection_requires_a_reason_and_only_then_loads_messages_and_creates_audit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);
        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje protegido',
        ]);
        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $recipient->id,
            'body' => 'Mensaje del receptor',
        ]);
        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Evento del sistema',
            'is_system' => true,
        ]);
        $editedMessage = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $recipient->id,
            'body' => 'Mensaje editado actual',
            'edited_at' => now(),
        ]);
        CompanyChatMessageRevision::query()->create([
            'company_chat_message_id' => $editedMessage->id,
            'body' => 'Mensaje antes de editar',
            'edited_at' => $editedMessage->edited_at,
            'edited_by' => $recipient->id,
        ]);
        $deletedMessage = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje eliminado visible según la política actual',
        ]);
        $deletedMessage->delete();
        $messages = CompanyChatMessage::withTrashed()
            ->where('company_chat_conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        Livewire::actingAs($admin);

        $component = Livewire::test(ConversationAccessPage::class)
            ->mountTableAction('access', $conversation->getKey())
            ->assertSet('mountedActions.0.name', 'access')
            ->assertSet('mountedActions.0.context.table', true)
            ->assertSet('mountedActions.0.context.recordKey', $conversation->getKey())
            ->assertSet('selectedConversation', null)
            ->assertSet('contentUnlocked', false);

        $mountedAction = $component->instance()->getMountedTableAction();
        $this->assertNotNull($mountedAction);
        $this->assertSame(
            'Vas a acceder al contenido de una conversación en la que no participas.',
            (string) $mountedAction->getModalHeading(),
        );
        $this->assertStringContainsString(
            'Este acceso debe estar justificado por motivos de seguridad, cumplimiento normativo, investigación de incidencias, mantenimiento técnico, requerimiento legal o control laboral proporcionado.',
            $mountedAction->getModalContent()->toHtml(),
        );
        $this->assertStringContainsString(
            'El acceso quedará registrado en el sistema de auditoría.',
            $mountedAction->getModalContent()->toHtml(),
        );
        $this->assertStringContainsString('Indica el motivo del acceso:', $mountedAction->getModalContent()->toHtml());
        $this->assertSame('Cancelar', $mountedAction->getModalCancelActionLabel());
        $this->assertSame('Registrar motivo y acceder', $mountedAction->getModalSubmitActionLabel());
        $this->assertSame('Motivo', $component->instance()->getMountedTableActionForm()->getComponent('reason')->getLabel());
        $component->assertDontSee('Descargar CSV');

        $component
            ->setTableActionData(['reason' => ''])
            ->callMountedTableAction()
            ->assertHasFormErrors(['reason']);

        $component
            ->setTableActionData(['reason' => '   '])
            ->callMountedTableAction()
            ->assertHasFormErrors(['reason']);

        $component
            ->unmountTableAction()
            ->assertTableActionNotMounted('access')
            ->assertSet('selectedConversation', null)
            ->assertSet('contentUnlocked', false);

        $this->assertDatabaseCount('company_chat_conversation_access_audits', 0);

        $component
            ->mountTableAction('access', $conversation->getKey())
            ->setTableActionData(['reason' => 'Investigación de incidencia'])
            ->callMountedTableAction()
            ->assertSet('contentUnlocked', true)
            ->assertSee('Mensaje protegido')
            ->assertSee('Mensaje del receptor')
            ->assertSee('Evento del sistema')
            ->assertSee('Editado')
            ->assertDontSee('Historial de ediciones')
            ->assertSee('Contenido anterior:')
            ->assertSee('Mensaje antes de editar')
            ->assertDontSee('revisions->first())')
            ->assertDontSee('{{ $revision->body')
            ->assertSee('Contenido actual:')
            ->assertSee('Mensaje editado actual')
            ->assertSee('Eliminado')
            ->assertSee('Mensaje eliminado visible según la política actual')
            ->assertSee('aria-label="Mensaje eliminado"', false)
            ->assertSee('aria-label="Mensaje editado"', false)
            ->assertDontSee('{{ $message->accessMessageContent() }}')
            ->assertSee('Descargar CSV')
            ->assertSee('is-left')
            ->assertSee('is-right')
            ->assertSee('is-system')
            ->assertSee('data-message-meta', false)
            ->assertSee('data-message-content', false)
            ->assertSee('data-message-id="' . $messages[0]->id . '"', false)
            ->assertSee('data-message-id="' . $messages[1]->id . '"', false)
            ->assertSee('data-message-id="' . $messages[2]->id . '"', false);
        $component->assertSee('data-message-id="' . $messages[3]->id . '"', false);
        $component->assertSee('data-message-id="' . $messages[4]->id . '"', false);

        $this->assertDatabaseHas('company_chat_conversation_access_audits', [
            'company_chat_conversation_id' => $conversation->id,
            'admin_user_id' => $admin->id,
            'action' => 'conversation_content_access',
            'reason' => 'Investigación de incidencia',
            'result' => 'granted',
        ]);
    }

    public function test_dealership_filter_includes_private_conversations_and_groups_with_a_matching_participant(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $dealership = Dealership::factory()->create(['name' => 'Delegación Norte']);
        $otherDealership = Dealership::factory()->create(['name' => 'Delegación Sur']);
        $northUser = User::factory()->create(['name' => 'Usuario Norte', 'dealership_id' => $dealership->id]);
        $southUser = User::factory()->create(['name' => 'Usuario Sur', 'dealership_id' => $otherDealership->id]);
        $unassignedUser = User::factory()->create(['name' => 'Usuario Sin Delegación', 'dealership_id' => null]);

        $privateConversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($northUser->id, $unassignedUser->id),
            'user_two_id' => max($northUser->id, $unassignedUser->id),
        ]);

        $group = \App\Models\CompanyChatGroup::query()->create(['name' => 'Grupo Norte']);
        $group->participants()->attach([$southUser->id, $northUser->id]);
        $groupConversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        CompanyChatConversation::query()->create([
            'user_one_id' => min($southUser->id, $unassignedUser->id),
            'user_two_id' => max($southUser->id, $unassignedUser->id),
        ]);

        Livewire::actingAs($admin);

        Livewire::test(ConversationAccessPage::class)
            ->set('tableFilters.dealership.value', (string) $dealership->id)
            ->assertSee('Usuario Norte')
            ->assertSee('Grupo Norte')
            ->assertSee((string) $privateConversation->id)
            ->assertSee((string) $groupConversation->id)
            ->assertDontSee('Usuario Sur');
    }

    public function test_conversation_messages_are_paginated_while_csv_exports_all_messages(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        foreach (range(1, 55) as $messageNumber) {
            CompanyChatMessage::query()->create([
                'company_chat_conversation_id' => $conversation->id,
                'sender_id' => $messageNumber % 2 === 0 ? $recipient->id : $sender->id,
                'body' => 'Mensaje paginado ' . $messageNumber,
            ]);
        }

        Livewire::actingAs($admin);

        $component = Livewire::test(ConversationAccessPage::class)
            ->mountTableAction('access', $conversation->getKey())
            ->setTableActionData(['reason' => 'Revisión de conversación extensa'])
            ->callMountedTableAction()
            ->assertSet('contentUnlocked', true)
            ->assertSee('Mensaje paginado 1')
            ->assertSee('Mensaje paginado 50')
            ->assertDontSee('Mensaje paginado 51')
            ->assertSee('conversation-messages', false)
            ->assertSee('conversation-pagination', false)
            ->assertSee('conversation-pagination-content', false)
            ->assertSee('conversation-pagination-summary', false)
            ->assertSee('conversation-pagination-controls', false)
            ->assertSee('Mensajes 1-50 de 55');

        $this->assertSame(50, $component->instance()->selectedMessages->count());
        $this->assertSame(55, $component->instance()->selectedMessagesTotal);
        $this->assertSame(1, $component->instance()->selectedMessagesPage);

        $component
            ->call('goToMessagesPage', 2)
            ->assertSee('Mensaje paginado 51')
            ->assertSee('Mensaje paginado 55')
            ->assertDontSee('Mensaje paginado 1');

        $this->assertSame(5, $component->instance()->selectedMessages->count());
        $this->assertSame(2, $component->instance()->selectedMessagesPage);

        $response = $component->instance()->downloadConversationCsv();

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Mensaje paginado 1', $csv);
        $this->assertStringContainsString('Mensaje paginado 55', $csv);
    }

    public function test_authorized_conversation_can_be_exported_as_a_complete_csv_and_export_is_audited(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $sender = User::factory()->create(['name' => 'Exportador']);
        $recipient = User::factory()->create(['name' => 'Destinatario']);
        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => "Primera línea\nSegunda línea; con separador",
        ]);
        CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $recipient->id,
            'body' => 'Evento del sistema',
            'is_system' => true,
        ]);
        $editedMessage = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $recipient->id,
            'body' => 'Contenido actualizado',
            'edited_at' => now(),
        ]);
        CompanyChatMessageRevision::query()->create([
            'company_chat_message_id' => $editedMessage->id,
            'body' => 'Contenido anterior exportado',
            'edited_at' => $editedMessage->edited_at,
            'edited_by' => $recipient->id,
        ]);
        CompanyChatMessageRevision::query()->create([
            'company_chat_message_id' => $editedMessage->id,
            'body' => 'Contenido anterior exportado en segunda edición',
            'edited_at' => $editedMessage->edited_at->copy()->addMinute(),
            'edited_by' => $recipient->id,
        ]);
        $deletedMessage = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje eliminado',
        ]);
        $deletedMessage->delete();

        Livewire::actingAs($admin);

        $component = Livewire::test(ConversationAccessPage::class)
            ->mountTableAction('access', $conversation->getKey())
            ->setTableActionData(['reason' => 'Exportación solicitada para auditoría'])
            ->callMountedTableAction();

        $response = $component->instance()->downloadConversationCsv();

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(
            'text/csv; charset=UTF-8',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString(
            'conversacion-' . $conversation->id . '-',
            (string) $response->headers->get('Content-Disposition'),
        );

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBFmessage_id;conversation_id;", $csv);
        $this->assertStringContainsString(
            "message_id;conversation_id;conversation_type;conversation_name;sender_name;message_type;sent_at;status;content;edited_at;previous_content;deleted_at",
            $csv,
        );
        foreach (['revision_count', 'edit_history', 'current_content', 'is_edited', 'is_deleted', 'is_system_message'] as $technicalColumn) {
            $this->assertStringNotContainsString($technicalColumn, strtok($csv, "\n"));
        }
        $this->assertStringContainsString('Primera línea', $csv);
        $this->assertStringContainsString('Segunda línea; con separador', $csv);
        $this->assertStringContainsString('Evento del sistema', $csv);
        $this->assertStringContainsString('Editado', $csv);
        $this->assertStringContainsString('Contenido actualizado', $csv);
        $this->assertStringContainsString('Contenido anterior exportado en segunda edición', $csv);
        $this->assertStringNotContainsString('{{ $message->accessMessageContent() }}', $csv);
        $this->assertStringNotContainsString('{{ $revision->body', $csv);
        $deletedCsvLine = collect(preg_split('/\r?\n/', $csv))
            ->first(fn (string $line): bool => str_starts_with($line, $deletedMessage->id . ';'));
        $this->assertNotNull($deletedCsvLine);
        $deletedCsvRow = str_getcsv($deletedCsvLine, ';');
        $this->assertSame('Eliminado', $deletedCsvRow[7]);
        $this->assertSame('Mensaje eliminado', $deletedCsvRow[8]);
        $this->assertNotEmpty($deletedCsvRow[11]);
        $this->assertSame(
            6,
            substr_count($csv, "\n"),
            'El CSV debe contener cabecera y una fila por cada mensaje; el contenido multilinea añade un salto interno escapado.',
        );

        $this->assertDatabaseHas('company_chat_conversation_access_audits', [
            'company_chat_conversation_id' => $conversation->id,
            'admin_user_id' => $admin->id,
            'action' => 'conversation_content_export',
            'reason' => 'Exportación solicitada para auditoría',
            'result' => 'granted',
        ]);
        $this->assertDatabaseHas('company_chat_conversation_access_audits', [
            'company_chat_conversation_id' => $conversation->id,
            'admin_user_id' => $admin->id,
            'action' => 'conversation_content_access',
            'reason' => 'Exportación solicitada para auditoría',
            'result' => 'granted',
        ]);
    }

    public function test_conversation_type_filter_only_returns_groups_or_private_conversations_and_can_be_cleared(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $firstUser = User::factory()->create(['name' => 'Usuario Privado']);
        $secondUser = User::factory()->create(['name' => 'Segundo Usuario']);
        $privateConversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($firstUser->id, $secondUser->id),
            'user_two_id' => max($firstUser->id, $secondUser->id),
        ]);

        $group = \App\Models\CompanyChatGroup::query()->create(['name' => 'Grupo Filtrado']);
        $group->participants()->attach([$firstUser->id, $secondUser->id]);
        $groupConversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        Livewire::actingAs($admin);

        Livewire::test(ConversationAccessPage::class)
            ->set('tableFilters.conversation_type.value', 'group')
            ->assertSee('Grupo Filtrado')
            ->assertSee((string) $groupConversation->id)
            ->assertDontSee('Usuario Privado')
            ->set('tableFilters.conversation_type.value', 'private')
            ->assertSee('Usuario Privado')
            ->assertSee((string) $privateConversation->id)
            ->assertDontSee('Grupo Filtrado')
            ->set('tableFilters.conversation_type.value', null)
            ->assertSee('Grupo Filtrado')
            ->assertSee('Usuario Privado');
    }

    private function grantPermissionToRole(string $role): AdminPermissionGrant
    {
        return AdminPermissionGrant::query()->create([
            'permission_key' => 'conversation-access.manage',
            'user_id' => null,
            'group_id' => null,
            'group_role' => $role,
            'is_revoked' => false,
            'granted_by_user_id' => null,
        ]);
    }
}
