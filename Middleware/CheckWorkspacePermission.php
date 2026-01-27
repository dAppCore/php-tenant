<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Middleware;

use Closure;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\WorkspaceTeamService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if the current user has a specific workspace permission.
 *
 * Usage in routes:
 *   Route::middleware('workspace.permission:bio.write')
 *   Route::middleware('workspace.permission:workspace.manage_settings,workspace.manage_members')
 *
 * The middleware checks if the user has ANY of the specified permissions (OR logic).
 * Use multiple middleware definitions for AND logic.
 */
class CheckWorkspacePermission
{
    public function __construct(
        protected WorkspaceTeamService $teamService
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, __('tenant::tenant.errors.unauthenticated'));
        }

        // Get current workspace from request or user's default
        $workspace = $this->getWorkspace($request);

        if (! $workspace) {
            abort(403, __('tenant::tenant.errors.no_workspace'));
        }

        // Set up the team service with the workspace context
        $this->teamService->forWorkspace($workspace);

        // Check if user has any of the required permissions
        if (! $this->teamService->hasAnyPermission($user, $permissions)) {
            abort(403, __('tenant::tenant.errors.insufficient_permissions'));
        }

        // Store the workspace and member in request for later use
        $request->attributes->set('workspace_model', $workspace);

        $member = $this->teamService->getMember($user);
        if ($member) {
            $request->attributes->set('workspace_member', $member);
        }

        return $next($request);
    }

    protected function getWorkspace(Request $request): ?Workspace
    {
        // First try to get from request attributes (already resolved by other middleware)
        if ($request->attributes->has('workspace_model')) {
            return $request->attributes->get('workspace_model');
        }

        // Try to get from route parameter
        $workspaceParam = $request->route('workspace');
        if ($workspaceParam instanceof Workspace) {
            return $workspaceParam;
        }

        if (is_string($workspaceParam) || is_int($workspaceParam)) {
            return Workspace::where('slug', $workspaceParam)
                ->orWhere('id', $workspaceParam)
                ->first();
        }

        // Try to get from session
        $sessionSlug = session('workspace');
        if ($sessionSlug) {
            return Workspace::where('slug', $sessionSlug)->first();
        }

        // Fall back to user's default workspace
        $user = $request->user();
        if ($user && method_exists($user, 'defaultHostWorkspace')) {
            return $user->defaultHostWorkspace();
        }

        return null;
    }
}
