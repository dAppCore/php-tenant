<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Tests\Feature;

use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Package;
use Core\Mod\Tenant\Models\UsageAlertHistory;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Notifications\UsageAlertNotification;
use Core\Mod\Tenant\Services\EntitlementService;
use Core\Mod\Tenant\Services\UsageAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UsageAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected UsageAlertService $alertService;

    protected EntitlementService $entitlementService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entitlementService = app(EntitlementService::class);
        $this->alertService = app(UsageAlertService::class);
    }

    public function test_it_sends_warning_alert_at_80_percent(): void
    {
        Notification::fake();

        // Create feature with limit
        $feature = Feature::factory()->create([
            'code' => 'test.feature',
            'name' => 'Test Feature',
            'type' => Feature::TYPE_LIMIT,
        ]);

        // Create package with limit of 10
        $package = Package::factory()->create(['code' => 'test-package', 'is_base_package' => true]);
        $package->features()->attach($feature->id, ['limit_value' => 10]);

        // Create workspace with owner
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        // Provision package
        $this->entitlementService->provisionPackage($workspace, 'test-package');

        // Record 8 uses (80%)
        for ($i = 0; $i < 8; $i++) {
            $this->entitlementService->recordUsage($workspace, 'test.feature', 1);
        }

        // Check for alerts
        $result = $this->alertService->checkWorkspace($workspace);

        // Should send one alert
        $this->assertEquals(1, $result['alerts_sent']);

        // Notification should be sent to owner
        Notification::assertSentTo(
            $user,
            UsageAlertNotification::class,
            fn ($notification) => $notification->threshold === UsageAlertHistory::THRESHOLD_WARNING
        );

        // Alert should be recorded
        $this->assertDatabaseHas('entitlement_usage_alert_history', [
            'workspace_id' => $workspace->id,
            'feature_code' => 'test.feature',
            'threshold' => 80,
        ]);
    }

    public function test_it_does_not_send_duplicate_alerts(): void
    {
        Notification::fake();

        $feature = Feature::factory()->create([
            'code' => 'test.feature',
            'name' => 'Test Feature',
            'type' => Feature::TYPE_LIMIT,
        ]);

        $package = Package::factory()->create(['code' => 'test-package', 'is_base_package' => true]);
        $package->features()->attach($feature->id, ['limit_value' => 10]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $this->entitlementService->provisionPackage($workspace, 'test-package');

        // Record 8 uses (80%)
        for ($i = 0; $i < 8; $i++) {
            $this->entitlementService->recordUsage($workspace, 'test.feature', 1);
        }

        // First check - should send alert
        $result1 = $this->alertService->checkWorkspace($workspace);
        $this->assertEquals(1, $result1['alerts_sent']);

        // Second check - should NOT send duplicate
        $result2 = $this->alertService->checkWorkspace($workspace);
        $this->assertEquals(0, $result2['alerts_sent']);

        // Only one notification should be sent
        Notification::assertSentToTimes($user, UsageAlertNotification::class, 1);
    }

    public function test_it_sends_escalating_alerts_at_different_thresholds(): void
    {
        Notification::fake();

        $feature = Feature::factory()->create([
            'code' => 'test.feature',
            'name' => 'Test Feature',
            'type' => Feature::TYPE_LIMIT,
        ]);

        $package = Package::factory()->create(['code' => 'test-package', 'is_base_package' => true]);
        $package->features()->attach($feature->id, ['limit_value' => 10]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $this->entitlementService->provisionPackage($workspace, 'test-package');

        // Record 8 uses (80%) - warning
        for ($i = 0; $i < 8; $i++) {
            $this->entitlementService->recordUsage($workspace, 'test.feature', 1);
        }
        $this->alertService->checkWorkspace($workspace);

        // Record 1 more (90%) - critical
        $this->entitlementService->recordUsage($workspace, 'test.feature', 1);
        $result = $this->alertService->checkWorkspace($workspace);
        $this->assertEquals(1, $result['alerts_sent']);

        // Record 1 more (100%) - limit reached
        $this->entitlementService->recordUsage($workspace, 'test.feature', 1);
        $result = $this->alertService->checkWorkspace($workspace);
        $this->assertEquals(1, $result['alerts_sent']);

        // Should have 3 notifications total
        Notification::assertSentToTimes($user, UsageAlertNotification::class, 3);
    }

    public function test_it_resolves_alerts_when_usage_drops(): void
    {
        $feature = Feature::factory()->create([
            'code' => 'test.feature',
            'name' => 'Test Feature',
            'type' => Feature::TYPE_LIMIT,
            'reset_type' => Feature::RESET_NONE,
        ]);

        $workspace = Workspace::factory()->create();

        // Create an unresolved alert
        UsageAlertHistory::record(
            workspaceId: $workspace->id,
            featureCode: 'test.feature',
            threshold: 80,
            metadata: ['used' => 8, 'limit' => 10]
        );

        $this->assertDatabaseHas('entitlement_usage_alert_history', [
            'workspace_id' => $workspace->id,
            'feature_code' => 'test.feature',
            'resolved_at' => null,
        ]);

        // Resolve alerts
        $resolved = UsageAlertHistory::resolveAllForFeature($workspace->id, 'test.feature');

        $this->assertEquals(1, $resolved);
        $this->assertDatabaseMissing('entitlement_usage_alert_history', [
            'workspace_id' => $workspace->id,
            'feature_code' => 'test.feature',
            'resolved_at' => null,
        ]);
    }

    public function test_it_skips_unlimited_features(): void
    {
        Notification::fake();

        $feature = Feature::factory()->create([
            'code' => 'unlimited.feature',
            'name' => 'Unlimited Feature',
            'type' => Feature::TYPE_UNLIMITED,
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $result = $this->alertService->checkFeatureUsage($workspace, $feature);

        $this->assertFalse($result['alert_sent']);
        Notification::assertNothingSent();
    }

    public function test_it_skips_boolean_features(): void
    {
        Notification::fake();

        $feature = Feature::factory()->create([
            'code' => 'boolean.feature',
            'name' => 'Boolean Feature',
            'type' => Feature::TYPE_BOOLEAN,
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        // Boolean features should be skipped by the service
        // since they don't have limits to check against
        $result = $this->alertService->checkFeatureUsage($workspace, $feature);

        $this->assertFalse($result['alert_sent']);
        Notification::assertNothingSent();
    }

    public function test_get_active_alerts_returns_unresolved_only(): void
    {
        $workspace = Workspace::factory()->create();

        // Create resolved alert
        $resolved = UsageAlertHistory::record(
            workspaceId: $workspace->id,
            featureCode: 'feature.a',
            threshold: 80
        );
        $resolved->resolve();

        // Create unresolved alert
        UsageAlertHistory::record(
            workspaceId: $workspace->id,
            featureCode: 'feature.b',
            threshold: 90
        );

        $activeAlerts = $this->alertService->getActiveAlertsForWorkspace($workspace);

        $this->assertCount(1, $activeAlerts);
        $this->assertEquals('feature.b', $activeAlerts->first()->feature_code);
    }
}
