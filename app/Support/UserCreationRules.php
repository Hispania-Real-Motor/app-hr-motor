<?php

namespace App\Support;

use App\Models\User;
use App\Rules\UserEnreachExtensionRule;
use Illuminate\Validation\Rule;

final class UserCreationRules
{
    public static function for(?string $baseRole, ?string $extraRole, ?array $allowedBaseRoles = null): array
    {
        $ranked = $baseRole === User::ROLE_USER && in_array($extraRole, [
            User::ROLE_COMMERCIAL,
            User::ROLE_STORE_MANAGER,
            User::ROLE_AREA_MANAGER,
            User::ROLE_HR_NEWCARS,
        ], true);
        $it = $extraRole === User::ROLE_INFORMATION_TECHNOLOGY;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'company_entry_date' => ['required', 'date'],
            'job_position' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::in($allowedBaseRoles ?? array_keys(User::baseRoleLabels()))],
            'extra_role' => ['nullable', 'string', Rule::in(array_keys(User::extraRoleLabels()))],
            'phone' => ['nullable', 'string', 'max:32'],
            'enreach_extension' => ['nullable', 'string', 'max:20', new UserEnreachExtensionRule(action: 'crear')],
            'dealership_id' => ['nullable', 'integer', Rule::exists('dealerships', 'id')],
            'salesforce_user_id' => [Rule::requiredIf($ranked), 'nullable', 'string', 'max:255', 'unique:users,salesforce_user_id'],
            ...self::scheduleRules($it),
        ];
    }

    public static function scheduleRules(bool $required): array
    {
        $rules = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            foreach (['start', 'end'] as $part) {
                $rules["it_{$day}_{$part}"] = [$required ? 'required' : 'nullable', 'date_format:H:i'];
            }
        }
        return $rules;
    }
}
