<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Core\Mod\Tenant\Events\Webhook\LimitReachedEvent;
use Core\Mod\Tenant\Events\Webhook\LimitWarningEvent;
use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\UsageAlertHistory;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Notifications\UsageAlertNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service to check usage against entitlement limits and send notifications.
 *
 * Monitors workspace feature usage and sends alerts when approaching limits:
 * - 80% (warning)
 * - 90% (critical)
 * - 100% (limit reached)
 *
 * Tracks sent alerts to avoid spamming users with duplicate notifications.
 */
class UsageAlertService
{
    public function __construct(
        protected EntitlementService $entitlementService,
        protected ?EntitlementWebhookService $webhookService = null
    ) {}

    /**
     * Check all workspaces for usage alerts.
     *
     * @return array{checked: int, alerts_sent: int, alerts_resolved: int}
     */
    public function checkAllWorkspaces(): array
    {
        $stats = [
            'checked' => 0,
            'alerts_sent' => 0,
            'alerts_resolved' => 0,
        ];

        // Get all active workspaces with packages
        $workspaces = Workspace::query()
            ->active()
            ->whereHas('workspacePackages', fn ($q) => $q->active())
            ->get();

        foreach ($workspaces as $workspace) {
            $result = $this->checkWorkspace($workspace);
            $stats['checked']++;
            $stats['alerts_sent'] += $result['alerts_sent'];
            $stats['alerts_resolved'] += $result['alerts_resolved'];
        }

        return $stats;
    }

    /**
     * Check a single workspace for usage alerts.
     *
     * @return array{alerts_sent: int, alerts_resolved: int, details: array}
     */
    public function checkWorkspace(Workspace $workspace): array
    {
        $alertsSent = 0;
        $alertsResolved = 0;
        $details = [];

        // Get all features with limits (not boolean, not unlimited)
        $features = Feature::active()
            ->where('type', Feature::TYPE_LIMIT)
            ->get();

        foreach ($features as $feature) {
            $result = $this->checkFeatureUsage($workspace, $feature);

            if ($result['alert_sent']) {
                $alertsSent++;
            }

            if ($result['resolved']) {
                $alertsResolved++;
            }

            if ($result['alert_sent'] || $result['resolved']) {
                $details[] = $result;
            }
        }

        return [
            'alerts_sent' => $alertsSent,
            'alerts_resolved' => $alertsResolved,
            'details' => $details,
        ];
    }

    /**
     * Check usage for a specific feature and send alert if needed.
     *
     * @return array{feature: string, percentage: float|null, threshold: int|null, alert_sent: bool, resolved: bool}
     */
    public function checkFeatureUsage(Workspace $workspace, Feature $feature): array
    {
        $result = [
            'feature' => $feature->code,
            'percentage' => null,
            'threshold' => null,
            'alert_sent' => false,
            'resolved' => false,
        ];

        // Get entitlement check result
        $entitlement = $this->entitlementService->can($workspace, $feature->code);

        // Skip if unlimited or no limit
        if ($entitlement->isUnlimited() || $entitlement->limit === null || $entitlement->limit === 0) {
            // Check if there are any unresolved alerts to clear
            $resolved = UsageAlertHistory::resolveAllForFeature($workspace->id, $feature->code);
            $result['resolved'] = $resolved > 0;

            return $result;
        }

        $percentage = $entitlement->getUsagePercentage();
        $result['percentage'] = $percentage;

        // Determine the applicable threshold
        $applicableThreshold = $this->getApplicableThreshold($percentage);

        // If usage dropped below all thresholds, resolve any active alerts
        if ($applicableThreshold === null) {
            $resolved = UsageAlertHistory::resolveAllForFeature($workspace->id, $feature->code);
            $result['resolved'] = $resolved > 0;

            return $result;
        }

        $result['threshold'] = $applicableThreshold;

        // Check if we've already sent an alert for this threshold
        if (UsageAlertHistory::hasActiveAlert($workspace->id, $feature->code, $applicableThreshold)) {
            return $result;
        }

        // Send the alert
        $this->sendAlert($workspace, $feature, $applicableThreshold, $entitlement->used, $entitlement->limit);
        $result['alert_sent'] = true;

        return $result;
    }

    /**
     * Determine which threshold applies based on usage percentage.
     */
    protected function getApplicableThreshold(?float $percentage): ?int
    {
        if ($percentage === null) {
            return null;
        }

        // Return the highest applicable threshold
        if ($percentage >= UsageAlertHistory::THRESHOLD_LIMIT) {
            return UsageAlertHistory::THRESHOLD_LIMIT;
        }

        if ($percentage >= UsageAlertHistory::THRESHOLD_CRITICAL) {
            return UsageAlertHistory::THRESHOLD_CRITICAL;
        }

        if ($percentage >= UsageAlertHistory::THRESHOLD_WARNING) {
            return UsageAlertHistory::THRESHOLD_WARNING;
        }

        return null;
    }

    /**
     * Send a usage alert notification.
     */
    protected function sendAlert(
        Workspace $workspace,
        Feature $feature,
        int $threshold,
        int $used,
        int $limit
    ): void {
        // Get workspace owner to notify
        $owner = $workspace->owner();

        if (! $owner) {
            Log::warning('Cannot send usage alert: workspace has no owner', [
                'workspace_id' => $workspace->id,
                'feature_code' => $feature->code,
                'threshold' => $threshold,
            ]);

            return;
        }

        // Record the alert
        UsageAlertHistory::record(
            workspaceId: $workspace->id,
            featureCode: $feature->code,
            threshold: $threshold,
            metadata: [
                'used' => $used,
                'limit' => $limit,
                'percentage' => round(($used / $limit) * 100),
                'notified_user_id' => $owner->id,
            ]
        );

        // Send notification
        $owner->notify(new UsageAlertNotification(
            workspace: $workspace,
            feature: $feature,
            threshold: $threshold,
            used: $used,
            limit: $limit
        ));

        Log::info('Usage alert sent', [
            'workspace_id' => $workspace->id,
            'workspace_name' => $workspace->name,
            'feature_code' => $feature->code,
            'threshold' => $threshold,
            'used' => $used,
            'limit' => $limit,
            'user_id' => $owner->id,
            'user_email' => $owner->email,
        ]);

        // Dispatch webhook event
        $this->dispatchWebhook($workspace, $feature, $threshold, $used, $limit);
    }

    /**
     * Dispatch webhook event for usage alert.
     */
    protected function dispatchWebhook(
        Workspace $workspace,
        Feature $feature,
        int $threshold,
        int $used,
        int $limit
    ): void {
        // Lazy load webhook service if not injected
        $webhookService = $this->webhookService ?? app(EntitlementWebhookService::class);

        // Create appropriate event based on threshold
        if ($threshold === UsageAlertHistory::THRESHOLD_LIMIT) {
            $event = new LimitReachedEvent($workspace, $feature, $used, $limit);
        } else {
            $event = new LimitWarningEvent($workspace, $feature, $used, $limit, $threshold);
        }

        // Dispatch to all matching webhooks (async)
        try {
            $webhookService->dispatch($workspace, $event);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch usage alert webhook', [
                'workspace_id' => $workspace->id,
                'feature_code' => $feature->code,
                'threshold' => $threshold,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get current alert status for a workspace.
     *
     * Returns all features that have active alerts.
     */
    public function getActiveAlertsForWorkspace(Workspace $workspace): Collection
    {
        return UsageAlertHistory::query()
            ->forWorkspace($workspace->id)
            ->unresolved()
            ->with('workspace')
            ->orderBy('threshold', 'desc')
            ->orderBy('notified_at', 'desc')
            ->get();
    }

    /**
     * Get usage status for all features in a workspace.
     *
     * Returns features approaching limits with their alert status.
     */
    public function getUsageStatus(Workspace $workspace): Collection
    {
        $features = Feature::active()
            ->where('type', Feature::TYPE_LIMIT)
            ->get();

        return $features->map(function (Feature $feature) use ($workspace) {
            $entitlement = $this->entitlementService->can($workspace, $feature->code);
            $percentage = $entitlement->getUsagePercentage();
            $activeAlert = UsageAlertHistory::getActiveAlert($workspace->id, $feature->code);

            return [
                'feature' => $feature,
                'code' => $feature->code,
                'name' => $feature->name,
                'used' => $entitlement->used,
                'limit' => $entitlement->limit,
                'percentage' => $percentage,
                'unlimited' => $entitlement->isUnlimited(),
                'near_limit' => $entitlement->isNearLimit(),
                'at_limit' => $entitlement->isAtLimit(),
                'active_alert' => $activeAlert,
                'alert_threshold' => $activeAlert?->threshold,
            ];
        })->filter(fn ($item) => $item['limit'] !== null && ! $item['unlimited']);
    }

    /**
     * Manually resolve an alert (e.g., after user upgrades).
     */
    public function resolveAlert(int $alertId): bool
    {
        $alert = UsageAlertHistory::find($alertId);

        if (! $alert || $alert->isResolved()) {
            return false;
        }

        $alert->resolve();

        Log::info('Usage alert manually resolved', [
            'alert_id' => $alertId,
            'workspace_id' => $alert->workspace_id,
            'feature_code' => $alert->feature_code,
        ]);

        return true;
    }

    /**
     * Get alert history for a workspace.
     */
    public function getAlertHistory(Workspace $workspace, int $days = 30): Collection
    {
        return UsageAlertHistory::query()
            ->forWorkspace($workspace->id)
            ->where('notified_at', '>=', now()->subDays($days))
            ->orderBy('notified_at', 'desc')
            ->get();
    }
}
