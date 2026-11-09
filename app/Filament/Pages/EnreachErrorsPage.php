<?php

namespace App\Filament\Pages;

use App\Models\EnreachError;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EnreachErrorsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Errores de Enreach';
    protected static ?string $title = 'Errores de Enreach';
    protected static ?string $slug = 'enreach-errors';
    protected static string|\UnitEnum|null $navigationGroup = 'Errores';
    protected static ?int $navigationSort = 11;
    protected static ?string $breadcrumb = 'Errores de Enreach';
    protected string $view = 'filament.pages.enreach-errors';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'enreach-errors.view');
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
            ->query(fn (): Builder => EnreachError::query()->with(['assignedTo', 'resolvedBy']))
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(['open' => 'Abierto', 'resolved' => 'Resuelto']),
            ])
            ->columns([
                TextColumn::make('created_at')->label('Recibido')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('source_reference')->label('Referencia')->placeholder('Sin referencia')->searchable(),
                TextColumn::make('summary')->label('Error')->wrap()->searchable(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn (string $state): string => $state === 'resolved' ? 'Resuelto' : 'Abierto')->color(fn (string $state): string => $state === 'resolved' ? 'success' : 'danger'),
                TextColumn::make('assignedTo.name')->label('Asignado a')->placeholder('Sin asignar')->searchable(),
            ])
            ->actions([
                Action::make('assign')
                    ->label('Asignar')
                    ->icon('heroicon-o-user-plus')
                    ->form([Select::make('assigned_to_user_id')->label('Responsable')->options(fn (): array => User::query()->where('is_active', true)->whereNull('disabled_at')->orderBy('name')->pluck('name', 'id')->all())->searchable()->required()])
                    ->visible(fn (): bool => app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'))
                    ->action(function (EnreachError $record, array $data): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'), 403);
                        $record->update(['assigned_to_user_id' => $data['assigned_to_user_id']]);
                        Notification::make()->success()->title('Error de Enreach asignado correctamente.')->send();
                    }),
                Action::make('resolve')
                    ->label('Resolver')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (EnreachError $record): bool => $record->status !== 'resolved' && app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'))
                    ->action(function (EnreachError $record): void {
                        abort_unless(app_user_has_admin_permission(auth()->user(), 'enreach-errors.manage'), 403);
                        $record->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by_user_id' => auth()->id()]);
                        Notification::make()->success()->title('Error de Enreach marcado como resuelto.')->send();
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
