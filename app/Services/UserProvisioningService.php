<?php

namespace App\Services;

use App\Models\Dealership;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserProvisioningService
{
    /** @throws QueryException */
    public function create(array $data): User
    {
        $dealership = filled($data['dealership_id'] ?? null)
            ? Dealership::query()->find($data['dealership_id'])
            : null;
        $extraRole = filled($data['extra_role'] ?? null) ? $data['extra_role'] : null;
        $isIt = $extraRole === User::ROLE_INFORMATION_TECHNOLOGY;
        $isRanked = $data['role'] === User::ROLE_USER && in_array($extraRole, [
            User::ROLE_COMMERCIAL, User::ROLE_STORE_MANAGER, User::ROLE_AREA_MANAGER, User::ROLE_HR_NEWCARS,
        ], true);
        $schedule = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            foreach (['start', 'end'] as $part) {
                $key = "it_{$day}_{$part}";
                $schedule[$key] = $isIt ? ($data[$key] ?? null) : null;
            }
        }

        return User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'company_entry_date' => $data['company_entry_date'],
            'job_position' => $data['job_position'],
            'role' => $data['role'],
            'extra_role' => $extraRole,
            'phone' => $data['phone'] ?? null,
            'enreach_extension' => $data['enreach_extension'] ?? null,
            'dealership' => $dealership?->name,
            'dealership_id' => $dealership?->id,
            'salesforce_user_id' => $isRanked ? ($data['salesforce_user_id'] ?? null) : null,
            ...$schedule,
            'password' => Hash::make(Str::password(32)),
            'is_active' => false,
            'must_change_password' => true,
            'activated_at' => null,
            'invitation_sent_at' => null,
        ]);
    }

    public function sendInvitation(User $user): string
    {
        return app(UserInvitationService::class)->resend($user);
    }
}
