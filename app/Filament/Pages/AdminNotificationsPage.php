<?php

namespace App\Filament\Pages;

use App\Models\NotificationActivityLog;
use App\Models\User;
use App\Notifications\AdminPriorityNotification;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Notification as NotificationSender;

class AdminNotificationsPage extends Page
{
    public const TARGET_ALL_USERS = '__all_users__';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationLabel = 'Notificaciones';
    protected static ?string $title = 'Notificaciones';
    protected static ?string $slug = 'notificaciones';
    protected static string|\UnitEnum|null $navigationGroup = 'Contenido';
    protected static ?int $navigationSort = 4;
    protected static ?string $breadcrumb = 'Notificaciones';

    public array $data = [];

    public static function canAccess(): bool
    {
        return app_user_has_admin_permission(auth()->user(), 'notifications.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Título')
                ->required()
                ->maxLength(120),
            Textarea::make('description')
                ->label('Descripción')
                ->required()
                ->maxLength(2000)
                ->rows(6)
                ->columnSpanFull(),
            TextInput::make('link_url')
                ->label('Enlace')
                ->url()
                ->maxLength(2048)
                ->helperText('Opcional. Si se rellena, el usuario podrá abrir este enlace desde la notificación.'),
            Select::make('target_roles')
                ->label('Rol o roles destinatarios')
                ->options([
                    self::TARGET_ALL_USERS => 'Todos los usuarios',
                    ...User::extraRoleLabels(),
                ])
                ->multiple()
                ->required()
                ->minItems(1)
                ->searchable()
                ->live()
                ->dehydrated()
                ->helperText('Puedes seleccionar uno o varios roles. “Todos los usuarios” envía el aviso a toda la aplicación.')
                ->columnSpanFull(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('viewLogs')
                            ->label('Ver log de notificaciones')
                            ->icon('heroicon-o-clipboard-document-list')
                            ->color('gray')
                            ->url(AdminNotificationsLogsPage::getUrl()),
                        Action::make('save')
                            ->label('Enviar notificación')
                            ->icon('heroicon-o-paper-airplane')
                            ->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $selectedTargets = array_values(array_filter((array) ($data['target_roles'] ?? [])));
        $sendToAllUsers = in_array(self::TARGET_ALL_USERS, $selectedTargets, true);

        if ($sendToAllUsers) {
            $selectedTargets = [self::TARGET_ALL_USERS];
        }

        $recipientQuery = User::query()->where('is_active', true);

        if (! $sendToAllUsers) {
            $recipientQuery->whereIn('extra_role', $selectedTargets);
        }

        $recipients = $recipientQuery->get();

        if ($recipients->isEmpty()) {
            Notification::make()
                ->title('No hay usuarios activos con los destinatarios seleccionados.')
                ->warning()
                ->send();

            return;
        }

        /** @var User $actor */
        $actor = auth()->user();

        NotificationSender::send(
            $recipients,
            new AdminPriorityNotification(
                title: $data['title'],
                description: $data['description'],
                linkUrl: $data['link_url'] ?? null,
                actor: $actor,
            ),
        );

        NotificationActivityLog::query()->create([
            'actor_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'title' => $data['title'],
            'description' => $data['description'],
            'link_url' => $data['link_url'] ?? null,
            'target_roles' => $selectedTargets,
            'recipient_count' => $recipients->count(),
            'created_at' => now(),
        ]);

        $this->form->fill();

        Notification::make()
            ->title('Notificación enviada correctamente.')
            ->body("Se ha enviado a {$recipients->count()} usuario(s).")
            ->success()
            ->send();
    }
}
