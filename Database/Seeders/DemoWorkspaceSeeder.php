<?php

namespace Core\Mod\Tenant\Database\Seeders;

use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Package;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\EntitlementService;
use Illuminate\Database\Seeder;

/**
 * Creates demo workspaces for testing entitlement scenarios.
 *
 * Run: php artisan db:seed --class="Mod\Tenant\Database\Seeders\DemoWorkspaceSeeder"
 */
class DemoWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        $entitlements = app(EntitlementService::class);

        // Create demo packages if they don't exist
        $this->createDemoPackages();

        // Create demo workspaces
        $workspaces = [
            [
                'name' => 'Demo Social',
                'slug' => 'demo-social',
                'domain' => 'demo-social.host.test',
                'description' => 'Demo workspace with SocialHost access',
                'icon' => 'share-nodes',
                'color' => 'green',
                'package' => 'demo-social',
            ],
            [
                'name' => 'Demo Trust',
                'slug' => 'demo-trust',
                'domain' => 'demo-trust.host.test',
                'description' => 'Demo workspace with TrustHost access',
                'icon' => 'shield-check',
                'color' => 'orange',
                'package' => 'demo-trust',
            ],
            [
                'name' => 'Demo No Services',
                'slug' => 'demo-no-services',
                'domain' => 'demo-free.host.test',
                'description' => 'Demo workspace with no service access',
                'icon' => 'user',
                'color' => 'gray',
                'package' => null,
            ],
        ];

        foreach ($workspaces as $data) {
            $workspace = Workspace::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'domain' => $data['domain'],
                    'description' => $data['description'],
                    'icon' => $data['icon'],
                    'color' => $data['color'],
                    'type' => 'custom',
                    'is_active' => true,
                ]
            );

            // Provision package if specified
            if ($data['package']) {
                $entitlements->provisionPackage($workspace, $data['package']);
            }

            $this->command->info("Created demo workspace: {$data['name']}");
        }

        // Create demo user and attach to workspaces
        $this->createDemoUser($workspaces);
    }

    protected function createDemoPackages(): void
    {
        // Demo Social Package - SocialHost access
        $socialPackage = Package::updateOrCreate(
            ['code' => 'demo-social'],
            [
                'name' => 'Demo Social',
                'description' => 'Demo package with SocialHost access',
                'is_stackable' => false,
                'is_base_package' => true,
                'is_active' => true,
                'is_public' => false,
                'sort_order' => 900,
            ]
        );

        // Attach service gate
        $hostSocial = Feature::where('code', 'core.srv.social')->first();
        if ($hostSocial && ! $socialPackage->features()->where('feature_id', $hostSocial->id)->exists()) {
            $socialPackage->features()->attach($hostSocial->id, ['limit_value' => null]);
        }

        // Attach social features with limits
        $socialAccounts = Feature::where('code', 'social.accounts')->first();
        if ($socialAccounts && ! $socialPackage->features()->where('feature_id', $socialAccounts->id)->exists()) {
            $socialPackage->features()->attach($socialAccounts->id, ['limit_value' => 5]);
        }

        $socialPosts = Feature::where('code', 'social.posts.scheduled')->first();
        if ($socialPosts && ! $socialPackage->features()->where('feature_id', $socialPosts->id)->exists()) {
            $socialPackage->features()->attach($socialPosts->id, ['limit_value' => 50]);
        }

        // Demo Trust Package - TrustHost access
        $trustPackage = Package::updateOrCreate(
            ['code' => 'demo-trust'],
            [
                'name' => 'Demo Trust',
                'description' => 'Demo package with TrustHost access',
                'is_stackable' => false,
                'is_base_package' => true,
                'is_active' => true,
                'is_public' => false,
                'sort_order' => 901,
            ]
        );

        // Attach service gate
        $hostTrust = Feature::where('code', 'core.srv.trust')->first();
        if ($hostTrust && ! $trustPackage->features()->where('feature_id', $hostTrust->id)->exists()) {
            $trustPackage->features()->attach($hostTrust->id, ['limit_value' => null]);
        }

        $this->command->info('Demo packages created.');
    }

    protected function createDemoUser(array $workspaces): void
    {
        // Find primary admin user, or create demo user as fallback
        $user = User::where('email', 'snider@host.uk.com')->first()
            ?? User::updateOrCreate(
                ['email' => 'demo@host.uk.com'],
                [
                    'name' => 'Demo User',
                    'password' => bcrypt('demo-password-123'),
                    'email_verified_at' => now(),
                ]
            );

        // Attach to all demo workspaces
        foreach ($workspaces as $data) {
            $workspace = Workspace::where('slug', $data['slug'])->first();
            if ($workspace && ! $workspace->users()->where('user_id', $user->id)->exists()) {
                $workspace->users()->attach($user->id, [
                    'role' => 'owner',
                    'is_default' => false, // Don't change their default workspace
                ]);
            }
        }

        $this->command->info("Demo workspaces attached to: {$user->email}");
    }
}
