<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulletin_posts', function (Blueprint $table): void {
            $table->string('audience_type')->default('all')->after('body');
            $table->index('audience_type', 'bulletin_posts_audience_type_lookup');
        });

        Schema::create('bulletin_post_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bulletin_post_id')->constrained('bulletin_posts')->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['bulletin_post_id', 'role'], 'bulletin_post_roles_post_role_unique');
            $table->index('role', 'bulletin_post_roles_role_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulletin_post_roles');

        Schema::table('bulletin_posts', function (Blueprint $table): void {
            $table->dropIndex('bulletin_posts_audience_type_lookup');
            $table->dropColumn('audience_type');
        });
    }
};
