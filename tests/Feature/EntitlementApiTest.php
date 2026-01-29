<?php

declare(strict_types=1);

use Core\Tenant\Models\EntitlementLog;
use Core\Tenant\Models\Feature;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // Create test user with API token capability
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Create features
    $this->socialAccountsFeature = Feature::create([
        'code' => 'social.accounts',
        'name' => 'Social Accounts',
        'description' => 'Connected social accounts',
        'category' => 'social',
        'type' => Feature::TYPE_LIMIT,
        'reset_type' => Feature::RESET_NONE,
        'is_active' => true,
    ]);

    $this->socialPostsFeature = Feature::create([
        'code' => 'social.posts.scheduled',
        'name' => 'Scheduled Posts',
        'description' => 'Monthly scheduled posts',
        'category' => 'social',
        'type' => Feature::TYPE_LIMIT,
        'reset_type' => Feature::RESET_MONTHLY,
        'is_active' => true,
    ]);

    // Create package
    $this->creatorPackage = Package::create([
        'code' => 'social-creator',
        'name' => 'SocialHost Creator',
        'description' => 'For individual creators',
        'is_stackable' => false,
        'is_base_package' => true,
        'is_active' => true,
    ]);

    $this->creatorPackage->features()->attach($this->socialAccountsFeature->id, ['limit_value' => 5]);
    $this->creatorPackage->features()->attach($this->socialPostsFeature->id, ['limit_value' => 30]);

    $this->service = app(EntitlementService::class);
});

// =============================================================================
// Cross-App Entitlement API Tests (check, usage, summary)
// =============================================================================

describe('Cross-App Entitlement API', function () {
    describe('GET /api/v1/entitlements/check', function () {
        it('requires authentication', function () {
            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts');

            $response->assertStatus(401);
        });

        it('validates required parameters', function () {
            $this->actingAs($this->user);

            // Missing email
            $response = $this->getJson('/api/v1/entitlements/check?feature=social.accounts');
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);

            // Missing feature
            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['feature']);

            // Invalid email format
            $response = $this->getJson('/api/v1/entitlements/check?email=invalid-email&feature=social.accounts');
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

        it('validates quantity parameter', function () {
            $this->actingAs($this->user);

            // Invalid quantity (must be positive)
            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts&quantity=0');
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);

            // Invalid quantity (negative)
            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts&quantity=-1');
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);
        });

        it('returns 404 for non-existent user', function () {
            $this->actingAs($this->user);

            $response = $this->getJson('/api/v1/entitlements/check?email=nonexistent@example.com&feature=social.accounts');

            $response->assertStatus(404)
                ->assertJson([
                    'allowed' => false,
                    'reason' => 'User not found',
                ]);
        });

        it('returns 404 when user has no workspace', function () {
            $this->actingAs($this->user);
            $this->workspace->users()->detach($this->user->id);

            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts');

            $response->assertStatus(404)
                ->assertJson([
                    'allowed' => false,
                    'reason' => 'No workspace found for user',
                ]);
        });

        it('denies when user has no package', function () {
            $this->actingAs($this->user);

            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts');

            $response->assertStatus(200)
                ->assertJson([
                    'allowed' => false,
                    'feature_code' => 'social.accounts',
                ]);
        });

        it('allows when user has package with feature', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts');

            $response->assertStatus(200)
                ->assertJson([
                    'allowed' => true,
                    'limit' => 5,
                    'used' => 0,
                    'remaining' => 5,
                    'unlimited' => false,
                    'feature_code' => 'social.accounts',
                    'workspace_id' => $this->workspace->id,
                ]);
        });

        it('respects quantity parameter', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            // Use 4 of 5 allowed
            $this->service->recordUsage($this->workspace, 'social.accounts', quantity: 4);
            Cache::flush();

            // Request 2 more (exceeds remaining)
            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts&quantity=2');

            $response->assertStatus(200)
                ->assertJson([
                    'allowed' => false,
                    'remaining' => 1,
                ]);
        });

        it('returns usage percentage', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            // Use 2 of 5 allowed (40%)
            $this->service->recordUsage($this->workspace, 'social.accounts', quantity: 2);
            Cache::flush();

            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts');

            $response->assertStatus(200);
            $percentage = $response->json('usage_percentage');
            expect($percentage)->toBe(40.0);
        });
    });

    describe('POST /api/v1/entitlements/usage', function () {
        it('requires authentication', function () {
            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
            ]);

            $response->assertStatus(401);
        });

        it('validates required parameters', function () {
            $this->actingAs($this->user);

            // Missing email
            $response = $this->postJson('/api/v1/entitlements/usage', [
                'feature' => 'social.posts.scheduled',
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);

            // Missing feature
            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['feature']);
        });

        it('validates quantity parameter', function () {
            $this->actingAs($this->user);

            // Invalid quantity (must be positive)
            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
                'quantity' => 0,
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);
        });

        it('validates metadata parameter', function () {
            $this->actingAs($this->user);

            // Invalid metadata (must be array)
            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
                'metadata' => 'not-an-array',
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['metadata']);
        });

        it('returns 404 for non-existent user', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => 'nonexistent@example.com',
                'feature' => 'social.posts.scheduled',
            ]);

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'User not found',
                ]);
        });

        it('returns 404 when user has no workspace', function () {
            $this->actingAs($this->user);
            $this->workspace->users()->detach($this->user->id);

            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
            ]);

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'No workspace found for user',
                ]);
        });

        it('records usage successfully', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
                'quantity' => 3,
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'feature_code' => 'social.posts.scheduled',
                    'quantity' => 3,
                ]);

            // Verify usage was recorded
            Cache::flush();
            $result = $this->service->can($this->workspace, 'social.posts.scheduled');
            expect($result->used)->toBe(3);
        });

        it('records usage with default quantity of 1', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'quantity' => 1,
                ]);
        });

        it('records usage with metadata', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
                'metadata' => ['source' => 'biohost', 'post_id' => 'abc123'],
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                ]);
        });
    });

    describe('GET /api/v1/entitlements/summary', function () {
        it('requires authentication', function () {
            $response = $this->getJson('/api/v1/entitlements/summary');

            $response->assertStatus(401);
        });

        it('returns 404 when user has no workspace', function () {
            $this->actingAs($this->user);
            $this->workspace->users()->detach($this->user->id);

            $response = $this->getJson('/api/v1/entitlements/summary');

            $response->assertStatus(404)
                ->assertJson([
                    'error' => 'No workspace found',
                ]);
        });

        it('returns summary for authenticated user', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->getJson('/api/v1/entitlements/summary');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'workspace_id',
                    'packages',
                    'features' => [
                        'social' => [
                            '*' => ['code', 'name', 'limit', 'used', 'remaining', 'unlimited', 'percentage'],
                        ],
                    ],
                    'boosts',
                ]);
        });

        it('includes package information', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->getJson('/api/v1/entitlements/summary');

            $response->assertStatus(200);

            $packages = $response->json('packages');
            expect($packages)->toHaveCount(1);
            expect($packages[0]['code'])->toBe('social-creator');
        });
    });

    describe('GET /api/v1/entitlements/summary/{workspace}', function () {
        it('requires authentication', function () {
            $response = $this->getJson('/api/v1/entitlements/summary/'.$this->workspace->id);

            $response->assertStatus(401);
        });

        it('returns summary for specified workspace', function () {
            $this->actingAs($this->user);
            $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->getJson('/api/v1/entitlements/summary/'.$this->workspace->id);

            $response->assertStatus(200)
                ->assertJson([
                    'workspace_id' => $this->workspace->id,
                ]);
        });
    });
});

// =============================================================================
// Blesta Provisioning API Tests (store, show, suspend, unsuspend, cancel, renew)
// =============================================================================

describe('Blesta Provisioning API', function () {
    describe('POST /api/provisioning/entitlements (store)', function () {
        it('requires authentication', function () {
            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'test@example.com',
                'name' => 'Test User',
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(401);
        });

        it('validates required parameters', function () {
            $this->actingAs($this->user);

            // Missing all required fields
            $response = $this->postJson('/api/provisioning/entitlements', []);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email', 'name', 'product_code']);
        });

        it('validates email format', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'invalid-email',
                'name' => 'Test User',
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
        });

        it('validates name max length', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'test@example.com',
                'name' => str_repeat('a', 256),
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['name']);
        });

        it('validates date format for billing_cycle_anchor', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'test@example.com',
                'name' => 'Test User',
                'product_code' => 'social-creator',
                'billing_cycle_anchor' => 'not-a-date',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['billing_cycle_anchor']);
        });

        it('validates date format for expires_at', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'test@example.com',
                'name' => 'Test User',
                'product_code' => 'social-creator',
                'expires_at' => 'invalid-date',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['expires_at']);
        });

        it('returns 404 for non-existent package', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'test@example.com',
                'name' => 'Test User',
                'product_code' => 'nonexistent-package',
            ]);

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => "Package 'nonexistent-package' not found",
                ]);
        });

        it('creates new user when user does not exist', function () {
            Event::fake([Registered::class]);
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'newuser@example.com',
                'name' => 'New User',
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'package' => 'social-creator',
                    'status' => WorkspacePackage::STATUS_ACTIVE,
                ]);

            // Verify user was created
            $newUser = User::where('email', 'newuser@example.com')->first();
            expect($newUser)->not->toBeNull();
            expect($newUser->name)->toBe('New User');

            // Verify Registered event was fired
            Event::assertDispatched(Registered::class, function ($event) use ($newUser) {
                return $event->user->id === $newUser->id;
            });
        });

        it('creates workspace for new user', function () {
            Event::fake([Registered::class]);
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => 'newuser@example.com',
                'name' => 'New User',
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(201);

            $newUser = User::where('email', 'newuser@example.com')->first();
            $workspace = $newUser->ownedWorkspaces()->first();

            expect($workspace)->not->toBeNull();
            expect($workspace->name)->toContain('New User');
        });

        it('uses existing user when user already exists', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => $this->user->email,
                'name' => 'Different Name', // Should be ignored for existing user
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(201)
                ->assertJson([
                    'success' => true,
                    'workspace_id' => $this->workspace->id,
                ]);
        });

        it('provisions package with optional parameters', function () {
            $this->actingAs($this->user);
            $billingAnchor = now()->subDays(10)->toIso8601String();
            $expiresAt = now()->addMonth()->toIso8601String();

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => $this->user->email,
                'name' => $this->user->name,
                'product_code' => 'social-creator',
                'billing_cycle_anchor' => $billingAnchor,
                'expires_at' => $expiresAt,
                'blesta_service_id' => 'blesta_12345',
            ]);

            $response->assertStatus(201);

            $entitlementId = $response->json('entitlement_id');
            $workspacePackage = WorkspacePackage::find($entitlementId);

            expect($workspacePackage->blesta_service_id)->toBe('blesta_12345');
            expect($workspacePackage->billing_cycle_anchor)->not->toBeNull();
            expect($workspacePackage->expires_at)->not->toBeNull();
        });

        it('creates entitlement log entry', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements', [
                'email' => $this->user->email,
                'name' => $this->user->name,
                'product_code' => 'social-creator',
            ]);

            $response->assertStatus(201);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_PROVISIONED)
                ->where('source', EntitlementLog::SOURCE_BLESTA)
                ->first();

            expect($log)->not->toBeNull();
        });
    });

    describe('GET /api/provisioning/entitlements/{id} (show)', function () {
        it('requires authentication', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->getJson('/api/provisioning/entitlements/'.$workspacePackage->id);

            $response->assertStatus(401);
        });

        it('returns 404 for non-existent entitlement', function () {
            $this->actingAs($this->user);

            $response = $this->getJson('/api/provisioning/entitlements/99999');

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'Entitlement not found',
                ]);
        });

        it('returns entitlement details', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator', [
                'blesta_service_id' => 'blesta_service_123',
            ]);

            $response = $this->getJson('/api/provisioning/entitlements/'.$workspacePackage->id);

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'entitlement' => [
                        'id' => $workspacePackage->id,
                        'workspace_id' => $this->workspace->id,
                        'workspace_slug' => $this->workspace->slug,
                        'package_code' => 'social-creator',
                        'package_name' => 'SocialHost Creator',
                        'status' => WorkspacePackage::STATUS_ACTIVE,
                        'blesta_service_id' => 'blesta_service_123',
                    ],
                ]);
        });

        it('includes ISO8601 formatted dates', function () {
            $this->actingAs($this->user);
            $expiresAt = now()->addMonth();
            $billingAnchor = now()->subDays(5);

            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator', [
                'expires_at' => $expiresAt,
                'billing_cycle_anchor' => $billingAnchor,
            ]);

            $response = $this->getJson('/api/provisioning/entitlements/'.$workspacePackage->id);

            $response->assertStatus(200);

            $entitlement = $response->json('entitlement');
            expect($entitlement['expires_at'])->not->toBeNull();
            expect($entitlement['billing_cycle_anchor'])->not->toBeNull();
        });
    });

    describe('POST /api/provisioning/entitlements/{id}/suspend', function () {
        it('requires authentication', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/suspend');

            $response->assertStatus(401);
        });

        it('returns 404 for non-existent entitlement', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements/99999/suspend');

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'Entitlement not found',
                ]);
        });

        it('suspends an active entitlement', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/suspend');

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'entitlement_id' => $workspacePackage->id,
                    'status' => WorkspacePackage::STATUS_SUSPENDED,
                ]);
        });

        it('accepts optional reason parameter', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/suspend', [
                'reason' => 'Payment failed',
            ]);

            $response->assertStatus(200);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_SUSPENDED)
                ->first();

            expect($log->metadata['reason'])->toBe('Payment failed');
        });

        it('creates entitlement log entry', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/suspend');

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_SUSPENDED)
                ->where('source', EntitlementLog::SOURCE_BLESTA)
                ->first();

            expect($log)->not->toBeNull();
        });

        it('denies access after suspension', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            // Can access before suspension
            $result = $this->service->can($this->workspace, 'social.accounts');
            expect($result->isAllowed())->toBeTrue();

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/suspend');
            Cache::flush();

            // Cannot access after suspension
            $result = $this->service->can($this->workspace, 'social.accounts');
            expect($result->isDenied())->toBeTrue();
        });
    });

    describe('POST /api/provisioning/entitlements/{id}/unsuspend', function () {
        it('requires authentication', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $workspacePackage->suspend();

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/unsuspend');

            $response->assertStatus(401);
        });

        it('returns 404 for non-existent entitlement', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements/99999/unsuspend');

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'Entitlement not found',
                ]);
        });

        it('reactivates a suspended entitlement', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $workspacePackage->suspend();

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/unsuspend');

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'entitlement_id' => $workspacePackage->id,
                    'status' => WorkspacePackage::STATUS_ACTIVE,
                ]);
        });

        it('creates entitlement log entry', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $workspacePackage->suspend();

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/unsuspend');

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_REACTIVATED)
                ->where('source', EntitlementLog::SOURCE_BLESTA)
                ->first();

            expect($log)->not->toBeNull();
        });

        it('restores access after unsuspension', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $workspacePackage->suspend();
            Cache::flush();

            // Cannot access while suspended
            $result = $this->service->can($this->workspace, 'social.accounts');
            expect($result->isDenied())->toBeTrue();

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/unsuspend');
            Cache::flush();

            // Can access after unsuspension
            $result = $this->service->can($this->workspace, 'social.accounts');
            expect($result->isAllowed())->toBeTrue();
        });
    });

    describe('POST /api/provisioning/entitlements/{id}/cancel', function () {
        it('requires authentication', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/cancel');

            $response->assertStatus(401);
        });

        it('returns 404 for non-existent entitlement', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements/99999/cancel');

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'Entitlement not found',
                ]);
        });

        it('cancels an active entitlement', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/cancel');

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'entitlement_id' => $workspacePackage->id,
                    'status' => WorkspacePackage::STATUS_CANCELLED,
                ]);
        });

        it('accepts optional reason parameter', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/cancel', [
                'reason' => 'Customer requested cancellation',
            ]);

            $response->assertStatus(200);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_CANCELLED)
                ->first();

            expect($log->metadata['reason'])->toBe('Customer requested cancellation');
        });

        it('creates entitlement log entry', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/cancel');

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_CANCELLED)
                ->where('source', EntitlementLog::SOURCE_BLESTA)
                ->first();

            expect($log)->not->toBeNull();
        });

        it('denies access after cancellation', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            // Can access before cancellation
            $result = $this->service->can($this->workspace, 'social.accounts');
            expect($result->isAllowed())->toBeTrue();

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/cancel');
            Cache::flush();

            // Cannot access after cancellation
            $result = $this->service->can($this->workspace, 'social.accounts');
            expect($result->isDenied())->toBeTrue();
        });
    });

    describe('POST /api/provisioning/entitlements/{id}/renew', function () {
        it('requires authentication', function () {
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew');

            $response->assertStatus(401);
        });

        it('returns 404 for non-existent entitlement', function () {
            $this->actingAs($this->user);

            $response = $this->postJson('/api/provisioning/entitlements/99999/renew');

            $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'error' => 'Entitlement not found',
                ]);
        });

        it('validates date format for expires_at', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew', [
                'expires_at' => 'invalid-date',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['expires_at']);
        });

        it('validates date format for billing_cycle_anchor', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew', [
                'billing_cycle_anchor' => 'not-a-date',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['billing_cycle_anchor']);
        });

        it('renews an entitlement without parameters', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew');

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'entitlement_id' => $workspacePackage->id,
                    'status' => WorkspacePackage::STATUS_ACTIVE,
                ]);
        });

        it('updates expires_at when provided', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $newExpiry = now()->addMonth();

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew', [
                'expires_at' => $newExpiry->toIso8601String(),
            ]);

            $response->assertStatus(200);

            $workspacePackage->refresh();
            expect($workspacePackage->expires_at->toDateString())->toBe($newExpiry->toDateString());
        });

        it('updates billing_cycle_anchor when provided', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $newAnchor = now();

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew', [
                'billing_cycle_anchor' => $newAnchor->toIso8601String(),
            ]);

            $response->assertStatus(200);

            $workspacePackage->refresh();
            expect($workspacePackage->billing_cycle_anchor->toDateString())->toBe($newAnchor->toDateString());
        });

        it('creates entitlement log entry', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');

            $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew', [
                'expires_at' => now()->addMonth()->toIso8601String(),
            ]);

            $log = EntitlementLog::where('workspace_id', $this->workspace->id)
                ->where('action', EntitlementLog::ACTION_PACKAGE_RENEWED)
                ->where('source', EntitlementLog::SOURCE_BLESTA)
                ->first();

            expect($log)->not->toBeNull();
        });

        it('returns ISO8601 formatted expires_at in response', function () {
            $this->actingAs($this->user);
            $workspacePackage = $this->service->provisionPackage($this->workspace, 'social-creator');
            $newExpiry = now()->addMonth();

            $response = $this->postJson('/api/provisioning/entitlements/'.$workspacePackage->id.'/renew', [
                'expires_at' => $newExpiry->toIso8601String(),
            ]);

            $response->assertStatus(200);
            expect($response->json('expires_at'))->not->toBeNull();
        });
    });
});

// =============================================================================
// Error Response Format Tests
// =============================================================================

describe('Error Response Format', function () {
    it('returns consistent error format for validation failures', function () {
        $this->actingAs($this->user);

        $response = $this->postJson('/api/provisioning/entitlements', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => ['email', 'name', 'product_code'],
            ]);
    });

    it('returns consistent error format for not found', function () {
        $this->actingAs($this->user);

        $response = $this->getJson('/api/provisioning/entitlements/99999');

        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'error',
            ]);
    });

    it('returns consistent error format for unauthenticated', function () {
        $response = $this->getJson('/api/provisioning/entitlements/1');

        $response->assertStatus(401);
    });
});

// =============================================================================
// Rate Limiting Tests
// =============================================================================

describe('Rate Limiting', function () {
    it('controller has rate limit attribute', function () {
        $reflection = new \ReflectionClass(\Core\Tenant\Controllers\EntitlementApiController::class);
        $attributes = $reflection->getAttributes(\Core\Api\RateLimit\RateLimit::class);

        expect($attributes)->toHaveCount(1);

        $rateLimit = $attributes[0]->newInstance();
        expect($rateLimit->limit)->toBe(60);
        expect($rateLimit->window)->toBe(60);
        expect($rateLimit->key)->toBe('entitlement-api');
    });
});
