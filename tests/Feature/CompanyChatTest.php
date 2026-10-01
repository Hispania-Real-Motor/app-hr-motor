<?php

namespace Tests\Feature;

use App\Models\CompanyChatConversation;
use App\Models\CompanyChatGroup;
use App\Models\CompanyChatMessage;
use App\Models\Dealership;
use App\Models\PolicyAcceptance;
use App\Models\User;
use App\Notifications\CompanyChatMessageNotification;
use App\Support\ChatPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CompanyChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        $attachmentsDirectory = storage_path('app/public/chat/attachments');

        if (File::exists($attachmentsDirectory)) {
            File::deleteDirectory($attachmentsDirectory);
        }

        parent::tearDown();
    }

    private function acceptChatPolicy(User $user, array $params = []): void
    {
        $this->actingAs($user)
            ->post(route('chat.beta.policy.accept', $params))
            ->assertRedirect(route('chat.beta'));
    }

    public function test_authenticated_user_can_open_the_chat_and_start_a_conversation_with_any_active_member(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);

        $response = $this->actingAs($sender)->get(route('chat.beta', ['recipient' => $recipient->id]));

        $conversation = CompanyChatConversation::query()->first();

        $response
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation?->id]));

        $this->assertNotNull($conversation);
        $this->assertSame(min($sender->id, $recipient->id), $conversation->user_one_id);
        $this->assertSame(max($sender->id, $recipient->id), $conversation->user_two_id);
    }

    public function test_user_can_send_messages_and_they_are_marked_as_read_when_opening_the_conversation(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Lucia Test',
        ]);

        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $message = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Hola, puedes revisar el expediente de hoy?',
        ]);

        $this->actingAs($recipient)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('data-chat-header-profile-link', false)
            ->assertSee('Hola, puedes revisar el expediente de hoy?');

        $message->refresh();

        $this->assertNotNull($message->read_at);

        $this->actingAs($recipient)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Si, lo miro ahora mismo.',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $conversation->refresh();
        $sentMessage = CompanyChatMessage::query()->latest('id')->first();

        $this->assertSame('Si, lo miro ahora mismo.', $conversation->last_message_excerpt);
        $this->assertNotNull($conversation->last_message_at);
        $this->assertDatabaseCount('company_chat_messages', 2);
        $this->assertNotNull($sentMessage);
        $this->assertNull($sentMessage->read_at);
        $this->assertDatabaseHas('notifications', [
            'type' => CompanyChatMessageNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $sender->id,
        ]);
    }

    public function test_entering_chat_does_not_select_or_mark_a_conversation_as_read(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $message = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje no leído',
        ]);

        $response = $this->actingAs($recipient)->get(route('chat.beta'));

        $response
            ->assertOk()
            ->assertSee('data-chat-empty-state', false)
            ->assertSee('data-chat-mobile-sidebar-open="false"', false)
            ->assertSee('w-[4.75rem] max-w-[4.75rem] translate-x-0', false)
            ->assertSee('data-chat-sidebar-expanded-shell aria-hidden="true"', false)
            ->assertSee('data-chat-mobile-sidebar-rail', false)
            ->assertSee('data-chat-sidebar-toggle', false)
            ->assertSee('data-chat-sidebar-expand-button', false)
            ->assertSee('data-chat-sidebar-overlay', false)
            ->assertSee('invisible opacity-0 pointer-events-none', false)
            ->assertSee('data-chat-sidebar-close', false)
            ->assertSee('data-chat-sidebar-expanded-shell', false)
            ->assertSee('data-chat-search-input', false)
            ->assertSee('data-chat-sidebar-tab="chats"', false)
            ->assertSee('data-chat-sidebar-tab="team"', false)
            ->assertSee('data-chat-sidebar-tab="groups"', false)
            ->assertSee('setMobileSidebarOpen(true)', false)
            ->assertSee('setMobileSidebarOpen(false)', false)
            ->assertSee('const closeChatSidebar = () => setMobileSidebarOpen(false)', false)
            ->assertSee('window.closeChatSidebar = closeChatSidebar', false)
            ->assertSee('mobileSidebarBackdrop.addEventListener(\'click\', closeChatSidebar)', false)
            ->assertSee('body.classList.remove(\'chat-sidebar-open\')', false)
            ->assertSee('body.style.removeProperty(\'overflow\')', false)
            ->assertSee('overflow-x-hidden', false)
            ->assertSee('pl-[5.75rem]', false)
            ->assertSee('md:pl-6', false)
            ->assertSee("event.key === 'Escape'", false)
            ->assertSee('Selecciona una conversación', false)
            ->assertSee('Selecciona una conversación de la lista para empezar.', false)
            ->assertDontSee('Mensaje no leído', false)
            ->assertDontSee('data-selected-conversation-id="' . $conversation->id . '"', false);

        $message->refresh();
        $this->assertNull($message->read_at);
    }

    public function test_chat_polling_is_disabled_while_the_realtime_connection_is_active(): void
    {
        $user = User::factory()->create();

        $this->acceptChatPolicy($user);

        $this->actingAs($user)
            ->get(route('chat.beta'))
            ->assertOk()
            ->assertSee('let chatRealtimeConnected = false;', false)
            ->assertSee('const shouldRunChatPolling = () => document.visibilityState === \'visible\' && !chatRealtimeConnected;', false)
            ->assertSee('if (shouldRunChatPolling()) {', false);
    }

    public function test_chat_api_polling_routes_are_throttled_to_protect_the_backend(): void
    {
        RateLimiter::for('chat-api', function (Request $request): Limit {
            return Limit::perMinute(1)->by($request->user()?->id ?: $request->ip());
        });

        $summaryUser = User::factory()->create();
        $this->acceptChatPolicy($summaryUser);

        $this->actingAs($summaryUser)
            ->get(route('chat.beta.summary'))
            ->assertOk();

        $this->actingAs($summaryUser)
            ->get(route('chat.beta.summary'))
            ->assertStatus(429);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($recipient)
            ->getJson(route('chat.beta.messages.index', $conversation))
            ->assertOk();

        $this->actingAs($recipient)
            ->getJson(route('chat.beta.messages.index', $conversation))
            ->assertStatus(429);
    }

    public function test_user_cannot_send_messages_to_a_disabled_private_recipient_and_the_composer_is_disabled(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Usuario desactivado',
            'is_active' => false,
            'disabled_at' => now(),
        ]);

        $this->acceptChatPolicy($sender);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Este usuario está desactivado. No puedes enviarle mensajes, emojis ni archivos.', false)
            ->assertSee('data-chat-composer-disabled="1"', false);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'No debería enviarse',
            ])
            ->assertForbidden();
    }

    public function test_chat_messages_payload_marks_disabled_private_recipient_conversations_as_disabled(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Usuario desactivado',
            'is_active' => false,
            'disabled_at' => now(),
        ]);

        $this->acceptChatPolicy($sender);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->getJson(route('chat.beta.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation_is_disabled', true)
            ->assertJsonPath('partner_is_disabled', true)
            ->assertJsonPath('partner_status_label', 'Usuario desactivado');
    }

    public function test_user_can_send_chat_messages_with_attachments_and_without_text(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $response = $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => '',
                'attachments' => [
                    UploadedFile::fake()->image('captura-chat.png', 1200, 900),
                ],
            ]);

        $message = CompanyChatMessage::query()->latest('id')->first();

        $response
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $this->assertNotNull($message);
        $this->assertSame('', $message->body);
        $this->assertNotEmpty($message->attachments);
        $this->assertSame('Archivo adjunto: captura-chat.png', $conversation->fresh()->last_message_excerpt);
        $this->assertSame(1, count($message->attachments));
        $this->assertFileExists(storage_path('app/public/' . $message->attachments[0]['path']));

        $responseJson = $this->actingAs($recipient)->getJson(route('chat.beta.messages.index', $conversation));

        $responseJson
            ->assertOk()
            ->assertJsonPath('partner_profile_url', route('users.show', $sender))
            ->assertJsonPath('messages.0.preview_text', 'Archivo adjunto: captura-chat.png')
            ->assertJsonPath('messages.0.attachments.0.original_name', 'captura-chat.png')
            ->assertJsonPath('messages.0.attachments.0.is_image', true)
            ->assertJsonPath('messages.0.attachments.0.url', route('chat.beta.attachments.show', [
                'conversation' => $conversation->id,
                'message' => $message->id,
                'attachmentIndex' => 0,
            ]));
    }

    public function test_chat_messages_endpoint_returns_paginated_blocks_from_the_end_of_the_conversation(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        for ($index = 1; $index <= 35; $index++) {
            CompanyChatMessage::query()->create([
                'company_chat_conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => 'Mensaje ' . $index,
            ]);
        }

        $response = $this->actingAs($recipient)->getJson(route('chat.beta.messages.index', $conversation));

        $response
            ->assertOk()
            ->assertJsonPath('message_count', 24)
            ->assertJsonPath('has_more_older', true)
            ->assertJsonPath('messages.0.body', 'Mensaje 12')
            ->assertJsonPath('messages.23.body', 'Mensaje 35');

        $olderBlockResponse = $this->actingAs($recipient)->getJson(route('chat.beta.messages.index', [
            'conversation' => $conversation->id,
            'before_message_id' => 12,
            'limit' => 20,
        ]));

        $olderBlockResponse
            ->assertOk()
            ->assertJsonPath('message_count', 11)
            ->assertJsonPath('has_more_older', false)
            ->assertJsonPath('messages.0.body', 'Mensaje 1')
            ->assertJsonPath('messages.10.body', 'Mensaje 11');

        $newerBlockResponse = $this->actingAs($recipient)->getJson(route('chat.beta.messages.index', [
            'conversation' => $conversation->id,
            'after_message_id' => 30,
            'limit' => 10,
        ]));

        $newerBlockResponse
            ->assertOk()
            ->assertJsonPath('message_count', 5)
            ->assertJsonPath('has_more_newer', false)
            ->assertJsonPath('messages.0.body', 'Mensaje 31')
            ->assertJsonPath('messages.4.body', 'Mensaje 35');
    }

    public function test_group_chat_messages_endpoint_returns_paginated_blocks_from_the_end_of_the_conversation(): void
    {
        $sender = User::factory()->create();
        $firstMember = User::factory()->create();
        $secondMember = User::factory()->create();

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($firstMember);
        $this->acceptChatPolicy($secondMember);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo paginado',
        ]);
        $group->participants()->sync([$sender->id, $firstMember->id, $secondMember->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        for ($index = 1; $index <= 32; $index++) {
            CompanyChatMessage::query()->create([
                'company_chat_conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => 'Mensaje grupo ' . $index,
            ]);
        }

        $response = $this->actingAs($firstMember)->getJson(route('chat.beta.messages.index', $conversation));

        $response
            ->assertOk()
            ->assertJsonPath('message_count', 24)
            ->assertJsonPath('has_more_older', true)
            ->assertJsonPath('messages.0.body', 'Mensaje grupo 9')
            ->assertJsonPath('messages.23.body', 'Mensaje grupo 32');

        $olderBlockResponse = $this->actingAs($firstMember)->getJson(route('chat.beta.messages.index', [
            'conversation' => $conversation->id,
            'before_message_id' => 9,
            'limit' => 10,
        ]));

        $olderBlockResponse
            ->assertOk()
            ->assertJsonPath('message_count', 8)
            ->assertJsonPath('has_more_older', false)
            ->assertJsonPath('messages.0.body', 'Mensaje grupo 1')
            ->assertJsonPath('messages.7.body', 'Mensaje grupo 8');
    }

    public function test_group_messages_only_become_read_when_all_participants_have_opened_them(): void
    {
        $sender = User::factory()->create(['name' => 'Emisor']);
        $firstReader = User::factory()->create(['name' => 'Lector Uno']);
        $secondReader = User::factory()->create(['name' => 'Lector Dos']);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($firstReader);
        $this->acceptChatPolicy($secondReader);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo de pruebas',
        ]);

        $group->participants()->sync([$sender->id, $firstReader->id, $secondReader->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        $message = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje de grupo',
        ]);

        $this->actingAs($firstReader)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk();

        $message->refresh();
        $this->assertNull($message->read_at);

        $this->actingAs($secondReader)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk();

        $message->refresh();
        $this->assertNotNull($message->read_at);
    }

    public function test_group_message_reads_are_registered_in_bulk_without_changing_the_final_read_state(): void
    {
        $sender = User::factory()->create(['name' => 'Emisor']);
        $firstReader = User::factory()->create(['name' => 'Lector Uno']);
        $secondReader = User::factory()->create(['name' => 'Lector Dos']);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($firstReader);
        $this->acceptChatPolicy($secondReader);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo bulk',
        ]);

        $group->participants()->sync([$sender->id, $firstReader->id, $secondReader->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        $messages = collect();
        for ($index = 1; $index <= 4; $index++) {
            $messages->push(CompanyChatMessage::query()->create([
                'company_chat_conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'body' => 'Mensaje ' . $index,
            ]));
        }

        $this->actingAs($firstReader)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk();

        $messages->each(function (CompanyChatMessage $message): void {
            $message->refresh();
            $this->assertNull($message->read_at);
        });

        $this->actingAs($secondReader)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk();

        $messages->each(function (CompanyChatMessage $message): void {
            $message->refresh();
            $this->assertNotNull($message->read_at);
        });
    }

    public function test_group_messages_notify_all_members_except_the_sender_and_show_the_sender_name(): void
    {
        $sender = User::factory()->create(['name' => 'Emisor Grupo']);
        $firstReader = User::factory()->create(['name' => 'Lector Uno']);
        $secondReader = User::factory()->create(['name' => 'Lector Dos']);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($firstReader);
        $this->acceptChatPolicy($secondReader);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo notificaciones',
        ]);
        $group->participants()->sync([$sender->id, $firstReader->id, $secondReader->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Mensaje para el grupo',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $firstReader->id,
            'type' => CompanyChatMessageNotification::class,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $secondReader->id,
            'type' => CompanyChatMessageNotification::class,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $sender->id,
            'type' => CompanyChatMessageNotification::class,
        ]);

        $this->actingAs($firstReader)
            ->getJson(route('chat.beta.summary'))
            ->assertOk()
            ->assertJsonPath('chat_groups.0.unread_messages_count', 1);

        $this->actingAs($firstReader)
            ->get(route('chat.beta', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('Emisor Grupo', false);
    }

    public function test_group_messages_show_the_sender_name_again_when_the_same_user_writes_in_a_new_minute(): void
    {
        Carbon::setTestNow(now()->setTime(13, 0, 0));

        $sender = User::factory()->create(['name' => 'Antonio Morales']);
        $viewer = User::factory()->create(['name' => 'Lector Grupo']);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($viewer);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo conversación',
        ]);
        $group->participants()->sync([$sender->id, $viewer->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Mensaje del primer minuto',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        Carbon::setTestNow(now()->addMinutes(2));

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Mensaje del minuto siguiente',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $response = $this->actingAs($viewer)->get(route('chat.beta', ['conversation' => $conversation->id]));

        $response
            ->assertOk()
            ->assertSee('Antonio Morales', false)
            ->assertSee('Mensaje del primer minuto', false)
            ->assertSee('Mensaje del minuto siguiente', false);

        $this->assertMatchesRegularExpression(
            '/Antonio Morales.*?Mensaje del primer minuto.*?Antonio Morales.*?Mensaje del minuto siguiente/s',
            $response->getContent()
        );

        Carbon::setTestNow();
    }

    public function test_group_messages_show_the_sender_name_again_when_someone_speaks_after_another_user(): void
    {
        Carbon::setTestNow(now()->setTime(12, 30, 0));

        $senderOne = User::factory()->create(['name' => 'Antonio Morales']);
        $senderTwo = User::factory()->create(['name' => 'Marta Gómez']);
        $viewer = User::factory()->create(['name' => 'Lector Grupo']);

        $this->acceptChatPolicy($senderOne);
        $this->acceptChatPolicy($senderTwo);
        $this->acceptChatPolicy($viewer);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo conversación',
        ]);
        $group->participants()->sync([$senderOne->id, $senderTwo->id, $viewer->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        $this->actingAs($senderOne)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Primero yo',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $this->actingAs($senderTwo)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Luego yo',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $this->actingAs($senderOne)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Vuelvo a hablar',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $response = $this->actingAs($viewer)->get(route('chat.beta', ['conversation' => $conversation->id]));

        $response
            ->assertOk()
            ->assertSee('Antonio Morales', false)
            ->assertSee('Marta Gómez', false);

        $this->assertMatchesRegularExpression(
            '/Antonio Morales.*?Primero yo.*?Marta Gómez.*?Luego yo.*?Antonio Morales.*?Vuelvo a hablar/s',
            $response->getContent()
        );

        Carbon::setTestNow();
    }

    public function test_chat_composer_clears_the_input_before_sending_the_request_to_allow_consecutive_submissions(): void
    {
        $sender = User::factory()->create(['name' => 'Emisor Rapido']);
        $recipient = User::factory()->create(['name' => 'Receptor Rapido']);

        $this->acceptChatPolicy($sender);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $response = $this->actingAs($sender)->get(route('chat.beta', ['conversation' => $conversation->id]));

        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringNotContainsString('if (isSubmitting)', $content);
        $this->assertMatchesRegularExpression(
            '/input\.value = \'\';\s*attachmentSnapshot = \[\];\s*composerMentionIds = \[\];[\s\S]*?const response = await fetch\(url,/s',
            $content
        );
    }

    public function test_group_mentions_are_stored_and_prioritised_for_the_mentioned_user(): void
    {
        $sender = User::factory()->create(['name' => 'Emisor Menciones']);
        $mentioned = User::factory()->create(['name' => 'Michael Perez']);
        $other = User::factory()->create(['name' => 'Lector Normal']);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($mentioned);
        $this->acceptChatPolicy($other);

        $group = CompanyChatGroup::query()->create([
            'name' => 'Grupo menciones',
        ]);
        $group->participants()->sync([$sender->id, $mentioned->id, $other->id]);

        $conversation = CompanyChatConversation::query()->create([
            'company_chat_group_id' => $group->id,
        ]);

        Notification::fake();

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Hola @Michael Perez, revisa esto por favor.',
                'mentioned_user_ids' => [$mentioned->id],
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $message = CompanyChatMessage::query()->latest('id')->first();

        $this->assertNotNull($message);
        $this->assertSame([$mentioned->id], $message->mentioned_user_ids);

        $this->actingAs($mentioned)
            ->getJson(route('chat.beta.messages.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('messages.0.mentioned_user_ids.0', $mentioned->id)
            ->assertJsonPath('messages.0.mentions_me', true)
            ->assertJsonPath('messages.0.rendered_body_html', 'Hola <span class="font-semibold text-sky-600">@Michael Perez</span>, revisa esto por favor.');

        Notification::assertSentTo($mentioned, CompanyChatMessageNotification::class, function (CompanyChatMessageNotification $notification) use ($mentioned, $conversation): bool {
            $payload = $notification->toDatabase($mentioned);

            return $payload['priority'] === true
                && str_contains($payload['title'], 'Te han mencionado')
                && $payload['conversation_id'] === $conversation->id;
        });

        Notification::assertSentTo($other, CompanyChatMessageNotification::class, function (CompanyChatMessageNotification $notification) use ($other): bool {
            return $notification->toDatabase($other)['priority'] === false;
        });
    }

    public function test_chat_messages_render_links_as_clickable_blue_anchors(): void
    {
        $sender = User::factory()->create([
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            'name' => 'Emisor Enlaces',
        ]);
        $recipient = User::factory()->create([
            'extra_role' => User::ROLE_HUMAN_RESOURCES,
            'name' => 'Receptor Enlaces',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Mira https://example.com para más detalles.',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $this->actingAs($recipient)
            ->getJson(route('chat.beta.messages.index', $conversation))
            ->assertOk()
            ->assertJsonPath('messages.0.rendered_body_html', 'Mira <a href="https://example.com" target="_blank" rel="noopener noreferrer" class="break-words [overflow-wrap:anywhere] font-medium text-sky-600 underline decoration-sky-400/70 underline-offset-2 transition hover:text-sky-700">https://example.com</a> para más detalles.');
    }

    public function test_chat_attachment_route_serves_files_for_conversation_participants(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => '',
                'attachments' => [
                    UploadedFile::fake()->image('captura-chat.png', 1200, 900),
                ],
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $message = CompanyChatMessage::query()->latest('id')->firstOrFail();

        $this->actingAs($recipient)
            ->get(route('chat.beta.attachments.show', [
                'conversation' => $conversation->id,
                'message' => $message->id,
                'attachmentIndex' => 0,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_chat_accepts_json_attachments(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => '',
                'attachments' => [
                    UploadedFile::fake()->create('config.json', 8, 'application/json'),
                ],
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $message = CompanyChatMessage::query()->latest('id')->firstOrFail();

        $this->assertCount(1, $message->attachments);
        $this->assertSame('config.json', $message->attachments[0]['original_name']);
        $this->assertSame('application/json', $message->attachments[0]['mime_type']);
    }

    public function test_chat_json_attachment_route_forces_download(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => '',
                'attachments' => [
                    UploadedFile::fake()->create('config.json', 8, 'application/json'),
                ],
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $message = CompanyChatMessage::query()->latest('id')->firstOrFail();

        $this->actingAs($recipient)
            ->get(route('chat.beta.attachments.show', [
                'conversation' => $conversation->id,
                'message' => $message->id,
                'attachmentIndex' => 0,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertHeader('content-disposition', 'attachment; filename=config.json');
    }

    public function test_chat_accepts_rdp_attachments(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => '',
                'attachments' => [
                    UploadedFile::fake()->createWithContent('remote.rdp', "full address:s:example.com\r\nusername:s:usuario\r\n"),
                ],
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $message = CompanyChatMessage::query()->latest('id')->firstOrFail();

        $this->assertCount(1, $message->attachments);
        $this->assertSame('remote.rdp', $message->attachments[0]['original_name']);
    }

    public function test_user_cannot_send_chat_messages_when_total_attachment_size_is_too_large(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Marta Test',
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $response = $this->actingAs($sender)
            ->postJson(route('chat.beta.messages.store', $conversation), [
                'body' => '',
                'attachments' => [
                    UploadedFile::fake()->image('captura-1.png')->size(10240),
                    UploadedFile::fake()->image('captura-2.png')->size(10240),
                    UploadedFile::fake()->image('captura-3.png')->size(10240),
                    UploadedFile::fake()->image('captura-4.png')->size(1024),
                ],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments']);

        $this->assertDatabaseCount('company_chat_messages', 0);
    }

    public function test_user_can_edit_and_delete_own_chat_messages(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Laura Test',
        ]);

        $this->acceptChatPolicy($sender);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $message = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje original',
        ]);

        $recipient->notify(new CompanyChatMessageNotification($conversation, $message, $sender));

        $this->actingAs($sender)
            ->patchJson(route('chat.beta.messages.update', [$conversation, $message]), [
                'body' => 'Mensaje editado',
            ])
            ->assertOk()
            ->assertJsonPath('message.body', 'Mensaje editado')
            ->assertJsonPath('last_message_excerpt', 'Mensaje editado');

        $this->assertSame('Mensaje editado', $message->fresh()->body);
        $this->assertNotNull($message->fresh()->edited_at);
        $this->assertDatabaseHas('company_chat_message_revisions', [
            'company_chat_message_id' => $message->id,
            'body' => 'Mensaje original',
            'edited_by' => $sender->id,
        ]);
        $this->assertSame(1, $message->revisions()->count());

        $this->actingAs($sender)
            ->patchJson(route('chat.beta.messages.update', [$conversation, $message]), [
                'body' => 'Mensaje editado por segunda vez',
            ])
            ->assertOk()
            ->assertJsonPath('message.body', 'Mensaje editado por segunda vez');

        $this->assertSame('Mensaje editado por segunda vez', $message->fresh()->body);
        $this->assertSame(2, $message->revisions()->count());
        $this->assertSame(
            ['Mensaje original', 'Mensaje editado'],
            $message->revisions()->orderBy('edited_at')->pluck('body')->all(),
        );

        $this->actingAs($sender)
            ->patchJson(route('chat.beta.messages.update', [$conversation, $message]), [
                'body' => 'Mensaje editado por segunda vez',
            ])
            ->assertOk();

        $this->assertSame(2, $message->revisions()->count());
        $this->assertSame('Mensaje editado por segunda vez', $conversation->fresh()->last_message_excerpt);

        $this->actingAs($sender)
            ->deleteJson(route('chat.beta.messages.destroy', [$conversation, $message]))
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversation->id)
            ->assertJsonPath('last_message_excerpt', 'Mensaje eliminado');

        $this->assertSoftDeleted('company_chat_messages', [
            'id' => $message->id,
        ]);
        $this->assertSame(0, $recipient->unreadNotifications()->count());
        $this->assertSame('Mensaje eliminado', $conversation->fresh()->last_message_excerpt);
    }

    public function test_user_cannot_edit_or_delete_own_chat_messages_after_two_minutes(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create([
            'name' => 'Laura Test',
        ]);

        $this->acceptChatPolicy($sender);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        Carbon::setTestNow(now());

        $message = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje original',
        ]);

        Carbon::setTestNow(now()->addMinutes(3));

        try {
            $this->actingAs($sender)
                ->patchJson(route('chat.beta.messages.update', [$conversation, $message]), [
                    'body' => 'Mensaje editado',
                ])
                ->assertForbidden();

            $this->actingAs($sender)
                ->deleteJson(route('chat.beta.messages.destroy', [$conversation, $message]))
                ->assertForbidden();

            $message->refresh();

            $this->assertSame('Mensaje original', $message->body);
            $this->assertNull($message->deleted_at);
            $this->assertNull($message->edited_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_chat_messages_endpoint_returns_json_and_marks_incoming_messages_as_read(): void
    {
        $sender = User::factory()->create([
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $recipient = User::factory()->create([
            'extra_role' => User::ROLE_HUMAN_RESOURCES,
        ]);

        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $message = CompanyChatMessage::query()->create([
            'company_chat_conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => 'Mensaje de prueba',
        ]);

        $response = $this->actingAs($recipient)->getJson(route('chat.beta.messages.index', $conversation));

        $response
            ->assertOk()
            ->assertJsonPath('partner_name', $sender->name)
            ->assertJsonPath('messages.0.body', 'Mensaje de prueba')
            ->assertJsonPath('messages.0.sender_chat_role_label', 'Informática');

        $message->refresh();

        $this->assertNotNull($message->read_at);
        $this->assertSame(0, $recipient->unreadNotifications()->count());
    }

    public function test_chat_summary_returns_conversation_metadata_and_unread_counts(): void
    {
        $sender = User::factory()->create([
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $recipient = User::factory()->create([
            'extra_role' => User::ROLE_HUMAN_RESOURCES,
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Mensaje sin leer',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $response = $this->actingAs($recipient)->getJson(route('chat.beta.summary'));

        $response
            ->assertOk()
            ->assertJsonPath('conversations.0.partner_name', $sender->name)
            ->assertJsonPath('conversations.0.partner_chat_role_label', 'Informática')
            ->assertJsonPath('conversations.0.unread_messages_count', 1)
            ->assertJsonPath('unread_messages_total', 1);

        $this->assertSame(1, $recipient->unreadNotifications()->count());
    }

    public function test_chat_group_sidebar_uses_the_dealership_image_for_dealership_groups(): void
    {
        $dealership = Dealership::factory()->create([
            'name' => 'Sevilla',
            'image_path' => 'images/dealerships/sevilla.png',
        ]);

        $user = User::factory()->create([
            'dealership_id' => $dealership->id,
            'dealership' => $dealership->name,
            'extra_role' => User::ROLE_COMMERCIAL,
        ]);

        $this->acceptChatPolicy($user);

        $response = $this->actingAs($user)->getJson(route('chat.beta.summary'));

        $response
            ->assertOk();

        $groupPayload = collect($response->json('chat_groups'))
            ->firstWhere('conversation_name', $dealership->name);

        $this->assertNotNull($groupPayload);
        $this->assertSame(asset($dealership->image_path), $groupPayload['conversation_avatar_url']);

        $this->actingAs($user)
            ->followingRedirects()
            ->get(route('chat.beta', ['group' => $groupPayload['group_id']]))
            ->assertOk()
            ->assertSee(asset($dealership->image_path), false)
            ->assertSee('data-chat-group-header-avatar-src="' . asset($dealership->image_path) . '"', false)
            ->assertSee('data-chat-group-header-avatar-button', false);
    }

    public function test_chat_contact_can_be_marked_as_favorite_and_appears_in_the_summary(): void
    {
        $authUser = User::factory()->create();
        $favoriteContact = User::factory()->create([
            'name' => 'Marta Favorita',
        ]);

        $this->acceptChatPolicy($authUser);

        CompanyChatConversation::query()->create([
            'user_one_id' => min($authUser->id, $favoriteContact->id),
            'user_two_id' => max($authUser->id, $favoriteContact->id),
        ]);

        $this->actingAs($authUser)
            ->postJson(route('chat.beta.favorites.toggle', $favoriteContact))
            ->assertOk()
            ->assertJsonPath('is_favorite', true);

        $this->assertDatabaseHas('company_chat_favorite_contacts', [
            'user_id' => $authUser->id,
            'favorite_user_id' => $favoriteContact->id,
        ]);

        $summaryResponse = $this->actingAs($authUser)->getJson(route('chat.beta.summary'));

        $summaryResponse
            ->assertOk()
            ->assertJsonPath('favorite_contacts.0.name', $favoriteContact->name)
            ->assertJsonPath('conversations.0.partner_name', $favoriteContact->name)
            ->assertJsonPath('conversations.0.partner_is_favorite', true);
    }

    public function test_chat_live_search_returns_json_results_without_loading_the_full_chat_view(): void
    {
        $authUser = User::factory()->create();
        $matchedUser = User::factory()->create([
            'name' => 'Marta Búsqueda',
        ]);
        User::factory()->create([
            'name' => 'No debería salir',
        ]);

        $this->acceptChatPolicy($authUser);

        $response = $this->actingAs($authUser)->getJson(route('chat.beta', [
            'search' => 'Marta',
            'ajax' => 1,
        ]));

        $response
            ->assertOk()
            ->assertJsonStructure(['html']);

        $this->assertStringContainsString($matchedUser->name, $response->json('html'));
        $this->assertStringNotContainsString('No debería salir', $response->json('html'));
    }

    public function test_chat_notifications_are_grouped_in_the_notifications_summary_and_marked_read_together(): void
    {
        $sender = User::factory()->create([
            'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
        ]);
        $recipient = User::factory()->create([
            'extra_role' => User::ROLE_HUMAN_RESOURCES,
        ]);

        $this->acceptChatPolicy($sender);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Primer mensaje',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Segundo mensaje',
            ])
            ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

        $summaryResponse = $this->actingAs($recipient)->getJson(route('notifications.summary'));

        $summaryResponse
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('notifications.0.type', 'chat.message.received')
            ->assertJsonPath('notifications.0.actor_name', $sender->name)
            ->assertJsonPath('notifications.0.message_count', 2);

        $notification = $recipient->unreadNotifications()->latest()->first();

        $this->assertNotNull($notification);

        $this->actingAs($recipient)
            ->get(route('notifications.show', $notification->id))
            ->assertRedirect();

        $this->assertSame(0, $recipient->unreadNotifications()->count());
    }

    public function test_chat_messages_in_the_same_minute_only_show_the_time_on_the_last_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:15:00'));

        try {
            $sender = User::factory()->create([
                'extra_role' => User::ROLE_INFORMATION_TECHNOLOGY,
            ]);
            $recipient = User::factory()->create([
                'extra_role' => User::ROLE_HUMAN_RESOURCES,
            ]);

            $this->acceptChatPolicy($sender);
            $this->acceptChatPolicy($recipient);

            $conversation = CompanyChatConversation::query()->create([
                'user_one_id' => min($sender->id, $recipient->id),
                'user_two_id' => max($sender->id, $recipient->id),
            ]);

            $this->actingAs($sender)
                ->post(route('chat.beta.messages.store', $conversation), [
                    'body' => 'Primer mensaje',
                ])
                ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

            $this->actingAs($sender)
                ->post(route('chat.beta.messages.store', $conversation), [
                    'body' => 'Segundo mensaje',
                ])
                ->assertRedirect(route('chat.beta', ['conversation' => $conversation->id]));

            $response = $this->actingAs($recipient)->getJson(route('chat.beta.messages.index', $conversation));

            $response
                ->assertOk()
                ->assertJsonPath('messages.0.show_time', false)
                ->assertJsonPath('messages.1.show_time', true);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_chat_page_shows_policy_modal_until_the_user_accepts_the_current_version(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('chat.beta'));

        $response
            ->assertOk()
            ->assertSee('Política de uso del chat corporativo')
            ->assertSee('Aceptar y continuar');
    }

    public function test_chat_policy_acceptance_is_persisted_and_unlocks_the_chat(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('chat.beta.policy.accept'), [
                'source' => 'ignored',
            ])
            ->assertRedirect(route('chat.beta'));

        $this->assertDatabaseHas('policy_acceptances', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'policy_version' => ChatPolicy::POLICY_VERSION,
            'source' => ChatPolicy::SOURCE_WEB_CHAT,
        ]);

        $this->actingAs($user)
            ->get(route('chat.beta'))
            ->assertOk()
            ->assertDontSee('Política de uso del chat corporativo');
    }

    public function test_chat_is_blocked_until_the_user_accepts_the_current_policy_version(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($sender->id, $recipient->id),
            'user_two_id' => max($sender->id, $recipient->id),
        ]);

        $this->actingAs($sender)
            ->post(route('chat.beta.messages.store', $conversation), [
                'body' => 'Mensaje bloqueado',
            ])
            ->assertForbidden();

        $this->actingAs($recipient)
            ->getJson(route('chat.beta.messages.index', $conversation))
            ->assertForbidden();

        $this->actingAs($recipient)
            ->getJson(route('chat.beta.summary'))
            ->assertForbidden();
    }

    public function test_chat_requires_acceptance_again_when_the_policy_version_changes(): void
    {
        $user = User::factory()->create();

        PolicyAcceptance::query()->create([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'policy_version' => '2026-01-01-v1',
            'accepted_at' => now()->subDay(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'source' => ChatPolicy::SOURCE_WEB_CHAT,
        ]);

        $this->actingAs($user)
            ->get(route('chat.beta'))
            ->assertOk()
            ->assertSee('Política de uso del chat corporativo');
    }
    public function test_chat_view_keeps_the_desktop_sidebar_controls_and_mobile_toggle_markup(): void
    {
        $user = User::factory()->create();
        $recipient = User::factory()->create();

        $this->acceptChatPolicy($user);
        $this->acceptChatPolicy($recipient);

        $conversation = CompanyChatConversation::query()->create([
            'user_one_id' => min($user->id, $recipient->id),
            'user_two_id' => max($user->id, $recipient->id),
        ]);

        $response = $this->actingAs($user)->get(route('chat.beta', ['conversation' => $conversation->id]));

        $response
            ->assertOk()
            ->assertSee('data-chat-sidebar-collapse-button', false)
            ->assertSee('data-chat-sidebar-collapsed-shell', false)
            ->assertSee('data-chat-mobile-sidebar-toggle', false)
            ->assertSee('data-chat-mobile-sidebar-backdrop', false);
    }
}
