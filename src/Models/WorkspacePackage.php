<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WorkspacePackage extends Model
{
    use HasFactory;

    protected $table = 'entitlement_workspace_packages';

    protected $fillable = [
        'workspace_id',
        'package_id',
        'status',
        'starts_at',
        'expires_at',
        'billing_cycle_anchor',
        'blesta_service_id',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'billing_cycle_anchor' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Status constants.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    /**
     * The workspace this package belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The package definition.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    /**
     * Scope to active assignments.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to non-expired assignments.
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Check if this assignment is currently active.
     */
    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if this assignment is on grace period.
     */
    public function onGracePeriod(): bool
    {
        return $this->status === self::STATUS_CANCELLED
            && $this->expires_at
            && $this->expires_at->isFuture();
    }

    /**
     * Get the current billing cycle start date.
     */
    public function getCurrentCycleStart(): Carbon
    {
        if (! $this->billing_cycle_anchor) {
            return $this->starts_at ?? $this->created_at;
        }

        $anchor = $this->billing_cycle_anchor->copy();
        $now = now();

        // Find the most recent cycle start
        while ($anchor->addMonth()->lte($now)) {
            // Keep advancing until we pass now
        }

        return $anchor->subMonth();
    }

    /**
     * Get the current billing cycle end date.
     */
    public function getCurrentCycleEnd(): Carbon
    {
        return $this->getCurrentCycleStart()->copy()->addMonth();
    }

    /**
     * Suspend this assignment.
     */
    public function suspend(): void
    {
        $this->update(['status' => self::STATUS_SUSPENDED]);
    }

    /**
     * Reactivate this assignment.
     */
    public function reactivate(): void
    {
        $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Cancel this assignment.
     */
    public function cancel(?Carbon $endsAt = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'expires_at' => $endsAt ?? $this->getCurrentCycleEnd(),
        ]);
    }
}
