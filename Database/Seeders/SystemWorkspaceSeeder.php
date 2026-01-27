<?php

namespace Core\Tenant\Database\Seeders;

use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Illuminate\Database\Seeder;

class SystemWorkspaceSeeder extends Seeder
{
    /**
     * Assign all entitlements to system workspaces.
     */
    public function run(): void
    {
        $hermes = Package::where('code', 'hermes')->first();

        if (! $hermes) {
            $this->command->error('Hermes package not found. Run PackageSeeder first.');

            return;
        }

        // Assign to both main and system workspaces
        $slugs = ['main', 'system'];

        foreach ($slugs as $slug) {
            $workspace = Workspace::where('slug', $slug)->first();

            if (! $workspace) {
                $this->command->warn("Workspace '{$slug}' not found, skipping.");

                continue;
            }

            $existing = WorkspacePackage::where('workspace_id', $workspace->id)
                ->where('package_id', $hermes->id)
                ->first();

            if ($existing) {
                $this->command->info('Hermes already assigned to '.$workspace->name);

                continue;
            }

            WorkspacePackage::create([
                'workspace_id' => $workspace->id,
                'package_id' => $hermes->id,
                'status' => WorkspacePackage::STATUS_ACTIVE,
                'starts_at' => now(),
            ]);

            $this->command->info('Hermes assigned to '.$workspace->name);
        }
    }
}
