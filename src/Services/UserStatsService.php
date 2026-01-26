<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use Core\Mod\Tenant\Enums\UserTier;
use Core\Mod\Tenant\Models\User;
use Illuminate\Support\Facades\Cache;

class UserStatsService
{
    /**
     * Compute and cache stats for a user.
     */
    public function computeStats(User $user): array
    {
        $tier = $user->getTier();

        $stats = [
            'quotas' => $this->computeQuotas($user, $tier),
            'services' => $this->computeServiceStats($user),
            'activity' => $this->getRecentActivity($user),
        ];

        // Save to user record
        $user->cached_stats = $stats;
        $user->stats_computed_at = now();
        $user->save();

        return $stats;
    }

    /**
     * Get cached stats or compute fresh if stale (> 5 minutes).
     */
    public function getStats(User $user): array
    {
        // Return cached if fresh (computed within last 5 minutes)
        if ($user->stats_computed_at && $user->stats_computed_at->gt(now()->subMinutes(5))) {
            return $user->cached_stats ?? $this->getDefaultStats($user);
        }

        // For page loads, return cached data immediately and queue refresh
        if ($user->cached_stats) {
            // Queue background refresh
            dispatch(new \Core\Mod\Tenant\Jobs\ComputeUserStats($user->id))->onQueue('stats');

            return $user->cached_stats;
        }

        // No cached data - compute synchronously (first time only)
        return $this->computeStats($user);
    }

    /**
     * Get default stats structure for a user tier.
     */
    public function getDefaultStats(User $user): array
    {
        $tier = $user->getTier();

        return [
            'quotas' => $this->getTierLimits($tier),
            'services' => $this->getDefaultServiceStats(),
            'activity' => [],
        ];
    }

    /**
     * Compute actual quota usage for user.
     */
    protected function computeQuotas(User $user, UserTier $tier): array
    {
        $limits = $this->getTierLimits($tier);

        // Compute actual usage
        // Host Hub workspaces the user has access to (via pivot table)
        $workspaceCount = $user->hostWorkspaces()->count();
        $limits['workspaces']['used'] = $workspaceCount;

        // Social accounts across all workspaces
        // TODO: Implement when social accounts are linked
        // $socialAccountCount = ...

        // Scheduled posts
        // TODO: Implement when scheduled posts are linked
        // $scheduledPostCount = ...

        // Storage usage
        // TODO: Implement when media storage tracking is added
        // $storageUsed = ...

        return $limits;
    }

    /**
     * Get tier limits configuration.
     */
    protected function getTierLimits(UserTier $tier): array
    {
        return match ($tier) {
            UserTier::HADES => [
                'workspaces' => ['used' => 0, 'limit' => null, 'label' => 'Workspaces'],
                'social_accounts' => ['used' => 0, 'limit' => null, 'label' => 'Social Accounts'],
                'scheduled_posts' => ['used' => 0, 'limit' => null, 'label' => 'Scheduled Posts'],
                'storage' => ['used' => 0, 'limit' => null, 'label' => 'Storage (GB)'],
            ],
            UserTier::APOLLO => [
                'workspaces' => ['used' => 0, 'limit' => 5, 'label' => 'Workspaces'],
                'social_accounts' => ['used' => 0, 'limit' => 25, 'label' => 'Social Accounts'],
                'scheduled_posts' => ['used' => 0, 'limit' => 500, 'label' => 'Scheduled Posts'],
                'storage' => ['used' => 0, 'limit' => 10, 'label' => 'Storage (GB)'],
            ],
            default => [
                'workspaces' => ['used' => 0, 'limit' => 1, 'label' => 'Workspaces'],
                'social_accounts' => ['used' => 0, 'limit' => 5, 'label' => 'Social Accounts'],
                'scheduled_posts' => ['used' => 0, 'limit' => 50, 'label' => 'Scheduled Posts'],
                'storage' => ['used' => 0, 'limit' => 1, 'label' => 'Storage (GB)'],
            ],
        };
    }

    /**
     * Compute service stats for user.
     */
    protected function computeServiceStats(User $user): array
    {
        $services = [
            [
                'name' => 'SocialHost',
                'icon' => 'fa-share-nodes',
                'color' => 'bg-blue-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
            [
                'name' => 'BioHost',
                'icon' => 'fa-id-card',
                'color' => 'bg-violet-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
            [
                'name' => 'AnalyticsHost',
                'icon' => 'fa-chart-line',
                'color' => 'bg-green-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
            [
                'name' => 'TrustHost',
                'icon' => 'fa-shield-check',
                'color' => 'bg-amber-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
        ];

        // Check for active Host Hub workspaces (via pivot table)
        $workspaceCount = $user->hostWorkspaces()->count();

        if ($workspaceCount > 0) {
            // SocialHost - check for social accounts
            // TODO: Check social accounts when integration is complete
            $services[0]['status'] = 'active';
            $services[0]['stat'] = $workspaceCount.' workspace(s)';

            // BioHost - check for bio pages
            // TODO: Check for bio pages when implemented
        }

        return $services;
    }

    /**
     * Get default service stats.
     */
    protected function getDefaultServiceStats(): array
    {
        return [
            [
                'name' => 'SocialHost',
                'icon' => 'fa-share-nodes',
                'color' => 'bg-blue-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
            [
                'name' => 'BioHost',
                'icon' => 'fa-id-card',
                'color' => 'bg-violet-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
            [
                'name' => 'AnalyticsHost',
                'icon' => 'fa-chart-line',
                'color' => 'bg-green-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
            [
                'name' => 'TrustHost',
                'icon' => 'fa-shield-check',
                'color' => 'bg-amber-500',
                'status' => 'inactive',
                'stat' => 'Not configured',
            ],
        ];
    }

    /**
     * Get recent activity for user.
     */
    protected function getRecentActivity(User $user): array
    {
        // TODO: Implement actual activity logging
        // For now return empty - activities will be added when actions are performed
        return [];
    }

    /**
     * Get cached timezone list.
     */
    public static function getTimezoneList(): array
    {
        return Cache::remember('timezone_list', 86400, function () {
            $groups = [];
            $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);

            foreach ($timezones as $tz) {
                $parts = explode('/', $tz, 2);
                $group = $parts[0] ?? 'Other';
                $label = $parts[1] ?? $tz;

                if (! isset($groups[$group])) {
                    $groups[$group] = [];
                }

                $groups[$group][$tz] = str_replace('_', ' ', $label);
            }

            ksort($groups);
            foreach ($groups as &$items) {
                asort($items);
            }

            return $groups;
        });
    }

    /**
     * Get cached locale list.
     */
    public static function getLocaleList(): array
    {
        return Cache::remember('locale_list', 86400, function () {
            $locales = [
                'en-GB' => 'English (UK)',
                'en-US' => 'English (US)',
                'es' => 'Español',
                'fr' => 'Français',
                'de' => 'Deutsch',
                'it' => 'Italiano',
                'pt' => 'Português',
                'nl' => 'Nederlands',
                'pl' => 'Polski',
                'ru' => 'Русский',
                'ja' => '日本語',
                'zh' => '中文',
                'ko' => '한국어',
                'ar' => 'العربية',
            ];

            $result = [];
            foreach ($locales as $code => $name) {
                $result[] = ['long' => $code, 'name' => $name];
            }

            return $result;
        });
    }
}
