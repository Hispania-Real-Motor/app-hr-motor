<?php

namespace App\Filament\Pages;

use App\Models\ItTicketNotificationSetting;
use App\Services\ItTicketNotificationSettingActivityLogWriter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItTicketNotificationSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Correo Tickets IT';

    protected static ?string $title = 'Correo Tickets IT';

    protected static ?string $slug = 'tickets-it-configuracion';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 8;

    protected static ?string $breadcrumb = 'Correo Tickets IT';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'backoffice.tickets-it-recipients.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Destinatarios de los avisos de nuevos tickets.';
    }

    public function mount(): void
    {
        $this->form->fill($this->getFormState());
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TagsInput::make('recipients')
                ->label('Destinatarios')
                ->placeholder('Escribe un correo y pulsa Enter')
                ->helperText('Se enviará un aviso a todas las direcciones configuradas. Pulsa Enter para confirmar cada dirección.')
                ->trim()
                ->distinctList()
                ->nestedRecursiveRules(['required', 'email:rfc', 'max:254'])
                ->required()
                ->default(ItTicketNotificationSetting::DEFAULT_RECIPIENTS),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment($this->getFormActionsAlignment())
                        ->fullWidth(true)
                        ->key('form-actions'),
                ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar destinatarios')
                ->icon('heroicon-o-check')
                ->submit('save'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::Start;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getRawState();
        $recipients = collect($data['recipients'] ?? [])
            ->map(static fn (mixed $email): string => strtolower(trim((string) $email)))
            ->filter()
            ->values();

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'data.recipients' => 'Debes configurar al menos un destinatario válido.',
            ]);
        }

        $recipients = $recipients->all();
        $duplicates = array_values(array_unique(array_diff_assoc($recipients, array_unique($recipients))));

        if ($duplicates !== []) {
            throw ValidationException::withMessages([
                'data.recipients' => 'No puedes repetir direcciones de correo.',
            ]);
        }

        Validator::make(
            ['recipients' => $recipients],
            ['recipients' => ['required', 'array', 'min:1'], 'recipients.*' => ['required', 'email:rfc', 'max:254']],
        )->validate();

        $setting = ItTicketNotificationSetting::query()->first() ?? new ItTicketNotificationSetting();
        $previousRecipients = ItTicketNotificationSetting::recipients();

        DB::transaction(function () use ($setting, $previousRecipients, $recipients): void {
            $setting->fill([
                'recipients' => $recipients,
                'updated_by_user_id' => auth()->id(),
            ]);
            $setting->save();

            app(ItTicketNotificationSettingActivityLogWriter::class)->record(
                actor: auth()->user(),
                previousRecipients: $previousRecipients,
                recipients: $recipients,
            );
        });

        $this->form->fill($this->getFormState());

        Notification::make()
            ->title('Los destinatarios de Tickets IT se han actualizado correctamente.')
            ->success()
            ->send();
    }

    /**
     * @return array{recipients: array<int, string>}
     */
    protected function getFormState(): array
    {
        return [
            'recipients' => ItTicketNotificationSetting::recipients(),
        ];
    }
}
