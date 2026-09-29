<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Dealership;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Services\UserActivityLogWriter;
use App\Services\UserInvitationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Password;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected array $pendingActivityLogChanges = [];

    protected bool $passwordWasChanged = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resendActivationEmail')
                ->label('Reenviar correo de activación')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->visible(fn (): bool => ! $this->getRecord()->isDisabled() && (
                    $this->getRecord()->isInvitationExpired()
                    || ! $this->getRecord()->is_active
                    || $this->getRecord()->must_change_password
                ))
                ->requiresConfirmation()
                ->modalHeading('Reenviar correo de activación')
                ->modalDescription(fn (): string => $this->buildResendInvitationWarning())
                ->action(function (): void {
                    $status = app(UserInvitationService::class)->resend($this->getRecord());

                    if ($status === UserInvitationService::DELIVERY_FAILED) {
                        Notification::make()
                            ->danger()
                            ->title('El servidor SMTP ha rechazado el correo de activación.')
                            ->body('Comprueba que ese buzón exista y que el servidor de correo de HR Motor lo acepte.')
                            ->send();

                        return;
                    }

                    if ($status !== Password::RESET_LINK_SENT) {
                        Notification::make()
                            ->danger()
                            ->title(match ($status) {
                                Password::RESET_THROTTLED => 'Espera un momento antes de volver a enviar el correo de activación.',
                                Password::INVALID_USER => 'No se ha encontrado un usuario válido para enviar el correo de activación.',
                                default => 'No se ha podido enviar el correo de activación.',
                            })
                            ->body(match ($status) {
                                Password::RESET_THROTTLED => 'Solo han pasado unos minutos desde el último envío. Espera un poco antes de intentarlo de nuevo.',
                                default => null,
                            })
                            ->send();

                        return;
                    }

                    $this->record->refresh();

                    Notification::make()
                        ->success()
                        ->title('Correo de activación reenviado correctamente.')
                        ->body('El estado de la cuenta ha pasado a Pendiente.')
                        ->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $newPassword = $this->data['new_password'] ?? null;

        unset($data['password'], $data['new_password'], $data['new_password_confirmation']);

        if (filled($newPassword)) {
            abort_unless(app_user_has_admin_permission(auth()->user(), 'users.manage'), 403);

            // User::$casts hashes this plain value exactly once when it is assigned.
            $data['password'] = $newPassword;
            $this->passwordWasChanged = true;
        } else {
            $this->passwordWasChanged = false;
        }

        if (($data['extra_role'] ?? null) !== User::ROLE_INFORMATION_TECHNOLOGY) {
            foreach ([
                'it_monday_start',
                'it_monday_end',
                'it_tuesday_start',
                'it_tuesday_end',
                'it_wednesday_start',
                'it_wednesday_end',
                'it_thursday_start',
                'it_thursday_end',
                'it_friday_start',
                'it_friday_end',
            ] as $field) {
                $data[$field] = null;
            }
        }

        $dealership = filled($data['dealership_id'] ?? null)
            ? Dealership::query()->find($data['dealership_id'])
            : null;

        $data['dealership'] = $dealership?->name;
        $loggableData = $data;
        unset($loggableData['password']);
        $this->pendingActivityLogChanges = $this->buildChangeSet($this->getRecord(), $loggableData);

        return $data;
    }

    protected function afterSave(): void
    {
        $changes = $this->pendingActivityLogChanges;

        if ($this->passwordWasChanged) {
            $changes['Contraseña'] = [
                'from' => 'Oculta',
                'to' => 'Actualizada',
            ];
        }

        if ($changes === []) {
            return;
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        app(UserActivityLogWriter::class)->record(
            actor: $actor,
            targetUser: $this->getRecord(),
            action: UserActivityLog::ACTION_UPDATED,
            changes: $changes,
        );

        $this->pendingActivityLogChanges = [];
        $this->passwordWasChanged = false;
    }

    protected function buildResendInvitationWarning(): string
    {
        $record = $this->getRecord();

        if ($record->isInvitationExpired()) {
            return 'Vas a reenviar el correo de activación para que la persona pueda definir su contraseña.';
        }

        $sentAt = $record->invitation_sent_at ?? $record->created_at;
        $elapsedMinutes = $sentAt ? max(1, now()->diffInMinutes($sentAt)) : null;
        $elapsedText = $elapsedMinutes === null
            ? 'muy poco tiempo'
            : ($elapsedMinutes === 1 ? '1 minuto' : "{$elapsedMinutes} minutos");

        return "Solo han pasado {$elapsedText} desde el último reenvío. ¿Seguro que quieres reenviarlo de nuevo?";
    }

    protected function buildChangeSet(User $user, array $newValues): array
    {
        $labels = [
            'name' => 'Nombre',
            'email' => 'Correo',
            'company_entry_date' => 'Día que entró en la empresa',
            'job_position' => 'Puesto',
            'phone' => 'Teléfono',
            'enreach_extension' => 'Extensión Enreach',
            'it_monday_start' => 'Horario lunes inicio',
            'it_monday_end' => 'Horario lunes fin',
            'it_tuesday_start' => 'Horario martes inicio',
            'it_tuesday_end' => 'Horario martes fin',
            'it_wednesday_start' => 'Horario miércoles inicio',
            'it_wednesday_end' => 'Horario miércoles fin',
            'it_thursday_start' => 'Horario jueves inicio',
            'it_thursday_end' => 'Horario jueves fin',
            'it_friday_start' => 'Horario viernes inicio',
            'it_friday_end' => 'Horario viernes fin',
            'role' => 'Rol',
            'extra_role' => 'Rol adicional',
            'salesforce_user_id' => 'ID Salesforce',
            'dealership' => 'Delegación',
        ];

        return collect($newValues)
            ->filter(fn ($value, $field) => $this->compareUserFieldValue($user, $field, $value))
            ->mapWithKeys(fn ($value, $field) => [
                $labels[$field] ?? $field => [
                    'from' => $this->displayUserFieldValue($this->getUserFieldValue($user, $field), $field),
                    'to' => $this->displayUserFieldValue($value, $field),
                ],
            ])
            ->all();
    }

    protected function getUserFieldValue(User $user, string $field): mixed
    {
        if ($field === 'company_entry_date') {
            return $user->getRawOriginal($field);
        }

        return $user->{$field};
    }

    protected function compareUserFieldValue(User $user, string $field, mixed $newValue): bool
    {
        if ($field === 'company_entry_date') {
            return $this->normalizeDateValue($user->getRawOriginal($field)) !== $this->normalizeDateValue($newValue);
        }

        if (in_array($field, ['phone', 'enreach_extension'], true)) {
            return $this->normalizeAgendaValue($user->{$field}) !== $this->normalizeAgendaValue($newValue);
        }

        return $user->{$field} !== $newValue;
    }

    protected function displayUserFieldValue(mixed $value, string $field): mixed
    {
        if ($field === 'company_entry_date') {
            $normalized = $this->normalizeDateValue($value);

            return $normalized ? \Illuminate\Support\Carbon::parse($normalized)->format('d/m/Y') : null;
        }

        return $value;
    }

    protected function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof \Illuminate\Support\Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($value)->toDateString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($value)->toDateString();
    }

    protected function normalizeAgendaValue(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
