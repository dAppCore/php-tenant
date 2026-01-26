<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Console\Commands;

use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\UsageAlertService;
use Illuminate\Console\Command;

/**
 * Check workspaces for usage alerts and send notifications.
 *
 * This command should be scheduled to run periodically (e.g., hourly)
 * to monitor entitlement usage and alert users when approaching limits.
 */
class CheckUsageAlerts extends Command
{
    protected $signature = 'tenant:check-usage-alerts
        {--workspace= : Check a specific workspace by ID or slug}
        {--dry-run : Show what would be sent without actually sending}
        {--verbose : Show detailed output}';

    protected $description = 'Check workspaces for usage alerts and send notifications when approaching limits';

    public function __construct(
        protected UsageAlertService $alertService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $verbose = $this->option('verbose');

        if ($dryRun) {
            $this->info('DRY RUN: No notifications will be sent.');
        }

        if ($workspaceOption = $this->option('workspace')) {
            return $this->checkSingleWorkspace($workspaceOption, $dryRun, $verbose);
        }

        return $this->checkAllWorkspaces($dryRun, $verbose);
    }

    /**
     * Check a single workspace.
     */
    protected function checkSingleWorkspace(string $identifier, bool $dryRun, bool $verbose): int
    {
        $workspace = is_numeric($identifier)
            ? Workspace::find($identifier)
            : Workspace::where('slug', $identifier)->first();

        if (! $workspace) {
            $this->error("Workspace not found: {$identifier}");

            return self::FAILURE;
        }

        $this->info("Checking workspace: {$workspace->name} ({$workspace->slug})");

        if ($dryRun) {
            $this->showUsageStatus($workspace);

            return self::SUCCESS;
        }

        $result = $this->alertService->checkWorkspace($workspace);

        $this->info("Alerts sent: {$result['alerts_sent']}");
        $this->info("Alerts resolved: {$result['alerts_resolved']}");

        if ($verbose && ! empty($result['details'])) {
            $this->newLine();
            $this->table(
                ['Feature', 'Usage %', 'Threshold', 'Action'],
                collect($result['details'])->map(fn ($d) => [
                    $d['feature'],
                    $d['percentage'] !== null ? round($d['percentage'], 1).'%' : 'N/A',
                    $d['threshold'] ? $d['threshold'].'%' : 'N/A',
                    $d['alert_sent'] ? 'Alert sent' : ($d['resolved'] ? 'Resolved' : 'No action'),
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }

    /**
     * Check all workspaces.
     */
    protected function checkAllWorkspaces(bool $dryRun, bool $verbose): int
    {
        $this->info('Checking all active workspaces for usage alerts...');

        if ($dryRun) {
            $this->showAllWorkspacesStatus($verbose);

            return self::SUCCESS;
        }

        $result = $this->alertService->checkAllWorkspaces();

        $this->newLine();
        $this->info("Workspaces checked: {$result['checked']}");
        $this->info("Alerts sent: {$result['alerts_sent']}");
        $this->info("Alerts resolved: {$result['alerts_resolved']}");

        if ($result['alerts_sent'] > 0) {
            $this->comment('Usage alert notifications have been queued for delivery.');
        }

        return self::SUCCESS;
    }

    /**
     * Show usage status for a single workspace (dry run).
     */
    protected function showUsageStatus(Workspace $workspace): void
    {
        $status = $this->alertService->getUsageStatus($workspace);

        if ($status->isEmpty()) {
            $this->info('No features with limits found.');

            return;
        }

        $this->newLine();
        $this->table(
            ['Feature', 'Used', 'Limit', 'Usage %', 'Status', 'Active Alert'],
            $status->map(fn ($s) => [
                $s['name'],
                $s['used'] ?? 0,
                $s['limit'] ?? 'N/A',
                $s['percentage'] !== null ? round($s['percentage'], 1).'%' : 'N/A',
                $this->getStatusLabel($s),
                $s['active_alert'] ? $s['alert_threshold'].'% alert' : '-',
            ])->toArray()
        );

        $approaching = $status->filter(fn ($s) => $s['near_limit'] || $s['at_limit']);

        if ($approaching->isNotEmpty()) {
            $this->newLine();
            $this->warn("Features approaching limits: {$approaching->count()}");

            foreach ($approaching as $item) {
                $wouldSend = ! $item['active_alert'] || $item['alert_threshold'] < $this->getThresholdForPercentage($item['percentage']);

                if ($wouldSend) {
                    $this->line("  - {$item['name']}: Would send alert");
                } else {
                    $this->line("  - {$item['name']}: Alert already sent");
                }
            }
        }
    }

    /**
     * Show status for all workspaces (dry run).
     */
    protected function showAllWorkspacesStatus(bool $verbose): void
    {
        $workspaces = Workspace::query()
            ->active()
            ->whereHas('workspacePackages', fn ($q) => $q->active())
            ->get();

        $this->info("Found {$workspaces->count()} active workspaces with packages.");

        $alerts = [];

        foreach ($workspaces as $workspace) {
            $status = $this->alertService->getUsageStatus($workspace);
            $approaching = $status->filter(fn ($s) => $s['near_limit'] || $s['at_limit']);

            if ($approaching->isNotEmpty()) {
                foreach ($approaching as $item) {
                    $alerts[] = [
                        'workspace' => $workspace->name,
                        'feature' => $item['name'],
                        'used' => $item['used'],
                        'limit' => $item['limit'],
                        'percentage' => round($item['percentage'], 1),
                        'has_alert' => $item['active_alert'] !== null,
                    ];
                }
            }
        }

        if (empty($alerts)) {
            $this->info('No workspaces are approaching limits.');

            return;
        }

        $this->newLine();
        $this->warn('Found '.count($alerts).' features approaching limits:');
        $this->newLine();

        $this->table(
            ['Workspace', 'Feature', 'Used', 'Limit', '%', 'Alert Sent?'],
            collect($alerts)->map(fn ($a) => [
                $a['workspace'],
                $a['feature'],
                $a['used'],
                $a['limit'],
                $a['percentage'].'%',
                $a['has_alert'] ? 'Yes' : 'No',
            ])->toArray()
        );
    }

    /**
     * Get status label for display.
     */
    protected function getStatusLabel(array $status): string
    {
        if ($status['at_limit']) {
            return '<fg=red>At Limit</>';
        }

        if ($status['percentage'] >= 90) {
            return '<fg=yellow>Critical</>';
        }

        if ($status['near_limit']) {
            return '<fg=yellow>Warning</>';
        }

        return '<fg=green>OK</>';
    }

    /**
     * Get threshold for a given percentage.
     */
    protected function getThresholdForPercentage(?float $percentage): ?int
    {
        if ($percentage === null) {
            return null;
        }

        if ($percentage >= 100) {
            return 100;
        }

        if ($percentage >= 90) {
            return 90;
        }

        if ($percentage >= 80) {
            return 80;
        }

        return null;
    }
}
