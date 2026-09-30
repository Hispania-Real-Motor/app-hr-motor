<?php

use App\Models\AdminPermissionGrant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Migrate the legacy public rankings visibility to the application permission.
     */
    public function up(): void
    {
        foreach ([
            User::ROLE_COMMERCIAL,
            User::ROLE_STORE_MANAGER,
            User::ROLE_AREA_MANAGER,
            User::ROLE_HR_NEWCARS,
            User::ROLE_MANAGEMENT,
        ] as $role) {
            $exists = AdminPermissionGrant::query()
                ->where('permission_key', 'rankings.view')
                ->where('group_role', $role)
                ->exists();

            if (! $exists) {
                AdminPermissionGrant::query()->create([
                    'permission_key' => 'rankings.view',
                    'user_id' => null,
                    'group_id' => null,
                    'group_role' => $role,
                    'is_revoked' => false,
                    'granted_by_user_id' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        AdminPermissionGrant::query()
            ->where('permission_key', 'rankings.view')
            ->whereIn('group_role', [
                User::ROLE_COMMERCIAL,
                User::ROLE_STORE_MANAGER,
                User::ROLE_AREA_MANAGER,
                User::ROLE_HR_NEWCARS,
                User::ROLE_MANAGEMENT,
            ])
            ->delete();
    }
};
