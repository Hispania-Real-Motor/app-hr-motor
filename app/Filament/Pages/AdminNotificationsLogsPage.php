<?php

namespace App\Filament\Pages;

use App\Models\NotificationActivityLog;
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

class AdminNotificationsLogsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'notificaciones/logs';

    protected static ?string $breadcrumb = 'Log de notificaciones';

    protected static ?string $title = 'Log de notificaciones';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'notifications.manage');
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
            ->query(fn (): Builder => NotificationActivityLog::query())
            ->defaultSort('created_at', 'desc')
            ->filtersFormMaxHeight('60vh')
            ->toolbarActions([
                Action::make('downloadCsv')
                    ->label('Descargar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('admin.notification-logs.export', array_filter([
                        'actor' => data_get($this->tableFilters, 'actor.value'),
                        'date_from' => data_get($this->tableFilters, 'created_at.from'),
                        'date_to' => data_get($this->tableFilters, 'created_at.until'),
                    ], static fn (mixed $value): bool => filled($value)))),
            ])
            ->filters([
                Filter::make('actor')
                    ->label('Enviado por')
                    ->form([
                        Select::make('value')
                            ->label('Enviado por')
                            ->options(fn (): array => User::query()
                                ->where('role', User::ROLE_ADMIN)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder('Todos los administradores'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'] ?? null,
                            fn (Builder $query, string $actorId): Builder => $query->where('actor_user_id', $actorId),
                        );
                    }),
                Filter::make('created_at')
                    ->label('Fecha')
                    ->form([
                        DatePicker::make('from')
                            ->label('Desde'),
                        DatePicker::make('until')
                            ->label('Hasta')
                            ->minDate(fn (Get $get): ?string => $get('from') ?: null),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha y hora')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->width('12rem'),
                TextColumn::make('actor_name')
                    ->label('Enviado por')
                    ->searchable()
                    ->width('10rem'),
                TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(100)
                    ->wrap(),
                TextColumn::make('target_roles')
                    ->label('Rol o roles')
                    ->state(fn (NotificationActivityLog $record): string => $this->formatRoles($record->target_roles)),
                TextColumn::make('recipient_count')
                    ->label('Destinatarios')
                    ->sortable()
                    ->width('8rem'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function formatRoles(mixed $roles): string
    {
        if (! is_array($roles)) {
            $roles = json_decode((string) $roles, true) ?: [];
        }

        $labels = array_merge([
            AdminNotificationsPage::TARGET_ALL_USERS => 'Todos los usuarios',
        ], User::extraRoleLabels());

        return collect($roles)
            ->map(fn (string $role): string => $labels[$role] ?? $role)
            ->implode(' | ');
    }
}
