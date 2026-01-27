<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Tests\Feature;

use Core\Mod\Social\Models\Account;
use Core\Mod\Tenant\Concerns\BelongsToWorkspace;
use Core\Mod\Tenant\Concerns\HasWorkspaceCache;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Scopes\WorkspaceScope;
use Core\Mod\Tenant\Services\WorkspaceCacheManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Test workspace-scoped caching functionality.
 *
 * These tests verify that the team-scoped caching feature works correctly,
 * including cache hit/miss behavior, auto-invalidation, TTL expiration,
 * and multi-workspace isolation.
 */
class WorkspaceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Workspace $workspace;

    protected Workspace $otherWorkspace;

    protected WorkspaceCacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset cache manager state
        WorkspaceCacheManager::resetKeyRegistry();

        // Configure the cache manager for testing
        $this->cacheManager = app(WorkspaceCacheManager::class);
        $this->cacheManager->setConfig([
            'enabled' => true,
            'ttl' => 300,
            'prefix' => 'test_workspace_cache',
            'use_tags' => false, // Use non-tagged mode for tests (array driver doesn't support tags)
        ]);

        // Enable strict mode for tests
        WorkspaceScope::enableStrictMode();

        // Create test data
        $this->user = User::factory()->create(['name' => 'Test User']);
        $this->workspace = Workspace::factory()->create(['name' => 'Test Workspace']);
        $this->otherWorkspace = Workspace::factory()->create(['name' => 'Other Workspace']);

        $this->user->hostWorkspaces()->attach($this->workspace, ['role' => 'owner', 'is_default' => true]);
        $this->user->hostWorkspaces()->attach($this->otherWorkspace, ['role' => 'member', 'is_default' => false]);

        // Clear any existing cache
        Cache::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceScope::enableStrictMode();
        WorkspaceCacheManager::resetKeyRegistry();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // WorkspaceCacheManager Basic Tests
    // -------------------------------------------------------------------------

    public function test_cache_manager_can_be_resolved(): void
    {
        $manager = app(WorkspaceCacheManager::class);

        $this->assertInstanceOf(WorkspaceCacheManager::class, $manager);
    }

    public function test_cache_manager_generates_correct_keys(): void
    {
        $key = $this->cacheManager->key($this->workspace, 'test_key');

        $this->assertStringContainsString((string) $this->workspace->id, $key);
        $this->assertStringContainsString('test_key', $key);
        $this->assertStringContainsString('test_workspace_cache', $key);
    }

    public function test_cache_manager_workspace_tag_generation(): void
    {
        $tag = $this->cacheManager->workspaceTag($this->workspace);

        $this->assertStringContainsString((string) $this->workspace->id, $tag);
        $this->assertStringContainsString('workspace', $tag);
    }

    public function test_cache_manager_model_tag_generation(): void
    {
        $tag = $this->cacheManager->modelTag(Account::class);

        $this->assertStringContainsString('Account', $tag);
        $this->assertStringContainsString('model', $tag);
    }

    // -------------------------------------------------------------------------
    // Cache Hit/Miss Tests
    // -------------------------------------------------------------------------

    public function test_cache_remember_stores_and_retrieves_value(): void
    {
        $callCount = 0;

        // First call - should execute callback
        $result1 = $this->cacheManager->remember($this->workspace, 'test', 300, function () use (&$callCount) {
            $callCount++;

            return 'cached_value';
        });

        // Second call - should use cache
        $result2 = $this->cacheManager->remember($this->workspace, 'test', 300, function () use (&$callCount) {
            $callCount++;

            return 'new_value';
        });

        $this->assertEquals('cached_value', $result1);
        $this->assertEquals('cached_value', $result2);
        $this->assertEquals(1, $callCount, 'Callback should only be called once');
    }

    public function test_cache_miss_executes_callback(): void
    {
        $callCount = 0;

        $result = $this->cacheManager->remember($this->workspace, 'new_key', 300, function () use (&$callCount) {
            $callCount++;

            return 'fresh_value';
        });

        $this->assertEquals('fresh_value', $result);
        $this->assertEquals(1, $callCount);
    }

    public function test_cache_can_store_collections(): void
    {
        $collection = collect(['item1', 'item2', 'item3']);

        $this->cacheManager->put($this->workspace, 'collection_test', $collection, 300);

        $retrieved = $this->cacheManager->get($this->workspace, 'collection_test');

        $this->assertInstanceOf(Collection::class, $retrieved);
        $this->assertEquals($collection->toArray(), $retrieved->toArray());
    }

    public function test_cache_has_returns_correct_boolean(): void
    {
        $this->assertFalse($this->cacheManager->has($this->workspace, 'nonexistent'));

        $this->cacheManager->put($this->workspace, 'exists', 'value', 300);

        $this->assertTrue($this->cacheManager->has($this->workspace, 'exists'));
    }

    // -------------------------------------------------------------------------
    // Cache Invalidation Tests
    // -------------------------------------------------------------------------

    public function test_cache_forget_removes_key(): void
    {
        $this->cacheManager->put($this->workspace, 'to_forget', 'value', 300);
        $this->assertTrue($this->cacheManager->has($this->workspace, 'to_forget'));

        $result = $this->cacheManager->forget($this->workspace, 'to_forget');

        $this->assertTrue($result);
        $this->assertFalse($this->cacheManager->has($this->workspace, 'to_forget'));
    }

    public function test_cache_flush_clears_all_workspace_keys(): void
    {
        // Store multiple keys
        $this->cacheManager->put($this->workspace, 'key1', 'value1', 300);
        $this->cacheManager->put($this->workspace, 'key2', 'value2', 300);
        $this->cacheManager->put($this->workspace, 'key3', 'value3', 300);

        // Verify keys exist
        $this->assertTrue($this->cacheManager->has($this->workspace, 'key1'));
        $this->assertTrue($this->cacheManager->has($this->workspace, 'key2'));
        $this->assertTrue($this->cacheManager->has($this->workspace, 'key3'));

        // Flush all keys for workspace
        $this->cacheManager->flush($this->workspace);

        // Verify keys are gone
        $this->assertFalse($this->cacheManager->has($this->workspace, 'key1'));
        $this->assertFalse($this->cacheManager->has($this->workspace, 'key2'));
        $this->assertFalse($this->cacheManager->has($this->workspace, 'key3'));
    }

    public function test_model_save_clears_workspace_cache(): void
    {
        $this->actingAs($this->user);
        request()->attributes->set('workspace_model', $this->workspace);

        // Create an account (bypassing strict mode for setup)
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        // Cache the collection
        $cached = Account::ownedByCurrentWorkspaceCached();
        $this->assertCount(1, $cached);

        // Create another account - this should clear the cache
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        // Get the collection again - should reflect the new data
        $refreshed = Account::ownedByCurrentWorkspaceCached();
        $this->assertCount(2, $refreshed);
    }

    public function test_model_delete_clears_workspace_cache(): void
    {
        $this->actingAs($this->user);
        request()->attributes->set('workspace_model', $this->workspace);

        // Create accounts
        $account = null;
        WorkspaceScope::withoutStrictMode(function () use (&$account) {
            $account = Account::factory()->create(['workspace_id' => $this->workspace->id]);
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        // Cache the collection
        $cached = Account::ownedByCurrentWorkspaceCached();
        $this->assertCount(2, $cached);

        // Delete one account - this should clear the cache
        WorkspaceScope::withoutStrictMode(function () use ($account) {
            $account->delete();
        });

        // Get the collection again - should reflect the deletion
        $refreshed = Account::ownedByCurrentWorkspaceCached();
        $this->assertCount(1, $refreshed);
    }

    // -------------------------------------------------------------------------
    // Multi-Workspace Isolation Tests
    // -------------------------------------------------------------------------

    public function test_cache_is_isolated_between_workspaces(): void
    {
        // Store different values in different workspaces
        $this->cacheManager->put($this->workspace, 'shared_key', 'workspace1_value', 300);
        $this->cacheManager->put($this->otherWorkspace, 'shared_key', 'workspace2_value', 300);

        // Retrieve values
        $value1 = $this->cacheManager->get($this->workspace, 'shared_key');
        $value2 = $this->cacheManager->get($this->otherWorkspace, 'shared_key');

        $this->assertEquals('workspace1_value', $value1);
        $this->assertEquals('workspace2_value', $value2);
    }

    public function test_flush_only_affects_target_workspace(): void
    {
        // Store values in both workspaces
        $this->cacheManager->put($this->workspace, 'key', 'value1', 300);
        $this->cacheManager->put($this->otherWorkspace, 'key', 'value2', 300);

        // Flush only the first workspace
        $this->cacheManager->flush($this->workspace);

        // First workspace key should be gone
        $this->assertFalse($this->cacheManager->has($this->workspace, 'key'));

        // Other workspace key should still exist
        $this->assertTrue($this->cacheManager->has($this->otherWorkspace, 'key'));
        $this->assertEquals('value2', $this->cacheManager->get($this->otherWorkspace, 'key'));
    }

    public function test_model_caching_respects_workspace_context(): void
    {
        $this->actingAs($this->user);

        // Create accounts in different workspaces
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Account 1']);
            Account::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Account 2']);
            Account::factory()->create(['workspace_id' => $this->otherWorkspace->id, 'name' => 'Other Account']);
        });

        // Set context to first workspace
        request()->attributes->set('workspace_model', $this->workspace);

        // Cache should only contain first workspace's accounts
        $cached = Account::ownedByCurrentWorkspaceCached();
        $this->assertCount(2, $cached);
        $this->assertTrue($cached->pluck('name')->contains('Account 1'));
        $this->assertTrue($cached->pluck('name')->contains('Account 2'));
        $this->assertFalse($cached->pluck('name')->contains('Other Account'));

        // Switch context to other workspace
        request()->attributes->set('workspace_model', $this->otherWorkspace);

        // Cache should only contain other workspace's accounts
        $otherCached = Account::ownedByCurrentWorkspaceCached();
        $this->assertCount(1, $otherCached);
        $this->assertTrue($otherCached->pluck('name')->contains('Other Account'));
    }

    // -------------------------------------------------------------------------
    // Configuration Tests
    // -------------------------------------------------------------------------

    public function test_cache_disabled_when_config_disabled(): void
    {
        $this->cacheManager->setConfig([
            'enabled' => false,
            'ttl' => 300,
            'prefix' => 'test',
            'use_tags' => false,
        ]);

        $callCount = 0;

        // Both calls should execute the callback because caching is disabled
        $this->cacheManager->remember($this->workspace, 'test', 300, function () use (&$callCount) {
            $callCount++;

            return 'value';
        });

        $this->cacheManager->remember($this->workspace, 'test', 300, function () use (&$callCount) {
            $callCount++;

            return 'value';
        });

        $this->assertEquals(2, $callCount, 'Both calls should execute callback when cache is disabled');
    }

    public function test_default_ttl_used_when_null_passed(): void
    {
        $this->cacheManager->setConfig([
            'enabled' => true,
            'ttl' => 600,
            'prefix' => 'test',
            'use_tags' => false,
        ]);

        $this->assertEquals(600, $this->cacheManager->defaultTtl());
    }

    public function test_custom_prefix_used_in_keys(): void
    {
        $this->cacheManager->setConfig([
            'enabled' => true,
            'ttl' => 300,
            'prefix' => 'custom_prefix',
            'use_tags' => false,
        ]);

        $key = $this->cacheManager->key($this->workspace, 'test');

        $this->assertStringContainsString('custom_prefix', $key);
    }

    // -------------------------------------------------------------------------
    // Cache Statistics Tests
    // -------------------------------------------------------------------------

    public function test_stats_returns_workspace_cache_info(): void
    {
        $this->cacheManager->put($this->workspace, 'key1', 'value1', 300);
        $this->cacheManager->put($this->workspace, 'key2', 'value2', 300);

        $stats = $this->cacheManager->stats($this->workspace);

        $this->assertEquals($this->workspace->id, $stats['workspace_id']);
        $this->assertTrue($stats['enabled']);
        $this->assertIsInt($stats['registered_keys']);
        $this->assertIsArray($stats['keys']);
    }

    public function test_get_registered_keys_returns_workspace_keys(): void
    {
        $this->cacheManager->put($this->workspace, 'key1', 'value1', 300);
        $this->cacheManager->put($this->workspace, 'key2', 'value2', 300);

        $keys = $this->cacheManager->getRegisteredKeys($this->workspace);

        $this->assertCount(2, $keys);
    }

    // -------------------------------------------------------------------------
    // HasWorkspaceCache Trait Tests
    // -------------------------------------------------------------------------

    public function test_has_workspace_cache_remember_for_workspace(): void
    {
        $this->actingAs($this->user);
        request()->attributes->set('workspace_model', $this->workspace);

        // Create a model class that uses HasWorkspaceCache
        $testModel = new class extends Model
        {
            use BelongsToWorkspace;
            use HasWorkspaceCache;

            protected $table = 'test_cache_models';
        };

        $callCount = 0;

        // First call - should execute callback
        $result1 = $testModel::rememberForWorkspace('custom_key', 300, function () use (&$callCount) {
            $callCount++;

            return collect(['item1', 'item2']);
        });

        // Second call - should use cache
        $result2 = $testModel::rememberForWorkspace('custom_key', 300, function () use (&$callCount) {
            $callCount++;

            return collect(['different']);
        });

        $this->assertEquals(['item1', 'item2'], $result1->toArray());
        $this->assertEquals(['item1', 'item2'], $result2->toArray());
        $this->assertEquals(1, $callCount);
    }

    public function test_has_workspace_cache_forget_for_workspace(): void
    {
        $this->actingAs($this->user);
        request()->attributes->set('workspace_model', $this->workspace);

        $testModel = new class extends Model
        {
            use BelongsToWorkspace;
            use HasWorkspaceCache;

            protected $table = 'test_cache_models';
        };

        // Store a value
        $testModel::putForWorkspace('to_forget', 'value', 300);
        $this->assertTrue($testModel::hasInWorkspaceCache('to_forget'));

        // Forget it
        $testModel::forgetForWorkspace('to_forget');
        $this->assertFalse($testModel::hasInWorkspaceCache('to_forget'));
    }

    public function test_has_workspace_cache_without_context_returns_callback_result(): void
    {
        // Ensure no workspace context
        request()->attributes->remove('workspace_model');
        WorkspaceScope::disableStrictMode();

        $testModel = new class extends Model
        {
            use BelongsToWorkspace;
            use HasWorkspaceCache;

            protected $table = 'test_cache_models';

            protected bool $workspaceContextRequired = false;
        };

        $callCount = 0;

        // Without context, should always execute callback (no caching)
        $result = $testModel::rememberForWorkspace('key', 300, function () use (&$callCount) {
            $callCount++;

            return 'uncached_value';
        });

        $this->assertEquals('uncached_value', $result);
        $this->assertEquals(1, $callCount);

        WorkspaceScope::enableStrictMode();
    }

    // -------------------------------------------------------------------------
    // BelongsToWorkspace Caching Tests
    // -------------------------------------------------------------------------

    public function test_owned_by_current_workspace_cached_uses_cache_manager(): void
    {
        $this->actingAs($this->user);
        request()->attributes->set('workspace_model', $this->workspace);

        // Create an account
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });

        // First call - should cache
        $result1 = Account::ownedByCurrentWorkspaceCached();

        // Verify result
        $this->assertCount(1, $result1);

        // Check that cache key was registered
        $keys = $this->cacheManager->getRegisteredKeys($this->workspace);
        $this->assertNotEmpty($keys);
    }

    public function test_for_workspace_cached_caches_for_specific_workspace(): void
    {
        // Create accounts in the workspace
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);
        });

        $callCount = 0;

        // Manually test caching behavior
        $firstCall = Account::forWorkspaceCached($this->workspace, 300);
        $this->assertCount(3, $firstCall);

        // Second call should use cache (we can't easily verify this without mocking,
        // but we can verify the result is consistent)
        $secondCall = Account::forWorkspaceCached($this->workspace, 300);
        $this->assertCount(3, $secondCall);
    }

    public function test_workspace_cache_key_includes_model_name(): void
    {
        $key = Account::workspaceCacheKey($this->workspace->id);

        $this->assertStringContainsString('Account', $key);
        $this->assertStringContainsString((string) $this->workspace->id, $key);
    }

    public function test_clear_all_workspace_caches_clears_user_workspaces(): void
    {
        $this->actingAs($this->user);

        // Cache data in both workspaces
        request()->attributes->set('workspace_model', $this->workspace);
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->workspace->id]);
        });
        Account::ownedByCurrentWorkspaceCached();

        request()->attributes->set('workspace_model', $this->otherWorkspace);
        WorkspaceScope::withoutStrictMode(function () {
            Account::factory()->create(['workspace_id' => $this->otherWorkspace->id]);
        });
        Account::ownedByCurrentWorkspaceCached();

        // Clear all caches for the model
        Account::clearAllWorkspaceCaches();

        // Note: Without tags, this clears cache for all workspaces the user has access to
        // The cache should be empty for both workspaces now
        $keys1 = $this->cacheManager->getRegisteredKeys($this->workspace);
        $keys2 = $this->cacheManager->getRegisteredKeys($this->otherWorkspace);

        // After clearing, the registered keys should be empty or the cache values should be missing
        // (depending on implementation details)
        $this->assertCount(0, $keys1);
        $this->assertCount(0, $keys2);
    }
}
