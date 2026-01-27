<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Features;

use Illuminate\Support\Lottery;

class BetaFeatures
{
    /**
     * New dashboard design.
     */
    public static function newDashboard(): bool
    {
        return false; // Enable when ready
    }

    /**
     * AI-powered content suggestions.
     */
    public static function aiSuggestions(): bool
    {
        return false;
    }

    /**
     * Real-time notifications via Reverb.
     */
    public static function realtimeNotifications(): bool
    {
        return true; // Enabled
    }

    /**
     * Advanced analytics dashboard.
     */
    public static function advancedAnalytics(): bool
    {
        return Lottery::odds(1, 10)->choose(); // 10% rollout
    }
}
