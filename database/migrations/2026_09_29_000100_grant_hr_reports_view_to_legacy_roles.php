<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([User::ROLE_MANAGEMENT, User::ROLE_AREA_MANAGER] as $role) {
            $exists = DB::table('admin_permission_grants')
                ->where('permission_key', 'reports.hr.view')
                ->where('group_role', $role)
                ->whereNull('user_id')
                ->whereNull('group_id')
                ->exists();

            if (! $exists) {
                DB::table('admin_permission_grants')->insert([
                    'permission_key' => 'reports.hr.view',
                    'user_id' => null,
                    'group_id' => null,
                    'group_role' => $role,
                    'is_revoked' => false,
                    'granted_by_user_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('admin_permission_grants')
            ->where('permission_key', 'reports.hr.view')
            ->whereIn('group_role', [User::ROLE_MANAGEMENT, User::ROLE_AREA_MANAGER])
            ->whereNull('user_id')
            ->whereNull('group_id')
            ->delete();
    }
};
