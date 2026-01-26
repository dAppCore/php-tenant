<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Concerns;

use Core\Mod\Tenant\Exceptions\MissingWorkspaceContextException;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Scopes\WorkspaceScope;
use Core\Mod\Tenant\Services\WorkspaceCacheManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Trait for models that belong to a workspace.
 *
 * SECURITY: This trait enforces workspace isolation by:
 * 1. Auto-assigning workspace_id on create (throws if no context)
 * 2. Scoping queries to current workspace
 * 3. Providing workspace-scoped caching with auto-invalidation
 *
 * Usage:
 *   class Account extends Model {
 *       use BelongsToWorkspace;
 *   }
 *
 *   // Get cached collection for current workspace
 *   $accounts = Account::ownedByCurrentWorkspaceCached();
 *
 *   // Get query scoped to current workspace
 *   $accounts = Account::ownedByCurrentWorkspace()->where('status', 'active')->get();
 *
 * To opt out of strict mode (not recommended):
 *   class LegacyModel extends Model {
 *       use BelongsToWorkspace;
 *       protected bool $workspaceContextRequired = false;
 *   }
 *
 * For custom caching beyond the default ownedByCurrentWorkspace, also use HasWorkspaceCache:
 *   class Account extends Model {
 *       use BelongsToWorkspace, HasWorkspaceCache;
 *
 *       public static function getActiveAccounts(): Collection
 *       {
 *           return static::rememberForWorkspace(
 *               'active_accounts',
 *               300,
 *               fn() => static::ownedByCurrentWorkspace()->where('status', 'active')->get()
 *           );
 *       }
 *   }
 */
trait BelongsToWorkspace
{
    /**
     * Boot the trait - sets up auto-assignment of workspace_id and cache invalidation.
     *
     * SECURITY: Throws MissingWorkspaceContextException when creating without workspace context,
     * unless the model has opted out with $workspaceContextRequired = false.
     */
    protected static function bootBelongsToWorkspace(): void
    {
        // Auto-assign workspace_id when creating a model without one
        static::creating(function ($model) {
            if (empty($model->workspace_id)) {
                $workspace = static::getCurrentWorkspace();

                if ($workspace) {
                    $model->workspace_id = $workspace->id;

                    return;
                }

                // No workspace context - check if we should enforce
                if ($model->requiresWorkspaceContext()) {
                    throw MissingWorkspaceContextException::forCreate(
                        class_basename($model)
                    );
                }
            }
        });

        // Clear cache on saved event (create/update)
        static::saved(function ($model) {
            if ($model->workspace_id) {
                static::clearWorkspaceCache($model->workspace_id);
            }
        });

        // Clear cache on deleted event
        static::deleted(function ($model) {
            if ($model->workspace_id) {
                static::clearWorkspaceCache($model->workspace_id);
            }
        });
    }

    /**
     * Determine if this model requires workspace context.
     *
     * Models can opt out by setting $workspaceContextRequired = false,
     * but this is not recommended for security reasons.
     */
    public function requiresWorkspaceContext(): bool
    {
        // Check model-level setting
        if (property_exists($this, 'workspaceContextRequired')) {
            return $this->workspaceContextRequired;
        }

        // Check if global strict mode is disabled
        if (! WorkspaceScope::isStrictModeEnabled()) {
            return false;
        }

        // Check if running from console (CLI commands may need to work without context)
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        // Default: require workspace context for security
        return true;
    }

    /**
     * Get the workspace this model belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Scope query to the current user's default workspace.
     *
     * SECURITY: Throws MissingWorkspaceContextException when no workspace context
     * is available and strict mode is enabled.
     *
     * @throws MissingWorkspaceContextException When workspace context is missing in strict mode
     */
    public function scopeOwnedByCurrentWorkspace(Builder $query): Builder
    {
        $workspace = static::getCurrentWorkspace();

        if ($workspace) {
            return $query->where('workspace_id', $workspace->id);
        }

        // No workspace context - check if we should enforce strict mode
        if ($this->requiresWorkspaceContext()) {
            throw MissingWorkspaceContextException::forScope(
                class_basename($this)
            );
        }

        // Non-strict mode: return empty result set (fail safe)
        return $query->whereRaw('1 = 0');
    }

    /**
     * Scope query to a specific workspace.
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Get all models owned by the current workspace, cached.
     *
     * Uses the WorkspaceCacheManager for caching, which supports both
     * tagged cache stores (Redis, Memcached) and non-tagged stores.
     *
     * SECURITY: Throws MissingWorkspaceContextException when no workspace context
     * is available and strict mode is enabled.
     *
     * @param  int|null  $ttl  Cache TTL in seconds (null = use config default)
     *
     * @throws MissingWorkspaceContextException When workspace context is missing in strict mode
     */
    public static function ownedByCurrentWorkspaceCached(?int $ttl = null): Collection
    {
        $workspace = static::getCurrentWorkspace();

        if ($workspace) {
            return static::getWorkspaceCacheManager()->rememberModel(
                $workspace,
                static::class,
                static::getDefaultCacheKey(),
                $ttl,
                fn () => static::ownedByCurrentWorkspace()->get()
            );
        }

        // No workspace context - check if we should enforce strict mode
        $instance = new static;
        if ($instance->requiresWorkspaceContext()) {
            throw MissingWorkspaceContextException::forScope(
                class_basename(static::class)
            );
        }

        // Non-strict mode: return empty collection (fail safe)
        return collect();
    }

    /**
     * Get all models for a specific workspace, cached.
     *
     * @param  int|null  $ttl  Cache TTL in seconds (null = use config default)
     */
    public static function forWorkspaceCached(Workspace|int $workspace, ?int $ttl = null): Collection
    {
        return static::getWorkspaceCacheManager()->rememberModel(
            $workspace,
            static::class,
            static::getDefaultCacheKey(),
            $ttl,
            fn () => static::forWorkspace($workspace)->get()
        );
    }

    /**
     * Get the cache key for a workspace's model collection.
     *
     * This generates the full cache key including the workspace-scoped prefix.
     */
    public static function workspaceCacheKey(int $workspaceId): string
    {
        return static::getWorkspaceCacheManager()->key(
            $workspaceId,
            static::getDefaultCacheKey()
        );
    }

    /**
     * Get the default cache key suffix for this model.
     *
     * Override this in your model to customise the cache key.
     */
    protected static function getDefaultCacheKey(): string
    {
        return class_basename(static::class).'.all';
    }

    /**
     * Clear the cache for a workspace's model collection.
     *
     * This clears the default cached collection. If using HasWorkspaceCache
     * for custom cached queries, you may need to clear those separately.
     */
    public static function clearWorkspaceCache(int $workspaceId): void
    {
        static::getWorkspaceCacheManager()->forget(
            $workspaceId,
            static::getDefaultCacheKey()
        );
    }

    /**
     * Clear cache for all workspaces this model exists in.
     *
     * For tagged cache stores (Redis), this flushes all cache for this model.
     * For non-tagged stores, this clears cache for workspaces the current user has access to.
     */
    public static function clearAllWorkspaceCaches(): void
    {
        $manager = static::getWorkspaceCacheManager();

        // If tags are supported, we can flush all cache for this model efficiently
        if ($manager->supportsTags()) {
            $manager->flushModel(static::class);

            return;
        }

        // For non-tagged stores, clear for all workspaces the current user has access to
        $user = auth()->user();

        if ($user && method_exists($user, 'hostWorkspaces')) {
            foreach ($user->hostWorkspaces as $workspace) {
                static::clearWorkspaceCache($workspace->id);
            }
        }
    }

    /**
     * Get the current user's default workspace.
     *
     * First checks request attributes (set by middleware), then falls back
     * to the authenticated user's default workspace.
     */
    protected static function getCurrentWorkspace(): ?Workspace
    {
        // First try to get from request attributes (set by middleware)
        if (request()->attributes->has('workspace_model')) {
            return request()->attributes->get('workspace_model');
        }

        // Then try to get from authenticated user
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        // Use the Host UK method if available
        if (method_exists($user, 'defaultHostWorkspace')) {
            return $user->defaultHostWorkspace();
        }

        return null;
    }

    /**
     * Check if this model belongs to the given workspace.
     */
    public function belongsToWorkspace(Workspace|int $workspace): bool
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $this->workspace_id === $workspaceId;
    }

    /**
     * Check if this model belongs to the current user's workspace.
     */
    public function belongsToCurrentWorkspace(): bool
    {
        $workspace = static::getCurrentWorkspace();

        if (! $workspace) {
            return false;
        }

        return $this->belongsToWorkspace($workspace);
    }

    /**
     * Get the workspace cache manager instance.
     */
    protected static function getWorkspaceCacheManager(): WorkspaceCacheManager
    {
        return app(WorkspaceCacheManager::class);
    }
}
