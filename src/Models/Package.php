<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory;

    protected $table = 'entitlement_packages';

    protected $fillable = [
        'code',
        'name',
        'description',
        'icon',
        'color',
        'sort_order',
        'is_stackable',
        'is_base_package',
        'is_active',
        'is_public',
        'blesta_package_id',
        // Pricing fields
        'monthly_price',
        'yearly_price',
        'setup_fee',
        'trial_days',
        'stripe_price_id_monthly',
        'stripe_price_id_yearly',
        'btcpay_price_id_monthly',
        'btcpay_price_id_yearly',
    ];

    protected $casts = [
        'is_stackable' => 'boolean',
        'is_base_package' => 'boolean',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'sort_order' => 'integer',
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'trial_days' => 'integer',
    ];

    /**
     * Features included in this package.
     */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'entitlement_package_features', 'package_id', 'feature_id')
            ->withPivot('limit_value')
            ->withTimestamps();
    }

    /**
     * Workspaces that have this package assigned.
     */
    public function workspacePackages(): HasMany
    {
        return $this->hasMany(WorkspacePackage::class, 'package_id');
    }

    /**
     * Scope to active packages.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to public packages (shown on pricing page).
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope to base packages (only one per workspace).
     */
    public function scopeBase($query)
    {
        return $query->where('is_base_package', true);
    }

    /**
     * Scope to addon packages (stackable).
     */
    public function scopeAddons($query)
    {
        return $query->where('is_base_package', false);
    }

    /**
     * Get the limit for a specific feature in this package.
     */
    public function getFeatureLimit(string $featureCode): ?int
    {
        $feature = $this->features()->where('code', $featureCode)->first();

        if (! $feature) {
            return null;
        }

        return $feature->pivot->limit_value;
    }

    /**
     * Check if package includes a feature (regardless of limit).
     */
    public function hasFeature(string $featureCode): bool
    {
        return $this->features()->where('code', $featureCode)->exists();
    }

    // Pricing Helpers

    /**
     * Check if package is free.
     */
    public function isFree(): bool
    {
        return ($this->monthly_price ?? 0) == 0 && ($this->yearly_price ?? 0) == 0;
    }

    /**
     * Check if package has pricing set.
     */
    public function hasPricing(): bool
    {
        return $this->monthly_price !== null || $this->yearly_price !== null;
    }

    /**
     * Get price for a billing cycle.
     */
    public function getPrice(string $cycle = 'monthly'): float
    {
        return match ($cycle) {
            'yearly', 'annual' => (float) ($this->yearly_price ?? 0),
            default => (float) ($this->monthly_price ?? 0),
        };
    }

    /**
     * Get yearly savings compared to monthly.
     */
    public function getYearlySavings(): float
    {
        if (! $this->monthly_price || ! $this->yearly_price) {
            return 0;
        }

        $monthlyTotal = $this->monthly_price * 12;

        return max(0, $monthlyTotal - $this->yearly_price);
    }

    /**
     * Get yearly savings as percentage.
     */
    public function getYearlySavingsPercent(): int
    {
        if (! $this->monthly_price || ! $this->yearly_price) {
            return 0;
        }

        $monthlyTotal = $this->monthly_price * 12;
        if ($monthlyTotal == 0) {
            return 0;
        }

        return (int) round(($this->getYearlySavings() / $monthlyTotal) * 100);
    }

    /**
     * Get gateway price ID for a cycle.
     */
    public function getGatewayPriceId(string $gateway, string $cycle = 'monthly'): ?string
    {
        $field = match ($cycle) {
            'yearly', 'annual' => "{$gateway}_price_id_yearly",
            default => "{$gateway}_price_id_monthly",
        };

        return $this->{$field};
    }

    /**
     * Check if package has trial period.
     */
    public function hasTrial(): bool
    {
        return ($this->trial_days ?? 0) > 0;
    }

    /**
     * Check if package has setup fee.
     */
    public function hasSetupFee(): bool
    {
        return ($this->setup_fee ?? 0) > 0;
    }

    /**
     * Scope to packages with pricing (purchasable).
     */
    public function scopePurchasable($query)
    {
        return $query->where(function ($q) {
            $q->whereNotNull('monthly_price')
                ->orWhereNotNull('yearly_price');
        });
    }

    /**
     * Scope to free packages.
     */
    public function scopeFree($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('monthly_price')
                ->orWhere('monthly_price', 0);
        })->where(function ($q) {
            $q->whereNull('yearly_price')
                ->orWhere('yearly_price', 0);
        });
    }

    /**
     * Scope to order by sort_order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
