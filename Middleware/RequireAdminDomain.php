<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminDomain
{
    /**
     * Handle an incoming request.
     *
     * Ensures admin routes are only accessible from admin domains.
     * Service subdomains (social.host.uk.com, etc.) get redirected to their public pages.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAdminDomain = $request->attributes->get('is_admin_domain', true);

        // Allow access on admin domains or local development
        if ($isAdminDomain) {
            return $next($request);
        }

        // On service subdomains, redirect to the public workspace page
        $workspace = $request->attributes->get('workspace', 'main');

        // Redirect to the public page for this workspace
        return redirect()->route('workspace.show', ['workspace' => $workspace]);
    }
}
