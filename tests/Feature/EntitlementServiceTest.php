<?php

use Core\Core\Tenant\Models\Boost;
use Core\Core\Tenant\Models\EntitlementLog;
use Core\Core\Tenant\Models\Feature;
use Core\Core\Tenant\Models\Package;
use Core\Core\Tenant\Models\UsageRecord;
use Core\Core\Tenant\Models\User;
use Core\Core\Tenant\Models\Workspace;
use Core\Core\Tenant\Models\WorkspacePackage;
use Core\Core\Tenant\Services\EntitlementResult;
use Core\Core\Tenant\Services\EntitlementService;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

            expect($summary)->toBeInstanceOf(\Illuminate\Support\Collection::class)
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
