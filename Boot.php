<?php

declare(strict_types=1);

namespace Core\Mod\Tenant;

use Core\Events\AdminPanelBooting;
use Core\Events\ApiRoutesRegistering;
use Core\Events\ConsoleBooting;
use Core\Events\WebRoutesRegistering;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Tenant Module Boot.
 *
 * Core multi-tenancy module handling:
 * - Users and authentication
 * - Workspaces (the tenant boundary)
 * - Account management (deletion, settings)
 * - Entitlements (feature access, packages, usage)
 * - Referrals
 */
class Boot extends ServiceProvider
{
    protected string $moduleName = 'tenant';

    /**
     * Events this module listens to for lazy loading.
     *
     * @var array<class-string, string>
     */
    public static array $listens = [
        AdminPanelBooting::class => 'onAdminPanel',
        ApiRoutesRegistering::class => 'onApiRoutes',
        WebRoutesRegistering::class => 'onWebRoutes',
        ConsoleBooting::class => 'onConsole',
    ];

    public function register(): void
    {
        $this->app->singleton(
            \Core\Mod\Tenant\Contracts\TwoFactorAuthenticationProvider::class,
            \Core\Mod\Tenant\Services\TotpService::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\EntitlementService::class,
            \Core\Mod\Tenant\Services\EntitlementService::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\WorkspaceManager::class,
            \Core\Mod\Tenant\Services\WorkspaceManager::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\UserStatsService::class,
            \Core\Mod\Tenant\Services\UserStatsService::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\WorkspaceService::class,
            \Core\Mod\Tenant\Services\WorkspaceService::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\WorkspaceCacheManager::class,
            \Core\Mod\Tenant\Services\WorkspaceCacheManager::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\UsageAlertService::class,
            \Core\Mod\Tenant\Services\UsageAlertService::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\EntitlementWebhookService::class,
            \Core\Mod\Tenant\Services\EntitlementWebhookService::class
        );

        $this->app->singleton(
            \Core\Mod\Tenant\Services\WorkspaceTeamService::class,
            \Core\Mod\Tenant\Services\WorkspaceTeamService::class
        );

        $this->registerBackwardCompatAliases();
    }

    protected function registerBackwardCompatAliases(): void
    {
        if (! class_exists(\App\Services\WorkspaceManager::class)) {
            class_alias(
                \Core\Mod\Tenant\Services\WorkspaceManager::class,
                \App\Services\WorkspaceManager::class
            );
        }

        if (! class_exists(\App\Services\UserStatsService::class)) {
            class_alias(
                \Core\Mod\Tenant\Services\UserStatsService::class,
                \App\Services\UserStatsService::class
            );
        }

        if (! class_exists(\App\Services\WorkspaceService::class)) {
            class_alias(
                \Core\Mod\Tenant\Services\WorkspaceService::class,
                \App\Services\WorkspaceService::class
            );
        }

        if (! class_exists(\App\Services\WorkspaceCacheManager::class)) {
            class_alias(
                \Core\Mod\Tenant\Services\WorkspaceCacheManager::class,
                \App\Services\WorkspaceCacheManager::class
            );
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang/en_GB', 'tenant');
    }

    // -------------------------------------------------------------------------
    // Event-driven handlers
    // -------------------------------------------------------------------------

    public function onAdminPanel(AdminPanelBooting $event): void
    {
        $event->views($this->moduleName, __DIR__.'/View/Blade');

        // Admin Livewire components
        $event->livewire('tenant.admin.entitlement-webhook-manager', View\Modal\Admin\EntitlementWebhookManager::class);
    }

    public function onApiRoutes(ApiRoutesRegistering $event): void
    {
        if (file_exists(__DIR__.'/Routes/api.php')) {
            $event->routes(fn () => Route::middleware('api')->group(__DIR__.'/Routes/api.php'));
        }
    }

    public function onWebRoutes(WebRoutesRegistering $event): void
    {
        $event->views($this->moduleName, __DIR__.'/View/Blade');

        if (file_exists(__DIR__.'/Routes/web.php')) {
            $event->routes(fn () => Route::middleware('web')->group(__DIR__.'/Routes/web.php'));
        }

        // Account management
        $event->livewire('tenant.account.cancel-deletion', View\Modal\Web\CancelDeletion::class);
        $event->livewire('tenant.account.confirm-deletion', View\Modal\Web\ConfirmDeletion::class);

        // Workspace
        $event->livewire('tenant.workspace.home', View\Modal\Web\WorkspaceHome::class);
    }

    public function onConsole(ConsoleBooting $event): void
    {
        $event->middleware('admin.domain', Middleware\RequireAdminDomain::class);
        $event->middleware('workspace.permission', Middleware\CheckWorkspacePermission::class);

        // Artisan commands
        $event->command(Console\Commands\RefreshUserStats::class);
        $event->command(Console\Commands\ProcessAccountDeletions::class);
        $event->command(Console\Commands\CheckUsageAlerts::class);
        $event->command(Console\Commands\ResetBillingCycles::class);
    }
}
