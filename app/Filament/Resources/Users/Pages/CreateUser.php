<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Notifications\UserWelcomeNotification;
use App\Services\UserActivityLogWriter;
use Filament\Resources\Pages\CreateRecord;
use App\Services\UserProvisioningService;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data;
    }

    protected function handleRecordCreation(array $data): User
    {
        return app(UserProvisioningService::class)->create($data);
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;
        $actor = auth()->user();

        if ($actor instanceof User) {
            app(UserActivityLogWriter::class)->record(
                actor: $actor,
                targetUser: $user,
                action: UserActivityLog::ACTION_CREATED,
            );
        }

        $user->notify(new UserWelcomeNotification());

        app(UserProvisioningService::class)->sendInvitation($user);
    }
}
