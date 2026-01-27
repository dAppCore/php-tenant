<?php

declare(strict_types=1);

namespace Core\Core\Tenant\Services;

use Core\Core\Tenant\Models\Namespace_;
use Core\Core\Tenant\Models\User;
use Core\Core\Tenant\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Manager for namespace CRUD operations.
 *
 * Handles creating, updating, and managing namespaces with proper
 * validation and default handling.
 */
class NamespaceManager
{
    public function __construct(
        protected NamespaceService $namespaceService
    ) {}

    /**
     * Create a namespace for a user.
     */
    public function createForUser(User $user, array $data): Namespace_
    {
        $namespace = new Namespace_;
        $namespace->fill([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? 'folder',
            'color' => $data['color'] ?? 'zinc',
            'owner_type' => User::class,
            'owner_id' => $user->id,
            'workspace_id' => $data['workspace_id'] ?? null,
            'settings' => $data['settings'] ?? null,
            'is_default' => $data['is_default'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        // If this is marked as default, unset other defaults
        if ($namespace->is_default) {
            Namespace_::ownedByUser($user)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $namespace->save();

        // Invalidate cache
        $this->namespaceService->invalidateUserCache($user);

        return $namespace;
    }

    /**
     * Create a namespace for a workspace.
     */
    public function createForWorkspace(Workspace $workspace, array $data): Namespace_
    {
        $namespace = new Namespace_;
        $namespace->fill([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? 'folder',
            'color' => $data['color'] ?? 'zinc',
            'owner_type' => Workspace::class,
            'owner_id' => $workspace->id,
            'workspace_id' => $workspace->id, // Billing context is the owner workspace
            'settings' => $data['settings'] ?? null,
            'is_default' => $data['is_default'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        // If this is marked as default, unset other defaults
        if ($namespace->is_default) {
            Namespace_::ownedByWorkspace($workspace)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $namespace->save();

        // Invalidate cache for all workspace members
        foreach ($workspace->users as $member) {
            $this->namespaceService->invalidateUserCache($member);
        }

        return $namespace;
    }

    /**
     * Create the default namespace for a user.
     *
     * This is typically called when a user first signs up.
     */
    public function createDefaultForUser(User $user): Namespace_
    {
        return $this->createForUser($user, [
            'name' => 'Personal',
            'slug' => 'personal',
            'description' => 'Your personal workspace',
            'icon' => 'user',
            'color' => 'blue',
            'is_default' => true,
        ]);
    }

    /**
     * Create the default namespace for a workspace.
     *
     * This is typically called when a workspace is created.
     */
    public function createDefaultForWorkspace(Workspace $workspace): Namespace_
    {
        return $this->createForWorkspace($workspace, [
            'name' => $workspace->name,
            'slug' => 'default',
            'description' => "Default namespace for {$workspace->name}",
            'icon' => $workspace->icon ?? 'building',
            'color' => $workspace->color ?? 'zinc',
            'is_default' => true,
        ]);
    }

    /**
     * Update a namespace.
     */
    public function update(Namespace_ $namespace, array $data): Namespace_
    {
        $wasDefault = $namespace->is_default;

        $namespace->fill(array_filter([
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
            'workspace_id' => array_key_exists('workspace_id', $data) ? $data['workspace_id'] : $namespace->workspace_id,
            'settings' => $data['settings'] ?? null,
            'is_default' => $data['is_default'] ?? null,
            'is_active' => $data['is_active'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        // If becoming default, unset other defaults for same owner
        if (! $wasDefault && $namespace->is_default) {
            Namespace_::where('owner_type', $namespace->owner_type)
                ->where('owner_id', $namespace->owner_id)
                ->where('id', '!=', $namespace->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $namespace->save();

        // Invalidate cache
        $this->namespaceService->invalidateCache($namespace->uuid);
        $this->invalidateCacheForOwner($namespace);

        return $namespace;
    }

    /**
     * Delete (soft delete) a namespace.
     */
    public function delete(Namespace_ $namespace): bool
    {
        // Invalidate cache first
        $this->namespaceService->invalidateCache($namespace->uuid);
        $this->invalidateCacheForOwner($namespace);

        // If this was the default, make another one default
        if ($namespace->is_default) {
            $newDefault = Namespace_::where('owner_type', $namespace->owner_type)
                ->where('owner_id', $namespace->owner_id)
                ->where('id', '!=', $namespace->id)
                ->active()
                ->ordered()
                ->first();

            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return $namespace->delete();
    }

    /**
     * Restore a soft-deleted namespace.
     */
    public function restore(Namespace_ $namespace): bool
    {
        $result = $namespace->restore();

        // Invalidate cache
        $this->namespaceService->invalidateCache($namespace->uuid);
        $this->invalidateCacheForOwner($namespace);

        return $result;
    }

    /**
     * Set a namespace as the default for its owner.
     */
    public function setAsDefault(Namespace_ $namespace): Namespace_
    {
        // Unset other defaults
        Namespace_::where('owner_type', $namespace->owner_type)
            ->where('owner_id', $namespace->owner_id)
            ->where('id', '!=', $namespace->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Set this as default
        $namespace->update(['is_default' => true]);

        // Invalidate cache
        $this->invalidateCacheForOwner($namespace);

        return $namespace;
    }

    /**
     * Transfer a namespace to a new owner.
     */
    public function transfer(Namespace_ $namespace, User|Workspace $newOwner): Namespace_
    {
        $oldOwnerType = $namespace->owner_type;
        $oldOwnerId = $namespace->owner_id;

        // Update ownership
        $namespace->update([
            'owner_type' => $newOwner::class,
            'owner_id' => $newOwner->id,
            'is_default' => false, // Can't be default in new context automatically
        ]);

        // Invalidate cache
        $this->namespaceService->invalidateCache($namespace->uuid);

        // Invalidate for old owner
        if ($oldOwnerType === User::class) {
            $this->namespaceService->invalidateUserCache(User::find($oldOwnerId));
        } else {
            $workspace = Workspace::find($oldOwnerId);
            foreach ($workspace->users as $member) {
                $this->namespaceService->invalidateUserCache($member);
            }
        }

        // Invalidate for new owner
        $this->invalidateCacheForOwner($namespace);

        return $namespace;
    }

    /**
     * Invalidate cache for the owner of a namespace.
     */
    protected function invalidateCacheForOwner(Namespace_ $namespace): void
    {
        if ($namespace->isOwnedByUser()) {
            $this->namespaceService->invalidateUserCache($namespace->owner);
        } else {
            foreach ($namespace->owner->users as $member) {
                $this->namespaceService->invalidateUserCache($member);
            }
        }
    }
}
