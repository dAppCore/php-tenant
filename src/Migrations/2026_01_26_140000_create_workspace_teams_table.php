<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workspace teams and enhanced member pivot for role-based access control.
     */
    public function up(): void
    {
        // 1. Create workspace teams table
        Schema::create('workspace_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('colour', 32)->default('zinc');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'is_default']);
        });

        // 2. Enhance user_workspace pivot table
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()
                ->after('role')
                ->constrained('workspace_teams')
                ->nullOnDelete();
            $table->json('custom_permissions')->nullable()->after('team_id');
            $table->timestamp('joined_at')->nullable()->after('custom_permissions');
            $table->foreignId('invited_by')->nullable()
                ->after('joined_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropForeign(['invited_by']);
            $table->dropColumn(['team_id', 'custom_permissions', 'joined_at', 'invited_by']);
        });

        Schema::dropIfExists('workspace_teams');
    }
};
