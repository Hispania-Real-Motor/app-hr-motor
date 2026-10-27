<?php

namespace App\Services;

use App\Models\ProvisioningRequest;
use App\Models\ProvisioningStep;
use App\Notifications\UserWelcomeNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class AppHrMotorProvisioner
{
    public function __construct(private readonly UserProvisioningService $users) {}

    /** @return array{status: string, message: string} */
    public function process(ProvisioningRequest $request, ProvisioningStep $step): array
    {
        $user = $request->provisioned_user_id ? $request->provisionedUser : null;

        if (! $user) {
            try {
                $user = DB::transaction(function () use ($request): \App\Models\User {
                    $locked = ProvisioningRequest::query()->lockForUpdate()->findOrFail($request->id);

                    if ($locked->provisioned_user_id) {
                        return $locked->provisionedUser()->firstOrFail();
                    }

                    $created = $this->users->create($locked->employee_data);
                    $locked->forceFill(['provisioned_user_id' => $created->id])->save();
                    return $created;
                });
            } catch (QueryException $exception) {
                Log::warning('Provisioning user creation failed due to a database constraint.', [
                    'request_id' => $request->id,
                    'exception' => $exception::class,
                ]);
                return [
                    'status' => ProvisioningStep::STATUS_FAILED,
                    'message' => 'No se ha podido crear el usuario porque alguno de sus datos ya está en uso.',
                ];
            }
        }

        $invitationStatus = $this->users->sendInvitation($user);

        if ($invitationStatus !== Password::RESET_LINK_SENT) {
            return [
                'status' => ProvisioningStep::STATUS_FAILED,
                'message' => 'El usuario se ha creado, pero no se ha podido enviar el correo de activación.',
            ];
        }

        $user->notify(new UserWelcomeNotification());

        return [
            'status' => ProvisioningStep::STATUS_COMPLETED,
            'message' => 'Usuario creado y correo de activación enviado correctamente.',
        ];
    }
}
