<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for frequently queried columns.
     *
     * Addresses PERF-002 from TODO.md:
     * - users.tier (tier-based queries)
     * - namespaces.slug (slug lookups independent of owner)
     * - entitlement_usage_records.user_id (foreign key)
     *
     * Additional indexes for common query patterns:
     * - workspaces.is_active (active scope)
     * - workspaces.type (type filtering)
     * - workspaces.domain (domain lookups)
     * - user_workspace.team_id (foreign key from teams migration)
     * - entitlement_logs.user_id (foreign key)
     */
    public function up(): void
    {
        // Users table indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index('tier', 'users_tier_idx');
        });

        // Namespaces table indexes
        Schema::table('namespaces', function (Blueprint $table) {
            $table->index('slug', 'namespaces_slug_idx');
        });

        // Workspaces table indexes
        Schema::table('workspaces', function (Blueprint $table) {
            $table->index('is_active', 'workspaces_is_active_idx');
            $table->index('type', 'workspaces_type_idx');
            $table->index('domain', 'workspaces_domain_idx');
        });

        // User workspace pivot table indexes
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->index('team_id', 'user_workspace_team_id_idx');
        });

        // Entitlement usage records indexes
        Schema::table('entitlement_usage_records', function (Blueprint $table) {
            $table->index('user_id', 'ent_usage_user_id_idx');
        });

        // Entitlement logs indexes
        Schema::table('entitlement_logs', function (Blueprint $table) {
            $table->index('user_id', 'ent_logs_user_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_tier_idx');
        });

        Schema::table('namespaces', function (Blueprint $table) {
            $table->dropIndex('namespaces_slug_idx');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex('workspaces_is_active_idx');
            $table->dropIndex('workspaces_type_idx');
            $table->dropIndex('workspaces_domain_idx');
        });

        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropIndex('user_workspace_team_id_idx');
        });

        Schema::table('entitlement_usage_records', function (Blueprint $table) {
            $table->dropIndex('ent_usage_user_id_idx');
        });

        Schema::table('entitlement_logs', function (Blueprint $table) {
            $table->dropIndex('ent_logs_user_id_idx');
        });
    }
};
