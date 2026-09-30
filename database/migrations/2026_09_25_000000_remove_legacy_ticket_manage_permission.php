<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $legacyGrants = DB::table('admin_permission_grants')
                ->where('permission_key', 'tickets-it.manage')
                ->get();

            foreach ($legacyGrants as $legacyGrant) {
                if ((bool) $legacyGrant->is_revoked) {
                    DB::table('admin_permission_grants')->where('id', $legacyGrant->id)->delete();

                    continue;
                }

                $matchingAssignGrant = DB::table('admin_permission_grants')
                    ->where('permission_key', 'tickets-it.assign')
                    ->when($legacyGrant->user_id === null, fn ($query) => $query->whereNull('user_id'), fn ($query) => $query->where('user_id', $legacyGrant->user_id))
                    ->when($legacyGrant->group_id === null, fn ($query) => $query->whereNull('group_id'), fn ($query) => $query->where('group_id', $legacyGrant->group_id))
                    ->when($legacyGrant->group_role === null, fn ($query) => $query->whereNull('group_role'), fn ($query) => $query->where('group_role', $legacyGrant->group_role))
                    ->first();

                if ($matchingAssignGrant) {
                    if ((bool) $matchingAssignGrant->is_revoked) {
                        DB::table('admin_permission_grants')
                            ->where('id', $matchingAssignGrant->id)
                            ->update(['is_revoked' => false, 'updated_at' => now()]);
                    }

                    DB::table('admin_permission_grants')->where('id', $legacyGrant->id)->delete();

                    continue;
                }

                DB::table('admin_permission_grants')
                    ->where('id', $legacyGrant->id)
                    ->update([
                        'permission_key' => 'tickets-it.assign',
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // The legacy permission is intentionally not restored. It is no longer
        // part of the permission catalogue and must remain unavailable.
    }
};
