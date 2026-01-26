<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks usage alert notifications to avoid spamming users.
 *
 * When a workspace approaches an entitlement limit (e.g., 80% used),
 * an alert is sent. This model tracks which alerts have been sent
 * and when, so we don't send duplicates.
 */
class UsageAlertHistory extends Model
{
    protected $table = 'entitlement_usage_alert_history';

    protected $fillable = [
        'workspace_id',
        'feature_code',
        'threshold',
        'notified_at',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Alert threshold levels.
     */
    public const THRESHOLD_WARNING = 80;

    public const THRESHOLD_CRITICAL = 90;

    public const THRESHOLD_LIMIT = 100;

    /**
     * All threshold levels in order.
     */
    public const THRESHOLDS = [
        self::THRESHOLD_WARNING,
        self::THRESHOLD_CRITICAL,
        self::THRESHOLD_LIMIT,
    ];

    /**
     * The workspace this alert belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Scope to alerts for a specific workspace.
     */
    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to alerts for a specific feature.
     */
    public function scopeForFeature($query, string $featureCode)
    {
        return $query->where('feature_code', $featureCode);
    }

    /**
     * Scope to alerts for a specific threshold.
     */
    public function scopeForThreshold($query, int $threshold)
    {
        return $query->where('threshold', $threshold);
    }

    /**
     * Scope to unresolved alerts (still active).
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope to resolved alerts.
     */
    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope to recent alerts (within given days).
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('notified_at', '>=', now()->subDays($days));
    }

    /**
     * Check if an alert has been sent for this workspace/feature/threshold combo.
     * Only considers unresolved alerts.
     */
    public static function hasActiveAlert(int $workspaceId, string $featureCode, int $threshold): bool
    {
        return static::query()
            ->forWorkspace($workspaceId)
            ->forFeature($featureCode)
            ->forThreshold($threshold)
            ->unresolved()
            ->exists();
    }

    /**
     * Get the most recent unresolved alert for a workspace/feature.
     */
    public static function getActiveAlert(int $workspaceId, string $featureCode): ?self
    {
        return static::query()
            ->forWorkspace($workspaceId)
            ->forFeature($featureCode)
            ->unresolved()
            ->latest('notified_at')
            ->first();
    }

    /**
     * Record a new alert being sent.
     */
    public static function record(
        int $workspaceId,
        string $featureCode,
        int $threshold,
        array $metadata = []
    ): self {
        return static::create([
            'workspace_id' => $workspaceId,
            'feature_code' => $featureCode,
            'threshold' => $threshold,
            'notified_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Mark this alert as resolved (usage dropped below threshold).
     */
    public function resolve(): self
    {
        $this->update(['resolved_at' => now()]);

        return $this;
    }

    /**
     * Resolve all unresolved alerts for a workspace/feature.
     */
    public static function resolveAllForFeature(int $workspaceId, string $featureCode): int
    {
        return static::query()
            ->forWorkspace($workspaceId)
            ->forFeature($featureCode)
            ->unresolved()
            ->update(['resolved_at' => now()]);
    }

    /**
     * Check if this alert is resolved.
     */
    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Get the threshold level name.
     */
    public function getThresholdName(): string
    {
        return match ($this->threshold) {
            self::THRESHOLD_WARNING => 'warning',
            self::THRESHOLD_CRITICAL => 'critical',
            self::THRESHOLD_LIMIT => 'limit_reached',
            default => 'unknown',
        };
    }
}
