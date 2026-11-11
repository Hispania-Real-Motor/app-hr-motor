<?php

namespace App\Filament\Pages;

use App\Models\MotorflashCommercialErrorEvent;
use App\Models\MotorflashCommercialErrorGroup;
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

class MotorflashCommercialErrorsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-minus';
    protected static ?string $navigationLabel = 'Comerciales no encontrados en Salesforce';
    protected static ?string $title = 'Comerciales no encontrados en Salesforce';
    protected static ?string $slug = 'motorflash-message-errors/commercials';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $breadcrumb = 'Comerciales no encontrados en Salesforce';
    protected string $view = 'filament.pages.motorflash-commercial-errors';

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
            self::getUrl() => 'Comerciales no encontrados en Salesforce',
        ];
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => MotorflashCommercialErrorEvent::query()
                ->join('motorflash_commercial_error_groups as groups', function ($join): void {
                    $join->on('groups.error_type', '=', 'motorflash_commercial_error_events.error_type')
                        ->on('groups.searched_email_normalized', '=', 'motorflash_commercial_error_events.searched_email_normalized');
                })
                ->where('motorflash_commercial_error_events.error_type', MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE)
                ->selectRaw('groups.id as id')
                ->selectRaw('groups.error_type as error_type')
                ->selectRaw('groups.searched_email_normalized as searched_email_normalized')
                ->selectRaw('groups.status as status')
                ->selectRaw('MIN(motorflash_commercial_error_events.searched_email) as searched_email')
                ->selectRaw('COUNT(motorflash_commercial_error_events.id) as occurrence_count')
                ->selectRaw('MAX(motorflash_commercial_error_events.occurred_at) as last_error_at')
                ->groupBy('groups.id', 'groups.error_type', 'groups.searched_email_normalized', 'groups.status'))
            ->defaultSort('last_error_at', 'desc')
            ->recordClasses(fn (Model $record): string => $record->status === MotorflashCommercialErrorGroup::STATUS_IN_PROCESS
                ? 'bg-yellow-50 dark:bg-yellow-950/30'
                : '')
            ->columns([
                TextColumn::make('searched_email')->label('Correo buscado')->searchable(),
                TextColumn::make('occurrence_count')->label('Ocurrencias')->numeric()->sortable(),
                TextColumn::make('last_error_at')->label('Último error')->dateTime('d/m/Y H:i')->sortable(),
            ]);

        if (! app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage')) {
            return $table;
        }

        return $table
            ->actionsColumnLabel('Acciones')
            ->actions([
                Action::make('markInProcess')
                    ->label(fn (Model $record): string => $record->status === MotorflashCommercialErrorGroup::STATUS_IN_PROCESS
                        ? 'Quitar de en proceso'
                        : 'Marcar en proceso')
                    ->icon(fn (Model $record): string => $record->status === MotorflashCommercialErrorGroup::STATUS_IN_PROCESS
                        ? 'heroicon-o-arrow-uturn-left'
                        : 'heroicon-o-clock')
                    ->color(fn (Model $record): string => $record->status === MotorflashCommercialErrorGroup::STATUS_IN_PROCESS
                        ? 'gray'
                        : 'warning')
                    ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'))
                    ->action(function (Model $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'), 403);

                        $group = MotorflashCommercialErrorGroup::query()->find($record->getAttribute('id'));

                        if ($group === null) {
                            $this->notifyMissingGroup();

                            return;
                        }

                        $inProcess = $group->status === MotorflashCommercialErrorGroup::STATUS_IN_PROCESS;
                        $group->update([
                            'status' => $inProcess
                                ? MotorflashCommercialErrorGroup::STATUS_OPEN
                                : MotorflashCommercialErrorGroup::STATUS_IN_PROCESS,
                            'in_process_at' => $inProcess ? null : now(),
                            'in_process_by_user_id' => $inProcess ? null : auth()->id(),
                        ]);

                        $this->resetTable();
                        Notification::make()
                            ->success()
                            ->title($inProcess ? 'Comercial quitado de en proceso.' : 'Comercial marcado como en proceso.')
                            ->send();
                    }),
                Action::make('deleteGroup')
                    ->label('Eliminar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('¿Seguro que quieres eliminar esta incidencia?')
                    ->modalDescription('Se eliminarán todas las ocurrencias asociadas a este correo.')
                    ->modalSubmitActionLabel('Sí, eliminar incidencia')
                    ->modalCancelActionLabel('Cancelar')
                    ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'))
                    ->action(function (Model $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'motorflash-message-errors.manage'), 403);

                        $group = MotorflashCommercialErrorGroup::query()->find($record->getAttribute('id'));

                        if ($group === null) {
                            $this->notifyMissingGroup();

                            return;
                        }

                        DB::transaction(function () use ($group): void {
                            MotorflashCommercialErrorEvent::query()
                                ->where('error_type', $group->error_type)
                                ->where('searched_email_normalized', $group->searched_email_normalized)
                                ->delete();
                            $group->delete();
                        });

                        $this->resetTable();
                        Notification::make()->success()->title('Incidencia eliminada correctamente.')->send();
                    }),
            ]);
    }

    private function notifyMissingGroup(): void
    {
        $this->resetTable();
        Notification::make()->warning()->title('La incidencia ya no existe.')->body('La tabla se ha actualizado.')->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
