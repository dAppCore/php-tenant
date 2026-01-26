<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class UsageRecord extends Model
{
    use HasFactory;

    protected $table = 'entitlement_usage_records';

    protected $fillable = [
        'workspace_id',
        'namespace_id',
        'feature_code',
        'quantity',
        'user_id',
        'metadata',
        'recorded_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'metadata' => 'array',
        'recorded_at' => 'datetime',
    ];

    /**
     * The workspace this usage belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The namespace this usage belongs to.
     */
    public function namespace(): BelongsTo
    {
        return $this->belongsTo(Namespace_::class, 'namespace_id');
    }

    /**
     * The user who incurred this usage.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to a specific feature.
     */
    public function scopeForFeature($query, string $featureCode)
    {
        return $query->where('feature_code', $featureCode);
    }

    /**
     * Scope to records since a date.
     */
    public function scopeSince($query, Carbon $date)
    {
        return $query->where('recorded_at', '>=', $date);
    }

    /**
     * Scope to records in a date range.
     */
    public function scopeBetween($query, Carbon $start, Carbon $end)
    {
        return $query->whereBetween('recorded_at', [$start, $end]);
    }

    /**
     * Scope to records in the current billing cycle.
     */
    public function scopeInCurrentCycle($query, Carbon $cycleStart)
    {
        return $query->where('recorded_at', '>=', $cycleStart);
    }

    /**
     * Scope to records in a rolling window.
     */
    public function scopeInRollingWindow($query, int $days)
    {
        return $query->where('recorded_at', '>=', now()->subDays($days));
    }

    /**
     * Get total usage for a workspace + feature since a date.
     */
    public static function getTotalUsage(int $workspaceId, string $featureCode, ?Carbon $since = null): int
    {
        $query = static::where('workspace_id', $workspaceId)
            ->where('feature_code', $featureCode);

        if ($since) {
            $query->where('recorded_at', '>=', $since);
        }

        return (int) $query->sum('quantity');
    }

    /**
     * Get total usage in a rolling window.
     */
    public static function getRollingUsage(int $workspaceId, string $featureCode, int $days): int
    {
        return static::where('workspace_id', $workspaceId)
            ->where('feature_code', $featureCode)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->sum('quantity');
    }
}
