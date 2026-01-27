<?php

use Core\Mod\Tenant\Models\Boost;
use Core\Mod\Tenant\Models\EntitlementLog;
use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Package;
use Core\Mod\Tenant\Models\UsageRecord;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Notifications\BoostExpiredNotification;
use Core\Mod\Tenant\Services\EntitlementService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Notification::fake();

    // Create test user and workspace
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Create features
    $this->aiCreditsFeature = Feature::create([
        'code' => 'ai.credits',
        'name' => 'AI Credits',
        'description' => 'AI generation credits',
        'category' => 'ai',
        'type' => Feature::TYPE_LIMIT,
        'reset_type' => Feature::RESET_MONTHLY,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->socialPostsFeature = Feature::create([
        'code' => 'social.posts',
        'name' => 'Scheduled Posts',
        'description' => 'Monthly scheduled posts',
        'category' => 'social',
        'type' => Feature::TYPE_LIMIT,
        'reset_type' => Feature::RESET_MONTHLY,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    // Create base package
    $this->creatorPackage = Package::create([
        'code' => 'creator',
        'name' => 'Creator',
        'description' => 'For individual creators',
        'is_stackable' => false,
        'is_base_package' => true,
        'is_active' => true,
        'is_public' => true,
        'sort_order' => 1,
    ]);

    $this->creatorPackage->features()->attach($this->aiCreditsFeature->id, ['limit_value' => 100]);
    $this->creatorPackage->features()->attach($this->socialPostsFeature->id, ['limit_value' => 50]);

    $this->service = app(EntitlementService::class);
});

describe('ResetBillingCycles Command', function () {
    describe('expiring cycle-bound boosts', function () {
        it('expires cycle-bound boosts', function () {
            // Provision package
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            // Create cycle-bound boost
            $boost = Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 10,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            // Run command
            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_EXPIRED);
        });

        it('does not expire permanent boosts', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            $boost = Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_PERMANENT,
                'limit_value' => 50,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_ACTIVE);
        });

        it('creates audit log entries for expired boosts', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_BOOST_EXPIRED)
                ->first();

            expect($log)->not->toBeNull()
                ->and($log->metadata['reason'])->toBe('Billing cycle ended');
        });
    });

    describe('expiring timed boosts', function () {
        it('expires boosts past their expiry date', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            $boost = Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_DURATION,
                'limit_value' => 100,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(30),
                'expires_at' => now()->subDay(), // Expired yesterday
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_EXPIRED);
        });

        it('does not expire boosts with future expiry', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            $boost = Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_DURATION,
                'limit_value' => 100,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => now()->addWeek(), // Expires next week
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_ACTIVE);
        });
    });

    describe('notifications', function () {
        it('sends notification to workspace owner when boosts expire', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 10,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            Notification::assertSentTo(
                $this->user,
                BoostExpiredNotification::class
            );
        });

        it('does not send notification in dry-run mode', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
                '--dry-run' => true,
            ])->assertExitCode(0);

            Notification::assertNothingSent();
        });
    });

    describe('dry-run mode', function () {
        it('does not modify boosts in dry-run mode', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            $boost = Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
                '--dry-run' => true,
            ])->assertExitCode(0);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_ACTIVE);
        });

        it('does not create log entries in dry-run mode', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            // Clear any existing logs
            EntitlementLog::where('workspace_id', $this->workspace->id)->delete();

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
                '--dry-run' => true,
            ])->assertExitCode(0);

            $logs = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_BOOST_EXPIRED)
                ->count();

            expect($logs)->toBe(0);
        });
    });

    describe('processing all workspaces', function () {
        it('processes multiple workspaces', function () {
            // Create second workspace
            $workspace2 = Workspace::factory()->create(['is_active' => true]);
            $user2 = User::factory()->create();
            $workspace2->users()->attach($user2->id, ['role' => 'owner', 'is_default' => true]);

            // Provision packages for both
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);
            $this->service->provisionPackage($workspace2, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            // Create boosts for both
            Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            Boost::create([
                'workspace_id' => $workspace2->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 100,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles')
                ->assertExitCode(0);

            // Both boosts should be expired
            expect(Boost::where('status', Boost::STATUS_EXPIRED)->count())->toBe(2);
        });

        it('skips workspaces without active packages', function () {
            // Don't provision a package for this workspace
            $workspace2 = Workspace::factory()->create(['is_active' => true]);

            $this->artisan('tenant:reset-billing-cycles')
                ->assertExitCode(0);

            // No errors should occur
        });

        it('skips inactive workspaces', function () {
            $this->workspace->update(['is_active' => false]);

            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            $this->artisan('tenant:reset-billing-cycles')
                ->assertExitCode(0);

            // Boost should not be expired (workspace is inactive)
            expect(Boost::where('status', Boost::STATUS_ACTIVE)->count())->toBe(1);
        });
    });

    describe('usage counter reset logging', function () {
        it('logs cycle reset when at cycle boundary with previous usage', function () {
            // Set billing cycle to start today
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now(),
            ]);

            // Create usage record from previous cycle
            UsageRecord::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'quantity' => 25,
                'recorded_at' => now()->subMonth(), // Previous cycle
            ]);

            // Clear logs from provisioning
            EntitlementLog::where('workspace_id', $this->workspace->id)->delete();

            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', 'cycle.reset')
                ->first();

            expect($log)->not->toBeNull()
                ->and($log->metadata['previous_cycle_records'])->toBe(1);
        });
    });

    describe('cache invalidation', function () {
        it('invalidates entitlement cache after processing', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => now()->startOfMonth(),
            ]);

            // Create and verify boost is counted in limit
            $boost = Boost::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
                'limit_value' => 50,
                'consumed_quantity' => 0,
                'status' => Boost::STATUS_ACTIVE,
                'starts_at' => now()->subDays(15),
            ]);

            Cache::flush();
            $resultBefore = $this->service->can($this->workspace, 'ai.credits');

            expect($resultBefore->limit)->toBe(150); // 100 + 50 boost

            // Run command
            $this->artisan('tenant:reset-billing-cycles', [
                '--workspace' => $this->workspace->id,
            ])->assertExitCode(0);

            // Limit should be back to package only
            $resultAfter = $this->service->can($this->workspace, 'ai.credits');

            expect($resultAfter->limit)->toBe(100);
        });
    });
});
