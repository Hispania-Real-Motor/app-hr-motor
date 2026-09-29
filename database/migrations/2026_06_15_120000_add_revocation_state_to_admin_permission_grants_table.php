<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_permission_grants', function (Blueprint $table): void {
            $table->boolean('is_revoked')->default(false)->after('group_role');
            $table->index(['group_role', 'is_revoked'], 'apg_group_role_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('admin_permission_grants', function (Blueprint $table): void {
            $table->dropIndex('apg_group_role_revoked_idx');
            $table->dropColumn('is_revoked');
        });
    }
};
