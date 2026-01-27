<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    use HasFactory;

    protected $table = 'entitlement_features';

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'type',
        'reset_type',
        'rolling_window_days',
        'parent_feature_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'rolling_window_days' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Feature types.
     */
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_LIMIT = 'limit';

    public const TYPE_UNLIMITED = 'unlimited';

    /**
     * Reset types.
     */
    public const RESET_NONE = 'none';

    public const RESET_MONTHLY = 'monthly';

    public const RESET_ROLLING = 'rolling';

    /**
     * Packages that include this feature.
     */
    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'entitlement_package_features', 'feature_id', 'package_id')
            ->withPivot('limit_value')
            ->withTimestamps();
    }

    /**
     * Parent feature (for hierarchical limits / global pools).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'parent_feature_id');
    }

    /**
     * Child features (allowances within a global pool).
     */
    public function children(): HasMany
    {
        return $this->hasMany(Feature::class, 'parent_feature_id');
    }

    /**
     * Scope to active features.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to features in a category.
     */
    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to root features (no parent).
     */
    public function scopeRoot($query)
    {
        return $query->whereNull('parent_feature_id');
    }

    /**
     * Check if this feature is a boolean toggle.
     */
    public function isBoolean(): bool
    {
        return $this->type === self::TYPE_BOOLEAN;
    }

    /**
     * Check if this feature has a usage limit.
     */
    public function hasLimit(): bool
    {
        return $this->type === self::TYPE_LIMIT;
    }

    /**
     * Check if this feature is unlimited.
     */
    public function isUnlimited(): bool
    {
        return $this->type === self::TYPE_UNLIMITED;
    }

    /**
     * Check if this feature resets monthly.
     */
    public function resetsMonthly(): bool
    {
        return $this->reset_type === self::RESET_MONTHLY;
    }

    /**
     * Check if this feature uses rolling window reset.
     */
    public function resetsRolling(): bool
    {
        return $this->reset_type === self::RESET_ROLLING;
    }

    /**
     * Check if this is a child feature (part of a global pool).
     */
    public function isChildFeature(): bool
    {
        return $this->parent_feature_id !== null;
    }

    /**
     * Get the global pool feature code (parent or self).
     */
    public function getPoolFeatureCode(): string
    {
        return $this->parent?->code ?? $this->code;
    }
}
