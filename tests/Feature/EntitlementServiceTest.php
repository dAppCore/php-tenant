<?php

declare(strict_types=1);

use Core\Tenant\Enums\UserTier;
use Core\Tenant\Models\Boost;
use Core\Tenant\Models\EntitlementLog;
use Core\Tenant\Models\Feature;
use Core\Tenant\Models\Namespace_;
use Core\Tenant\Models\NamespacePackage;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\UsageRecord;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Core\Tenant\Services\EntitlementResult;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();

    // Create test workspace
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

    $this->apolloTierFeature = Feature::create([
        'code' => 'tier.apollo',
        'name' => 'Apollo Tier',
        'description' => 'Apollo tier access',
        'category' => 'tier',
        'type' => Feature::TYPE_BOOLEAN,
        'reset_type' => Feature::RESET_NONE,
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

    // Create packages
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

    $this->agencyPackage = Package::create([
        'code' => 'agency',
        'name' => 'Agency',
        'description' => 'For agencies',
        'is_stackable' => false,
        'is_base_package' => true,
        'is_active' => true,
        'is_public' => true,
        'sort_order' => 2,
    ]);

    // Attach features to packages
    $this->creatorPackage->features()->attach($this->aiCreditsFeature->id, ['limit_value' => 100]);
    $this->creatorPackage->features()->attach($this->apolloTierFeature->id, ['limit_value' => null]);
    $this->creatorPackage->features()->attach($this->socialPostsFeature->id, ['limit_value' => 50]);

    $this->agencyPackage->features()->attach($this->aiCreditsFeature->id, ['limit_value' => 500]);
    $this->agencyPackage->features()->attach($this->apolloTierFeature->id, ['limit_value' => null]);
    $this->agencyPackage->features()->attach($this->socialPostsFeature->id, ['limit_value' => 200]);

    $this->service = app(EntitlementService::class);
});

describe('EntitlementService', function () {
    describe('can() method', function () {
        it('denies access when workspace has no packages', function () {
            $result = $this->service->can($this->workspace, 'ai.credits');

            expect($result)->toBeInstanceOf(EntitlementResult::class)
                ->and($result->isAllowed())->toBeFalse()
                ->and($result->isDenied())->toBeTrue()
                ->and($result->reason)->toContain('plan does not include');
        });

        it('allows access when workspace has package with feature', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $result = $this->service->can($this->workspace, 'ai.credits');

            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBe(100)
                ->and($result->used)->toBe(0);
        });

        it('allows boolean features without limits', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $result = $this->service->can($this->workspace, 'tier.apollo');

            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBeNull();
        });

        it('denies access when limit is exceeded', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Record usage up to the limit
            for ($i = 0; $i < 100; $i++) {
                UsageRecord::create([
                    'workspace_id' => $this->workspace->id,
                    'feature_code' => 'ai.credits',
                    'quantity' => 1,
                    'recorded_at' => now(),
                ]);
            }

            Cache::flush();
            $result = $this->service->can($this->workspace, 'ai.credits');

            expect($result->isDenied())->toBeTrue()
                ->and($result->used)->toBe(100)
                ->and($result->limit)->toBe(100)
                ->and($result->reason)->toContain('reached your');
        });

        it('allows access when quantity is within remaining limit', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Use 50 credits
            UsageRecord::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'quantity' => 50,
                'recorded_at' => now(),
            ]);

            Cache::flush();
            $result = $this->service->can($this->workspace, 'ai.credits', quantity: 25);

            expect($result->isAllowed())->toBeTrue()
                ->and($result->remaining)->toBe(50);
        });

        it('denies access when requested quantity exceeds remaining', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Use 90 credits
            UsageRecord::create([
                'workspace_id' => $this->workspace->id,
                'feature_code' => 'ai.credits',
                'quantity' => 90,
                'recorded_at' => now(),
            ]);

            Cache::flush();
            $result = $this->service->can($this->workspace, 'ai.credits', quantity: 20);

            expect($result->isDenied())->toBeTrue()
                ->and($result->used)->toBe(90)
                ->and($result->remaining)->toBe(10);
        });

        it('denies access for non-existent feature', function () {
            $result = $this->service->can($this->workspace, 'non.existent.feature');

            expect($result->isDenied())->toBeTrue()
                ->and($result->reason)->toContain('does not exist');
        });
    });

    describe('recordUsage() method', function () {
        it('creates a usage record', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $record = $this->service->recordUsage(
                $this->workspace,
                'ai.credits',
                quantity: 5,
                user: $this->user
            );

            expect($record)->toBeInstanceOf(UsageRecord::class)
                ->and($record->workspace_id)->toBe($this->workspace->id)
                ->and($record->feature_code)->toBe('ai.credits')
                ->and($record->quantity)->toBe(5)
                ->and($record->user_id)->toBe($this->user->id);
        });

        it('records usage with metadata', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $record = $this->service->recordUsage(
                $this->workspace,
                'ai.credits',
                quantity: 1,
                metadata: ['model' => 'claude-3', 'tokens' => 1500]
            );

            expect($record->metadata)->toBe(['model' => 'claude-3', 'tokens' => 1500]);
        });

        it('invalidates cache after recording usage', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Warm up cache
            $this->service->can($this->workspace, 'ai.credits');

            // Record usage
            $this->service->recordUsage($this->workspace, 'ai.credits', quantity: 10);

            // Check that usage is reflected (cache was invalidated)
            $result = $this->service->can($this->workspace, 'ai.credits');

            expect($result->used)->toBe(10);
        });
    });

    describe('provisionPackage() method', function () {
        it('provisions a package to workspace', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator');

            expect($workspacePackage)->toBeInstanceOf(WorkspacePackage::class)
                ->and($workspacePackage->workspace_id)->toBe($this->workspace->id)
                ->and($workspacePackage->package->code)->toBe('creator')
                ->and($workspacePackage->status)->toBe(WorkspacePackage::STATUS_ACTIVE);
        });

        it('creates an entitlement log entry', function () {
            $this->service->provisionPackage($this->workspace, 'creator', [
                'source' => EntitlementLog::SOURCE_BLESTA,
            ]);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_PROVISIONED)
                ->first();

            expect($log)->not->toBeNull()
                ->and($log->source)->toBe(EntitlementLog::SOURCE_BLESTA);
        });

        it('replaces existing base package when provisioning new base package', function () {
            // Provision creator package
            $creatorWp = $this->service->provisionPackage($this->workspace, 'creator');

            // Provision agency package (should cancel creator)
            $agencyWp = $this->service->provisionPackage($this->workspace, 'agency');

            // Refresh creator package
            $creatorWp->refresh();

            expect($creatorWp->status)->toBe(WorkspacePackage::STATUS_CANCELLED)
                ->and($agencyWp->status)->toBe(WorkspacePackage::STATUS_ACTIVE);
        });

        it('sets billing cycle anchor', function () {
            $anchor = now()->subDays(15);

            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator', [
                'billing_cycle_anchor' => $anchor,
            ]);

            expect($workspacePackage->billing_cycle_anchor->toDateString())
                ->toBe($anchor->toDateString());
        });

        it('stores blesta service id', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator', [
                'blesta_service_id' => 'blesta_12345',
            ]);

            expect($workspacePackage->blesta_service_id)->toBe('blesta_12345');
        });
    });

    describe('provisionBoost() method', function () {
        it('provisions a boost to workspace', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $boost = $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 100,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
            ]);

            expect($boost)->toBeInstanceOf(Boost::class)
                ->and($boost->workspace_id)->toBe($this->workspace->id)
                ->and($boost->feature_code)->toBe('ai.credits')
                ->and($boost->limit_value)->toBe(100)
                ->and($boost->status)->toBe(Boost::STATUS_ACTIVE);
        });

        it('adds boost limit to package limit', function () {
            $this->service->provisionPackage($this->workspace, 'creator'); // 100 credits

            $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 50,
            ]);

            Cache::flush();
            $result = $this->service->can($this->workspace, 'ai.credits');

            expect($result->limit)->toBe(150); // 100 + 50
        });

        it('creates an entitlement log entry for boost', function () {
            $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'limit_value' => 100,
            ]);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_BOOST_PROVISIONED)
                ->first();

            expect($log)->not->toBeNull();
        });
    });

    describe('suspendWorkspace() method', function () {
        it('suspends all active packages', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator');

            $this->service->suspendWorkspace($this->workspace);

            $workspacePackage->refresh();

            expect($workspacePackage->status)->toBe(WorkspacePackage::STATUS_SUSPENDED);
        });

        it('creates suspension log entries', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $this->service->suspendWorkspace($this->workspace, EntitlementLog::SOURCE_BLESTA);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_SUSPENDED)
                ->first();

            expect($log)->not->toBeNull()
                ->and($log->source)->toBe(EntitlementLog::SOURCE_BLESTA);
        });

        it('denies access after suspension', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Can access before suspension
            expect($this->service->can($this->workspace, 'ai.credits')->isAllowed())->toBeTrue();

            $this->service->suspendWorkspace($this->workspace);
            Cache::flush();

            // Cannot access after suspension
            expect($this->service->can($this->workspace, 'ai.credits')->isDenied())->toBeTrue();
        });
    });

    describe('reactivateWorkspace() method', function () {
        it('reactivates suspended packages', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator');
            $this->service->suspendWorkspace($this->workspace);

            $this->service->reactivateWorkspace($this->workspace);

            $workspacePackage->refresh();

            expect($workspacePackage->status)->toBe(WorkspacePackage::STATUS_ACTIVE);
        });

        it('creates reactivation log entries', function () {
            $this->service->provisionPackage($this->workspace, 'creator');
            $this->service->suspendWorkspace($this->workspace);

            $this->service->reactivateWorkspace($this->workspace, EntitlementLog::SOURCE_BLESTA);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_REACTIVATED)
                ->first();

            expect($log)->not->toBeNull()
                ->and($log->source)->toBe(EntitlementLog::SOURCE_BLESTA);
        });

        it('restores access after reactivation', function () {
            $this->service->provisionPackage($this->workspace, 'creator');
            $this->service->suspendWorkspace($this->workspace);

            $this->service->reactivateWorkspace($this->workspace);
            Cache::flush();

            expect($this->service->can($this->workspace, 'ai.credits')->isAllowed())->toBeTrue();
        });
    });

    describe('getUsageSummary() method', function () {
        it('returns usage summary for all features', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $summary = $this->service->getUsageSummary($this->workspace);

            expect($summary)->toBeInstanceOf(Collection::class)
                ->and($summary->has('ai'))->toBeTrue()
                ->and($summary->has('tier'))->toBeTrue()
                ->and($summary->has('social'))->toBeTrue();
        });

        it('includes usage percentages', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Use 50 of 100 credits
            $this->service->recordUsage($this->workspace, 'ai.credits', quantity: 50);

            $summary = $this->service->getUsageSummary($this->workspace);
            $aiFeature = $summary->get('ai')->first();

            expect($aiFeature['used'])->toBe(50)
                ->and($aiFeature['limit'])->toBe(100)
                ->and((int) $aiFeature['percentage'])->toBe(50);
        });
    });

    describe('getActivePackages() method', function () {
        it('returns only active packages', function () {
            $this->service->provisionPackage($this->workspace, 'creator');
            $this->service->suspendWorkspace($this->workspace);

            $activePackages = $this->service->getActivePackages($this->workspace);

            expect($activePackages)->toHaveCount(0);
        });

        it('excludes expired packages', function () {
            $wp = $this->service->provisionPackage($this->workspace, 'creator', [
                'expires_at' => now()->subDay(),
            ]);

            $activePackages = $this->service->getActivePackages($this->workspace);

            expect($activePackages)->toHaveCount(0);
        });
    });

    describe('getActiveBoosts() method', function () {
        it('returns only active boosts', function () {
            $boost = $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'limit_value' => 100,
            ]);

            $activeBoosts = $this->service->getActiveBoosts($this->workspace);

            expect($activeBoosts)->toHaveCount(1);

            // Cancel the boost
            $boost->update(['status' => Boost::STATUS_CANCELLED]);

            $activeBoosts = $this->service->getActiveBoosts($this->workspace);

            expect($activeBoosts)->toHaveCount(0);
        });
    });

    describe('revokePackage() method', function () {
        it('revokes an active package', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator');

            expect($workspacePackage->status)->toBe(WorkspacePackage::STATUS_ACTIVE);

            $this->service->revokePackage($this->workspace, 'creator');

            $workspacePackage->refresh();

            expect($workspacePackage->status)->toBe(WorkspacePackage::STATUS_CANCELLED)
                ->and($workspacePackage->expires_at)->not->toBeNull();
        });

        it('creates a cancellation log entry', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            $this->service->revokePackage($this->workspace, 'creator', EntitlementLog::SOURCE_SYSTEM);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_CANCELLED)
                ->first();

            expect($log)->not->toBeNull()
                ->and($log->source)->toBe(EntitlementLog::SOURCE_SYSTEM);
        });

        it('denies access after package revocation', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Can access before revocation
            expect($this->service->can($this->workspace, 'ai.credits')->isAllowed())->toBeTrue();

            $this->service->revokePackage($this->workspace, 'creator');
            Cache::flush();

            // Cannot access after revocation
            expect($this->service->can($this->workspace, 'ai.credits')->isDenied())->toBeTrue();
        });

        it('does nothing when package does not exist', function () {
            // Should not throw, just return silently
            $this->service->revokePackage($this->workspace, 'nonexistent-package');

            // No log entries should be created
            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_CANCELLED)
                ->first();

            expect($log)->toBeNull();
        });

        it('does nothing when package already cancelled', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'creator');
            $workspacePackage->update(['status' => WorkspacePackage::STATUS_CANCELLED]);

            // Should not throw
            $this->service->revokePackage($this->workspace, 'creator');

            // Only one log entry (from provisioning, not cancellation)
            $logs = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_CANCELLED)
                ->count();

            expect($logs)->toBe(0);
        });

        it('invalidates cache after revocation', function () {
            $this->service->provisionPackage($this->workspace, 'creator');

            // Warm up cache
            $this->service->can($this->workspace, 'ai.credits');

            // Revoke
            $this->service->revokePackage($this->workspace, 'creator');

            // Check that revocation is reflected (cache was invalidated)
            $result = $this->service->can($this->workspace, 'ai.credits');

            expect($result->isDenied())->toBeTrue();
        });
    });

    describe('expireCycleBoundBoosts() method', function () {
        it('expires cycle-bound boosts', function () {
            $boost = $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'limit_value' => 100,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
            ]);

            $this->service->expireCycleBoundBoosts($this->workspace);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_EXPIRED);
        });

        it('does not expire permanent boosts', function () {
            $boost = $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'limit_value' => 100,
                'duration_type' => Boost::DURATION_PERMANENT,
            ]);

            $this->service->expireCycleBoundBoosts($this->workspace);

            $boost->refresh();

            expect($boost->status)->toBe(Boost::STATUS_ACTIVE);
        });

        it('creates expiration log entries', function () {
            $this->service->provisionBoost($this->workspace, 'ai.credits', [
                'limit_value' => 100,
                'duration_type' => Boost::DURATION_CYCLE_BOUND,
            ]);

            $this->service->expireCycleBoundBoosts($this->workspace);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_BOOST_EXPIRED)
                ->first();

            expect($log)->not->toBeNull();
        });
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Namespace-Level Entitlement Tests
// ─────────────────────────────────────────────────────────────────────────────

describe('Namespace Entitlements', function () {
    beforeEach(function () {
        // Clear cache before each test
        Cache::flush();

        // Create test user and workspace
        $this->user = User::factory()->create(['tier' => UserTier::APOLLO]);
        $this->workspace = Workspace::factory()->create();
        $this->workspace->users()->attach($this->user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);

        // Create a user-owned namespace
        $this->userNamespace = Namespace_::create([
            'name' => 'User Namespace',
            'slug' => 'user-ns',
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'is_active' => true,
        ]);

        // Create a workspace-owned namespace
        $this->workspaceNamespace = Namespace_::create([
            'name' => 'Workspace Namespace',
            'slug' => 'workspace-ns',
            'owner_type' => Workspace::class,
            'owner_id' => $this->workspace->id,
            'workspace_id' => $this->workspace->id,
            'is_active' => true,
        ]);

        // Create a namespace with explicit workspace context (user-owned but billed through workspace)
        $this->billedNamespace = Namespace_::create([
            'name' => 'Billed Namespace',
            'slug' => 'billed-ns',
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'is_active' => true,
        ]);

        // Create features
        $this->linksFeature = Feature::create([
            'code' => 'links',
            'name' => 'Links',
            'description' => 'Number of links allowed',
            'category' => 'content',
            'type' => Feature::TYPE_LIMIT,
            'reset_type' => Feature::RESET_NONE,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->customDomainFeature = Feature::create([
            'code' => 'custom_domain',
            'name' => 'Custom Domain',
            'description' => 'Custom domain support',
            'category' => 'features',
            'type' => Feature::TYPE_BOOLEAN,
            'reset_type' => Feature::RESET_NONE,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->pageViewsFeature = Feature::create([
            'code' => 'page_views',
            'name' => 'Page Views',
            'description' => 'Monthly page views',
            'category' => 'analytics',
            'type' => Feature::TYPE_LIMIT,
            'reset_type' => Feature::RESET_MONTHLY,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        // Create packages
        $this->bioFreePackage = Package::create([
            'code' => 'bio-free',
            'name' => 'Bio Free',
            'description' => 'Free bio plan',
            'is_stackable' => false,
            'is_base_package' => true,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 1,
        ]);

        $this->bioProPackage = Package::create([
            'code' => 'bio-pro',
            'name' => 'Bio Pro',
            'description' => 'Professional bio plan',
            'is_stackable' => false,
            'is_base_package' => true,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 2,
        ]);

        // Attach features to packages
        $this->bioFreePackage->features()->attach($this->linksFeature->id, ['limit_value' => 10]);

        $this->bioProPackage->features()->attach($this->linksFeature->id, ['limit_value' => 100]);
        $this->bioProPackage->features()->attach($this->customDomainFeature->id, ['limit_value' => null]);
        $this->bioProPackage->features()->attach($this->pageViewsFeature->id, ['limit_value' => 50000]);

        $this->service = app(EntitlementService::class);
    });

    describe('canForNamespace() method', function () {
        it('denies access when namespace has no packages', function () {
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result)->toBeInstanceOf(EntitlementResult::class)
                ->and($result->isAllowed())->toBeFalse()
                ->and($result->isDenied())->toBeTrue()
                ->and($result->reason)->toContain('plan does not include');
        });

        it('allows access when namespace has package with feature', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBe(100)
                ->and($result->used)->toBe(0);
        });

        it('allows boolean features without limits', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            $result = $this->service->canForNamespace($this->userNamespace, 'custom_domain');

            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBeNull();
        });

        it('denies access when limit is exceeded', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');

            // Record usage up to the limit
            for ($i = 0; $i < 10; $i++) {
                UsageRecord::create([
                    'namespace_id' => $this->userNamespace->id,
                    'feature_code' => 'links',
                    'quantity' => 1,
                    'recorded_at' => now(),
                ]);
            }

            Cache::flush();
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->isDenied())->toBeTrue()
                ->and($result->used)->toBe(10)
                ->and($result->limit)->toBe(10)
                ->and($result->reason)->toContain('reached your');
        });

        it('denies access for non-existent feature', function () {
            $result = $this->service->canForNamespace($this->userNamespace, 'non.existent.feature');

            expect($result->isDenied())->toBeTrue()
                ->and($result->reason)->toContain('does not exist');
        });
    });

    describe('entitlement cascade', function () {
        it('uses namespace package when available', function () {
            // Give workspace more links than namespace
            $this->service->provisionPackage($this->workspace, 'bio-pro');
            $this->service->provisionNamespacePackage($this->workspaceNamespace, 'bio-free');

            $result = $this->service->canForNamespace($this->workspaceNamespace, 'links');

            // Should use namespace's bio-free (10 links), not workspace's bio-pro (100 links)
            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBe(10);
        });

        it('falls back to workspace package when namespace has none', function () {
            // Only workspace has a package
            $this->service->provisionPackage($this->workspace, 'bio-pro');

            $result = $this->service->canForNamespace($this->workspaceNamespace, 'links');

            // Should use workspace's bio-pro (100 links)
            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBe(100);
        });

        it('uses workspace context for billing when explicitly set', function () {
            // Namespace is user-owned but has workspace_id for billing
            $this->service->provisionPackage($this->workspace, 'bio-pro');

            $result = $this->service->canForNamespace($this->billedNamespace, 'links');

            // Should use workspace's bio-pro (100 links) via workspace_id
            expect($result->isAllowed())->toBeTrue()
                ->and($result->limit)->toBe(100);
        });

        it('falls back to user tier for user-owned namespace without workspace', function () {
            // User has Apollo tier which includes 'analytics_basic'
            // Create a feature that Apollo tier grants
            Feature::create([
                'code' => 'analytics_basic',
                'name' => 'Basic Analytics',
                'description' => 'Basic analytics access',
                'category' => 'analytics',
                'type' => Feature::TYPE_BOOLEAN,
                'reset_type' => Feature::RESET_NONE,
                'is_active' => true,
                'sort_order' => 10,
            ]);

            $result = $this->service->canForNamespace($this->userNamespace, 'analytics_basic');

            // Should allow based on user's Apollo tier
            expect($result->isAllowed())->toBeTrue();
        });

        it('denies access when user tier does not include feature', function () {
            // Create a free user
            $freeUser = User::factory()->create(['tier' => UserTier::FREE]);
            $freeNamespace = Namespace_::create([
                'name' => 'Free User Namespace',
                'slug' => 'free-user-ns',
                'owner_type' => User::class,
                'owner_id' => $freeUser->id,
                'is_active' => true,
            ]);

            // Feature that only Hades has
            Feature::create([
                'code' => 'api_access',
                'name' => 'API Access',
                'description' => 'API access feature',
                'category' => 'advanced',
                'type' => Feature::TYPE_BOOLEAN,
                'reset_type' => Feature::RESET_NONE,
                'is_active' => true,
                'sort_order' => 20,
            ]);

            $result = $this->service->canForNamespace($freeNamespace, 'api_access');

            expect($result->isDenied())->toBeTrue();
        });

        it('namespace package overrides workspace package', function () {
            // Give workspace bio-pro (100 links)
            $this->service->provisionPackage($this->workspace, 'bio-pro');

            // Give namespace bio-free (10 links)
            $this->service->provisionNamespacePackage($this->workspaceNamespace, 'bio-free');

            $result = $this->service->canForNamespace($this->workspaceNamespace, 'links');

            // Namespace package takes precedence
            expect($result->limit)->toBe(10);
        });
    });

    describe('recordNamespaceUsage() method', function () {
        it('creates a usage record for namespace', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            $record = $this->service->recordNamespaceUsage(
                $this->userNamespace,
                'links',
                quantity: 5,
                user: $this->user
            );

            expect($record)->toBeInstanceOf(UsageRecord::class)
                ->and($record->namespace_id)->toBe($this->userNamespace->id)
                ->and($record->feature_code)->toBe('links')
                ->and($record->quantity)->toBe(5)
                ->and($record->user_id)->toBe($this->user->id);
        });

        it('records usage with metadata', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            $record = $this->service->recordNamespaceUsage(
                $this->userNamespace,
                'links',
                quantity: 1,
                metadata: ['link_type' => 'social', 'platform' => 'instagram']
            );

            expect($record->metadata)->toBe(['link_type' => 'social', 'platform' => 'instagram']);
        });

        it('includes workspace_id when namespace has workspace context', function () {
            $this->service->provisionPackage($this->workspace, 'bio-pro');

            $record = $this->service->recordNamespaceUsage(
                $this->workspaceNamespace,
                'links',
                quantity: 1
            );

            expect($record->workspace_id)->toBe($this->workspace->id);
        });

        it('invalidates namespace cache after recording usage', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');

            // Warm up cache
            $this->service->canForNamespace($this->userNamespace, 'links');

            // Record usage
            $this->service->recordNamespaceUsage($this->userNamespace, 'links', quantity: 3);

            // Check that usage is reflected (cache was invalidated)
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->used)->toBe(3);
        });
    });

    describe('provisionNamespacePackage() method', function () {
        it('provisions a package to namespace', function () {
            $namespacePackage = $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            expect($namespacePackage)->toBeInstanceOf(NamespacePackage::class)
                ->and($namespacePackage->namespace_id)->toBe($this->userNamespace->id)
                ->and($namespacePackage->package->code)->toBe('bio-pro')
                ->and($namespacePackage->status)->toBe(NamespacePackage::STATUS_ACTIVE);
        });

        it('replaces existing base package when provisioning new base package', function () {
            // Provision bio-free package
            $freePackage = $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');

            // Provision bio-pro package (should cancel bio-free)
            $proPackage = $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            // Refresh the free package
            $freePackage->refresh();

            expect($freePackage->status)->toBe(NamespacePackage::STATUS_CANCELLED)
                ->and($proPackage->status)->toBe(NamespacePackage::STATUS_ACTIVE);
        });

        it('sets billing cycle anchor', function () {
            $anchor = now()->subDays(15);

            $namespacePackage = $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro', [
                'billing_cycle_anchor' => $anchor,
            ]);

            expect($namespacePackage->billing_cycle_anchor->toDateString())
                ->toBe($anchor->toDateString());
        });

        it('stores metadata', function () {
            $namespacePackage = $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro', [
                'metadata' => ['upgraded_from' => 'bio-free', 'reason' => 'trial'],
            ]);

            expect($namespacePackage->metadata)->toBe(['upgraded_from' => 'bio-free', 'reason' => 'trial']);
        });

        it('invalidates namespace cache after provisioning', function () {
            // Check entitlement before (should be denied)
            $resultBefore = $this->service->canForNamespace($this->userNamespace, 'links');
            expect($resultBefore->isDenied())->toBeTrue();

            // Provision package
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            // Check entitlement after (should be allowed)
            $resultAfter = $this->service->canForNamespace($this->userNamespace, 'links');
            expect($resultAfter->isAllowed())->toBeTrue();
        });

        it('allows setting expiry date', function () {
            $expiryDate = now()->addDays(14);

            $namespacePackage = $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro', [
                'expires_at' => $expiryDate,
            ]);

            expect($namespacePackage->expires_at->toDateString())
                ->toBe($expiryDate->toDateString());
        });
    });

    describe('provisionNamespaceBoost() method', function () {
        it('provisions a boost to namespace', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');

            $boost = $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 50,
                'duration_type' => Boost::DURATION_PERMANENT,
            ]);

            expect($boost)->toBeInstanceOf(Boost::class)
                ->and($boost->namespace_id)->toBe($this->userNamespace->id)
                ->and($boost->feature_code)->toBe('links')
                ->and($boost->limit_value)->toBe(50)
                ->and($boost->status)->toBe(Boost::STATUS_ACTIVE);
        });

        it('adds boost limit to namespace package limit', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free'); // 10 links

            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 25,
            ]);

            Cache::flush();
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->limit)->toBe(35); // 10 + 25
        });

        it('supports unlimited boost type', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free'); // 10 links

            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_UNLIMITED,
            ]);

            Cache::flush();
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->isUnlimited())->toBeTrue();
        });

        it('includes workspace_id when namespace has workspace context', function () {
            $boost = $this->service->provisionNamespaceBoost($this->workspaceNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 50,
            ]);

            expect($boost->workspace_id)->toBe($this->workspace->id);
        });

        it('supports expiry date for boosts', function () {
            $expiryDate = now()->addDays(7);

            $boost = $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 50,
                'expires_at' => $expiryDate,
            ]);

            expect($boost->expires_at->toDateString())
                ->toBe($expiryDate->toDateString());
        });

        it('invalidates namespace cache after provisioning boost', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free'); // 10 links

            // Warm up cache
            $initialResult = $this->service->canForNamespace($this->userNamespace, 'links');
            expect($initialResult->limit)->toBe(10);

            // Provision boost
            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 40,
            ]);

            // Cache should be invalidated
            $result = $this->service->canForNamespace($this->userNamespace, 'links');
            expect($result->limit)->toBe(50);
        });
    });

    describe('getNamespaceUsageSummary() method', function () {
        it('returns usage summary for namespace features', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            $summary = $this->service->getNamespaceUsageSummary($this->userNamespace);

            expect($summary)->toBeInstanceOf(Collection::class)
                ->and($summary->has('content'))->toBeTrue()
                ->and($summary->has('features'))->toBeTrue();
        });

        it('includes usage percentages for namespace', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-pro');

            // Use 50 of 100 links
            $this->service->recordNamespaceUsage($this->userNamespace, 'links', quantity: 50);

            $summary = $this->service->getNamespaceUsageSummary($this->userNamespace);
            $linksFeature = $summary->get('content')->first();

            expect($linksFeature['used'])->toBe(50)
                ->and($linksFeature['limit'])->toBe(100)
                ->and((int) $linksFeature['percentage'])->toBe(50);
        });

        it('identifies near-limit features', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free'); // 10 links

            // Use 9 of 10 links (90%)
            $this->service->recordNamespaceUsage($this->userNamespace, 'links', quantity: 9);

            $summary = $this->service->getNamespaceUsageSummary($this->userNamespace);
            $linksFeature = $summary->get('content')->first();

            expect($linksFeature['near_limit'])->toBeTrue();
        });
    });

    describe('invalidateNamespaceCache() method', function () {
        it('clears cached limits for namespace', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');

            // Warm up cache
            $this->service->canForNamespace($this->userNamespace, 'links');

            // Manually check cache key exists
            $cacheKey = "entitlement:ns:{$this->userNamespace->id}:limit:links";
            expect(Cache::has($cacheKey))->toBeTrue();

            // Invalidate cache
            $this->service->invalidateNamespaceCache($this->userNamespace);

            // Cache should be cleared
            expect(Cache::has($cacheKey))->toBeFalse();
        });

        it('clears cached usage for namespace', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');
            $this->service->recordNamespaceUsage($this->userNamespace, 'links', quantity: 5);

            // Warm up cache
            $this->service->canForNamespace($this->userNamespace, 'links');

            // Invalidate cache
            $this->service->invalidateNamespaceCache($this->userNamespace);

            // Usage cache key should be cleared
            $cacheKey = "entitlement:ns:{$this->userNamespace->id}:usage:links";
            expect(Cache::has($cacheKey))->toBeFalse();
        });
    });

    describe('namespace ownership scenarios', function () {
        it('handles user-owned namespace correctly', function () {
            expect($this->userNamespace->isOwnedByUser())->toBeTrue()
                ->and($this->userNamespace->isOwnedByWorkspace())->toBeFalse()
                ->and($this->userNamespace->getOwnerUser()->id)->toBe($this->user->id)
                ->and($this->userNamespace->getOwnerWorkspace())->toBeNull();
        });

        it('handles workspace-owned namespace correctly', function () {
            expect($this->workspaceNamespace->isOwnedByWorkspace())->toBeTrue()
                ->and($this->workspaceNamespace->isOwnedByUser())->toBeFalse()
                ->and($this->workspaceNamespace->getOwnerWorkspace()->id)->toBe($this->workspace->id)
                ->and($this->workspaceNamespace->getOwnerUser())->toBeNull();
        });

        it('handles user-owned namespace with workspace billing context', function () {
            expect($this->billedNamespace->isOwnedByUser())->toBeTrue()
                ->and($this->billedNamespace->workspace_id)->toBe($this->workspace->id)
                ->and($this->billedNamespace->getBillingContext()->id)->toBe($this->workspace->id);
        });
    });

    describe('multiple namespaces with different entitlements', function () {
        it('tracks usage separately per namespace', function () {
            // Provision same package to both namespaces
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');
            $this->service->provisionNamespacePackage($this->workspaceNamespace, 'bio-free');

            // Record different usage for each
            $this->service->recordNamespaceUsage($this->userNamespace, 'links', quantity: 3);
            $this->service->recordNamespaceUsage($this->workspaceNamespace, 'links', quantity: 7);

            Cache::flush();

            $userResult = $this->service->canForNamespace($this->userNamespace, 'links');
            $workspaceResult = $this->service->canForNamespace($this->workspaceNamespace, 'links');

            expect($userResult->used)->toBe(3)
                ->and($userResult->remaining)->toBe(7)
                ->and($workspaceResult->used)->toBe(7)
                ->and($workspaceResult->remaining)->toBe(3);
        });

        it('allows different package levels per namespace', function () {
            // User namespace gets free plan
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free');

            // Workspace namespace gets pro plan
            $this->service->provisionNamespacePackage($this->workspaceNamespace, 'bio-pro');

            $userResult = $this->service->canForNamespace($this->userNamespace, 'links');
            $workspaceResult = $this->service->canForNamespace($this->workspaceNamespace, 'links');

            expect($userResult->limit)->toBe(10)
                ->and($workspaceResult->limit)->toBe(100);
        });
    });

    describe('boost stacking with namespace packages', function () {
        it('stacks multiple boosts on namespace package', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free'); // 10 links

            // Add two boosts
            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 20,
            ]);
            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 15,
            ]);

            Cache::flush();
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->limit)->toBe(45); // 10 + 20 + 15
        });

        it('unlimited boost overrides all limits', function () {
            $this->service->provisionNamespacePackage($this->userNamespace, 'bio-free'); // 10 links

            // Add a regular boost first
            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
                'limit_value' => 20,
            ]);

            // Add unlimited boost
            $this->service->provisionNamespaceBoost($this->userNamespace, 'links', [
                'boost_type' => Boost::BOOST_TYPE_UNLIMITED,
            ]);

            Cache::flush();
            $result = $this->service->canForNamespace($this->userNamespace, 'links');

            expect($result->isUnlimited())->toBeTrue();
        });
    });
});

describe('EntitlementResult', function () {
    it('calculates remaining correctly', function () {
        $result = EntitlementResult::allowed(limit: 100, used: 75, featureCode: 'test');

        expect($result->remaining)->toBe(25);
    });

    it('calculates usage percentage correctly', function () {
        $result = EntitlementResult::allowed(limit: 100, used: 75, featureCode: 'test');

        expect((int) $result->getUsagePercentage())->toBe(75);
    });

    it('identifies near limit correctly', function () {
        $result = EntitlementResult::allowed(limit: 100, used: 85, featureCode: 'test');

        expect($result->isNearLimit())->toBeTrue();

        $result2 = EntitlementResult::allowed(limit: 100, used: 50, featureCode: 'test');

        expect($result2->isNearLimit())->toBeFalse();
    });

    it('identifies unlimited correctly', function () {
        $result = EntitlementResult::unlimited('test');

        expect($result->isUnlimited())->toBeTrue()
            ->and($result->isAllowed())->toBeTrue();
    });
});
