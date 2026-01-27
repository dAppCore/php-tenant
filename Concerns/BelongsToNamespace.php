<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Concerns;

use Core\Mod\Tenant\Models\Namespace_;
use Core\Mod\Tenant\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Trait for models that belong to a namespace.
 *
 * Provides namespace relationship, scoping, and namespace-scoped caching.
 * This replaces dual workspace_id/user_id ownership with a single namespace_id.
 *
 * Usage:
 *   class Page extends Model {
 *       use BelongsToNamespace;
 *   }
 *
 *   // Get cached collection for current namespace
 *   $pages = Page::ownedByCurrentNamespaceCached();
 *
 *   // Get query scoped to current namespace
 *   $pages = Page::ownedByCurrentNamespace()->where('is_active', true)->get();
 */
trait BelongsToNamespace
{
    /**
     * Boot the trait - sets up auto-assignment of namespace_id and cache invalidation.
     */
    protected static function bootBelongsToNamespace(): void
    {
        // Auto-assign namespace_id when creating a model without one
        static::creating(function ($model) {
            if (empty($model->namespace_id)) {
                $namespace = static::getCurrentNamespace();
                if ($namespace) {
                    $model->namespace_id = $namespace->id;
                }
            }
        });

        static::saved(function ($model) {
            if ($model->namespace_id) {
                static::clearNamespaceCache($model->namespace_id);
            }
        });

        static::deleted(function ($model) {
            if ($model->namespace_id) {
                static::clearNamespaceCache($model->namespace_id);
            }
        });
    }

    /**
     * Get the namespace this model belongs to.
     */
    public function namespace(): BelongsTo
    {
        return $this->belongsTo(Namespace_::class, 'namespace_id');
    }

    /**
     * Scope query to the current namespace.
     */
    public function scopeOwnedByCurrentNamespace(Builder $query): Builder
    {
        $namespace = static::getCurrentNamespace();

        if (! $namespace) {
            return $query->whereRaw('1 = 0'); // Return empty result
        }

        return $query->where('namespace_id', $namespace->id);
    }

    /**
     * Scope query to a specific namespace.
     */
    public function scopeForNamespace(Builder $query, Namespace_|int $namespace): Builder
    {
        $namespaceId = $namespace instanceof Namespace_ ? $namespace->id : $namespace;

        return $query->where('namespace_id', $namespaceId);
    }

    /**
     * Scope query to all namespaces accessible by the current user.
     */
    public function scopeAccessibleByCurrentUser(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user || ! $user instanceof User) {
            return $query->whereRaw('1 = 0'); // Return empty result
        }

        $namespaceIds = Namespace_::accessibleBy($user)->pluck('id');

        return $query->whereIn('namespace_id', $namespaceIds);
    }

    /**
     * Get all models owned by the current namespace, cached.
     *
     * @param  int  $ttl  Cache TTL in seconds (default 5 minutes)
     */
    public static function ownedByCurrentNamespaceCached(int $ttl = 300): Collection
    {
        $namespace = static::getCurrentNamespace();

        if (! $namespace) {
            return collect();
        }

        return Cache::remember(
            static::namespaceCacheKey($namespace->id),
            $ttl,
            fn () => static::ownedByCurrentNamespace()->get()
        );
    }

    /**
     * Get all models for a specific namespace, cached.
     *
     * @param  int  $ttl  Cache TTL in seconds (default 5 minutes)
     */
    public static function forNamespaceCached(Namespace_|int $namespace, int $ttl = 300): Collection
    {
        $namespaceId = $namespace instanceof Namespace_ ? $namespace->id : $namespace;

        return Cache::remember(
            static::namespaceCacheKey($namespaceId),
            $ttl,
            fn () => static::forNamespace($namespaceId)->get()
        );
    }

    /**
     * Get the cache key for a namespace's model collection.
     */
    protected static function namespaceCacheKey(int $namespaceId): string
    {
        $modelClass = class_basename(static::class);

        return "namespace.{$namespaceId}.{$modelClass}";
    }

    /**
     * Clear the cache for a namespace's model collection.
     */
    public static function clearNamespaceCache(int $namespaceId): void
    {
        Cache::forget(static::namespaceCacheKey($namespaceId));
    }

    /**
     * Clear cache for all namespaces accessible to current user.
     */
    public static function clearAllNamespaceCache(): void
    {
        $user = auth()->user();

        if ($user && $user instanceof User) {
            $namespaces = Namespace_::accessibleBy($user)->get();
            foreach ($namespaces as $namespace) {
                static::clearNamespaceCache($namespace->id);
            }
        }
    }

    /**
     * Get the current namespace from session/request.
     */
    protected static function getCurrentNamespace(): ?Namespace_
    {
        // Try to get from request attributes (set by middleware)
        if (request()->attributes->has('current_namespace')) {
            return request()->attributes->get('current_namespace');
        }

        // Try to get from session
        $namespaceUuid = session('current_namespace_uuid');
        if ($namespaceUuid) {
            $namespace = Namespace_::where('uuid', $namespaceUuid)->first();
            if ($namespace) {
                return $namespace;
            }
        }

        // Fall back to user's default namespace
        $user = auth()->user();
        if ($user && method_exists($user, 'defaultNamespace')) {
            return $user->defaultNamespace();
        }

        return null;
    }

    /**
     * Check if this model belongs to the given namespace.
     */
    public function belongsToNamespace(Namespace_|int $namespace): bool
    {
        $namespaceId = $namespace instanceof Namespace_ ? $namespace->id : $namespace;

        return $this->namespace_id === $namespaceId;
    }

    /**
     * Check if this model belongs to the current namespace.
     */
    public function belongsToCurrentNamespace(): bool
    {
        $namespace = static::getCurrentNamespace();

        if (! $namespace) {
            return false;
        }

        return $this->belongsToNamespace($namespace);
    }

    /**
     * Check if the current user can access this model.
     */
    public function isAccessibleByCurrentUser(): bool
    {
        $user = auth()->user();

        if (! $user || ! $user instanceof User) {
            return false;
        }

        if (! $this->namespace) {
            return false;
        }

        return $this->namespace->isAccessibleBy($user);
    }
}
