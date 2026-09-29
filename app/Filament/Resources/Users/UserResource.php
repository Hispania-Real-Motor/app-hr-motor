<?php

namespace App\Filament\Resources\Users;

use App\Models\User;
use App\Models\Dealership;
use App\Rules\UserEnreachExtensionRule;
use App\Services\UserDeactivationService;
use App\Services\UserPasswordResetService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;
use Filament\Notifications\Notification;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios';

    protected static ?string $slug = 'usuarios';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationItems(): array
    {
        return array_map(
            fn ($item) => $item->visible(fn (): bool => static::canViewAny()),
            parent::getNavigationItems(),
        );
    }

    public static function canViewAny(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'users.manage')
            && auth()->user()?->can('viewAny', User::class) === true;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny()
            && auth()->user()?->can('create', User::class) === true;
    }

    public static function canView($record): bool
    {
        return static::canViewAny()
            && auth()->user()?->can('view', $record) === true;
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && auth()->user()?->can('update', $record) === true;
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny()
            && auth()->user()?->can('delete', $record) === true;
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny()
            && auth()->user()?->can('deleteAny', User::class) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('assignedDealership');
    }

    public static function form(Schema $schema): Schema
    {
        $itScheduleFields = [
            'it_monday_start',
            'it_monday_end',
            'it_tuesday_start',
            'it_tuesday_end',
            'it_wednesday_start',
            'it_wednesday_end',
            'it_thursday_start',
            'it_thursday_end',
            'it_friday_start',
            'it_friday_end',
        ];

        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Correo')
                ->email()
                ->required()
                ->maxLength(255),
            DatePicker::make('company_entry_date')
                ->label('Día que entró en la empresa')
                ->required(),
            TextInput::make('job_position')
                ->label('Puesto')
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label('Teléfono')
                ->tel()
                ->maxLength(255),
            TextInput::make('enreach_extension')
                ->label('Extensión Enreach')
                ->maxLength(255)
                ->rules(fn (?User $record): array => [
                    new UserEnreachExtensionRule(
                        ignoreUserId: $record?->id,
                        action: $record ? 'editar' : 'crear',
                    ),
                ]),
            Select::make('role')
                ->label('Rol base')
                ->options(fn (): array => auth()->user()?->role === User::ROLE_ADMIN
                    ? User::baseRoleLabels()
                    : [User::ROLE_USER => User::baseRoleLabels()[User::ROLE_USER]])
                ->required()
                ->searchable(),
            Select::make('extra_role')
                ->label('Rol extra')
                ->options(User::extraRoleLabels())
                ->placeholder('Sin rol extra')
                ->required(fn (?User $record): bool => $record === null)
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) use ($itScheduleFields): void {
                    if (! in_array($state, [User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER, User::ROLE_HR_NEWCARS], true)) {
                        $set('salesforce_user_id', null);
                    }

                    if ($state !== User::ROLE_INFORMATION_TECHNOLOGY) {
                        foreach ($itScheduleFields as $field) {
                            $set($field, null);
                        }
                    }
                }),
            TextInput::make('new_password')
                ->label('Nueva contraseña')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->helperText('Déjalo vacío para conservar la contraseña actual.')
                ->visible(fn (?User $record): bool => $record !== null
                    && app_user_has_admin_permission(auth()->user(), 'users.manage'))
                ->required(fn (Get $get): bool => filled($get('new_password_confirmation')))
                ->rules([
                    'nullable',
                    'string',
                    'min:8',
                    'confirmed',
                ]),
            TextInput::make('new_password_confirmation')
                ->label('Confirmar contraseña')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->visible(fn (?User $record): bool => $record !== null
                    && app_user_has_admin_permission(auth()->user(), 'users.manage'))
                ->required(fn (Get $get): bool => filled($get('new_password')))
                ->rules([
                    'nullable',
                    'string',
                ]),
            TextInput::make('salesforce_user_id')
                ->label('ID Salesforce')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => in_array($get('extra_role'), [User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER, User::ROLE_HR_NEWCARS], true))
                ->required(fn (Get $get): bool => in_array($get('extra_role'), [User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER, User::ROLE_HR_NEWCARS], true))
                ->dehydrated(fn (Get $get): bool => in_array($get('extra_role'), [User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER, User::ROLE_HR_NEWCARS], true)),
            Section::make('Horario IT')
                ->description('Los sábados y domingos no llevan horario.')
                ->visible(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                ->columnSpanFull()
                ->schema([
                    Grid::make(2)
                        ->schema([
                            TimePicker::make('it_monday_start')
                                ->label('Lunes inicio')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                            TimePicker::make('it_monday_end')
                                ->label('Lunes fin')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                        ])
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->schema([
                            TimePicker::make('it_tuesday_start')
                                ->label('Martes inicio')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                            TimePicker::make('it_tuesday_end')
                                ->label('Martes fin')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                        ])
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->schema([
                            TimePicker::make('it_wednesday_start')
                                ->label('Miércoles inicio')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                            TimePicker::make('it_wednesday_end')
                                ->label('Miércoles fin')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                        ])
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->schema([
                            TimePicker::make('it_thursday_start')
                                ->label('Jueves inicio')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                            TimePicker::make('it_thursday_end')
                                ->label('Jueves fin')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                        ])
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->schema([
                            TimePicker::make('it_friday_start')
                                ->label('Viernes inicio')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                            TimePicker::make('it_friday_end')
                                ->label('Viernes fin')
                                ->seconds(false)
                                ->required(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->dehydrated(fn (Get $get): bool => $get('extra_role') === User::ROLE_INFORMATION_TECHNOLOGY)
                                ->columnSpan(1),
                        ])
                        ->columnSpanFull(),
                ]),
            Select::make('dealership_id')
                ->label('Delegación')
                ->relationship('assignedDealership', 'name')
                ->searchable()
                ->preload()
                ->placeholder('No aplica'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('Avatar')
                    ->circular()
                    ->grow(false)
                    ->imageSize(44)
                    ->width('4rem'),
                TextColumn::make('name')
                    ->label('Usuario')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->grow(false)
                    ->width('16rem'),
                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->sortable()
                    ->grow(false)
                    ->width('22rem'),
                TextColumn::make('role_label')
                    ->label('Rol')
                    ->badge()
                    ->color('primary')
                    ->grow(false)
                    ->width('12rem')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('role', $direction)->orderBy('extra_role', $direction);
                    }),
                TextColumn::make('resolved_dealership_name')
                    ->label('Delegacion')
                    ->placeholder('No aplica')
                    ->grow(false)
                    ->width('16rem')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('dealership_id', $direction)->orderBy('dealership', $direction);
                    }),
                TextColumn::make('status')
                    ->label('Estado')
                    ->state(function (User $record): string {
                        if ($record->isDisabled()) {
                            return 'Desactivado';
                        }

                        if ($record->is_active) {
                            return 'Activo';
                        }

                        if ($record->isInvitationExpired()) {
                            return 'Caducado';
                        }

                        return 'Pendiente';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Activo' => 'success',
                        'Pendiente' => 'warning',
                        'Caducado' => 'danger',
                        'Desactivado' => 'gray',
                        default => 'gray',
                    })
                    ->grow(false)
                    ->width('10rem'),
            ])
            ->defaultSort('name')
            ->striped()
            ->recordUrl(function (User $record): ?string {
                $authUser = auth()->user();

                if (! $authUser instanceof User) {
                    return null;
                }

                if ($authUser->role === User::ROLE_ADMIN) {
                    return static::getUrl('edit', ['record' => $record]);
                }

                return $authUser->role === User::ROLE_MANAGER
                    && $authUser->id !== $record->id
                    && $record->role === User::ROLE_USER
                    ? static::getUrl('edit', ['record' => $record])
                    : null;
            })
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'activo' => 'Activo',
                        'pendiente' => 'Pendiente',
                        'caducado' => 'Caducado',
                        'desactivado' => 'Desactivado',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'activo' => $query->where('is_active', true)->whereNull('disabled_at'),
                            'pendiente' => $query
                                ->where('is_active', false)
                                ->whereNull('disabled_at')
                                ->where('must_change_password', true)
                                ->where(function (Builder $query): void {
                                    $query->whereNull('invitation_sent_at')
                                        ->orWhere('invitation_sent_at', '>=', now()->subMinutes((int) config('auth.passwords.users.expire', 60)));
                                }),
                            'caducado' => $query
                                ->where('is_active', false)
                                ->whereNull('disabled_at')
                                ->where('must_change_password', true)
                                ->whereNotNull('invitation_sent_at')
                                ->where('invitation_sent_at', '<', now()->subMinutes((int) config('auth.passwords.users.expire', 60))),
                            'desactivado' => $query->whereNotNull('disabled_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('dealership_id')
                    ->label('Delegación')
                    ->options(fn (): array => ['__none__' => 'Sin delegación'] + Dealership::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            '__none__' => $query->whereNull('dealership_id'),
                            null, '' => $query,
                            default => $query->where('dealership_id', $data['value']),
                        };
                    })
                    ->searchable()
                    ->preload(),
            ])
            ->toolbarActions([
                Action::make('exportCsv')
                    ->label('Exportar CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('users.export-csv')),
                Action::make('viewLogs')
                    ->label('Ver logs')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(static::getUrl('logs'))
                    ->visible(fn (): bool => auth()->user()?->role === User::ROLE_ADMIN),
                CreateAction::make(),
            ])
            ->actions([
                DeleteAction::make()
                    ->label('Borrar')
                    ->modalIconColor('primary')
                    ->modalSubmitAction(fn (Action $action) => $action->color('primary'))
                    ->visible(function (User $record): bool {
                        $authUser = auth()->user();

                        return $authUser instanceof User
                            && $authUser->role === User::ROLE_ADMIN
                            && $authUser->id !== $record->id;
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Borrar usuario')
                    ->modalDescription('¿Estás seguro de que quieres borrar este usuario? Esta acción no se puede deshacer.')
                    ->using(function (User $record): bool {
                        abort_unless(static::canDelete($record), 403);

                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            return false;
                        }

                        app(\App\Services\UserActivityLogWriter::class)->record(
                            actor: $actor,
                            targetUser: $record,
                            action: \App\Models\UserActivityLog::ACTION_DELETED,
                        );

                        return (bool) $record->delete();
                    }),
                Action::make('disable')
                    ->label('Desactivar')
                    ->icon('heroicon-o-user-minus')
                    ->color('gray')
                    ->visible(function (User $record): bool {
                        $authUser = auth()->user();

                        return $authUser instanceof User
                            && app(UserDeactivationService::class)->canDeactivate($authUser, $record);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Desactivar usuario')
                    ->modalDescription('¿Estás seguro de que quieres desactivar este usuario? Se cerrarán sus sesiones y quedará marcado como desactivado.')
                    ->action(function (User $record): void {
                        abort_unless(static::canViewAny(), 403);

                        $authUser = auth()->user();

                        if (! $authUser instanceof User) {
                            return;
                        }

                        try {
                            app(UserDeactivationService::class)->deactivate($authUser, $record);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Usuario desactivado correctamente.')
                            ->body('El usuario ya no podrá acceder al backoffice.')
                            ->send();
                    }),
                Action::make('reactivate')
                    ->label('Activar')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(function (User $record): bool {
                        $authUser = auth()->user();

                        return $authUser instanceof User
                            && app(UserDeactivationService::class)->canReactivate($authUser, $record);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Activar usuario')
                    ->modalDescription('¿Estás seguro de que quieres activar este usuario? Podrá volver a acceder a la aplicación.')
                    ->action(function (User $record): void {
                        abort_unless(static::canViewAny(), 403);

                        $authUser = auth()->user();

                        if (! $authUser instanceof User) {
                            return;
                        }

                        try {
                            app(UserDeactivationService::class)->reactivate($authUser, $record);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Usuario activado correctamente.')
                            ->body('El usuario ya puede volver a acceder a la aplicación.')
                            ->send();
                    }),
                Action::make('resetPassword')
                    ->label('Restablecer')
                    ->tooltip('Restablecer contraseña')
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->visible(function (User $record): bool {
                        return ! $record->isDisabled()
                            && ($record->is_active || $record->isInvitationExpired());
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Restablecer contraseña')
                    ->modalDescription(fn (User $record): string => "Se enviará un enlace de restablecimiento a {$record->email}. ¿Quieres continuar?")
                    ->action(function (User $record): void {
                        abort_unless(static::canViewAny(), 403);

                        $status = app(UserPasswordResetService::class)->send($record);

                        if ($status !== Password::RESET_LINK_SENT) {
                            Notification::make()
                                ->danger()
                                ->title(match ($status) {
                                    UserPasswordResetService::DELIVERY_FAILED => 'El servidor SMTP ha rechazado el correo.',
                                    Password::RESET_THROTTLED => 'Espera un momento antes de volver a enviar el correo.',
                                    Password::INVALID_USER => 'No se ha encontrado un usuario válido.',
                                    default => 'No se ha podido enviar el correo de restablecimiento.',
                                })
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Correo de restablecimiento enviado correctamente.')
                            ->body('El usuario recibirá un enlace para definir una nueva contraseña.')
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'logs' => Pages\ListUserLogs::route('/logs'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
