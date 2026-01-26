<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Console\Commands;

use Core\Mod\Tenant\Models\Boost;
use Core\Mod\Tenant\Models\EntitlementLog;
use Core\Mod\Tenant\Models\UsageRecord;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Notifications\BoostExpiredNotification;
use Core\Mod\Tenant\Services\EntitlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reset billing cycle counters and expire cycle-bound boosts.
 *
 * This command should be scheduled to run daily to:
 * - Reset usage counters at billing period start
 * - Expire temporary boosts at period end
 * - Notify users when boosts expire
 * - Log all actions for audit trail
 */
class ResetBillingCycles extends Command
{
    protected $signature = 'tenant:reset-billing-cycles
        {--workspace= : Process a specific workspace by ID or slug}
        {--dry-run : Show what would happen without making changes}
        {--verbose : Show detailed output}';

    protected $description = 'Reset billing cycle usage counters and expire cycle-bound boosts';

    protected int $boostsExpired = 0;

    protected int $usageCountersReset = 0;

    protected int $notificationsSent = 0;

    protected int $workspacesProcessed = 0;

    public function __construct(
        protected EntitlementService $entitlementService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $verbose = $this->option('verbose');

        if ($dryRun) {
            $this->info('DRY RUN: No changes will be made.');
        }

        $this->info('Starting billing cycle reset process...');
        $this->newLine();

        if ($workspaceOption = $this->option('workspace')) {
            return $this->processSingleWorkspace($workspaceOption, $dryRun, $verbose);
        }

        return $this->processAllWorkspaces($dryRun, $verbose);
    }

    /**
     * Process a single workspace.
     */
    protected function processSingleWorkspace(string $identifier, bool $dryRun, bool $verbose): int
    {
        $workspace = is_numeric($identifier)
            ? Workspace::find($identifier)
            : Workspace::where('slug', $identifier)->first();

        if (! $workspace) {
            $this->error("Workspace not found: {$identifier}");

            return self::FAILURE;
        }

        $this->info("Processing workspace: {$workspace->name} ({$workspace->slug})");

        $result = $this->processWorkspace($workspace, $dryRun, $verbose);

        $this->outputSummary();

        return $result ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Process all workspaces.
     */
    protected function processAllWorkspaces(bool $dryRun, bool $verbose): int
    {
        // Get workspaces with active packages
        $workspaces = Workspace::query()
            ->active()
            ->whereHas('workspacePackages', fn ($q) => $q->active())
            ->get();

        $this->info("Found {$workspaces->count()} active workspaces with packages.");
        $this->newLine();

        $bar = $this->output->createProgressBar($workspaces->count());
        $bar->start();

        foreach ($workspaces as $workspace) {
            try {
                $this->processWorkspace($workspace, $dryRun, $verbose);
                $this->workspacesProcessed++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Error processing workspace {$workspace->slug}: {$e->getMessage()}");

                Log::error('Billing cycle reset failed for workspace', [
                    'workspace_id' => $workspace->id,
                    'workspace_slug' => $workspace->slug,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->outputSummary();

        return self::SUCCESS;
    }

    /**
     * Process a single workspace's billing cycle.
     */
    protected function processWorkspace(Workspace $workspace, bool $dryRun, bool $verbose): bool
    {
        // Get the primary (base) package to determine billing cycle
        $primaryPackage = $workspace->workspacePackages()
            ->whereHas('package', fn ($q) => $q->where('is_base_package', true))
            ->active()
            ->first();

        if (! $primaryPackage) {
            if ($verbose) {
                $this->line("  Skipping {$workspace->name}: No active base package");
            }

            return true;
        }

        $cycleStart = $primaryPackage->getCurrentCycleStart();
        $cycleEnd = $primaryPackage->getCurrentCycleEnd();
        $previousCycleEnd = $cycleStart;

        // Determine if we're at a billing cycle boundary (within 24 hours of cycle start)
        $isAtCycleStart = now()->diffInHours($cycleStart) < 24 && now()->gte($cycleStart);

        if ($verbose) {
            $this->newLine();
            $this->line("  Workspace: {$workspace->name}");
            $this->line("    Cycle: {$cycleStart->format('Y-m-d')} to {$cycleEnd->format('Y-m-d')}");
            $this->line('    At cycle start: '.($isAtCycleStart ? 'Yes' : 'No'));
        }

        // 1. Expire cycle-bound boosts from previous cycle
        $expiredBoosts = $this->expireCycleBoundBoosts($workspace, $previousCycleEnd, $dryRun, $verbose);

        // 2. Reset usage counters at cycle start
        if ($isAtCycleStart) {
            $this->resetUsageCounters($workspace, $cycleStart, $dryRun, $verbose);
        }

        // 3. Expire time-based boosts that have passed their expiry
        $this->expireTimedBoosts($workspace, $dryRun, $verbose);

        // 4. Send notifications for expired boosts
        if (! $dryRun && $expiredBoosts->isNotEmpty()) {
            $this->sendBoostExpiryNotifications($workspace, $expiredBoosts, $verbose);
        }

        return true;
    }

    /**
     * Expire cycle-bound boosts that should have ended in the previous cycle.
     */
    protected function expireCycleBoundBoosts(Workspace $workspace, Carbon $cycleEnd, bool $dryRun, bool $verbose): Collection
    {
        $boosts = $workspace->boosts()
            ->where('duration_type', Boost::DURATION_CYCLE_BOUND)
            ->where('status', Boost::STATUS_ACTIVE)
            ->where(function ($q) {
                // Either no explicit expiry (cycle-bound) or expiry has passed
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '<=', now());
            })
            ->get();

        if ($boosts->isEmpty()) {
            return collect();
        }

        if ($verbose) {
            $this->line("    Found {$boosts->count()} cycle-bound boosts to expire");
        }

        if ($dryRun) {
            foreach ($boosts as $boost) {
                $this->line("      [DRY RUN] Would expire boost: {$boost->feature_code} (ID: {$boost->id})");
            }

            return $boosts;
        }

        DB::transaction(function () use ($workspace, $boosts) {
            foreach ($boosts as $boost) {
                $boost->expire();

                EntitlementLog::logBoostAction(
                    $workspace,
                    EntitlementLog::ACTION_BOOST_EXPIRED,
                    $boost,
                    source: EntitlementLog::SOURCE_SYSTEM,
                    metadata: [
                        'reason' => 'Billing cycle ended',
                        'expired_at' => now()->toIso8601String(),
                    ]
                );

                $this->boostsExpired++;
            }
        });

        // Invalidate entitlement cache
        $this->entitlementService->invalidateCache($workspace);

        Log::info('Billing cycle: Expired cycle-bound boosts', [
            'workspace_id' => $workspace->id,
            'workspace_slug' => $workspace->slug,
            'boosts_expired' => $boosts->count(),
            'boost_ids' => $boosts->pluck('id')->toArray(),
        ]);

        return $boosts;
    }

    /**
     * Expire boosts with explicit time-based expiry that has passed.
     */
    protected function expireTimedBoosts(Workspace $workspace, bool $dryRun, bool $verbose): void
    {
        $boosts = $workspace->boosts()
            ->where('duration_type', Boost::DURATION_DURATION)
            ->where('status', Boost::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->get();

        if ($boosts->isEmpty()) {
            return;
        }

        if ($verbose) {
            $this->line("    Found {$boosts->count()} timed boosts to expire");
        }

        if ($dryRun) {
            foreach ($boosts as $boost) {
                $this->line("      [DRY RUN] Would expire timed boost: {$boost->feature_code} (ID: {$boost->id})");
            }

            return;
        }

        DB::transaction(function () use ($workspace, $boosts) {
            foreach ($boosts as $boost) {
                $boost->expire();

                EntitlementLog::logBoostAction(
                    $workspace,
                    EntitlementLog::ACTION_BOOST_EXPIRED,
                    $boost,
                    source: EntitlementLog::SOURCE_SYSTEM,
                    metadata: [
                        'reason' => 'Duration expired',
                        'expires_at' => $boost->expires_at->toIso8601String(),
                        'expired_at' => now()->toIso8601String(),
                    ]
                );

                $this->boostsExpired++;
            }
        });

        $this->entitlementService->invalidateCache($workspace);
    }

    /**
     * Reset usage counters for cycle-based features.
     *
     * Note: We don't actually delete usage records - instead, the EntitlementService
     * calculates usage based on the current cycle start date. This method logs the
     * cycle reset for audit purposes.
     */
    protected function resetUsageCounters(Workspace $workspace, Carbon $cycleStart, bool $dryRun, bool $verbose): void
    {
        // Get count of usage records from previous cycle
        $previousUsage = UsageRecord::where('workspace_id', $workspace->id)
            ->where('recorded_at', '<', $cycleStart)
            ->count();

        if ($previousUsage === 0) {
            return;
        }

        if ($verbose) {
            $this->line("    Cycle reset: {$previousUsage} usage records now in previous cycle");
        }

        if ($dryRun) {
            $this->line('      [DRY RUN] Would log cycle reset for workspace');

            return;
        }

        // Log the cycle reset for audit trail
        EntitlementLog::create([
            'workspace_id' => $workspace->id,
            'action' => EntitlementLog::ACTION_CYCLE_RESET,
            'entity_type' => 'workspace',
            'entity_id' => $workspace->id,
            'source' => EntitlementLog::SOURCE_SYSTEM,
            'metadata' => [
                'cycle_start' => $cycleStart->toIso8601String(),
                'previous_cycle_records' => $previousUsage,
                'reset_at' => now()->toIso8601String(),
            ],
        ]);

        $this->usageCountersReset++;

        // Invalidate usage cache so new calculations use current cycle
        $this->entitlementService->invalidateCache($workspace);

        Log::info('Billing cycle: Reset usage counters', [
            'workspace_id' => $workspace->id,
            'workspace_slug' => $workspace->slug,
            'cycle_start' => $cycleStart->toIso8601String(),
            'previous_cycle_records' => $previousUsage,
        ]);
    }

    /**
     * Send notifications to workspace owner about expired boosts.
     */
    protected function sendBoostExpiryNotifications(Workspace $workspace, Collection $expiredBoosts, bool $verbose): void
    {
        $owner = $workspace->owner();

        if (! $owner) {
            if ($verbose) {
                $this->line('    No owner found for notification');
            }

            return;
        }

        try {
            $owner->notify(new BoostExpiredNotification($workspace, $expiredBoosts));
            $this->notificationsSent++;

            if ($verbose) {
                $this->line("    Sent boost expiry notification to: {$owner->email}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to send boost expiry notification', [
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Output summary statistics.
     */
    protected function outputSummary(): void
    {
        $this->info('Billing cycle reset completed.');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Workspaces processed', $this->workspacesProcessed],
                ['Boosts expired', $this->boostsExpired],
                ['Usage cycles reset', $this->usageCountersReset],
                ['Notifications sent', $this->notificationsSent],
            ]
        );

        if ($this->boostsExpired > 0) {
            $this->comment('Boost expiry notifications have been queued for delivery.');
        }
    }
}
