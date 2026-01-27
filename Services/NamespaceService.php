<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Core\Mod\Tenant\Models\Namespace_;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Service for namespace context management.
 *
 * Handles resolving the current namespace from session, checking access,
 * and providing namespace collections for users.
 */
class NamespaceService
{
    /**
     * Cache TTL in seconds.
     */
    protected const CACHE_TTL = 300; // 5 minutes

    /**
     * Get the current namespace from session/request.
     */
    public function current(): ?Namespace_
    {
        // Try from request attributes first (set by middleware)
        if (request()->attributes->has('current_namespace')) {
            return request()->attributes->get('current_namespace');
        }

        // Try from session
        $uuid = session('current_namespace_uuid');
        if ($uuid) {
            $namespace = $this->findByUuid($uuid);
            if ($namespace && $this->canAccess($namespace)) {
                return $namespace;
            }
        }

        // Fall back to user's default
        return $this->defaultForCurrentUser();
    }

    /**
     * Get the current namespace UUID from session.
     */
    public function currentUuid(): ?string
    {
        return session('current_namespace_uuid');
    }

    /**
     * Set the current namespace in session.
     */
    public function setCurrent(Namespace_|string $namespace): void
    {
        $uuid = $namespace instanceof Namespace_ ? $namespace->uuid : $namespace;

        session(['current_namespace_uuid' => $uuid]);
    }

    /**
     * Clear the current namespace from session.
     */
    public function clearCurrent(): void
    {
        session()->forget('current_namespace_uuid');
    }

    /**
     * Find a namespace by UUID.
     */
    public function findByUuid(string $uuid): ?Namespace_
    {
        return Cache::remember(
            "namespace:uuid:{$uuid}",
            self::CACHE_TTL,
            fn () => Namespace_::where('uuid', $uuid)->first()
        );
    }

    /**
     * Find a namespace by slug within an owner context.
     */
    public function findBySlug(string $slug, User|Workspace $owner): ?Namespace_
    {
        return Namespace_::where('owner_type', $owner::class)
            ->where('owner_id', $owner->id)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Get the default namespace for the current authenticated user.
     */
    public function defaultForCurrentUser(): ?Namespace_
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return $this->defaultForUser($user);
    }

    /**
     * Get the default namespace for a user.
     *
     * Priority:
     * 1. User's default namespace (is_default = true)
     * 2. First active user-owned namespace
     * 3. First namespace from user's default workspace
     */
    public function defaultForUser(User $user): ?Namespace_
    {
        // Try user's explicit default
        $default = Namespace_::ownedByUser($user)
            ->where('is_default', true)
            ->active()
            ->first();

        if ($default) {
            return $default;
        }

        // Try first user-owned namespace
        $userOwned = Namespace_::ownedByUser($user)
            ->active()
            ->ordered()
            ->first();

        if ($userOwned) {
            return $userOwned;
        }

        // Try namespace from user's default workspace
        $workspace = $user->defaultHostWorkspace();
        if ($workspace) {
            return Namespace_::ownedByWorkspace($workspace)
                ->active()
                ->ordered()
                ->first();
        }

        return null;
    }

    /**
     * Get all namespaces accessible by the current user.
     */
    public function accessibleByCurrentUser(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return $this->accessibleByUser($user);
    }

    /**
     * Get all namespaces accessible by a user.
     */
    public function accessibleByUser(User $user): Collection
    {
        return Cache::remember(
            "user:{$user->id}:accessible_namespaces",
            self::CACHE_TTL,
            fn () => Namespace_::accessibleBy($user)
                ->active()
                ->ordered()
                ->get()
        );
    }

    /**
     * Get all namespaces owned by a user.
     */
    public function ownedByUser(User $user): Collection
    {
        return Namespace_::ownedByUser($user)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Get all namespaces owned by a workspace.
     */
    public function ownedByWorkspace(Workspace $workspace): Collection
    {
        return Namespace_::ownedByWorkspace($workspace)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Check if the current user can access a namespace.
     */
    public function canAccess(Namespace_ $namespace): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $namespace->isAccessibleBy($user);
    }

    /**
     * Group namespaces by owner type for UI display.
     *
     * Returns:
     * [
     *   'personal' => Collection of user-owned namespaces,
     *   'workspaces' => [
     *     ['workspace' => Workspace, 'namespaces' => Collection],
     *     ...
     *   ]
     * ]
     */
    public function groupedForCurrentUser(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ['personal' => collect(), 'workspaces' => []];
        }

        return $this->groupedForUser($user);
    }

    /**
     * Group namespaces by owner type for a user.
     */
    public function groupedForUser(User $user): array
    {
        $personal = Namespace_::ownedByUser($user)
            ->active()
            ->ordered()
            ->get();

        $workspaces = [];
        foreach ($user->workspaces()->active()->get() as $workspace) {
            $namespaces = Namespace_::ownedByWorkspace($workspace)
                ->active()
                ->ordered()
                ->get();

            if ($namespaces->isNotEmpty()) {
                $workspaces[] = [
                    'workspace' => $workspace,
                    'namespaces' => $namespaces,
                ];
            }
        }

        return [
            'personal' => $personal,
            'workspaces' => $workspaces,
        ];
    }

    /**
     * Invalidate namespace cache for a user.
     */
    public function invalidateUserCache(User $user): void
    {
        Cache::forget("user:{$user->id}:accessible_namespaces");
    }

    /**
     * Invalidate namespace cache by UUID.
     */
    public function invalidateCache(string $uuid): void
    {
        Cache::forget("namespace:uuid:{$uuid}");
    }
}
