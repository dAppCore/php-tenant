<?php

namespace Core\Mod\Tenant\Database\Seeders;

use Core\Mod\Tenant\Enums\UserTier;
use Core\Mod\Tenant\Models\Package;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Models\WorkspacePackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class WorkspaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasTable('users')) {
            return;
        }

        // Environment-aware domains: .test for local, .uk.com for production
        $isLocal = app()->environment('local');
        $domain = $isLocal ? 'host.test' : 'host.uk.com';
        $email = 'snider@host.uk.com';

        // Create system user first so we can assign ownership
        $systemUser = User::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Snider',
                'email' => $email,
                'password' => Hash::make('change-me-in-env'),
                'tier' => UserTier::HADES,
                'tier_expires_at' => null,
                'email_verified_at' => now(),
            ]
        );

        // Service workspaces - marketing domains are handled by Mod modules, not workspace routing.
        // The workspace domain field is for custom user-assigned domains (e.g., mybrand.com).
        // Service domains (lthn.test, social.host.test, etc.) are routed via Mod\{Service}\Boot.
        $workspaces = [
            [
                'name' => 'Host UK',
                'slug' => 'main',
                'domain' => $domain, // Main marketing site
                'icon' => 'globe',
                'color' => 'violet',
                'description' => 'Main website content',
                'type' => 'cms',
                'sort_order' => 0,
            ],
            [
                'name' => 'Social',
                'slug' => 'social',
                'domain' => '', // Marketing domain routed via Mod\Social
                'icon' => 'share-nodes',
                'color' => 'green',
                'description' => 'Social media scheduling',
                'type' => 'custom',
                'sort_order' => 2,
            ],
            [
                'name' => 'Analytics',
                'slug' => 'analytics',
                'domain' => '', // Marketing domain routed via Mod\Analytics
                'icon' => 'chart-line',
                'color' => 'yellow',
                'description' => 'Privacy-first analytics',
                'type' => 'custom',
                'sort_order' => 3,
            ],
            [
                'name' => 'Trust',
                'slug' => 'trust',
                'domain' => '', // Marketing domain routed via Mod\Trust
                'icon' => 'shield-check',
                'color' => 'orange',
                'description' => 'Social proof widgets',
                'type' => 'custom',
                'sort_order' => 4,
            ],
            [
                'name' => 'Notify',
                'slug' => 'notify',
                'domain' => '', // Marketing domain routed via Mod\Notify
                'icon' => 'bell',
                'color' => 'red',
                'description' => 'Push notifications',
                'type' => 'custom',
                'sort_order' => 5,
            ],
            [
                'name' => 'LtHn',
                'slug' => 'lthn',
                'domain' => '', // Marketing domain routed via Mod\LtHn
                'icon' => 'link',
                'color' => 'cyan',
                'description' => 'lt.hn bio link service',
                'type' => 'custom',
                'sort_order' => 6,
            ],
        ];

        foreach ($workspaces as $workspace) {
            $ws = Workspace::updateOrCreate(
                ['slug' => $workspace['slug']],
                array_merge($workspace, ['is_active' => true])
            );

            // Attach system user as owner if not already attached
            if (! $ws->users()->where('user_id', $systemUser->id)->exists()) {
                $ws->users()->attach($systemUser->id, [
                    'role' => 'owner',
                    'is_default' => false,
                ]);
            }
        }

        // Provision hades to main workspace only
        $this->provisionWorkspaceEntitlements();
    }

    /**
     * Provision packages for workspaces.
     */
    protected function provisionWorkspaceEntitlements(): void
    {
        if (! Schema::hasTable('entitlement_workspace_packages')) {
            return;
        }

        // Main workspace gets full Hades access
        $this->provisionPackage('main', 'hades');

        // Service workspaces get analytics, social, trust, notify for tracking & upsell
        $serviceWorkspaces = ['social', 'analytics', 'trust', 'notify', 'lthn'];
        $marketingServices = [
            'core-srv-analytics-access',
            'core-srv-social-access',
            'core-srv-trust-access',
            'core-srv-notify-access',
        ];

        foreach ($serviceWorkspaces as $workspace) {
            foreach ($marketingServices as $package) {
                $this->provisionPackage($workspace, $package);
            }
        }
    }

    /**
     * Provision a package to a workspace.
     */
    protected function provisionPackage(string $workspaceSlug, string $packageCode): void
    {
        $package = Package::where('code', $packageCode)->first();
        if (! $package) {
            return;
        }

        $workspace = Workspace::where('slug', $workspaceSlug)->first();
        if (! $workspace) {
            return;
        }

        WorkspacePackage::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'package_id' => $package->id,
            ],
            [
                'status' => WorkspacePackage::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => null,
            ]
        );
    }
}
