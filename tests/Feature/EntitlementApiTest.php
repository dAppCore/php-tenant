<?php

use Core\Tenant\Models\Feature;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Support\Facades\Cache;

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

describe('Entitlement API', function () {
    describe('GET /api/v1/entitlements/check', function () {
        it('requires authentication', function () {
            $response = $this->getJson('/api/v1/entitlements/check?email='.$this->user->email.'&feature=social.accounts');

            $response->assertStatus(401);
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
    });

    describe('POST /api/v1/entitlements/usage', function () {
        it('requires authentication', function () {
            $response = $this->postJson('/api/v1/entitlements/usage', [
                'email' => $this->user->email,
                'feature' => 'social.posts.scheduled',
            ]);

            $response->assertStatus(401);
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
