<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Workspace Manager.
 *
 * Manages workspace operations including current workspace resolution,
 * workspace creation, user assignment, and validation rules. This is
 * the single source of truth for workspace management across all Host Hub
 * services (SocialHost, BioHost, AnalyticsHost, etc).
 */
class WorkspaceManager
{
    /**
     * Set the current workspace in request context.
     */
    public function setCurrent(Workspace $workspace): void
    {
        request()->attributes->set('workspace_model', $workspace);

        // Also cache it for quick retrieval
        Cache::put("workspace.current.{$workspace->id}", $workspace, now()->addMinutes(5));
    }

    /**
     * Forget the current workspace from request context.
     */
    public function forgetCurrent(): void
    {
        if (request()->attributes->has('workspace_model')) {
            $workspace = request()->attributes->get('workspace_model');
            Cache::forget("workspace.current.{$workspace->id}");
            request()->attributes->remove('workspace_model');
        }
    }

    /**
     * Get the current workspace.
     */
    public function current(): ?Workspace
    {
        return Workspace::current();
    }

    /**
     * Get all workspaces for the authenticated user.
     */
    public function all(): Collection|array
    {
        if (! auth()->check()) {
            return collect([]);
        }

        /** @var User $user */
        $user = auth()->user();

        return $user instanceof User
            ? $user->workspaces
            : collect([]);
    }

    /**
     * Load workspace by ID and set as current.
     */
    public function loadById(int $id): bool
    {
        $workspace = Workspace::find($id);

        if (! $workspace) {
            return false;
        }

        $this->setCurrent($workspace);

        return true;
    }

    /**
     * Load workspace by UUID and set as current.
     */
    public function loadByUuid(string $uuid): bool
    {
        $workspace = Workspace::where('uuid', $uuid)->first();

        if (! $workspace) {
            return false;
        }

        $this->setCurrent($workspace);

        return true;
    }

    /**
     * Load workspace by slug and set as current.
     */
    public function loadBySlug(string $slug): bool
    {
        $workspace = Workspace::where('slug', $slug)->first();

        if (! $workspace) {
            return false;
        }

        $this->setCurrent($workspace);

        return true;
    }

    /**
     * Get unique validation rule for a column scoped to workspace.
     *
     * This ensures uniqueness within a workspace context (e.g., account names,
     * template titles) rather than globally.
     */
    public function uniqueRule(string $table, string $column = 'id', bool $softDelete = false): Rule
    {
        $workspace = $this->current();

        $rule = Rule::unique($table, $column);

        if ($workspace) {
            $rule->where('workspace_id', $workspace->id);
        }

        if ($softDelete) {
            $rule->whereNull('deleted_at');
        }

        return $rule;
    }

    /**
     * Get exists validation rule for a column scoped to workspace.
     */
    public function existsRule(string $table, string $column = 'id', bool $softDelete = false): Rule
    {
        $workspace = $this->current();

        $rule = Rule::exists($table, $column);

        if ($workspace) {
            $rule->where('workspace_id', $workspace->id);
        }

        if ($softDelete) {
            $rule->whereNull('deleted_at');
        }

        return $rule;
    }

    /**
     * Create a new workspace for a user.
     */
    public function create(User $user, array $attributes): Workspace
    {
        $workspace = Workspace::create($attributes);

        // Attach user as owner
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);

        return $workspace;
    }

    /**
     * Add a user to a workspace.
     */
    public function addUser(Workspace $workspace, User $user, string $role = 'member', bool $isDefault = false): void
    {
        $workspace->users()->syncWithoutDetaching([
            $user->id => [
                'role' => $role,
                'is_default' => $isDefault,
            ],
        ]);
    }

    /**
     * Remove a user from a workspace.
     */
    public function removeUser(Workspace $workspace, User $user): void
    {
        $workspace->users()->detach($user->id);
    }

    /**
     * Switch user's default workspace.
     */
    public function setDefault(User $user, Workspace $workspace): void
    {
        // Remove default flag from all workspaces
        $user->workspaces()->updateExistingPivot(
            $user->workspaces()->pluck('workspaces.id')->toArray(),
            ['is_default' => false]
        );

        // Set this one as default
        $user->workspaces()->updateExistingPivot($workspace->id, ['is_default' => true]);
    }

    /**
     * Check if workspace has capacity for new resources.
     */
    public function hasCapacity(Workspace $workspace, string $featureCode, int $quantity = 1): bool
    {
        return $workspace->can($featureCode, $quantity)->isAllowed();
    }
}
