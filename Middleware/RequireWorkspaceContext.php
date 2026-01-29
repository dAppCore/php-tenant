<?php

declare(strict_types=1);

namespace Core\Tenant\Middleware;

use Closure;
use Core\Tenant\Exceptions\MissingWorkspaceContextException;
use Core\Tenant\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that ensures workspace context is established before processing the request.
 *
 * SECURITY: Use this middleware on routes that handle workspace-scoped data to prevent
 * accidental cross-tenant data access. This middleware:
 *
 * 1. Verifies workspace context exists in the request
 * 2. Throws MissingWorkspaceContextException if missing (fails fast)
 * 3. ALWAYS validates the user has access to the workspace (security default)
 *
 * Usage in routes:
 *   Route::middleware(['auth', 'workspace.required'])->group(function () {
 *       Route::resource('accounts', AccountController::class);
 *   });
 *
 *   // To skip validation (NOT RECOMMENDED for production):
 *   Route::middleware(['auth', 'workspace.required:skip_validation'])->group(function () {
 *       Route::get('/public-workspace-info', PublicController::class);
 *   });
 *
 * Register in Kernel.php:
 *   'workspace.required' => \Core\Tenant\Middleware\RequireWorkspaceContext::class,
 */
class RequireWorkspaceContext
{
    /**
     * Handle an incoming request.
     *
     * @param  string|null  $mode  Pass 'skip_validation' to disable access validation (NOT RECOMMENDED)
     *
     * @throws MissingWorkspaceContextException When workspace context is missing or access denied
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        // Get current workspace from various sources
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace) {
            $this->logWorkspaceAccessAttempt($request, null, 'missing_context');
            throw MissingWorkspaceContextException::forMiddleware();
        }

        // Validate workspace_id is a valid integer (prevent injection)
        if (! $this->isValidWorkspaceId($workspace->id)) {
            $this->logWorkspaceAccessAttempt($request, $workspace, 'invalid_workspace_id');
            throw new MissingWorkspaceContextException(
                message: 'Invalid workspace identifier.',
                operation: 'access',
                code: 400
            );
        }

        // SECURITY: Always validate access by default (breaking change from previous behaviour)
        // Pass 'skip_validation' to disable (NOT RECOMMENDED for production use)
        if ($mode !== 'skip_validation' && auth()->check()) {
            $this->validateUserAccess($request, $workspace);
        }

        // Ensure workspace is set in request attributes for downstream use
        if (! $request->attributes->has('workspace_model')) {
            $request->attributes->set('workspace_model', $workspace);
        }

        // Log successful access for security monitoring
        $this->logWorkspaceAccessAttempt($request, $workspace, 'granted');

        return $next($request);
    }

    /**
     * Validate that the workspace ID is a valid positive integer.
     */
    protected function isValidWorkspaceId(mixed $id): bool
    {
        return is_int($id) && $id > 0;
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

        if (! $user) {
            return; // No user to validate against
        }

        // Check if user model has workspaces relationship
        if (method_exists($user, 'workspaces') || method_exists($user, 'hostWorkspaces')) {
            $workspaces = method_exists($user, 'hostWorkspaces')
                ? $user->hostWorkspaces
                : $user->workspaces;

            if (! $workspaces->contains('id', $workspace->id)) {
                $this->logWorkspaceAccessAttempt($request, $workspace, 'denied');

                throw new MissingWorkspaceContextException(
                    message: 'You do not have access to this workspace.',
                    operation: 'access',
                    code: 403
                );
            }
        }
    }

    /**
     * Log workspace access attempts for security monitoring.
     *
     * @param  string  $status  One of: 'granted', 'denied', 'missing_context', 'invalid_workspace_id'
     */
    protected function logWorkspaceAccessAttempt(Request $request, ?Workspace $workspace, string $status): void
    {
        // Only log security-relevant events (failures) in production to avoid log noise
        if ($status === 'granted' && ! config('app.debug', false)) {
            return;
        }

        $context = [
            'status' => $status,
            'workspace_id' => $workspace?->id,
            'workspace_slug' => $workspace?->slug,
            'user_id' => auth()->id(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'source' => $this->determineWorkspaceSource($request),
        ];

        if ($status === 'denied' || $status === 'invalid_workspace_id') {
            Log::warning('Workspace access attempt failed', $context);
        } elseif ($status === 'missing_context') {
            Log::info('Workspace context missing', $context);
        } elseif (config('app.debug', false)) {
            Log::debug('Workspace access granted', $context);
        }
    }

    /**
     * Determine where the workspace_id came from for logging.
     */
    protected function determineWorkspaceSource(Request $request): string
    {
        if ($request->attributes->has('workspace_model')) {
            return 'subdomain';
        }

        if (Workspace::current()) {
            return 'session';
        }

        if ($request->input('workspace_id')) {
            return 'input';
        }

        if ($request->header('X-Workspace-ID')) {
            return 'header';
        }

        if ($request->query('workspace')) {
            return 'query';
        }

        return 'unknown';
    }
}
