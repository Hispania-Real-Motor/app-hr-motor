<?php

namespace App\Filament\Pages;

use App\Models\CompanyChatRetentionHoldAudit;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChatRetentionHoldLogsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'conservacion-excepcional/logs';
    protected static ?string $breadcrumb = 'Logs de conservación excepcional';
    protected static ?string $title = 'Logs de conservación excepcional';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'chat-retention-holds.manage');
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
            ->query(fn (): Builder => $this->logsQuery())
            ->defaultSort('created_at', 'desc')
            ->filtersFormMaxHeight('60vh')
            ->toolbarActions([
                Action::make('back')
                    ->label('Volver a conservación excepcional')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(ChatRetentionHoldsPage::getUrl()),
            ])
            ->filters([
                Filter::make('action')
                    ->label('Acción')
                    ->form([
                        Select::make('value')
                            ->label('Acción')
                            ->options([
                                'activated' => 'Activada',
                                'reason_updated' => 'Motivo actualizado',
                                'expires_at_updated' => 'Caducidad actualizada',
                                'deactivated' => 'Desactivada',
                            ])
                            ->placeholder('Todas las acciones'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $action): Builder => $query->where('action', $action),
                    )),
                Filter::make('actor')
                    ->label('Administrador')
                    ->form([
                        Select::make('value')
                            ->label('Administrador')
                            ->options(fn (): array => User::query()->where('role', User::ROLE_ADMIN)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->placeholder('Todos los administradores'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $actor): Builder => $query->where('admin_user_id', $actor),
                    )),
                Filter::make('created_at')
                    ->label('Fecha')
                    ->form([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta')->minDate(fn (Get $get): ?string => $get('from') ?: null),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->columns([
                TextColumn::make('created_at')->label('Fecha y hora')->dateTime('d/m/Y H:i:s')->sortable()->width('12rem'),
                TextColumn::make('action')->label('Acción')->formatStateUsing(fn (string $state): string => match ($state) {
                    'activated' => 'Activada',
                    'reason_updated' => 'Motivo actualizado',
                    'expires_at_updated' => 'Caducidad actualizada',
                    'deactivated' => 'Desactivada',
                    default => $state,
                })->badge()->color(fn (string $state): string => $state === 'deactivated' ? 'gray' : 'success'),
                TextColumn::make('target_label')->label('Objetivo')->searchable()->wrap(),
                TextColumn::make('admin_name')->label('Administrador')->searchable(),
                TextColumn::make('reason')->label('Motivo')->wrap()->limit(120),
                TextColumn::make('expires_at')->label('Caducidad')->dateTime('d/m/Y H:i')->placeholder('Sin caducidad'),
                TextColumn::make('source')->label('Origen')->badge()->color('gray'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    private function logsQuery(): Builder
    {
        $conversationLogs = DB::table('company_chat_retention_hold_audits as logs')
            ->leftJoin('users as admin', 'admin.id', '=', 'logs.admin_user_id')
            ->leftJoin('company_chat_conversations as conversations', 'conversations.id', '=', 'logs.company_chat_conversation_id')
            ->leftJoin('users as first_user', 'first_user.id', '=', 'conversations.user_one_id')
            ->leftJoin('users as second_user', 'second_user.id', '=', 'conversations.user_two_id')
            ->select([
                'logs.id', 'logs.created_at', 'logs.action', 'logs.reason', 'logs.expires_at', 'logs.admin_user_id', 'logs.source',
                DB::raw("'conversation' as target_type"),
                DB::raw("COALESCE(first_user.name || ' con ' || second_user.name, 'Conversación #' || conversations.id) as target_label"),
                DB::raw("COALESCE(admin.name, 'Sistema') as admin_name"),
            ]);

        $userLogs = DB::table('company_chat_retention_user_hold_audits as logs')
            ->leftJoin('users as admin', 'admin.id', '=', 'logs.admin_user_id')
            ->leftJoin('users as target_user', 'target_user.id', '=', 'logs.user_id')
            ->select([
                'logs.id', 'logs.created_at', 'logs.action', 'logs.reason', 'logs.expires_at', 'logs.admin_user_id', 'logs.source',
                DB::raw("'user' as target_type"),
                DB::raw("COALESCE(target_user.name, 'Usuario #' || logs.user_id) as target_label"),
                DB::raw("COALESCE(admin.name, 'Sistema') as admin_name"),
            ]);

        $union = $conversationLogs->unionAll($userLogs);

        $auditModel = new CompanyChatRetentionHoldAudit();
        $auditModel->setTable('retention_logs');

        return $auditModel->newQuery()->fromSub($union, 'retention_logs');
    }
}
