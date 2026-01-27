<?php

declare(strict_types=1);

namespace Core\Core\Tenant\Middleware;

use Closure;
use Core\Core\Tenant\Models\Workspace;
use Core\Core\Tenant\Services\WorkspaceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspaceFromSubdomain
{
    /**
     * Subdomains that serve the admin panel (main domain aliases).
     */
    protected array $adminSubdomains = ['hub', 'www', 'hestia', 'main', ''];

    public function __construct(
        protected WorkspaceService $workspaceService
    ) {}

    /**
     * Handle an incoming request.
     *
     * Resolves workspace from subdomain: {workspace}.host.uk.com
     * - Admin subdomains (hub, www, hestia) → full admin panel access
     * - Service subdomains (social, push, etc.) → public workspace pages only
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $subdomain = $this->extractSubdomain($host);
        $workspace = $this->resolveWorkspaceFromSubdomain($subdomain);

        // Store subdomain info in request
        $request->attributes->set('subdomain', $subdomain);
        $request->attributes->set('is_admin_domain', $this->isAdminDomain($subdomain));

        if ($workspace) {
            // Wrap session operations in try-catch to handle corrupted sessions
            try {
                $this->workspaceService->setCurrent($workspace);
                $request->attributes->set('workspace_data', $this->workspaceService->current());
            } catch (\Throwable) {
                // Session write failed - continue with defaults
                // ResilientSession middleware will handle the actual error
            }

            $request->attributes->set('workspace', $workspace);

            // CRITICAL: Also set the Workspace MODEL instance (not array)
            // This enables Workspace::current() and WorkspaceScope to work
            try {
                $workspaceModel = Workspace::where('slug', $workspace)->first();
                if ($workspaceModel) {
                    $request->attributes->set('workspace_model', $workspaceModel);
                }
            } catch (\Throwable) {
                // Database query failed - continue without workspace model
            }
        }

        return $next($request);
    }

    /**
     * Extract subdomain from hostname.
     */
    protected function extractSubdomain(string $host): string
    {
        $baseDomain = config('app.base_domain', 'host.uk.com');

        // Handle localhost/dev environments
        if (str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || str_ends_with($host, '.test')) {
            return ''; // Treat as main domain for local dev
        }

        // Check if this is our base domain
        if (! str_ends_with($host, $baseDomain)) {
            return '';
        }

        // Extract subdomain
        $subdomain = str_replace('.'.$baseDomain, '', $host);

        // Handle bare domain (no subdomain)
        if ($subdomain === $host) {
            return '';
        }

        return $subdomain;
    }

    /**
     * Check if subdomain should serve admin panel.
     */
    public function isAdminDomain(?string $subdomain): bool
    {
        return in_array($subdomain ?? '', $this->adminSubdomains, true);
    }

    /**
     * Resolve workspace slug from subdomain.
     */
    protected function resolveWorkspaceFromSubdomain(string $subdomain): ?string
    {
        // Map subdomains to workspace slugs (must match database Workspace slugs)
        $mappings = [
            // Admin/main domain aliases
            'hestia' => 'main',
            'main' => 'main',
            'www' => 'main',
            'hub' => 'main',
            '' => 'main',
            // Service subdomains - bio is canonical, link is alias
            'bio' => 'bio',
            'link' => 'bio',
            'social' => 'social',
            'analytics' => 'analytics',
            'stats' => 'analytics',
            'trust' => 'trust',
            'proof' => 'trust',
            'notify' => 'notify',
            'push' => 'notify',
        ];

        if (isset($mappings[$subdomain])) {
            return $mappings[$subdomain];
        }

        // Check if subdomain matches a workspace slug directly
        $workspace = $this->workspaceService->get($subdomain);
        if ($workspace) {
            return $subdomain;
        }

        // Unknown subdomain - could be a user subdomain, default to main
        return 'main';
    }
}
