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
                TextColumn::make('line_name')->label('Nombre de línea en Meta')->placeholder('Sin nombre')->searchable(),
                TextColumn::make('phone_original')->label('Teléfono')->searchable(),
                TextColumn::make('line_since_at')->label('Desde')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === MotorflashLineIncident::STATUS_IN_PROCESS ? 'En proceso' : 'Abierta')
                    ->color(fn (string $state): string => $state === MotorflashLineIncident::STATUS_IN_PROCESS ? 'warning' : 'danger'),
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
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
