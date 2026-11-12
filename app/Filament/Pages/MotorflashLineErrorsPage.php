<?php

namespace App\Filament\Pages;

use App\Models\MotorflashLineIncident;
use App\Models\MotorflashLineIncidentHistory;
use App\Models\MotorflashLineReportReceipt;
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
use Illuminate\Support\Facades\DB;

class MotorflashLineErrorsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone-x-mark';
    protected static ?string $navigationLabel = 'Líneas desconectadas de Meta';
    protected static ?string $title = 'Líneas desconectadas de Meta';
    protected static ?string $slug = 'motorflash-message-errors/meta-lines';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $breadcrumb = 'Líneas desconectadas de Meta';
    protected string $view = 'filament.pages.motorflash-line-errors';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getBreadcrumbs(): array
    {
        return [
            Dashboard::getUrl() => 'Escritorio',
            MotorflashMessageErrorsPage::getUrl() => 'Motorflash Message',
            self::getUrl() => 'Líneas desconectadas de Meta',
        ];
    }

    public function hasProcessedReport(): bool
    {
        return MotorflashLineReportReceipt::query()->exists();
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => MotorflashLineIncident::query()
                ->where('error_type', MotorflashLineIncident::TYPE_META_LINE_DISCONNECTED)
                ->where('status', '!=', MotorflashLineIncident::STATUS_CLOSED)
                ->with('inProcessBy')
                ->withCount(['histories as error_count' => fn (Builder $query): Builder => $query->whereIn('state', [
                    MotorflashLineIncidentHistory::STATE_ERROR,
                    MotorflashLineIncidentHistory::STATE_REOPENED,
                ])]))
            ->defaultSort('last_error_at', 'desc')
            ->columns([
                TextColumn::make('line_name')->label('Empresa en Motorflash')->placeholder('Sin nombre')->searchable(),
                TextColumn::make('phone_original')->label('Teléfono')->searchable(),
                TextColumn::make('error_reason')->label('Motivo')->placeholder('Sin motivo'),
                TextColumn::make('line_since_at')->label('Desde')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->recordClasses(fn (MotorflashLineIncident $record): string => $record->status === MotorflashLineIncident::STATUS_IN_PROCESS
                ? 'bg-yellow-50 dark:bg-yellow-950/30'
                : '');

        if (! app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage')) {
            return $table;
        }

        return $table->actionsColumnLabel('Acciones')->actions([
            Action::make('markInProcess')
                ->label(fn (MotorflashLineIncident $record): string => $record->status === MotorflashLineIncident::STATUS_IN_PROCESS
                    ? 'Quitar de en proceso'
                    : 'Marcar en proceso')
                ->icon(fn (MotorflashLineIncident $record): string => $record->status === MotorflashLineIncident::STATUS_IN_PROCESS
                    ? 'heroicon-o-arrow-uturn-left'
                    : 'heroicon-o-clock')
                ->color(fn (MotorflashLineIncident $record): string => $record->status === MotorflashLineIncident::STATUS_IN_PROCESS
                    ? 'gray'
                    : 'warning')
                ->action(function (MotorflashLineIncident $record): void {
                    abort_unless(app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'), 403);

                    if ($record->status === MotorflashLineIncident::STATUS_IN_PROCESS) {
                        $record->update([
                            'status' => MotorflashLineIncident::STATUS_OPEN,
                            'in_process_at' => null,
                            'in_process_by_user_id' => null,
                        ]);

                        $this->resetTable();
                        Notification::make()->success()->title('Línea quitada de en proceso.')->send();

                        return;
                    }

                    DB::transaction(function () use ($record): void {
                        $record->update([
                            'status' => MotorflashLineIncident::STATUS_IN_PROCESS,
                            'in_process_at' => now(),
                            'in_process_by_user_id' => auth()->id(),
                        ]);

                        MotorflashLineIncidentHistory::query()->create([
                            'incident_id' => $record->getKey(),
                            'state' => MotorflashLineIncidentHistory::STATE_IN_PROCESS,
                            'company' => $record->company,
                            'phone_original' => $record->phone_original,
                            'detail' => 'Marcada manualmente como en proceso.',
                            'occurred_at' => now(),
                        ]);
                    });

                    $this->resetTable();
                    Notification::make()->success()->title('Línea marcada como en proceso.')->send();
                }),
            Action::make('deleteGroup')
                ->label('Eliminar')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Seguro que quieres eliminar esta incidencia?')
                ->modalDescription('Se eliminarán todos los registros de esta incidencia asociados a esta línea.')
                ->modalSubmitActionLabel('Sí, eliminar incidencia')
                ->modalCancelActionLabel('Cancelar')
                ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'))
                ->action(function (MotorflashLineIncident $record): void {
                    abort_unless(app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'), 403);

                    if (! MotorflashLineIncident::query()->whereKey($record->getKey())->exists()) {
                        $this->resetTable();
                        Notification::make()->warning()->title('La incidencia ya no existe.')->body('La tabla se ha actualizado.')->send();

                        return;
                    }

                    DB::transaction(function () use ($record): void {
                        MotorflashLineIncidentHistory::query()
                            ->where('incident_id', $record->getKey())
                            ->delete();
                        $record->delete();
                    });

                    $this->resetTable();
                    Notification::make()->success()->title('Incidencia eliminada correctamente.')->send();
                }),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
