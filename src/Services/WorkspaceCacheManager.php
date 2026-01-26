<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Closure;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages workspace-scoped caching with support for both tagged and non-tagged cache stores.
 *
 * This service provides a unified API for workspace-scoped caching, automatically
 * detecting whether the current cache driver supports tags (Redis, Memcached) and
 * falling back to key-prefix-based cache management when tags are not available.
 *
 * Usage:
 *   $manager = app(WorkspaceCacheManager::class);
 *
 *   // Remember a value for a workspace
 *   $data = $manager->remember($workspace, 'key', 300, fn() => expensive_query());
 *
 *   // Clear all cache for a workspace
 *   $manager->flush($workspace);
 *
 *   // Get cache statistics (useful for debugging)
 *   $stats = $manager->stats($workspace);
 */
class WorkspaceCacheManager
{
    /**
     * Track all cache keys used (for non-tagged stores).
     * This allows us to clear cache for a workspace even without tags.
     */
    protected static array $keyRegistry = [];

    /**
     * Configuration cache.
     */
    protected ?array $config = null;

    /**
     * Get the configuration for workspace caching.
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($this->config === null) {
            $this->config = config('core.workspace_cache', [
                'enabled' => true,
                'ttl' => 300,
                'prefix' => 'workspace_cache',
                'use_tags' => true,
            ]);
        }

        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * Check if workspace caching is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config('enabled', true);
    }

    /**
     * Get the cache prefix.
     */
    public function prefix(): string
    {
        return $this->config('prefix', 'workspace_cache');
    }

    /**
     * Get the default TTL.
     */
    public function defaultTtl(): int
    {
        return (int) $this->config('ttl', 300);
    }

    /**
     * Check if the current cache store supports tags.
     */
    public function supportsTags(): bool
    {
        if (! $this->config('use_tags', true)) {
            return false;
        }

        try {
            return Cache::getStore() instanceof TaggableStore;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the workspace tag name.
     */
    public function workspaceTag(Workspace|int $workspace): string
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $this->prefix().":workspace:{$workspaceId}";
    }

    /**
     * Get the model tag name.
     */
    public function modelTag(string $modelClass): string
    {
        $modelName = class_basename($modelClass);

        return $this->prefix().":model:{$modelName}";
    }

    /**
     * Generate a cache key for a workspace-scoped value.
     */
    public function key(Workspace|int $workspace, string $key): string
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return "{$this->prefix()}.{$workspaceId}.{$key}";
    }

    /**
     * Remember a value in the cache for a workspace.
     *
     * @template T
     *
     * @param  Workspace|int  $workspace  The workspace context
     * @param  string  $key  The cache key (will be prefixed automatically)
     * @param  int|null  $ttl  TTL in seconds (null = use default)
     * @param  Closure(): T  $callback  The callback to generate the value
     * @return T
     */
    public function remember(Workspace|int $workspace, string $key, ?int $ttl, Closure $callback): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $fullKey = $this->key($workspace, $key);
        $ttl = $ttl ?? $this->defaultTtl();

        // Register the key for later cleanup
        $this->registerKey($workspace, $fullKey);

        if ($this->supportsTags()) {
            return Cache::tags([$this->workspaceTag($workspace)])
                ->remember($fullKey, $ttl, $callback);
        }

        return Cache::remember($fullKey, $ttl, $callback);
    }

    /**
     * Remember a value forever in the cache for a workspace.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function rememberForever(Workspace|int $workspace, string $key, Closure $callback): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $fullKey = $this->key($workspace, $key);

        // Register the key for later cleanup
        $this->registerKey($workspace, $fullKey);

        if ($this->supportsTags()) {
            return Cache::tags([$this->workspaceTag($workspace)])
                ->rememberForever($fullKey, $callback);
        }

        return Cache::rememberForever($fullKey, $callback);
    }

    /**
     * Store a value in the cache for a workspace.
     */
    public function put(Workspace|int $workspace, string $key, mixed $value, ?int $ttl = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $fullKey = $this->key($workspace, $key);
        $ttl = $ttl ?? $this->defaultTtl();

        // Register the key for later cleanup
        $this->registerKey($workspace, $fullKey);

        if ($this->supportsTags()) {
            return Cache::tags([$this->workspaceTag($workspace)])
                ->put($fullKey, $value, $ttl);
        }

        return Cache::put($fullKey, $value, $ttl);
    }

    /**
     * Get a value from the cache.
     */
    public function get(Workspace|int $workspace, string $key, mixed $default = null): mixed
    {
        if (! $this->isEnabled()) {
            return $default;
        }

        $fullKey = $this->key($workspace, $key);

        if ($this->supportsTags()) {
            return Cache::tags([$this->workspaceTag($workspace)])
                ->get($fullKey, $default);
        }

        return Cache::get($fullKey, $default);
    }

    /**
     * Check if a key exists in the cache.
     */
    public function has(Workspace|int $workspace, string $key): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $fullKey = $this->key($workspace, $key);

        if ($this->supportsTags()) {
            return Cache::tags([$this->workspaceTag($workspace)])
                ->has($fullKey);
        }

        return Cache::has($fullKey);
    }

    /**
     * Remove a specific key from the cache.
     */
    public function forget(Workspace|int $workspace, string $key): bool
    {
        $fullKey = $this->key($workspace, $key);

        // Unregister the key
        $this->unregisterKey($workspace, $fullKey);

        if ($this->supportsTags()) {
            return Cache::tags([$this->workspaceTag($workspace)])
                ->forget($fullKey);
        }

        return Cache::forget($fullKey);
    }

    /**
     * Flush all cache for a specific workspace.
     */
    public function flush(Workspace|int $workspace): bool
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        if ($this->supportsTags()) {
            $result = Cache::tags([$this->workspaceTag($workspace)])->flush();
            $this->clearKeyRegistry($workspaceId);

            return $result;
        }

        // For non-tagged stores, we need to clear each registered key
        return $this->flushRegisteredKeys($workspaceId);
    }

    /**
     * Flush cache for a specific model across all workspaces.
     * Useful when a model's caching logic changes.
     */
    public function flushModel(string $modelClass): bool
    {
        if ($this->supportsTags()) {
            return Cache::tags([$this->modelTag($modelClass)])->flush();
        }

        // For non-tagged stores, we would need to track model-specific keys
        // This is a best-effort operation
        Log::warning("WorkspaceCacheManager: Cannot flush model cache without tags for {$modelClass}");

        return false;
    }

    /**
     * Remember a model collection for a workspace with proper tagging.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function rememberModel(
        Workspace|int $workspace,
        string $modelClass,
        string $key,
        ?int $ttl,
        Closure $callback
    ): mixed {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $fullKey = $this->key($workspace, $key);
        $ttl = $ttl ?? $this->defaultTtl();

        // Register the key for later cleanup
        $this->registerKey($workspace, $fullKey);

        if ($this->supportsTags()) {
            return Cache::tags([
                $this->workspaceTag($workspace),
                $this->modelTag($modelClass),
            ])->remember($fullKey, $ttl, $callback);
        }

        return Cache::remember($fullKey, $ttl, $callback);
    }

    /**
     * Get cache statistics for a workspace.
     *
     * This is useful for debugging and monitoring cache usage.
     */
    public function stats(Workspace|int $workspace): array
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $keys = self::$keyRegistry[$workspaceId] ?? [];

        $stats = [
            'workspace_id' => $workspaceId,
            'enabled' => $this->isEnabled(),
            'supports_tags' => $this->supportsTags(),
            'prefix' => $this->prefix(),
            'default_ttl' => $this->defaultTtl(),
            'registered_keys' => count($keys),
            'keys' => $keys,
        ];

        // If we can, check which keys actually exist in cache
        $existingKeys = 0;
        foreach ($keys as $key) {
            if (Cache::has($key)) {
                $existingKeys++;
            }
        }
        $stats['existing_keys'] = $existingKeys;

        return $stats;
    }

    /**
     * Get all registered keys for a workspace.
     */
    public function getRegisteredKeys(Workspace|int $workspace): array
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return self::$keyRegistry[$workspaceId] ?? [];
    }

    /**
     * Register a cache key for a workspace.
     * This allows us to track all keys for cleanup later.
     */
    protected function registerKey(Workspace|int $workspace, string $key): void
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        if (! isset(self::$keyRegistry[$workspaceId])) {
            self::$keyRegistry[$workspaceId] = [];
        }

        if (! in_array($key, self::$keyRegistry[$workspaceId], true)) {
            self::$keyRegistry[$workspaceId][] = $key;
        }
    }

    /**
     * Unregister a cache key for a workspace.
     */
    protected function unregisterKey(Workspace|int $workspace, string $key): void
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        if (isset(self::$keyRegistry[$workspaceId])) {
            self::$keyRegistry[$workspaceId] = array_filter(
                self::$keyRegistry[$workspaceId],
                fn ($k) => $k !== $key
            );
        }
    }

    /**
     * Clear the key registry for a workspace.
     */
    protected function clearKeyRegistry(int $workspaceId): void
    {
        unset(self::$keyRegistry[$workspaceId]);
    }

    /**
     * Flush all registered keys for a workspace (non-tagged stores).
     */
    protected function flushRegisteredKeys(int $workspaceId): bool
    {
        $keys = self::$keyRegistry[$workspaceId] ?? [];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        $this->clearKeyRegistry($workspaceId);

        return true;
    }

    /**
     * Reset the key registry (useful for testing).
     */
    public static function resetKeyRegistry(): void
    {
        self::$keyRegistry = [];
    }

    /**
     * Override configuration (useful for testing).
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }
}
