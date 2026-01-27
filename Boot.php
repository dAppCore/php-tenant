<?php

declare(strict_types=1);

namespace Core\Tenant;

use Core\Events\AdminPanelBooting;
use Illuminate\Support\ServiceProvider;

/**
 * Tenant Module Boot (Host UK extension).
 *
 * Extends core Tenant module with Host UK specific admin features:
 * - Team Manager
 * - Member Manager
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
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
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

        // Admin components
        $event->livewire('tenant.admin.team-manager', View\Modal\Admin\TeamManager::class);
        $event->livewire('tenant.admin.member-manager', View\Modal\Admin\MemberManager::class);
    }
}
