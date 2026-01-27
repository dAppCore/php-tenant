<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Concerns;

use Closure;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\WorkspaceCacheManager;
use Illuminate\Support\Collection;

/**
 * Trait for models that need custom workspace-scoped caching.
 *
 * While BelongsToWorkspace provides basic caching for the default
 * ownedByCurrentWorkspace query, this trait provides a more flexible API
 * for custom caching needs within a workspace context.
 *
 * Usage:
 *   class Account extends Model {
 *       use BelongsToWorkspace, HasWorkspaceCache;
 *
 *       public static function getActiveAccounts(): Collection
 *       {
 *           return static::rememberForWorkspace(
 *               'active_accounts',
 *               300,
 *               fn() => static::ownedByCurrentWorkspace()
 *                   ->where('status', 'active')
 *                   ->get()
 *           );
 *       }
 *   }
 */
trait HasWorkspaceCache
{
    /**
     * Remember a value for the current workspace.
     *
     * @template T
     *
     * @param  string  $key  The cache key (will be prefixed with workspace context)
     * @param  int|null  $ttl  TTL in seconds (null = use default from config)
     * @param  Closure(): T  $callback  The callback to generate the value
     * @return T
     */
    public static function rememberForWorkspace(string $key, ?int $ttl, Closure $callback): mixed
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            // No workspace context - execute callback directly without caching
            return $callback();
        }

        // Include model name in key to avoid collisions
        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->rememberModel(
            $workspace,
            static::class,
            $modelKey,
            $ttl,
            $callback
        );
    }

    /**
     * Remember a value forever for the current workspace.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function rememberForWorkspaceForever(string $key, Closure $callback): mixed
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            return $callback();
        }

        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->rememberForever(
            $workspace,
            $modelKey,
            $callback
        );
    }

    /**
     * Remember a value for a specific workspace.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function rememberForSpecificWorkspace(
        Workspace|int $workspace,
        string $key,
        ?int $ttl,
        Closure $callback
    ): mixed {
        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->rememberModel(
            $workspace,
            static::class,
            $modelKey,
            $ttl,
            $callback
        );
    }

    /**
     * Store a value in cache for the current workspace.
     */
    public static function putForWorkspace(string $key, mixed $value, ?int $ttl = null): bool
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            return false;
        }

        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->put(
            $workspace,
            $modelKey,
            $value,
            $ttl
        );
    }

    /**
     * Get a cached value for the current workspace.
     */
    public static function getFromWorkspaceCache(string $key, mixed $default = null): mixed
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            return $default;
        }

        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->get(
            $workspace,
            $modelKey,
            $default
        );
    }

    /**
     * Check if a key exists in the workspace cache.
     */
    public static function hasInWorkspaceCache(string $key): bool
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            return false;
        }

        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->has(
            $workspace,
            $modelKey
        );
    }

    /**
     * Forget a specific key from the current workspace cache.
     */
    public static function forgetForWorkspace(string $key): bool
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            return false;
        }

        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->forget(
            $workspace,
            $modelKey
        );
    }

    /**
     * Forget a specific key from a specific workspace cache.
     */
    public static function forgetForSpecificWorkspace(Workspace|int $workspace, string $key): bool
    {
        $modelKey = static::getCacheKeyForModel($key);

        return static::getWorkspaceCacheManager()->forget(
            $workspace,
            $modelKey
        );
    }

    /**
     * Clear all cache for the current workspace's model data.
     */
    public static function clearWorkspaceCacheForModel(): bool
    {
        $workspace = static::getCurrentWorkspaceForCache();

        if (! $workspace) {
            return false;
        }

        // Clear the default workspace cache key
        return static::getWorkspaceCacheManager()->forget(
            $workspace,
            static::getCacheKeyForModel('all')
        );
    }

    /**
     * Clear all cache for this model across all workspaces.
     * Only works with tagged cache stores (Redis, Memcached).
     */
    public static function clearAllWorkspaceCacheForModel(): bool
    {
        return static::getWorkspaceCacheManager()->flushModel(static::class);
    }

    /**
     * Get the cache key prefix for this model.
     */
    protected static function getCacheKeyForModel(string $key): string
    {
        return class_basename(static::class).'.'.$key;
    }

    /**
     * Get the current workspace for caching.
     */
    protected static function getCurrentWorkspaceForCache(): ?Workspace
    {
        // First try to get from request attributes (set by middleware)
        if (request()->attributes->has('workspace_model')) {
            return request()->attributes->get('workspace_model');
        }

        // Then try to get from authenticated user
        $user = auth()->user();

        if ($user && method_exists($user, 'defaultHostWorkspace')) {
            return $user->defaultHostWorkspace();
        }

        return null;
    }

    /**
     * Get the workspace cache manager instance.
     */
    protected static function getWorkspaceCacheManager(): WorkspaceCacheManager
    {
        return app(WorkspaceCacheManager::class);
    }
}
