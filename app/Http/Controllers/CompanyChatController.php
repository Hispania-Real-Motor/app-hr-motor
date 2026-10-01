<?php

namespace App\Http\Controllers;

use App\Events\CompanyChatConversationRead;
use App\Events\CompanyChatMessageCreated;
use App\Models\CompanyChatConversation;
use App\Models\CompanyChatGroup;
use App\Models\CompanyChatFavoriteContact;
use App\Models\CompanyChatMessage;
use App\Models\CompanyChatMessageRevision;
use App\Models\CompanyChatMessageRead;
use App\Models\PolicyAcceptance;
use App\Models\User;
use App\Notifications\CompanyChatMessageNotification;
use App\Support\ChatPolicy;
use App\Services\NotificationBadgeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CompanyChatController extends Controller
{
    private const MAX_ATTACHMENT_TOTAL_BYTES = 31457280;
    private const MAX_ATTACHMENT_FILE_KILOBYTES = 30720;
    private const CHAT_MESSAGES_PAGE_SIZE = 24;
    private const CHAT_MESSAGES_SYNC_OVERLAP = 16;

    public function index(Request $request): View|RedirectResponse|JsonResponse
    {
        abort_unless(app_can_access_chat_beta($request->user()), 403);

        $authUser = $request->user();
        $search = trim((string) $request->query('search'));
        $policyVersion = ChatPolicy::version();
        $policyAccepted = $this->hasAcceptedChatPolicy($authUser);
        $favoriteUserIds = $this->favoriteUserIdsFor($authUser);
        $chatGroups = $authUser->chatGroups()
            ->withCount('participants')
            ->with(['participants' => function ($query): void {
                $query->orderBy('name');
            }, 'conversation' => function ($query): void {
                $query->with(['messages' => function ($messageQuery): void {
                    $messageQuery->with('reads');
                }]);
            }])
            ->get();
        $chatGroups = $this->sortChatGroupsForSidebar($chatGroups);

        $conversations = CompanyChatConversation::query()
            ->forUser($authUser)
            ->whereNull('company_chat_group_id')
            ->with(['userOne', 'userTwo'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        $conversations->each(function (CompanyChatConversation $conversation) use ($authUser): void {
            $conversation->setAttribute('unread_messages_count', $this->conversationUnreadMessagesCount($conversation, $authUser));
        });

        $chatGroups->each(function (CompanyChatGroup $chatGroup) use ($authUser): void {
            $chatGroup->setAttribute('avatar_url', $this->groupAvatarUrl($chatGroup));
            $chatGroup->setRelation('conversation', $chatGroup->conversation);
            $chatGroup->conversation?->setAttribute('unread_messages_count', $this->conversationUnreadMessagesCount($chatGroup->conversation, $authUser));
        });

        if ($request->boolean('ajax')) {
            abort_unless($policyAccepted, 403);

            $people = User::query()
                ->where('is_active', true)
                ->whereKeyNot($authUser->id)
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($subquery) use ($search): void {
                        $subquery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('dealership', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->limit(12)
                ->get();

            return response()->json([
                'html' => view('tools.chat-beta.partials.search-results', [
                    'people' => $people,
                    'search' => $search,
                    'favoriteUserIds' => $favoriteUserIds,
                ])->render(),
            ]);
        }

        if ($policyAccepted && $request->filled('recipient')) {
            $recipient = User::query()
                ->whereKey($request->integer('recipient'))
                ->whereKeyNot($authUser->id)
                ->where('is_active', true)
                ->firstOrFail();

            $conversation = $this->findOrCreateConversation($authUser, $recipient);

            return redirect()->route('chat.beta', [
                'conversation' => $conversation->id,
            ]);
        }

        if ($policyAccepted && $request->filled('group')) {
            $group = CompanyChatGroup::query()
                ->whereKey($request->integer('group'))
                ->whereHas('participants', function ($query) use ($authUser): void {
                    $query->whereKey($authUser->id);
                })
                ->firstOrFail();

            $conversation = $this->findOrCreateGroupConversation($group);

            return redirect()->route('chat.beta', [
                'conversation' => $conversation->id,
            ]);
        }

        if (! $policyAccepted) {
            return view('tools.chat-beta', [
                'conversations' => collect(),
                'selectedConversation' => null,
                'people' => collect(),
                'favoriteContacts' => [],
                'teamUsers' => [],
                'search' => $search,
                'favoriteUserIds' => [],
                'chatGroups' => collect(),
                'policyAccepted' => false,
                'policyVersion' => $policyVersion,
                'policyStatusUrl' => route('chat.beta.policy.status'),
                'policyAcceptUrl' => route('chat.beta.policy.accept'),
                'policyReturnRecipient' => $request->filled('recipient') ? $request->integer('recipient') : null,
                'policyReturnConversation' => $request->filled('conversation') ? $request->integer('conversation') : null,
                'policyReturnGroup' => $request->filled('group') ? $request->integer('group') : null,
            ]);
        }

        $conversations = CompanyChatConversation::query()
            ->forUser($authUser)
            ->whereNull('company_chat_group_id')
            ->with(['userOne', 'userTwo'])
            ->withCount([
                'messages as unread_messages_count' => function ($query) use ($authUser): void {
                    $query->whereNull('read_at')
                        ->where('sender_id', '!=', $authUser->id);
                },
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        $selectedConversation = null;
        $conversationId = $request->integer('conversation');

        if ($conversationId) {
            $selectedConversation = $conversations->firstWhere('id', $conversationId);

            if (! $selectedConversation) {
                $selectedConversation = CompanyChatConversation::query()
                    ->forUser($authUser)
                    ->whereKey($conversationId)
                    ->with(['userOne', 'userTwo', 'chatGroup.participants'])
                    ->first();
            }
        }

        if ($selectedConversation) {
            $selectedConversation->load([
                'userOne',
                'userTwo',
                'chatGroup.participants',
            ]);

            $selectedConversation->chatGroup?->setAttribute(
                'avatar_url',
                $this->groupAvatarUrl($selectedConversation->chatGroup)
            );
            $readMessageIds = $this->markConversationAsRead($selectedConversation, $authUser);
            if ($readMessageIds !== []) {
                $targetUserIds = $selectedConversation->participantsFor($authUser)
                    ->pluck('id')
                    ->map(static fn ($value): int => (int) $value)
                    ->reject(static fn (int $userId): bool => $userId === $authUser->id)
                    ->values()
                    ->all();

                broadcast(new CompanyChatConversationRead(
                    $selectedConversation->id,
                    $authUser->id,
                    $readMessageIds,
                    now()->toIso8601String(),
                    $targetUserIds
                ))->toOthers();
            }
            $this->markConversationNotificationsAsRead($selectedConversation, $authUser);
            [$selectedConversationMessages, $selectedConversationHasMoreOlder] = $this->conversationMessagesPage(
                $selectedConversation,
                $authUser,
                self::CHAT_MESSAGES_PAGE_SIZE
            );
            $selectedConversation->setRelation('messages', $selectedConversationMessages);
            $selectedConversation->setAttribute('messages_has_more_older', $selectedConversationHasMoreOlder);
            $selectedConversation->chatGroup?->setAttribute(
                'avatar_url',
                $this->groupAvatarUrl($selectedConversation->chatGroup)
            );
        }

        $people = User::query()
            ->where('is_active', true)
            ->whereKeyNot($authUser->id)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($subquery) use ($search): void {
                    $subquery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('dealership', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(12)
            ->get();

        $favoriteContacts = $this->favoriteContactsPayload($authUser);

        $teamUsers = User::query()
            ->where('is_active', true)
            ->whereKeyNot($authUser->id)
            ->orderBy('name')
            ->get()
            ->groupBy(fn (User $user): string => $user->chat_role_label)
            ->sortKeys()
            ->map(function ($users, string $roleLabel): array {
                return [
                    'role_label' => $roleLabel,
                    'users' => $users->map(function (User $user): array {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'avatar_url' => $user->avatar_url,
                            'chat_role_label' => $user->chat_role_label,
                            'resolved_dealership_name' => $user->resolved_dealership_name,
                            'is_disabled' => $user->isDisabled(),
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();

        $privateConversations = $conversations
            ->reject(fn (CompanyChatConversation $conversation): bool => $conversation->isGroupConversation())
            ->values();

        return view('tools.chat-beta', [
            'conversations' => $privateConversations,
            'selectedConversation' => $selectedConversation,
            'people' => $people,
            'favoriteContacts' => $favoriteContacts,
            'teamUsers' => $teamUsers,
            'chatGroups' => $chatGroups,
            'search' => $search,
            'favoriteUserIds' => $favoriteUserIds,
            'policyAccepted' => true,
            'policyVersion' => $policyVersion,
            'policyStatusUrl' => route('chat.beta.policy.status'),
            'policyAcceptUrl' => route('chat.beta.policy.accept'),
            'policyReturnRecipient' => null,
            'policyReturnConversation' => null,
        ]);
    }

    public function policyStatus(Request $request): JsonResponse
    {
        abort_unless(app_can_access_chat_beta($request->user()), 403);

        $authUser = $request->user();

        return response()->json([
            'policy_version' => ChatPolicy::version(),
            'accepted' => $this->hasAcceptedChatPolicy($authUser),
        ]);
    }

    public function acceptPolicy(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(app_can_access_chat_beta($request->user()), 403);

        $authUser = $request->user();
        $policyVersion = ChatPolicy::version();

        PolicyAcceptance::query()->updateOrCreate(
            [
                'user_id' => $authUser->id,
                'policy_version' => $policyVersion,
            ],
            [
                'user_email' => $authUser->email,
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => ChatPolicy::SOURCE_WEB_CHAT,
            ],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'accepted' => true,
                'policy_version' => $policyVersion,
            ]);
        }

        $redirectParams = [];

        if ($request->filled('recipient')) {
            $redirectParams['recipient'] = $request->integer('recipient');
        } elseif ($request->filled('conversation')) {
            $redirectParams['conversation'] = $request->integer('conversation');
        } elseif ($request->filled('group')) {
            $redirectParams['group'] = $request->integer('group');
        }

        return redirect()->route('chat.beta', $redirectParams);
    }

    public function storeMessage(Request $request, CompanyChatConversation $conversation): JsonResponse|RedirectResponse
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);
        abort_unless($conversation->involves($request->user()), 403);
        abort_unless($conversation->isGroupConversation() || ! $conversation->otherParticipant($request->user())?->isDisabled(), 403);

        $this->guardAgainstBrokenAttachmentUploads($request);

        $allowedAttachmentMimeRule = 'mimes:jpg,jpeg,png,webp,gif,svg,pdf,txt,md,csv,doc,docx,xls,xlsx,ppt,pptx,zip,rar,json';
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => [
                'file',
                'max:' . self::MAX_ATTACHMENT_FILE_KILOBYTES,
                function (string $attribute, mixed $value, \Closure $fail) use ($allowedAttachmentMimeRule): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    if (strtolower((string) $value->getClientOriginalExtension()) === 'rdp') {
                        return;
                    }

                    if (validator(['file' => $value], ['file' => [$allowedAttachmentMimeRule]])->fails()) {
                        $fail('El campo ' . $attribute . ' debe ser un archivo de tipo: jpg, jpeg, png, webp, gif, svg, pdf, txt, md, csv, doc, docx, xls, xlsx, ppt, pptx, zip, rar, json o rdp.');
                    }
                },
            ],
            'mentioned_user_ids' => ['nullable', 'array'],
            'mentioned_user_ids.*' => ['integer'],
        ]);

        if ($this->attachmentsTotalSize($request) > self::MAX_ATTACHMENT_TOTAL_BYTES) {
            throw ValidationException::withMessages([
                'attachments' => 'El conjunto de archivos adjuntos supera el peso máximo permitido de 30 MB.',
            ]);
        }

        $body = trim((string) ($validated['body'] ?? ''));
        $attachments = $this->storeAttachments($request);
        $mentionedUserIds = $this->sanitizeMentionedUserIds(
            $conversation,
            $request->user(),
            (array) ($validated['mentioned_user_ids'] ?? [])
        );

        if ($body === '' && $attachments === []) {
            throw ValidationException::withMessages([
                'body' => 'Escribe un mensaje o adjunta un archivo.',
            ]);
        }

        $message = DB::transaction(function () use ($conversation, $request, $body, $attachments, $mentionedUserIds): CompanyChatMessage {
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'body' => $body,
                'attachments' => $attachments,
                'mentioned_user_ids' => $mentionedUserIds,
                'read_at' => null,
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at ?? now(),
                'last_message_excerpt' => $message->preview_text,
            ])->save();

            return $message->load('sender');
        });

        $this->hydrateMessageMentions(collect([$message]), $request->user());

        $this->notifyConversationParticipants($conversation, $message, $request->user());
        $targetUserIds = $conversation->participantsFor($request->user())
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->reject(static fn (int $userId): bool => $userId === $request->user()->id)
            ->values()
            ->all();
        broadcast(new CompanyChatMessageCreated(
            $conversation->id,
            $this->messagePayload($message, $request->user(), null, true),
            $targetUserIds
        ))->toOthers();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->messagePayload($message, $request->user(), null, true),
                'conversation_id' => $conversation->id,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'last_message_excerpt' => $conversation->last_message_excerpt,
            ]);
        }

        return redirect()->route('chat.beta', [
            'conversation' => $conversation->id,
        ]);
    }

    public function updateMessage(Request $request, CompanyChatConversation $conversation, CompanyChatMessage $message): JsonResponse|RedirectResponse
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);
        abort_unless($conversation->involves($request->user()), 403);
        abort_unless($message->company_chat_conversation_id === $conversation->id, 404);
        abort_unless($message->canBeEditedOrDeletedBy($request->user()), 403);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
        ]);

        $originalBody = (string) $message->body;
        $originalBodyForComparison = trim($originalBody);
        $body = trim((string) ($validated['body'] ?? ''));
        $hasAttachments = filled($message->attachments);

        if ($body === '' && ! $hasAttachments) {
            throw ValidationException::withMessages([
                'body' => 'Escribe un mensaje o conserva un adjunto.',
            ]);
        }

        if ($body !== $originalBodyForComparison) {
            $editedAt = now();

            DB::transaction(function () use ($message, $originalBody, $body, $editedAt): void {
                CompanyChatMessageRevision::query()->create([
                    'company_chat_message_id' => $message->id,
                    'body' => $originalBody,
                    'edited_at' => $editedAt,
                    'edited_by' => request()->user()?->id,
                ]);

                $message->forceFill([
                    'body' => $body,
                    'edited_at' => $editedAt,
                ])->save();
            });
        }

        $this->refreshConversationSummary($conversation);

        if ($request->expectsJson()) {
            $message = $message->load('sender');
            $this->hydrateMessageMentions(collect([$message]), $request->user());

            return response()->json([
                'message' => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'rendered_body_html' => (string) ($message->rendered_body_html ?? e((string) $message->body)),
                    'preview_text' => $message->preview_text,
                    'sender_id' => $message->sender_id,
                    'sender_name' => $message->sender?->name,
                    'sender_chat_role_label' => app_chat_role_label($message->sender),
                    'sender_is_active' => $message->sender?->is_active,
                    'sender_is_disabled' => $message->sender?->isDisabled(),
                    'is_mine' => true,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'updated_at' => $message->updated_at?->toIso8601String(),
                    'edited_at' => $message->edited_at?->toIso8601String(),
                    'deleted_at' => $message->deleted_at?->toIso8601String(),
                    'created_at_label' => $message->created_at?->translatedFormat('H:i'),
                    'show_time' => true,
                    'read_at' => $message->read_at?->toIso8601String(),
                    'attachments' => $this->formatAttachmentsForPayload($message),
                ],
                'conversation_id' => $conversation->id,
                'last_message_at' => $conversation->fresh()->last_message_at?->toIso8601String(),
                'last_message_excerpt' => $conversation->fresh()->last_message_excerpt,
            ]);
        }

        return redirect()->route('chat.beta', [
            'conversation' => $conversation->id,
        ]);
    }

    public function destroyMessage(Request $request, CompanyChatConversation $conversation, CompanyChatMessage $message): JsonResponse|RedirectResponse
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);
        abort_unless($conversation->involves($request->user()), 403);
        abort_unless($message->company_chat_conversation_id === $conversation->id, 404);
        abort_unless($message->canBeEditedOrDeletedBy($request->user()), 403);

        $attachmentPaths = collect($message->attachments ?? [])
            ->pluck('path')
            ->filter()
            ->map(static fn ($path): string => (string) $path)
            ->values()
            ->all();

        if ($attachmentPaths !== []) {
            Storage::disk('public')->delete($attachmentPaths);
        }

        $messageId = $message->id;
        $message->delete();

        $this->removeChatNotificationsForMessage($conversation, $request->user(), $messageId);
        $this->refreshConversationSummary($conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'conversation_id' => $conversation->id,
                'last_message_at' => $conversation->fresh()->last_message_at?->toIso8601String(),
                'last_message_excerpt' => $conversation->fresh()->last_message_excerpt,
            ]);
        }

        return redirect()->route('chat.beta', [
            'conversation' => $conversation->id,
        ]);
    }

    public function downloadAttachment(Request $request, CompanyChatConversation $conversation, CompanyChatMessage $message, int $attachmentIndex): Response
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);
        abort_unless($conversation->involves($request->user()), 403);
        abort_unless($message->company_chat_conversation_id === $conversation->id, 404);

        $attachments = collect($message->attachments ?? [])->values();

        abort_unless($attachmentIndex >= 0 && $attachmentIndex < $attachments->count(), 404);

        $attachment = $attachments->get($attachmentIndex);
        $path = (string) ($attachment['path'] ?? '');
        $mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
        $downloadName = (string) ($attachment['original_name'] ?? '');
        $downloadName = $downloadName !== '' ? $downloadName : ((basename($path) !== '') ? basename($path) : 'archivo');
        $isJsonAttachment = $this->isJsonAttachment($attachment, $path, $mimeType);

        abort_unless($path !== '' && Storage::disk('public')->exists($path), 404);

        if ($isJsonAttachment) {
            return Storage::disk('public')->download($path, $downloadName, [
                'Content-Type' => $mimeType,
            ]);
        }

        return Storage::disk('public')->response($path, $downloadName, [
            'Content-Type' => $mimeType,
        ]);
    }

    public function messages(Request $request, CompanyChatConversation $conversation): JsonResponse
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);
        abort_unless($conversation->involves($request->user()), 403);

        $authUser = $request->user();
        $favoriteUserIds = $this->favoriteUserIdsFor($authUser);
        $limit = max(1, min((int) $request->integer('limit', self::CHAT_MESSAGES_PAGE_SIZE), 100));
        $beforeMessageId = $this->sanitizeMessageCursor($request->query('before_message_id'));
        $afterMessageId = $this->sanitizeMessageCursor($request->query('after_message_id'));

        $readMessageIds = $this->markConversationAsRead($conversation, $authUser);
        $this->markConversationNotificationsAsRead($conversation, $authUser);

        if ($readMessageIds !== []) {
            $targetUserIds = $conversation->participantsFor($authUser)
                ->pluck('id')
                ->map(static fn ($value): int => (int) $value)
                ->reject(static fn (int $userId): bool => $userId === $authUser->id)
                ->values()
                ->all();

            broadcast(new CompanyChatConversationRead(
                $conversation->id,
                $authUser->id,
                $readMessageIds,
                now()->toIso8601String(),
                $targetUserIds
            ))->toOthers();
        }

        [$messages, $hasMoreOlder, $hasMoreNewer] = $this->conversationMessagesPage(
            $conversation,
            $authUser,
            $limit,
            $beforeMessageId,
            $afterMessageId
        );

        $messagesPayload = $messages->map(function (CompanyChatMessage $message, int $index) use ($authUser, $messages): array {
            $nextMessage = $messages->get($index + 1);

            return $this->messagePayload($message, $authUser, $nextMessage);
        });

        return response()->json([
            'conversation_id' => $conversation->id,
            ...$this->conversationPayload($conversation, $authUser, $favoriteUserIds),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'messages' => $messagesPayload,
            'has_more_older' => $hasMoreOlder,
            'has_more_newer' => $hasMoreNewer,
            'message_count' => $messages->count(),
            'limit' => $limit,
        ]);
    }

    /**
     * @return array{0: Collection<int, CompanyChatMessage>, 1: bool, 2: bool}
     */
    private function conversationMessagesPage(
        CompanyChatConversation $conversation,
        User $authUser,
        int $limit = self::CHAT_MESSAGES_PAGE_SIZE,
        ?int $beforeMessageId = null,
        ?int $afterMessageId = null,
    ): array {
        $query = $conversation->messages()
            ->withTrashed()
            ->with('sender');

        $hasMoreOlder = false;
        $hasMoreNewer = false;

        if ($beforeMessageId !== null) {
            $cursor = $conversation->messages()
                ->withTrashed()
                ->findOrFail($beforeMessageId);

            $messages = $query
                ->where(function ($cursorQuery) use ($cursor): void {
                    $cursorQuery->where('created_at', '<', $cursor->created_at)
                        ->orWhere(function ($tieBreakerQuery) use ($cursor): void {
                            $tieBreakerQuery->where('created_at', '=', $cursor->created_at)
                                ->where('id', '<', $cursor->id);
                        });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get()
                ->reverse()
                ->values();

            $hasMoreOlder = $messages->count() > $limit;

            if ($hasMoreOlder) {
                $messages = $messages->slice(-$limit)->values();
            }
        } elseif ($afterMessageId !== null) {
            $cursor = $conversation->messages()
                ->withTrashed()
                ->findOrFail($afterMessageId);

            $messages = $query
                ->where(function ($cursorQuery) use ($cursor): void {
                    $cursorQuery->where('created_at', '>', $cursor->created_at)
                        ->orWhere(function ($tieBreakerQuery) use ($cursor): void {
                            $tieBreakerQuery->where('created_at', '=', $cursor->created_at)
                                ->where('id', '>', $cursor->id);
                        });
                })
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit + 1)
                ->get()
                ->values();

            $hasMoreNewer = $messages->count() > $limit;

            if ($hasMoreNewer) {
                $messages = $messages->slice(0, $limit)->values();
            }
        } else {
            $messages = $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get()
                ->reverse()
                ->values();

            $hasMoreOlder = $messages->count() > $limit;

            if ($hasMoreOlder) {
                $messages = $messages->slice(-$limit)->values();
            }
        }

        $this->hydrateMessageMentions($messages, $authUser);

        return [$messages, $hasMoreOlder, $hasMoreNewer];
    }

    private function sanitizeMessageCursor(mixed $cursor): ?int
    {
        if (! is_numeric($cursor)) {
            return null;
        }

        $cursor = (int) $cursor;

        return $cursor > 0 ? $cursor : null;
    }

    public function summary(Request $request): JsonResponse
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);

        $authUser = $request->user();
        $favoriteUserIds = $this->favoriteUserIdsFor($authUser);

        $privateConversations = CompanyChatConversation::query()
            ->forUser($authUser)
            ->whereNull('company_chat_group_id')
            ->with(['userOne', 'userTwo'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CompanyChatConversation $conversation) use ($authUser, $favoriteUserIds): array {
                $unreadMessagesCount = $this->conversationUnreadMessagesCount($conversation, $authUser);

                return [
                    'id' => $conversation->id,
                    ...$this->conversationPayload($conversation, $authUser, $favoriteUserIds),
                    'last_message_excerpt' => $conversation->last_message_excerpt,
                    'last_message_at_label' => $conversation->last_message_at?->translatedFormat('d/m H:i'),
                    'unread_messages_count' => $unreadMessagesCount,
                ];
            });

        $chatGroups = $authUser->chatGroups()
            ->withCount('participants')
            ->with(['participants' => function ($query): void {
                $query->orderBy('name');
            }, 'conversation' => function ($query): void {
                $query->with(['messages' => function ($messageQuery): void {
                    $messageQuery->with('reads');
                }]);
            }])
            ->get();
        $chatGroups = $this->sortChatGroupsForSidebar($chatGroups);

        $chatGroupsPayload = $chatGroups->map(function (CompanyChatGroup $chatGroup) use ($authUser): array {
            $conversation = $chatGroup->conversation;
            $participants = $chatGroup->participants;

            return [
                'id' => $chatGroup->id,
                'group_id' => $chatGroup->id,
                'system_group_type' => $chatGroup->system_group_type,
                'conversation_id' => $conversation?->id,
                'conversation_is_group' => true,
                'conversation_type_label' => 'Grupo',
                'conversation_name' => $chatGroup->name,
                'conversation_avatar_url' => $this->groupAvatarUrl($chatGroup),
                'conversation_profile_url' => null,
                'conversation_chat_role_label' => 'Grupo',
                'conversation_dealership_name' => $participants->count() . ' participantes',
                'conversation_is_active' => true,
                'conversation_is_disabled' => false,
                'conversation_status_label' => $participants->count() . ' participantes',
                'conversation_is_favorite' => false,
                'conversation_participants_count' => $participants->count(),
                'last_message_excerpt' => $conversation?->last_message_excerpt,
                'last_message_at_label' => $conversation?->last_message_at?->translatedFormat('d/m H:i'),
                'unread_messages_count' => $this->conversationUnreadMessagesCount($conversation, $authUser),
            ];
        })->values();

        $favoriteContacts = $this->favoriteContactsPayload($authUser);

        return response()->json([
            'unread_messages_total' => $privateConversations->sum('unread_messages_count'),
            'conversations' => $privateConversations,
            'chat_groups' => $chatGroupsPayload,
            'favorite_contacts' => $favoriteContacts,
        ]);
    }

    public function toggleFavorite(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $this->ensureChatPolicyAccepted($request);
        abort_unless(app_can_access_chat_beta($request->user()), 403);

        $authUser = $request->user();
        abort_unless($user->is_active, 404);
        abort_unless($user->id !== $authUser->id, 403);

        $favorite = CompanyChatFavoriteContact::query()
            ->where('user_id', $authUser->id)
            ->where('favorite_user_id', $user->id)
            ->first();

        if ($favorite) {
            $favorite->delete();
            $isFavorite = false;
        } else {
            CompanyChatFavoriteContact::query()->create([
                'user_id' => $authUser->id,
                'favorite_user_id' => $user->id,
            ]);

            $isFavorite = true;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'favorite_user_id' => $user->id,
                'is_favorite' => $isFavorite,
                'favorite_contacts' => $this->favoriteContactsPayload($authUser),
            ]);
        }

        return back();
    }

    private function findOrCreateConversation(User $firstUser, User $secondUser): CompanyChatConversation
    {
        [$userOneId, $userTwoId] = CompanyChatConversation::sortParticipantIds($firstUser, $secondUser);

        return DB::transaction(function () use ($userOneId, $userTwoId): CompanyChatConversation {
            return CompanyChatConversation::query()->firstOrCreate([
                'user_one_id' => $userOneId,
                'user_two_id' => $userTwoId,
            ]);
        });
    }

    private function findOrCreateGroupConversation(CompanyChatGroup $group): CompanyChatConversation
    {
        return DB::transaction(function () use ($group): CompanyChatConversation {
            return CompanyChatConversation::query()->firstOrCreate([
                'company_chat_group_id' => $group->id,
            ]);
        });
    }

    /**
     * @param  Collection<int, CompanyChatGroup>  $chatGroups
     * @return Collection<int, CompanyChatGroup>
     */
    private function sortChatGroupsForSidebar(Collection $chatGroups): Collection
    {
        return $chatGroups
            ->sort(function (CompanyChatGroup $leftGroup, CompanyChatGroup $rightGroup): int {
                $leftConversation = $leftGroup->conversation;
                $rightConversation = $rightGroup->conversation;
                $leftTimestamp = $leftConversation?->last_message_at?->timestamp;
                $rightTimestamp = $rightConversation?->last_message_at?->timestamp;

                if ($leftTimestamp === null && $rightTimestamp === null) {
                    return strcasecmp($leftGroup->name, $rightGroup->name);
                }

                if ($leftTimestamp === null) {
                    return 1;
                }

                if ($rightTimestamp === null) {
                    return -1;
                }

                if ($leftTimestamp !== $rightTimestamp) {
                    return $rightTimestamp <=> $leftTimestamp;
                }

                return strcasecmp($leftGroup->name, $rightGroup->name);
            })
            ->values();
    }

    private function hasAcceptedChatPolicy(?User $user): bool
    {
        return (bool) $user && $user->hasAcceptedPolicyVersion(ChatPolicy::version());
    }

    private function ensureChatPolicyAccepted(Request $request): void
    {
        abort_unless($this->hasAcceptedChatPolicy($request->user()), 403);
    }

    /**
     * @return array<int, int>
     */
    private function markConversationAsRead(CompanyChatConversation $conversation, User $user): array
    {
        if ($conversation->isGroupConversation()) {
            return $this->markGroupConversationAsRead($conversation, $user);
        }

        $messageIds = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->values()
            ->all();

        if ($messageIds === []) {
            return [];
        }

        $conversation->messages()
            ->whereKey($messageIds)
            ->update(['read_at' => now()]);

        return $messageIds;
    }

    /**
     * @return array<int, int>
     */
    private function markGroupConversationAsRead(CompanyChatConversation $conversation, User $user): array
    {
        $conversation->loadMissing('chatGroup.participants');

        $messages = $conversation->messages()
            ->with('sender')
            ->where(function ($query) use ($user): void {
                $query->whereNull('sender_id')
                    ->orWhere('sender_id', '!=', $user->id);
            })
            ->whereDoesntHave('reads', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->get();

        if ($messages->isEmpty()) {
            return [];
        }

        $now = now();
        $messageIds = $messages
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->values()
            ->all();

        foreach ($messages->chunk(500) as $messageChunk) {
            CompanyChatMessageRead::query()->upsert(
                $messageChunk->map(static function (CompanyChatMessage $message) use ($user, $now): array {
                    return [
                        'company_chat_message_id' => $message->id,
                        'user_id' => $user->id,
                        'read_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->all(),
                ['company_chat_message_id', 'user_id'],
                ['read_at', 'updated_at']
            );
        }

        $participantCount = (int) ($conversation->chatGroup?->participants?->count() ?? 0);

        if ($participantCount > 0) {
            $readCountsByMessageId = CompanyChatMessageRead::query()
                ->selectRaw('company_chat_message_id, COUNT(DISTINCT user_id) as read_count')
                ->whereIn('company_chat_message_id', $messageIds)
                ->groupBy('company_chat_message_id')
                ->pluck('read_count', 'company_chat_message_id');

            $fullyReadMessageIds = $messages
                ->filter(function (CompanyChatMessage $message) use ($participantCount, $readCountsByMessageId): bool {
                    $requiredReaderCount = $message->isSystemMessage() || ! $message->sender instanceof User
                        ? $participantCount
                        : max(0, $participantCount - 1);

                    return (int) ($readCountsByMessageId->get($message->id) ?? 0) >= $requiredReaderCount;
                })
                ->pluck('id')
                ->map(static fn ($value): int => (int) $value)
                ->values()
                ->all();

            if ($fullyReadMessageIds !== []) {
                CompanyChatMessage::query()
                    ->whereKey($fullyReadMessageIds)
                    ->whereNull('read_at')
                    ->update(['read_at' => $now]);
            }
        }

        return $messageIds;
    }

    private function refreshGroupMessageReadState(CompanyChatConversation $conversation, CompanyChatMessage $message): void
    {
        if (! $conversation->isGroupConversation()) {
            return;
        }

        if ($message->isSystemMessage() || ! $message->sender instanceof User) {
            $requiredReaderIds = $conversation->chatGroup?->participants
                ->pluck('id')
                ->filter()
                ->values();
        } else {
            $requiredReaderIds = $conversation->participantsFor($message->sender)
                ->pluck('id')
                ->reject(fn (int $userId): bool => $userId === $message->sender_id)
                ->values();
        }

        if ($requiredReaderIds->isEmpty()) {
            if ($message->read_at === null) {
                $message->forceFill(['read_at' => now()])->save();
            }

            return;
        }

        $readCount = CompanyChatMessageRead::query()
            ->where('company_chat_message_id', $message->id)
            ->whereIn('user_id', $requiredReaderIds->all())
            ->distinct()
            ->count('user_id');

        if ($readCount >= $requiredReaderIds->count() && $message->read_at === null) {
            $message->forceFill(['read_at' => now()])->save();
        }
    }

    private function markConversationNotificationsAsRead(CompanyChatConversation $conversation, User $user): void
    {
        $user->unreadNotifications()
            ->where('type', CompanyChatMessageNotification::class)
            ->where('data->conversation_id', $conversation->id)
            ->update(['read_at' => now()]);

        app(NotificationBadgeBroadcaster::class)->broadcast($user);
    }

    private function conversationUnreadMessagesCount(?CompanyChatConversation $conversation, User $user): int
    {
        if (! $conversation) {
            return 0;
        }

        if ($conversation->isGroupConversation()) {
            return (int) $conversation->messages()
                ->where(function ($query) use ($user): void {
                    $query->whereNull('sender_id')
                        ->orWhere('sender_id', '!=', $user->id);
                })
                ->whereDoesntHave('reads', function ($query) use ($user): void {
                    $query->where('user_id', $user->id);
                })
                ->count();
        }

        return (int) $conversation->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->count();
    }

    private function removeChatNotificationsForMessage(CompanyChatConversation $conversation, User $user, int $messageId): void
    {
        $recipients = $conversation->participantsFor($user)
            ->reject(fn (User $participant): bool => $participant->id === $user->id);

        if ($recipients->isEmpty()) {
            return;
        }

        foreach ($recipients as $recipient) {
            $deletedCount = $recipient->notifications()
                ->where('type', CompanyChatMessageNotification::class)
                ->where('data->conversation_id', $conversation->id)
                ->where('data->message_id', $messageId)
                ->delete();

            if ($deletedCount > 0) {
                app(NotificationBadgeBroadcaster::class)->broadcast($recipient);
            }
        }
    }

    private function refreshConversationSummary(CompanyChatConversation $conversation): void
    {
        $latestMessage = $conversation->messages()
            ->withTrashed()
            ->with('sender')
            ->orderByDesc('created_at')
            ->first();

        $conversation->forceFill([
            'last_message_at' => $latestMessage?->created_at,
            'last_message_excerpt' => $latestMessage?->preview_text,
        ])->save();
    }

    /**
     * @return array<int, int>
     */
    private function favoriteUserIdsFor(User $user): array
    {
        return $user->chatFavoriteContacts()
            ->pluck('favorite_user_id')
            ->map(static fn ($value): int => (int) $value)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function favoriteContactsPayload(User $user): array
    {
        $favoriteUsers = User::query()
            ->where('is_active', true)
            ->whereIn('id', $this->favoriteUserIdsFor($user))
            ->orderBy('name')
            ->get();

        return $favoriteUsers->map(function (User $favoriteUser) use ($user): array {
            return [
                'id' => $favoriteUser->id,
                'name' => $favoriteUser->name,
                'avatar_url' => $favoriteUser->avatar_url,
                'chat_role_label' => $favoriteUser->chat_role_label,
                'resolved_dealership_name' => $favoriteUser->resolved_dealership_name,
                'is_disabled' => $favoriteUser->isDisabled(),
                'is_favorite' => true,
            ];
        })->values()->all();
    }

    private function notifyConversationParticipants(CompanyChatConversation $conversation, CompanyChatMessage $message, User $sender): void
    {
        $conversation->participantsFor($sender)
            ->reject(fn (User $participant): bool => $participant->id === $sender->id)
            ->each(function (User $participant) use ($conversation, $message, $sender): void {
                $participant->notify(new CompanyChatMessageNotification($conversation, $message, $sender));
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationPayload(CompanyChatConversation $conversation, User $authUser, array $favoriteUserIds = []): array
    {
        $isGroup = $conversation->isGroupConversation();
        $partner = $conversation->otherParticipant($authUser);
        $group = $conversation->chatGroup;
        $participants = $conversation->participantsFor($authUser);
        $participantsCount = $participants->count();
        $partnerRoleLabel = app_chat_role_label($partner);
        $partnerDealershipName = $partner?->resolved_dealership_name ?? 'Sin delegación';

        return [
            'conversation_is_group' => $isGroup,
            'conversation_system_group_type' => $isGroup ? $group?->system_group_type : null,
            'conversation_type_label' => $isGroup ? 'Grupo' : 'Privada',
            'partner_id' => $isGroup ? null : $partner?->id,
            'partner_name' => $isGroup ? ($group?->name ?? 'Grupo de chat') : $partner?->name,
            'partner_avatar_url' => $isGroup
                ? $this->groupAvatarUrl($group)
                : ($partner?->avatar_url ?? asset('images/users/hrmotor-default-user-avatar.png')),
            'partner_profile_url' => $isGroup ? null : ($partner ? route('users.show', $partner) : null),
            'partner_chat_role_label' => $isGroup ? 'Grupo' : $partnerRoleLabel,
            'partner_dealership_name' => $isGroup ? ($participantsCount . ' participantes') : $partnerDealershipName,
            'partner_is_active' => $isGroup ? true : $partner?->is_active,
            'partner_is_disabled' => $isGroup ? false : $partner?->isDisabled(),
            'partner_status_label' => $isGroup
                ? ($participantsCount . ' participantes')
                : ($partner?->isDisabled() ? 'Usuario desactivado' : ($partner?->is_active ? 'Activo' : 'Pendiente')),
            'partner_is_favorite' => $isGroup ? false : ($partner?->id ? in_array($partner->id, $favoriteUserIds, true) : false),
            'conversation_name' => $isGroup ? ($group?->name ?? 'Grupo de chat') : ($partner?->name ?? 'Conversación'),
            'conversation_avatar_url' => $isGroup
                ? $this->groupAvatarUrl($group)
                : ($partner?->avatar_url ?? asset('images/users/hrmotor-default-user-avatar.png')),
            'conversation_profile_url' => $isGroup ? null : ($partner ? route('users.show', $partner) : null),
            'conversation_chat_role_label' => $isGroup ? 'Grupo' : $partnerRoleLabel,
            'conversation_dealership_name' => $isGroup ? ($participantsCount . ' participantes') : $partnerDealershipName,
            'conversation_is_active' => $isGroup ? true : $partner?->is_active,
            'conversation_is_disabled' => $isGroup ? false : $partner?->isDisabled(),
            'conversation_status_label' => $isGroup
                ? ($participantsCount . ' participantes')
                : ($partner?->isDisabled() ? 'Usuario desactivado' : ($partner?->is_active ? 'Activo' : 'Pendiente')),
            'conversation_is_favorite' => $isGroup ? false : ($partner?->id ? in_array($partner->id, $favoriteUserIds, true) : false),
            'conversation_participants_count' => $participantsCount,
            'conversation_participants_text' => $participants->pluck('name')->implode(', '),
            'conversation_participants' => $participants->map(function (User $participant) use ($favoriteUserIds): array {
                return [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'profile_url' => route('users.show', $participant),
                    'avatar_url' => $participant->avatar_url,
                    'resolved_dealership_name' => $participant->resolved_dealership_name,
                    'extra_role_label' => $participant->extra_role ? (User::extraRoleLabels()[$participant->extra_role] ?? ucfirst((string) $participant->extra_role)) : null,
                    'chat_role_label' => app_chat_role_label($participant),
                    'is_disabled' => $participant->isDisabled(),
                    'is_favorite' => in_array($participant->id, $favoriteUserIds, true),
                ];
            })->values()->all(),
            'conversation_participants_preview' => $participants->take(3)->map(function (User $participant) use ($favoriteUserIds): array {
                return [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'avatar_url' => $participant->avatar_url,
                    'chat_role_label' => app_chat_role_label($participant),
                    'resolved_dealership_name' => $participant->resolved_dealership_name,
                    'is_disabled' => $participant->isDisabled(),
                    'is_favorite' => in_array($participant->id, $favoriteUserIds, true),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param Collection<int, CompanyChatMessage> $messages
     */
    private function hydrateMessageMentions(Collection $messages, User $authUser): void
    {
        $mentionUserIds = $messages
            ->flatMap(function (CompanyChatMessage $message): array {
                return array_map(
                    static fn ($value): int => (int) $value,
                    (array) ($message->mentioned_user_ids ?? [])
                );
            })
            ->filter()
            ->unique()
            ->values();

        $mentionedUsersById = $mentionUserIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $mentionUserIds->all())->get()->keyBy('id');

        $messages->each(function (CompanyChatMessage $message) use ($authUser, $mentionedUsersById): void {
            $mentionedUserIds = collect((array) ($message->mentioned_user_ids ?? []))
                ->map(static fn ($value): int => (int) $value)
                ->filter()
                ->unique()
                ->values();

            $mentionedUsers = $mentionedUserIds
                ->map(fn (int $mentionedUserId): ?User => $mentionedUsersById->get($mentionedUserId))
                ->filter()
                ->values();

            $message->setAttribute('mentioned_user_ids', $mentionedUserIds->all());
            $message->setAttribute('mentioned_users', $mentionedUsers->map(function (User $mentionedUser): array {
                return [
                    'id' => $mentionedUser->id,
                    'name' => $mentionedUser->name,
                ];
            })->values()->all());
            $message->setAttribute('mentions_auth_user', $mentionedUserIds->contains($authUser->id));
            $message->setAttribute('rendered_body_html', $this->renderMessageBodyHtml((string) $message->body, $mentionedUsers->all()));
        });
    }

    /**
     * @param array<int, int|string> $mentionedUserIds
     * @return array<int, int>
     */
    private function sanitizeMentionedUserIds(CompanyChatConversation $conversation, User $sender, array $mentionedUserIds): array
    {
        if (! $conversation->isGroupConversation()) {
            return [];
        }

        $participantIds = $conversation->participantsFor($sender)
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return collect($mentionedUserIds)
            ->map(static fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value !== $sender->id)
            ->filter(fn (int $value): bool => in_array($value, $participantIds, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, User|array{id:int,name:string}> $mentionedUsers
     */
    private function renderMessageBodyHtml(string $body, array $mentionedUsers = []): string
    {
        $escapedBody = e($body);

        if ($escapedBody === '') {
            return $escapedBody;
        }

        if ($mentionedUsers !== []) {
            usort($mentionedUsers, static function ($first, $second): int {
                $firstName = (string) (is_array($first) ? ($first['name'] ?? '') : $first->name);
                $secondName = (string) (is_array($second) ? ($second['name'] ?? '') : $second->name);

                return mb_strlen($secondName) <=> mb_strlen($firstName);
            });

            foreach ($mentionedUsers as $mentionedUser) {
                $name = trim((string) (is_array($mentionedUser) ? ($mentionedUser['name'] ?? '') : $mentionedUser->name));

                if ($name === '') {
                    continue;
                }

                $escapedMention = e('@' . $name);
                $replacement = '<span class="font-semibold text-sky-600">@' . e($name) . '</span>';
                $escapedBody = str_replace($escapedMention, $replacement, $escapedBody);
            }
        }

        return $this->linkifyMessageBodyHtml($escapedBody);
    }

    private function linkifyMessageBodyHtml(string $html): string
    {
        return (string) preg_replace_callback(
            '/\b((?:https?:\/\/|www\.)[^\s<]+)/i',
            static function (array $matches): string {
                $url = $matches[1];
                $trailing = '';

                while ($url !== '' && preg_match('/[)\]\}.,!?;:]+$/', $url) === 1) {
                    $trailingChar = mb_substr($url, -1);
                    $trailing = $trailingChar . $trailing;
                    $url = mb_substr($url, 0, -1);
                }

                if ($url === '') {
                    return $matches[1];
                }

                $href = str_starts_with($url, 'www.') ? 'https://' . $url : $url;

                return '<a href="' . e($href) . '" target="_blank" rel="noopener noreferrer" class="break-words [overflow-wrap:anywhere] font-medium text-sky-600 underline decoration-sky-400/70 underline-offset-2 transition hover:text-sky-700">' . e($url) . '</a>' . e($trailing);
            },
            $html
        );
    }

    private function messagePayload(CompanyChatMessage $message, User $authUser, ?CompanyChatMessage $nextMessage = null, bool $forceShowTime = false): array
    {
        $currentLabel = $message->created_at?->translatedFormat('H:i');
        $nextLabel = $nextMessage?->created_at?->translatedFormat('H:i');
        $currentDateKey = $message->created_at?->format('Y-m-d');
        $nextDateKey = $nextMessage?->created_at?->format('Y-m-d');
        $isSystem = $message->isSystemMessage();

        return [
            'id' => $message->id,
            'body' => $message->body,
            'rendered_body_html' => (string) ($message->rendered_body_html ?? e((string) $message->body)),
            'preview_text' => $message->preview_text,
            'sender_id' => $message->sender_id,
            'sender_name' => $isSystem ? null : $message->sender?->name,
            'sender_chat_role_label' => $isSystem ? 'Sistema' : app_chat_role_label($message->sender),
            'sender_is_active' => $isSystem ? false : $message->sender?->is_active,
            'sender_is_disabled' => $isSystem ? false : $message->sender?->isDisabled(),
            'is_mine' => ! $isSystem && $message->sender_id === $authUser->id,
            'is_system' => $isSystem,
            'mentioned_user_ids' => collect((array) ($message->mentioned_user_ids ?? []))
                ->map(static fn ($value): int => (int) $value)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'mentioned_users' => collect((array) ($message->mentioned_users ?? []))
                ->map(static fn ($user): array => [
                    'id' => (int) ($user['id'] ?? 0),
                    'name' => (string) ($user['name'] ?? ''),
                ])
                ->filter(fn (array $user): bool => $user['id'] > 0 && $user['name'] !== '')
                ->values()
                ->all(),
            'mentions_me' => (bool) ($message->mentions_auth_user ?? false),
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'deleted_at' => $message->deleted_at?->toIso8601String(),
            'created_at_label' => $currentLabel,
            'show_time' => $forceShowTime || $nextDateKey !== $currentDateKey || $nextLabel !== $currentLabel,
            'read_at' => $message->read_at?->toIso8601String(),
            'attachments' => $this->formatAttachmentsForPayload($message),
        ];
    }

    private function groupAvatarUrl(?CompanyChatGroup $group): string
    {
        return $group?->avatar_url ?? '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeAttachments(Request $request): array
    {
        if (! $request->hasFile('attachments')) {
            return [];
        }

        $directory = storage_path('app/public/chat/attachments');
        File::ensureDirectoryExists($directory);

        $storedAttachments = [];

        foreach ((array) $request->file('attachments') as $file) {
            if (! $file) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $size = (int) $file->getSize();
            $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream');
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $filename = Str::uuid()->toString() . '.' . $extension;
            $file->move($directory, $filename);
            $path = 'chat/attachments/' . $filename;

            $storedAttachments[] = [
                'path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size' => $size,
                'is_image' => str_starts_with($mimeType, 'image/'),
                'url' => Storage::disk('public')->url($path),
            ];
        }

        return $storedAttachments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatAttachmentsForPayload(CompanyChatMessage $message): array
    {
        return collect($message->attachments ?? [])
            ->map(function (array $attachment, int $index) use ($message): array {
                $path = (string) ($attachment['path'] ?? '');
                $mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
                $originalName = (string) ($attachment['original_name'] ?? '');
                $resolvedOriginalName = $originalName !== '' ? $originalName : (basename($path) !== '' ? basename($path) : 'archivo');

                return [
                    'path' => $path,
                    'original_name' => $resolvedOriginalName,
                    'mime_type' => $mimeType,
                    'size' => (int) ($attachment['size'] ?? 0),
                    'size_label' => $this->formatBytes((int) ($attachment['size'] ?? 0)),
                    'is_image' => (bool) ($attachment['is_image'] ?? str_starts_with($mimeType, 'image/')),
                    'is_json' => $this->isJsonAttachment($attachment, $path, $mimeType),
                    'url' => route('chat.beta.attachments.show', [
                        'conversation' => $message->company_chat_conversation_id,
                        'message' => $message->id,
                        'attachmentIndex' => $index,
                    ]),
                ];
            })
            ->values()
            ->all();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }

        return $bytes . ' B';
    }

    private function attachmentsTotalSize(Request $request): int
    {
        return collect((array) $request->file('attachments'))
            ->filter()
            ->sum(static fn ($file): int => (int) ($file?->getSize() ?? 0));
    }

    private function isJsonAttachment(array $attachment, string $path, string $mimeType): bool
    {
        $originalName = strtolower((string) ($attachment['original_name'] ?? ''));
        $resolvedPath = strtolower($path);

        return $mimeType === 'application/json'
            || str_ends_with($originalName, '.json')
            || str_ends_with($resolvedPath, '.json');
    }

    private function guardAgainstBrokenAttachmentUploads(Request $request): void
    {
        collect((array) $request->file('attachments'))
            ->filter()
            ->each(function (mixed $file, int $index): void {
                if (! $file instanceof UploadedFile) {
                    return;
                }

                if ($file->isValid()) {
                    return;
                }

                $message = match ($file->getError()) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el servidor. Prueba con una versión más pequeña o revisa la configuración de subida.',
                    UPLOAD_ERR_PARTIAL => 'El archivo se ha subido solo parcialmente. Vuelve a intentarlo.',
                    UPLOAD_ERR_NO_FILE => 'No se ha recibido ningún archivo adjunto.',
                    UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene directorio temporal disponible para subir archivos.',
                    UPLOAD_ERR_CANT_WRITE => 'El servidor no ha podido escribir el archivo temporal.',
                    UPLOAD_ERR_EXTENSION => 'Una extensión del servidor ha bloqueado la subida de este archivo.',
                    default => 'No se pudo subir este archivo adjunto. Comprueba que no supera el tamaño permitido por el servidor e inténtalo de nuevo.',
                };

                throw ValidationException::withMessages([
                    "attachments.$index" => $message,
                ]);
            });
    }
}
