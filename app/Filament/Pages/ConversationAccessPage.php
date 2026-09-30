<?php

namespace App\Filament\Pages;

use App\Models\CompanyChatConversation;
use App\Models\CompanyChatConversationAccessAudit;
use App\Models\Dealership;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ConversationAccessPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-eye';
    protected static ?string $navigationLabel = 'Acceso a conversaciones';
    protected static ?string $title = 'Acceso justificado a conversaciones';
    protected static ?string $slug = 'acceso-conversacion';
    protected static string|\UnitEnum|null $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 6;
    protected static ?string $breadcrumb = 'Acceso a conversaciones';
    protected string $view = 'filament.pages.conversation-access';

    public ?CompanyChatConversation $selectedConversation = null;
    public mixed $selectedMessages = [];
    public int $selectedMessagesPage = 1;
    public int $selectedMessagesPerPage = 50;
    public int $selectedMessagesTotal = 0;
    public bool $contentUnlocked = false;

    private const ACCESS_GRANT_SESSION_KEY = 'filament_conversation_access_grant';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'conversation-access.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CompanyChatConversation::query()
                ->with(['userOne', 'userTwo', 'chatGroup.participants'])
                ->withCount('messages'))
            ->defaultSort('last_message_at', 'desc')
            ->toolbarActions([
                Action::make('viewLog')
                    ->label('Ver log')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(ConversationAccessLogsPage::getUrl())
                    ->visible(fn (): bool => static::canAccess()),
            ])
            ->filters([
                SelectFilter::make('conversation_type')
                    ->label('Tipo de conversación')
                    ->options([
                        'group' => 'Grupo',
                        'private' => 'Privada',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'group' => $query->whereNotNull('company_chat_group_id'),
                            'private' => $query->whereNull('company_chat_group_id'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('dealership')
                    ->label('Delegación')
                    ->options(fn (): array => Dealership::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $dealershipId = $data['value'] ?? null;

                        if (blank($dealershipId)) {
                            return $query;
                        }

                        $dealershipName = Dealership::query()
                            ->whereKey($dealershipId)
                            ->value('name');

                        return $query->where(function (Builder $conversationQuery) use ($dealershipId, $dealershipName): void {
                            $conversationQuery
                                ->whereHas('userOne', function (Builder $userQuery) use ($dealershipId, $dealershipName): void {
                                    $userQuery->where('dealership_id', $dealershipId)
                                        ->when($dealershipName, fn (Builder $query): Builder => $query->orWhere('dealership', $dealershipName));
                                })
                                ->orWhereHas('userTwo', function (Builder $userQuery) use ($dealershipId, $dealershipName): void {
                                    $userQuery->where('dealership_id', $dealershipId)
                                        ->when($dealershipName, fn (Builder $query): Builder => $query->orWhere('dealership', $dealershipName));
                                })
                                ->orWhereHas('chatGroup.participants', function (Builder $userQuery) use ($dealershipId, $dealershipName): void {
                                    $userQuery->where('dealership_id', $dealershipId)
                                        ->when($dealershipName, fn (Builder $query): Builder => $query->orWhere('dealership', $dealershipName));
                                });
                        });
                    }),
            ])
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('conversation_type_label')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Grupo' ? 'info' : 'gray'),
                TextColumn::make('target_label')
                    ->label('Conversación')
                    ->state(fn (CompanyChatConversation $record): string => $record->retention_hold_target_label)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->whereHas('userOne', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->orWhereHas('userTwo', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->orWhereHas('chatGroup', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('chatGroup.participants', fn (Builder $q): Builder => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
                        });
                    })
                    ->wrap(),
                TextColumn::make('last_message_at')
                    ->label('Último mensaje')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin mensajes')
                    ->sortable(),
                TextColumn::make('messages_count')
                    ->label('Mensajes')
                    ->numeric(),
            ])
            ->actions([
                Action::make('access')
                    ->label('Seleccionar conversación')
                    ->icon('heroicon-o-lock-open')
                    ->color('primary')
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('warning')
                    ->extraModalWindowAttributes(['class' => 'conversation-access-modal-window'])
                    ->modalHeading('Vas a acceder al contenido de una conversación en la que no participas.')
                    ->modalContent(new HtmlString(<<<'HTML'
                        <div class="space-y-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            <p>Este acceso debe estar justificado por motivos de seguridad, cumplimiento normativo, investigación de incidencias, mantenimiento técnico, requerimiento legal o control laboral proporcionado.</p>
                            <p>El acceso quedará registrado en el sistema de auditoría.</p>
                            <p>Indica el motivo del acceso:</p>
                        </div>
                        HTML
                    ))
                    ->form([
                        Textarea::make('reason')
                            ->label('Motivo')
                            ->placeholder('Indica el motivo justificado del acceso...')
                            ->rows(6)
                            ->required()
                            ->maxLength(2000)
                            ->rules(['not_regex:/^\s*$/'])
                            ->validationMessages([
                                'not_regex' => 'El motivo no puede estar vacío.',
                            ]),
                    ])
                    ->modalCancelActionLabel('Cancelar')
                    ->modalSubmitActionLabel('Registrar motivo y acceder')
                    ->action(function (CompanyChatConversation $record, array $data): void {
                        $this->grantAccess($record, (string) ($data['reason'] ?? ''));
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    private function grantAccess(CompanyChatConversation $record, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            Notification::make()->danger()->title('Indica un motivo antes de continuar.')->send();
            return;
        }

        try {
            $affectedUserIds = array_values(array_filter([
                $record->user_one_id,
                $record->user_two_id,
                ...($record->chatGroup?->participants?->pluck('id')->all() ?? []),
            ]));

            $audit = DB::transaction(fn (): CompanyChatConversationAccessAudit => CompanyChatConversationAccessAudit::query()->create([
                'company_chat_conversation_id' => $record->id,
                'admin_user_id' => auth()->id(),
                'admin_email' => auth()->user()?->email,
                'action' => 'conversation_content_access',
                'conversation_type' => $record->conversation_type_label,
                'affected_user_ids' => $affectedUserIds,
                'reason' => $reason,
                'accessed_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'result' => 'granted',
            ]));

            session()->put(self::ACCESS_GRANT_SESSION_KEY, [
                'conversation_id' => $record->id,
                'audit_id' => $audit->id,
                'reason' => $reason,
            ]);

            $this->selectedConversation = $record->load(['userOne', 'userTwo', 'chatGroup.participants']);
            $this->selectedMessagesPage = 1;
            $this->loadSelectedMessagesPage();
            $this->contentUnlocked = true;

            Notification::make()
                ->success()
                ->title('Acceso registrado')
                ->body('Auditoría #' . $audit->id . '. Ahora puedes consultar el contenido.')
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            $this->selectedMessages = [];
            $this->selectedMessagesPage = 1;
            $this->selectedMessagesTotal = 0;
            $this->contentUnlocked = false;
            Notification::make()
                ->danger()
                ->title('No se pudo registrar el acceso')
                ->body('No se ha mostrado ningún mensaje. Inténtalo de nuevo o contacta con soporte.')
                ->send();
        }
    }

    public function goToMessagesPage(int $page): void
    {
        if (! static::canAccess() || ! $this->contentUnlocked || ! $this->selectedConversation) {
            return;
        }

        $lastPage = max(1, (int) ceil($this->selectedMessagesTotal / $this->selectedMessagesPerPage));
        $this->selectedMessagesPage = max(1, min($page, $lastPage));
        $this->loadSelectedMessagesPage();
    }

    private function loadSelectedMessagesPage(): void
    {
        if (! $this->selectedConversation) {
            $this->selectedMessages = [];
            $this->selectedMessagesTotal = 0;

            return;
        }

        $messagesQuery = $this->selectedConversation->messages()->withTrashed();
        $this->selectedMessagesTotal = (clone $messagesQuery)->count();
        $lastPage = max(1, (int) ceil($this->selectedMessagesTotal / $this->selectedMessagesPerPage));
        $this->selectedMessagesPage = min($this->selectedMessagesPage, $lastPage);
        $this->selectedMessages = $messagesQuery
            ->with(['sender', 'revisions'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->forPage($this->selectedMessagesPage, $this->selectedMessagesPerPage)
            ->get();
    }

    public function downloadConversationCsv(): ?StreamedResponse
    {
        if (! static::canAccess() || ! $this->contentUnlocked || ! $this->selectedConversation) {
            Notification::make()
                ->danger()
                ->title('La conversación no está autorizada para exportación.')
                ->send();

            return null;
        }

        $grant = session(self::ACCESS_GRANT_SESSION_KEY);

        if (! is_array($grant)
            || (int) ($grant['conversation_id'] ?? 0) !== (int) $this->selectedConversation->id
            || blank($grant['reason'] ?? null)
            || blank($grant['audit_id'] ?? null)
        ) {
            Notification::make()
                ->danger()
                ->title('El acceso autorizado ya no está disponible.')
                ->send();

            return null;
        }

        $accessAudit = CompanyChatConversationAccessAudit::query()
            ->whereKey((int) $grant['audit_id'])
            ->where('company_chat_conversation_id', $this->selectedConversation->id)
            ->where('admin_user_id', auth()->id())
            ->where('result', 'granted')
            ->first();

        if (! $accessAudit) {
            Notification::make()
                ->danger()
                ->title('No se ha podido verificar la autorización de la conversación.')
                ->send();

            return null;
        }

        $conversation = CompanyChatConversation::query()
            ->with(['userOne', 'userTwo', 'chatGroup.participants'])
            ->find($this->selectedConversation->id);

        if (! $conversation) {
            Notification::make()->danger()->title('La conversación ya no existe.')->send();

            return null;
        }

        try {
            $affectedUserIds = array_values(array_filter([
                $conversation->user_one_id,
                $conversation->user_two_id,
                ...($conversation->chatGroup?->participants?->pluck('id')->all() ?? []),
            ]));

            DB::transaction(function () use ($conversation, $grant, $affectedUserIds): void {
                CompanyChatConversationAccessAudit::query()->create([
                    'company_chat_conversation_id' => $conversation->id,
                    'admin_user_id' => auth()->id(),
                    'admin_email' => auth()->user()?->email,
                    'action' => 'conversation_content_export',
                    'conversation_type' => $conversation->conversation_type_label,
                    'affected_user_ids' => $affectedUserIds,
                    'reason' => $grant['reason'],
                    'accessed_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'result' => 'granted',
                ]);
            });

            $messages = $conversation->messages()
                ->withTrashed()
                ->with(['sender', 'revisions'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->danger()
                ->title('No se pudo registrar la exportación.')
                ->body('No se ha generado ningún archivo.')
                ->send();

            return null;
        }

        $conversationName = Str::slug($conversation->conversation_display_name) ?: 'conversacion';
        $filename = sprintf(
            'conversacion-%d-%s-%s.csv',
            $conversation->id,
            $conversationName,
            now()->format('Y-m-d'),
        );

        return response()->streamDownload(function () use ($conversation, $messages): void {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'message_id',
                'conversation_id',
                'conversation_type',
                'conversation_name',
                'sender_name',
                'message_type',
                'sent_at',
                'status',
                'content',
                'edited_at',
                'previous_content',
                'deleted_at',
            ], ';');

            foreach ($messages as $message) {
                $revisions = $message->trashed()
                    ? collect()
                    : $message->revisions;
                $latestRevision = $revisions->last();

                fputcsv($output, [
                    $message->id,
                    $conversation->id,
                    $conversation->conversation_type_label,
                    $conversation->conversation_display_name,
                    $message->sender?->name ?? 'Sistema',
                    $message->isSystemMessage() ? 'system' : 'user',
                    $message->created_at?->format('Y-m-d H:i:s'),
                    $message->accessMessageStateLabel(),
                    $message->accessMessageContent(),
                    $message->edited_at?->format('Y-m-d H:i:s'),
                    $latestRevision?->body ?? '',
                    $message->deleted_at?->format('Y-m-d H:i:s'),
                ], ';');
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
