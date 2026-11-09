<?php

namespace App\Filament\Pages;

use App\Models\EnreachErrorEvent;
use App\Models\EnreachErrorIncidenceState;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EnreachErrorPhonesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';
    protected static ?string $navigationLabel = 'Teléfonos afectados';
    protected static ?string $title = 'Teléfonos afectados';
    protected static ?string $slug = 'enreach-errors/phones';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $breadcrumb = 'Teléfonos afectados';
    protected string $view = 'filament.pages.enreach-error-phones';

    public string $type = EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND;

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'enreach-errors.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->type = (string) request()->query('type', $this->type);
        abort_unless($this->type === EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND, 404);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getBreadcrumbs(): array
    {
        return [
            Dashboard::getUrl() => 'Escritorio',
            EnreachErrorsPage::getUrl() => 'Enreach',
            EnreachErrorsPage::getUrl(['type' => $this->type]) => 'Teléfono de comercial no encontrado en Salesforce',
            self::getUrl(['type' => $this->type]) => 'Teléfonos afectados',
        ];
    }

    public function integrationIsConnected(): bool
    {
        return config('services.enreach.integration_status', 'pending') === 'connected';
    }

    public function getTableRecordKey(Model|array $record): string
    {
        return (string) (is_array($record) ? $record['phone_normalized'] : $record->phone_normalized);
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(function (): Builder {
                $query = EnreachErrorEvent::query()
                    ->where('error_type', $this->type)
                    ->whereNotNull('phone_normalized');

                if (! $this->integrationIsConnected()) {
                    $query->whereRaw('1 = 0');
                }

                return $query
                    ->select('phone_normalized')
                    ->selectRaw('COUNT(*) as error_count')
                    ->selectRaw('MAX(occurred_at) as last_error_at')
                    ->selectRaw(
                        'EXISTS (SELECT 1 FROM enreach_error_incidence_states states WHERE states.error_type = ? AND states.phone_normalized = enreach_error_events.phone_normalized) as is_reviewed',
                        [$this->type],
                    )
                    ->groupBy('phone_normalized');
            })
            ->defaultSort('last_error_at', 'desc')
            ->recordUrl(fn (EnreachErrorEvent $record): string => EnreachErrorPhoneDetailsPage::getUrl([
                'type' => $this->type,
                'phone' => $record->phone_normalized,
            ]))
            ->columns([
                TextColumn::make('phone_normalized')->label('Teléfono buscado')->searchable(),
                TextColumn::make('error_count')->label('Veces con error')->numeric()->sortable(),
                TextColumn::make('last_error_at')->label('Último error')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordClasses(fn (EnreachErrorEvent $record): string => (bool) $record->is_reviewed
                ? 'bg-yellow-50 dark:bg-yellow-950/30'
                : '');

        if (! app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage')) {
            return $table;
        }

        return $table
            ->actionsColumnLabel('Acciones')
            ->actions([
                Action::make('markReviewed')
                    ->label('Marcar como revisada')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'))
                    ->action(function (EnreachErrorEvent $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'), 403);

                        EnreachErrorIncidenceState::query()->updateOrCreate(
                            [
                                'error_type' => $this->type,
                                'phone_normalized' => $record->phone_normalized,
                            ],
                            ['reviewed_at' => now()],
                        );

                        $this->resetTable();
                        Notification::make()->success()->title('Incidencia marcada como revisada.')->send();
                    }),
                Action::make('resolve')
                    ->label('Resolver')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->tooltip('Dar por resuelta')
                    ->requiresConfirmation()
                    ->modalHeading('¿Seguro que quieres dar esta incidencia por resuelta?')
                    ->modalDescription('Se eliminarán todos los registros de esta incidencia asociados a este número de teléfono.')
                    ->modalSubmitActionLabel('Sí, resolver incidencia')
                    ->modalCancelActionLabel('Cancelar')
                    ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'))
                    ->action(function (EnreachErrorEvent $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'), 403);

                        DB::transaction(function () use ($record): void {
                            EnreachErrorEvent::query()
                                ->where('error_type', $this->type)
                                ->where('phone_normalized', $record->phone_normalized)
                                ->delete();

                            EnreachErrorIncidenceState::query()
                                ->where('error_type', $this->type)
                                ->where('phone_normalized', $record->phone_normalized)
                                ->delete();
                        });

                        $this->resetTable();
                        Notification::make()->success()->title('Incidencia resuelta correctamente.')->send();
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
