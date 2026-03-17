<?php

declare(strict_types=1);

namespace Core\Tenant;

use App\Services\UserStatsService;
use App\Services\WorkspaceCacheManager;
use App\Services\WorkspaceManager;
use App\Services\WorkspaceService;
use Core\Events\AdminPanelBooting;
use Core\Events\ApiRoutesRegistering;
use Core\Events\ConsoleBooting;
use Core\Events\WebRoutesRegistering;
use Core\Tenant\Contracts\TwoFactorAuthenticationProvider;
use Core\Tenant\Services\EntitlementService;
use Core\Tenant\Services\EntitlementWebhookService;
use Core\Tenant\Services\TotpService;
use Core\Tenant\Services\UsageAlertService;
use Core\Tenant\Services\WorkspaceTeamService;
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
            TwoFactorAuthenticationProvider::class,
            TotpService::class
        );

        $this->app->singleton(
            EntitlementService::class,
            EntitlementService::class
        );

        $this->app->singleton(
            Services\WorkspaceManager::class,
            Services\WorkspaceManager::class
        );

        $this->app->singleton(
            Services\UserStatsService::class,
            Services\UserStatsService::class
        );

        $this->app->singleton(
            Services\WorkspaceService::class,
            Services\WorkspaceService::class
        );

        $this->app->singleton(
            Services\WorkspaceCacheManager::class,
            Services\WorkspaceCacheManager::class
        );

        $this->app->singleton(
            UsageAlertService::class,
            UsageAlertService::class
        );

        $this->app->singleton(
            EntitlementWebhookService::class,
            EntitlementWebhookService::class
        );

        $this->app->singleton(
            WorkspaceTeamService::class,
            WorkspaceTeamService::class
        );

        $this->registerBackwardCompatAliases();
    }

    protected function registerBackwardCompatAliases(): void
    {
        if (! class_exists(WorkspaceManager::class)) {
            class_alias(
                Services\WorkspaceManager::class,
                WorkspaceManager::class
            );
        }

        if (! class_exists(UserStatsService::class)) {
            class_alias(
                Services\UserStatsService::class,
                UserStatsService::class
            );
        }

        if (! class_exists(WorkspaceService::class)) {
            class_alias(
                Services\WorkspaceService::class,
                WorkspaceService::class
            );
        }

        if (! class_exists(WorkspaceCacheManager::class)) {
            class_alias(
                Services\WorkspaceCacheManager::class,
                WorkspaceCacheManager::class
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

        if (file_exists(__DIR__.'/Routes/admin.php')) {
            $event->routes(fn () => require __DIR__.'/Routes/admin.php');
        }

        // Admin Livewire components
        $event->livewire('tenant.admin.entitlement-webhook-manager', View\Modal\Admin\EntitlementWebhookManager::class);
        $event->livewire('tenant.admin.team-manager', View\Modal\Admin\TeamManager::class);
        $event->livewire('tenant.admin.member-manager', View\Modal\Admin\MemberManager::class);
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

        // Security migration commands
        $event->command(Console\Commands\EncryptTwoFactorSecrets::class);
        $event->command(Console\Commands\HashInvitationTokens::class);
    }
}
