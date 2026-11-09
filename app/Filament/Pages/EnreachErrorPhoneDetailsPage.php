<?php

namespace App\Filament\Pages;

use App\Models\EnreachErrorEvent;
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

class EnreachErrorPhoneDetailsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';
    protected static ?string $navigationLabel = 'Detalle del teléfono';
    protected static ?string $title = 'Detalle del teléfono';
    protected static ?string $slug = 'enreach-errors/phone';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $breadcrumb = 'Detalle del teléfono';
    protected string $view = 'filament.pages.enreach-error-phone-details';

    public string $type = EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND;

    public string $phone = '';

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'enreach-errors.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->type = (string) request()->query('type', $this->type);
        $this->phone = (string) request()->query('phone', $this->phone);
        abort_unless($this->type === EnreachErrorEvent::TYPE_COMMERCIAL_PHONE_NOT_FOUND && $this->phone !== '', 404);
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
            EnreachErrorPhonesPage::getUrl(['type' => $this->type]) => 'Teléfonos afectados',
            self::getUrl(['type' => $this->type, 'phone' => $this->phone]) => 'Detalle del teléfono',
        ];
    }

    public function integrationIsConnected(): bool
    {
        return config('services.enreach.integration_status', 'pending') === 'connected';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = EnreachErrorEvent::query()
                    ->where('error_type', $this->type)
                    ->where('phone_normalized', $this->phone);

                if (! $this->integrationIsConnected()) {
                    $query->whereRaw('1 = 0');
                }

                return $query->orderByDesc('occurred_at');
            })
            ->columns([
                TextColumn::make('occurred_at')->label('Fecha y hora de la llamada')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('direction')->label('Sentido')->formatStateUsing(fn (string $state): string => $state === EnreachErrorEvent::DIRECTION_INCOMING ? 'Entrante' : 'Saliente'),
                TextColumn::make('other_party_phone')->label('Teléfono de la otra parte'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
