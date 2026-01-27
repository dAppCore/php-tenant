<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Middleware;

use Closure;
use Core\Mod\Tenant\Services\NamespaceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to resolve the current namespace from session/request.
 *
 * Sets the current namespace in request attributes for use by
 * BelongsToNamespace trait and other components.
 */
class ResolveNamespace
{
    public function __construct(
        protected NamespaceService $namespaceService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try to resolve namespace from query parameter first
        if ($namespaceUuid = $request->query('namespace')) {
            $namespace = $this->namespaceService->findByUuid($namespaceUuid);
            if ($namespace && $this->namespaceService->canAccess($namespace)) {
                // Store in session for subsequent requests
                $this->namespaceService->setCurrent($namespace);
                $request->attributes->set('current_namespace', $namespace);

                return $next($request);
            }
        }

        // Try to resolve namespace from header (for API requests)
        if ($namespaceUuid = $request->header('X-Namespace')) {
            $namespace = $this->namespaceService->findByUuid($namespaceUuid);
            if ($namespace && $this->namespaceService->canAccess($namespace)) {
                $request->attributes->set('current_namespace', $namespace);

                return $next($request);
            }
        }

        // Try to resolve from session
        $namespace = $this->namespaceService->current();
        if ($namespace) {
            $request->attributes->set('current_namespace', $namespace);
        }

        return $next($request);
    }
}
