<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Middleware;

use Closure;
use Core\Mod\Tenant\Exceptions\MissingWorkspaceContextException;
use Core\Mod\Tenant\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that ensures workspace context is established before processing the request.
 *
 * SECURITY: Use this middleware on routes that handle workspace-scoped data to prevent
 * accidental cross-tenant data access. This middleware:
 *
 * 1. Verifies workspace context exists in the request
 * 2. Throws MissingWorkspaceContextException if missing (fails fast)
 * 3. Optionally validates the user has access to the workspace
 *
 * Usage in routes:
 *   Route::middleware(['auth', 'workspace.required'])->group(function () {
 *       Route::resource('accounts', AccountController::class);
 *   });
 *
 * Register in Kernel.php:
 *   'workspace.required' => \Core\Mod\Tenant\Middleware\RequireWorkspaceContext::class,
 */
class RequireWorkspaceContext
{
    /**
     * Handle an incoming request.
     *
     * @throws MissingWorkspaceContextException When workspace context is missing
     */
    public function handle(Request $request, Closure $next, ?string $validateAccess = null): Response
    {
        // Get current workspace from various sources
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            throw MissingWorkspaceContextException::forMiddleware();
        }

        // Optionally validate user has access to the workspace
        if ($validateAccess === 'validate' && auth()->check()) {
            $this->validateUserAccess($request, $workspace);
        }

        // Ensure workspace is set in request attributes for downstream use
        if (! $request->attributes->has('workspace_model')) {
            $request->attributes->set('workspace_model', $workspace);
        }

        return $next($request);
    }

    /**
     * Resolve workspace from request.
     */
    protected function resolveWorkspace(Request $request): ?Workspace
    {
        // 1. Check if workspace_model is already set (by ResolveWorkspaceFromSubdomain)
        if ($request->attributes->has('workspace_model')) {
            return $request->attributes->get('workspace_model');
        }

        // 2. Try Workspace::current() which checks multiple sources
        $current = Workspace::current();
        if ($current) {
            return $current;
        }

        // 3. Check request input for workspace_id (API requests)
        if ($workspaceId = $request->input('workspace_id')) {
            return Workspace::find($workspaceId);
        }

        // 4. Check header for workspace context (API requests)
        if ($workspaceId = $request->header('X-Workspace-ID')) {
            return Workspace::find($workspaceId);
        }

        // 5. Check query parameter for workspace (API/webhook requests)
        if ($workspaceSlug = $request->query('workspace')) {
            return Workspace::where('slug', $workspaceSlug)->first();
        }

        return null;
    }

    /**
     * Validate that the authenticated user has access to the workspace.
     *
     * @throws MissingWorkspaceContextException When user doesn't have access
     */
    protected function validateUserAccess(Request $request, Workspace $workspace): void
    {
        $user = auth()->user();

        // Check if user model has workspaces relationship
        if (method_exists($user, 'workspaces') || method_exists($user, 'hostWorkspaces')) {
            $workspaces = method_exists($user, 'hostWorkspaces')
                ? $user->hostWorkspaces
                : $user->workspaces;

            if (! $workspaces->contains('id', $workspace->id)) {
                throw new MissingWorkspaceContextException(
                    message: 'You do not have access to this workspace.',
                    operation: 'access',
                    code: 403
                );
            }
        }
    }
}
