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

    public function getTableRecord(?string $key): Model|array|null
    {
        if ($key === null) {
            return null;
        }

        $record = $this->getTable()->getQuery(isResolvingRecord: true)
            ->where('phone_normalized', $key)
            ->first();

        if ($record !== null) {
            return $record;
        }

        // Keep a valid grouped row context so a stale click can be handled by
        // the action with a notification instead of becoming a 404 response.
        return (new EnreachErrorEvent)->newInstance([
            'id' => $key,
            'error_type' => $this->type,
            'phone_normalized' => $key,
            'is_reviewed' => false,
        ]);
    }

    /** @return array{type: string, phone: string}|null */
    private function getGroupedRecordKeys(Model|array|null $record): ?array
    {
        if ($record === null) {
            return null;
        }

        $attributes = $record instanceof Model ? $record->getAttributes() : ($record ?? []);
        $type = (string) ($attributes['error_type'] ?? $this->type);
        $phone = (string) ($attributes['phone_normalized'] ?? '');

        if ($type !== EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND || $phone === '') {
            return null;
        }

        return [
            'type' => $type,
            'phone' => $phone,
        ];
    }

    private function notifyMissingIncidence(): void
    {
        $this->resetTable();
        Notification::make()
            ->warning()
            ->title('La incidencia ya no existe.')
            ->body('La tabla se ha actualizado.')
            ->send();
    }

    private function isGroupedRecordReviewed(Model|array|null $record): bool
    {
        if ($record === null) {
            return false;
        }

        return (bool) ($record instanceof Model
            ? $record->getAttribute('is_reviewed')
            : ($record['is_reviewed'] ?? false));
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
                    ->select('error_type', 'phone_normalized')
                    ->selectRaw('phone_normalized as id')
                    ->selectRaw('COUNT(*) as error_count')
                    ->selectRaw('MAX(occurred_at) as last_error_at')
                    ->selectRaw(
                        'EXISTS (SELECT 1 FROM enreach_error_incidence_states states WHERE states.error_type = ? AND states.phone_normalized = enreach_error_events.phone_normalized) as is_reviewed',
                        [$this->type],
                    )
                    ->groupBy('error_type', 'phone_normalized');
            })
            ->defaultSort('last_error_at', 'desc')
            ->recordUrl(function (Model|array $record): string {
                $keys = $this->getGroupedRecordKeys($record);

                return EnreachErrorPhoneDetailsPage::getUrl([
                    'type' => $keys['type'],
                    'phone' => $keys['phone'],
                ]);
            })
            ->columns([
                TextColumn::make('phone_normalized')->label('Teléfono buscado')->searchable(),
                TextColumn::make('error_count')->label('Veces con error')->numeric()->sortable(),
                TextColumn::make('last_error_at')->label('Último error')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordClasses(fn (Model|array $record): string => (bool) (is_array($record) ? ($record['is_reviewed'] ?? false) : $record->is_reviewed)
                ? 'bg-yellow-50 dark:bg-yellow-950/30'
                : '');

        if (! app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage')) {
            return $table;
        }

        return $table
            ->actionsColumnLabel('Acciones')
            ->actions([
                Action::make('markReviewed')
                    ->label(fn (Model|array|null $record): string => $this->isGroupedRecordReviewed($record) ? 'Quitar revisión' : 'Revisar más tarde')
                    ->icon(fn (Model|array|null $record): string => $this->isGroupedRecordReviewed($record) ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Model|array|null $record): string => $this->isGroupedRecordReviewed($record) ? 'gray' : 'warning')
                    ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'))
                    ->action(function (Model|array|null $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'), 403);
                        $keys = $this->getGroupedRecordKeys($record);

                        if ($keys === null) {
                            $this->notifyMissingIncidence();

                            return;
                        }

                        if (! EnreachErrorEvent::query()
                            ->where('error_type', $keys['type'])
                            ->where('phone_normalized', $keys['phone'])
                            ->exists()) {
                            $this->notifyMissingIncidence();

                            return;
                        }

                        $stateQuery = EnreachErrorIncidenceState::query()
                            ->where('error_type', $keys['type'])
                            ->where('phone_normalized', $keys['phone']);

                        $isReviewed = $stateQuery->exists();

                        if ($isReviewed) {
                            $stateQuery->delete();
                        } else {
                            EnreachErrorIncidenceState::query()->create([
                                'error_type' => $keys['type'],
                                'phone_normalized' => $keys['phone'],
                                'reviewed_at' => now(),
                            ]);
                        }

                        $this->resetTable();
                        Notification::make()
                            ->success()
                            ->title($isReviewed ? 'Revisión quitada.' : 'Incidencia marcada para revisar más tarde.')
                            ->send();
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
                    ->action(function (Model|array|null $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'), 403);
                        $keys = $this->getGroupedRecordKeys($record);

                        if ($keys === null) {
                            $this->notifyMissingIncidence();

                            return;
                        }

                        if (! EnreachErrorEvent::query()
                            ->where('error_type', $keys['type'])
                            ->where('phone_normalized', $keys['phone'])
                            ->exists()) {
                            $this->notifyMissingIncidence();

                            return;
                        }

                        DB::transaction(function () use ($keys): void {
                            EnreachErrorEvent::query()
                                ->where('error_type', $keys['type'])
                                ->where('phone_normalized', $keys['phone'])
                                ->delete();

                            EnreachErrorIncidenceState::query()
                                ->where('error_type', $keys['type'])
                                ->where('phone_normalized', $keys['phone'])
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
