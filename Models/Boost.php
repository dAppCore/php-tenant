<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Boost extends Model
{
    use HasFactory;

    protected $table = 'entitlement_boosts';

    protected $fillable = [
        'workspace_id',
        'namespace_id',
        'user_id',
        'feature_code',
        'boost_type',
        'duration_type',
        'limit_value',
        'consumed_quantity',
        'status',
        'starts_at',
        'expires_at',
        'blesta_addon_id',
        'metadata',
    ];

    protected $casts = [
        'limit_value' => 'integer',
        'consumed_quantity' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Boost types.
     */
    public const BOOST_TYPE_ADD_LIMIT = 'add_limit';

    public const BOOST_TYPE_ENABLE = 'enable';

    public const BOOST_TYPE_UNLIMITED = 'unlimited';

    /**
     * Duration types.
     */
    public const DURATION_CYCLE_BOUND = 'cycle_bound';

    public const DURATION_DURATION = 'duration';

    public const DURATION_PERMANENT = 'permanent';

    /**
     * Status constants.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The workspace this boost belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The namespace this boost belongs to.
     */
    public function namespace(): BelongsTo
    {
        return $this->belongsTo(Namespace_::class, 'namespace_id');
    }

    /**
     * The user this boost belongs to (for user-level boosts like vanity URLs).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to active boosts.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to a specific feature.
     */
    public function scopeForFeature($query, string $featureCode)
    {
        return $query->where('feature_code', $featureCode);
    }

    /**
     * Scope to usable boosts (active and not expired).
     */
    public function scopeUsable($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            });
    }

    /**
     * Check if this boost is currently usable.
     */
    public function isUsable(): bool
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
     * Get remaining limit for this boost.
     */
    public function getRemainingLimit(): ?int
    {
        if ($this->boost_type === self::BOOST_TYPE_UNLIMITED) {
            return null; // Unlimited
        }

        if ($this->boost_type === self::BOOST_TYPE_ENABLE) {
            return null; // Boolean, no limit
        }

        return max(0, $this->limit_value - $this->consumed_quantity);
    }

    /**
     * Consume some of this boost's limit.
     */
    public function consume(int $quantity = 1): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        if ($this->boost_type !== self::BOOST_TYPE_ADD_LIMIT) {
            return true; // No consumption for enable/unlimited
        }

        $remaining = $this->getRemainingLimit();

        if ($remaining !== null && $quantity > $remaining) {
            return false;
        }

        $this->increment('consumed_quantity', $quantity);

        // Check if exhausted
        if ($this->getRemainingLimit() === 0) {
            $this->update(['status' => self::STATUS_EXHAUSTED]);
        }

        return true;
    }

    /**
     * Check if this boost has remaining capacity.
     */
    public function hasCapacity(): bool
    {
        if ($this->boost_type === self::BOOST_TYPE_UNLIMITED) {
            return true;
        }

        if ($this->boost_type === self::BOOST_TYPE_ENABLE) {
            return true;
        }

        return $this->getRemainingLimit() > 0;
    }

    /**
     * Expire this boost.
     */
    public function expire(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Cancel this boost.
     */
    public function cancel(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
