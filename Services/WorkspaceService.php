<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Support\Facades\Session;

/**
 * Service for managing workspace selection and context.
 *
 * Uses database Workspaces, storing the current selection in session.
 */
class WorkspaceService
{
    /**
     * Get all workspaces for the current user.
     *
     * @return array<string, array>
     */
    public function all(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        return $user->workspaces()
            ->active()
            ->ordered()
            ->get()
            ->keyBy('slug')
            ->map(fn (Workspace $w) => $w->toServiceArray())
            ->toArray();
    }

    /**
     * Get the current workspace slug from session.
     */
    public function currentSlug(): string
    {
        return Session::get('workspace', 'main');
    }

    /**
     * Get the current workspace as array.
     */
    public function current(): array
    {
        $workspace = $this->currentModel();

        return $workspace?->toServiceArray() ?? [
            'name' => 'No Workspace',
            'slug' => 'main',
            'domain' => '',
            'icon' => 'globe',
            'color' => 'zinc',
            'description' => 'Select a workspace',
        ];
    }

    /**
     * Get the current workspace model.
     */
    public function currentModel(): ?Workspace
    {
        $slug = $this->currentSlug();
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        // Try to find in user's workspaces
        $workspace = $user->workspaces()->where('slug', $slug)->first();

        // Fall back to default workspace
        if (! $workspace) {
            $workspace = $user->workspaces()->wherePivot('is_default', true)->first()
                ?? $user->workspaces()->first();

            if ($workspace) {
                Session::put('workspace', $workspace->slug);
            }
        }

        return $workspace;
    }

    /**
     * Set the current workspace by slug.
     */
    public function setCurrent(string $slug): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Verify user has access to this workspace
        $workspace = $user->workspaces()->where('slug', $slug)->first();
        if (! $workspace) {
            return false;
        }

        Session::put('workspace', $slug);

        return true;
    }

    /**
     * Get a specific workspace by slug (as array).
     */
    public function get(string $slug): ?array
    {
        $workspace = Workspace::where('slug', $slug)->first();

        return $workspace?->toServiceArray();
    }

    /**
     * Get a workspace model by slug.
     */
    public function getModel(string $slug): ?Workspace
    {
        return Workspace::where('slug', $slug)->first();
    }

    /**
     * Find workspace by subdomain.
     */
    public function findBySubdomain(string $subdomain): ?array
    {
        // Check for exact slug match first
        $workspace = Workspace::where('slug', $subdomain)->first();
        if ($workspace) {
            return $workspace->toServiceArray();
        }

        // Check domain contains subdomain
        $workspace = Workspace::where('domain', 'LIKE', "{$subdomain}.%")->first();

        return $workspace?->toServiceArray();
    }

    /**
     * Get workspace slug from subdomain.
     */
    public function getSlugFromSubdomain(string $subdomain): ?string
    {
        $workspace = $this->findBySubdomain($subdomain);

        return $workspace['slug'] ?? null;
    }
}
