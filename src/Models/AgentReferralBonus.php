<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentReferralBonus extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'model',
        'next_referral_guaranteed',
        'last_conversion_at',
        'total_conversions',
    ];

    protected $casts = [
        'next_referral_guaranteed' => 'boolean',
        'last_conversion_at' => 'datetime',
        'total_conversions' => 'integer',
    ];

    /**
     * Get or create a bonus record for a provider/model.
     */
    public static function getOrCreate(string $provider, ?string $model = null): self
    {
        return static::firstOrCreate(
            ['provider' => $provider, 'model' => $model],
            ['next_referral_guaranteed' => false, 'total_conversions' => 0]
        );
    }

    /**
     * Check if the next referral is guaranteed for a provider/model.
     */
    public static function hasGuaranteedReferral(string $provider, ?string $model = null): bool
    {
        $bonus = static::where('provider', $provider)
            ->where('model', $model)
            ->first();

        return $bonus?->next_referral_guaranteed ?? false;
    }

    /**
     * Grant a guaranteed next referral to a provider/model.
     */
    public static function grantGuaranteedReferral(string $provider, ?string $model = null): self
    {
        $bonus = static::getOrCreate($provider, $model);

        $bonus->update([
            'next_referral_guaranteed' => true,
            'last_conversion_at' => now(),
            'total_conversions' => $bonus->total_conversions + 1,
        ]);

        return $bonus;
    }

    /**
     * Consume the guaranteed referral for a provider/model.
     */
    public static function consumeGuaranteedReferral(string $provider, ?string $model = null): bool
    {
        $bonus = static::where('provider', $provider)
            ->where('model', $model)
            ->where('next_referral_guaranteed', true)
            ->first();

        if (! $bonus) {
            return false;
        }

        $bonus->update(['next_referral_guaranteed' => false]);

        return true;
    }

    /**
     * Scope to a specific provider.
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope to records with guaranteed next referral.
     */
    public function scopeGuaranteed(Builder $query): Builder
    {
        return $query->where('next_referral_guaranteed', true);
    }

    /**
     * Check if this bonus has a guaranteed next referral.
     */
    public function hasGuarantee(): bool
    {
        return $this->next_referral_guaranteed;
    }
}
