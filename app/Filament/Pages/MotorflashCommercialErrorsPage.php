<?php

namespace App\Filament\Pages;

use App\Models\MotorflashCommercialErrorEvent;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
        return $table
            ->query(fn (): Builder => MotorflashCommercialErrorEvent::query()
                ->where('error_type', MotorflashCommercialErrorEvent::TYPE_COMMERCIAL_NOT_FOUND_IN_SALESFORCE)
                ->whereNotNull('searched_email_normalized')
                ->select('error_type', 'searched_email_normalized')
                ->selectRaw('MIN(id) as id')
                ->selectRaw('MIN(searched_email) as searched_email')
                ->selectRaw('COUNT(*) as occurrence_count')
                ->selectRaw('MAX(occurred_at) as last_error_at')
                ->groupBy('error_type', 'searched_email_normalized'))
            ->defaultSort('last_error_at', 'desc')
            ->columns([
                TextColumn::make('searched_email')->label('Correo buscado')->searchable(),
                TextColumn::make('occurrence_count')->label('Ocurrencias')->numeric()->sortable(),
                TextColumn::make('last_error_at')->label('Último error')->dateTime('d/m/Y H:i')->sortable(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
