<?php

namespace Core\Mod\Tenant\Database\Seeders;

use Core\Mod\Tenant\Models\Package;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a demo test user with known state for Playwright acceptance tests.
 *
 * This user has:
 * - Nyx tier (Lethean Network demo/test designation)
 * - Minimal settings and data
 * - Predictable credentials for automated testing
 *
 * Tier naming follows Lethean Network designations:
 * - Nyx: Demo/test accounts (goddess of night)
 * - Stygian: Standard users (River Styx)
 * - Apollo/Hades: Internal tiers (existing)
 *
 * Run with: php artisan db:seed --class=DemoTestUserSeeder
 */
class DemoTestUserSeeder extends Seeder
{
    // Fixed credentials for test automation
    public const EMAIL = 'nyx@host.uk.com';

    public const PASSWORD = 'nyx-test-2026';

    public const WORKSPACE_SLUG = 'nyx-demo';

    public function run(): void
    {
        // Create or update Nyx demo user
        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Nyx Tester',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]
        );

        // Create or update Nyx demo workspace
        $workspace = Workspace::updateOrCreate(
            ['slug' => self::WORKSPACE_SLUG],
            [
                'name' => 'Nyx Demo Workspace',
                'domain' => 'nyx.host.uk.com',
                'is_active' => true,
            ]
        );

        // Attach user to workspace (if not already)
        if (! $workspace->users()->where('user_id', $user->id)->exists()) {
            $workspace->users()->attach($user->id, [
                'role' => 'owner',
                'is_default' => true,
            ]);
        }

        // Assign Nyx package (Lethean Network demo tier)
        $nyxPackage = Package::where('code', 'nyx')->first();
        if ($nyxPackage) {
            // Remove any existing packages
            $workspace->workspacePackages()->delete();

            // Create Nyx package assignment
            $workspace->workspacePackages()->create([
                'package_id' => $nyxPackage->id,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => null, // No expiry for test account
            ]);
        }

        // Create minimal test data for the workspace
        $this->createTestBioPage($workspace, $user);
        $this->createTestShortLink($workspace, $user);

        $this->command->info('Nyx demo user created successfully.');
        $this->command->info("Email: {$user->email}");
        $this->command->info('Password: '.self::PASSWORD);
        $this->command->info("Workspace: {$workspace->slug}");
        $this->command->info('Tier: Nyx (Lethean Network)');
    }

    /**
     * Create a single test bio page.
     */
    protected function createTestBioPage(Workspace $workspace, User $user): void
    {
        // Only create if Web Page model exists and no test page exists
        if (! class_exists(\Core\Mod\Web\Models\Page::class)) {
            return;
        }

        $existingPage = \Core\Mod\Web\Models\Page::where('workspace_id', $workspace->id)
            ->where('url', 'nyx-test')
            ->first();

        if ($existingPage) {
            return;
        }

        \Core\Mod\Web\Models\Page::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'url' => 'nyx-test',
            'type' => 'page',
            'settings' => [
                'name' => 'Nyx Test Page',
                'description' => 'Test page for Playwright acceptance tests (Lethean Network)',
                'title' => 'Nyx Test',
                'blocks' => [
                    [
                        'id' => 'header-1',
                        'type' => 'header',
                        'data' => [
                            'name' => 'Nyx Tester',
                            'bio' => 'Lethean Network demo account',
                        ],
                    ],
                    [
                        'id' => 'link-1',
                        'type' => 'link',
                        'data' => [
                            'title' => 'Test Link',
                            'url' => 'https://example.com',
                        ],
                    ],
                ],
                'theme' => 'default',
                'show_branding' => true,
            ],
            'is_enabled' => true,
        ]);
    }

    /**
     * Create a single test short link.
     */
    protected function createTestShortLink(Workspace $workspace, User $user): void
    {
        // Only create if Web Page model exists
        if (! class_exists(\Core\Mod\Web\Models\Page::class)) {
            return;
        }

        $existingLink = \Core\Mod\Web\Models\Page::where('workspace_id', $workspace->id)
            ->where('url', 'nyx-short')
            ->first();

        if ($existingLink) {
            return;
        }

        \Core\Mod\Web\Models\Page::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'url' => 'nyx-short',
            'type' => 'link',
            'location_url' => 'https://host.uk.com',
            'is_enabled' => true,
        ]);
    }
}
