<?php

namespace App\Filament\Pages;

use App\Models\CompanyChatConversationAccessAudit;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConversationAccessLogsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'acceso-conversacion/logs';

    protected static ?string $breadcrumb = 'Log de accesos a conversaciones';

    protected static ?string $title = 'Log de accesos a conversaciones';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'conversation-access.manage');
    }

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CompanyChatConversationAccessAudit::query()
                ->with(['adminUser', 'conversation.userOne', 'conversation.userTwo', 'conversation.chatGroup.participants']))
            ->defaultSort('accessed_at', 'desc')
            ->filtersFormMaxHeight('60vh')
            ->toolbarActions([
                Action::make('back')
                    ->label('Volver a acceso a conversaciones')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(ConversationAccessPage::getUrl()),
                Action::make('downloadCsv')
                    ->label('Descargar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('admin.conversation-access.logs.export')),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Acción')
                    ->options([
                        'conversation_content_access' => 'Acceso a conversación',
                        'conversation_content_export' => 'Descarga CSV',
                        'VIEW_CONVERSATION_AS_ADMIN_DENIED' => 'Acceso denegado',
                    ]),
                SelectFilter::make('conversation_type')
                    ->label('Tipo de conversación')
                    ->options([
                        'Grupo' => 'Grupo',
                        'Privada' => 'Privada',
                    ]),
                SelectFilter::make('result')
                    ->label('Resultado')
                    ->options([
                        'granted' => 'Concedido',
                        'denied' => 'Denegado',
                    ]),
                Filter::make('admin')
                    ->label('Administrador')
                    ->form([
                        Select::make('value')
                            ->label('Administrador')
                            ->options(fn (): array => User::query()
                                ->where('role', User::ROLE_ADMIN)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder('Todos los administradores'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $adminId): Builder => $query->where('admin_user_id', $adminId),
                    )),
                Filter::make('accessed_at')
                    ->label('Fecha')
                    ->form([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta')->minDate(fn (Get $get): ?string => $get('from') ?: null),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('accessed_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('accessed_at', '<=', $date))),
            ])
            ->columns([
                TextColumn::make('accessed_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->width('12rem'),
                TextColumn::make('admin_name')
                    ->label('Administrador')
                    ->state(fn (CompanyChatConversationAccessAudit $record): string => $record->adminUser?->name ?? 'Usuario eliminado')
                    ->description(fn (CompanyChatConversationAccessAudit $record): ?string => $record->admin_email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->where('admin_email', 'like', "%{$search}%")
                                ->orWhereHas('adminUser', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%"));
                        });
                    })
                    ->wrap(),
                TextColumn::make('conversation_id')
                    ->label('ID conversación')
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? '#' . $state : 'N/D')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('conversation_name')
                    ->label('Conversación')
                    ->state(fn (CompanyChatConversationAccessAudit $record): string => $record->conversation?->retention_hold_target_label ?: 'Conversación no disponible')
                    ->description(fn (CompanyChatConversationAccessAudit $record): ?string => $record->conversation_type)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->whereHas('conversation.chatGroup', fn (Builder $groupQuery): Builder => $groupQuery->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('conversation.userOne', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                                ->orWhereHas('conversation.userTwo', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
                        });
                    })
                    ->wrap(),
                TextColumn::make('action_label')
                    ->label('Acción')
                    ->state(fn (CompanyChatConversationAccessAudit $record): string => $this->actionLabel($record->action))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Acceso denegado' ? 'danger' : ($state === 'Descarga CSV' ? 'info' : 'warning')),
                TextColumn::make('reason')
                    ->label('Motivo')
                    ->wrap()
                    ->limit(160),
                TextColumn::make('result_label')
                    ->label('Resultado')
                    ->state(fn (CompanyChatConversationAccessAudit $record): string => $record->result === 'granted' ? 'Concedido' : 'Denegado')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Concedido' ? 'success' : 'danger'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'conversation_content_access' => 'Acceso a conversación',
            'conversation_content_export' => 'Descarga CSV',
            'VIEW_CONVERSATION_AS_ADMIN_DENIED' => 'Acceso denegado',
            default => $action,
        };
    }
}
