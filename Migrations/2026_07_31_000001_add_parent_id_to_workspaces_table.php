<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Workspaces can belong to a workspace.
     *
     * An agency with clients, a group with subsidiaries, a platform reselling
     * to teams — all of them are one workspace that owns others, and without a
     * parent every consumer that needs the relationship invents its own way of
     * faking it. lthn.ai already had this column locally, which is why its
     * hierarchy code could not be shared.
     *
     * Nullable, so every existing workspace is a root and nothing changes for
     * anyone not using it.
     */
    public function up(): void
    {
        if (! Schema::hasTable('workspaces') || Schema::hasColumn('workspaces', 'parent_id')) {
            return;
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            // nullOnDelete, not cascade: deleting a parent must not silently
            // take its children — and everything they own — with it. They
            // become roots and stay visible, which is recoverable; a cascade
            // is not.
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('workspaces')
                ->nullOnDelete();

            $table->index(['parent_id', 'is_active'], 'workspaces_parent_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasColumn('workspaces', 'parent_id')) {
            return;
        }

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex('workspaces_parent_active');
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
