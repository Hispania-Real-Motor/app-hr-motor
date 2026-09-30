<?php

namespace App\Filament\Resources\Zones;

use App\Filament\Resources\Zones\Pages\CreateZone;
use App\Filament\Resources\Zones\Pages\EditZone;
use App\Filament\Resources\Zones\Pages\ListZoneLogs;
use App\Filament\Resources\Zones\Pages\ListZones;
use App\Models\Zone;
use App\Services\ZoneManagementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Zonas';

    protected static ?string $modelLabel = 'zona';

    protected static ?string $pluralModelLabel = 'zonas';

    protected static ?string $slug = 'zonas';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 3;

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
        return app_user_has_admin_permission(auth()->user(), 'zones.manage');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('dealerships')
            ->withCount('dealerships');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('dealership_ids')
                ->label('Delegaciones')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(fn (): array => app(ZoneManagementService::class)->allDealershipOptions())
                ->disableOptionWhen(
                    fn (Select $component, string $value): bool => app(ZoneManagementService::class)->isDealershipAssignedElsewhere(
                        (int) $value,
                        $component->getRecord()?->getKey(),
                    ),
                ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Zona')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->grow(false)
                    ->width('22rem'),
                TextColumn::make('dealerships_count')
                    ->label('Delegaciones')
                    ->state(function (Zone $record): string {
                        $count = (int) ($record->dealerships_count ?? 0);

                        return $count . ' ' . ($count === 1 ? 'delegación' : 'delegaciones');
                    })
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->grow(false)
                    ->width('14rem'),
            ])
            ->defaultSort('name')
            ->striped()
            ->toolbarActions([
                Action::make('viewLogs')
                    ->label('Ver logs')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(static::getUrl('logs'))
                    ->visible(fn (): bool => auth()->user()?->role === \App\Models\User::ROLE_ADMIN),
                CreateAction::make()
                    ->label('Crear zona')
                    ->url(static::getUrl('create'))
                    ->modal(false),
            ])
            ->actions([
                EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square'),
                DeleteAction::make()
                    ->label('Borrar')
                    ->icon('heroicon-o-trash')
                    ->modalHeading('Borrar zona')
                    ->modalDescription('¿Seguro que quieres borrar esta zona? Esta acción no se puede deshacer.')
                    ->using(function (Zone $record): bool {
                        abort_unless(static::canDelete($record), 403);

                        $actor = auth()->user();

                        if (! $actor instanceof \App\Models\User) {
                            return false;
                        }

                        $record->loadMissing('dealerships');
                        $service = app(ZoneManagementService::class);
                        $dealershipNames = $service->dealershipNames($record->dealerships->pluck('id')->all());

                        $service->syncDealershipAssignments($record, []);
                        $service->recordActivityLog(
                            actor: $actor,
                            zone: $record,
                            action: \App\Models\ZoneActivityLog::ACTION_DELETED,
                            changes: [
                                'Nombre' => ['from' => $record->name, 'to' => null],
                                'Delegaciones' => ['from' => implode(', ', $dealershipNames), 'to' => null],
                            ],
                            dealershipNames: $dealershipNames,
                        );

                        return (bool) $record->delete();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListZones::route('/'),
            'logs' => ListZoneLogs::route('/logs'),
            'create' => CreateZone::route('/create'),
            'edit' => EditZone::route('/{record}/edit'),
        ];
    }

    public static function conflictingDealershipNames(array $dealershipIds, ?Zone $zone = null): array
    {
        return app(ZoneManagementService::class)->conflictingDealershipNames($dealershipIds, $zone);
    }

    public static function dealershipNames(array $dealershipIds): array
    {
        return app(ZoneManagementService::class)->dealershipNames($dealershipIds);
    }
}
