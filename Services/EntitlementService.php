<?php

namespace Core\Tenant\Services;

use Core\Tenant\Models\Boost;
use Core\Tenant\Models\EntitlementLog;
use Core\Tenant\Models\Feature;
use Core\Tenant\Models\Namespace_;
use Core\Tenant\Models\NamespacePackage;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\UsageRecord;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EntitlementService
{
    /**
     * Cache TTL in seconds.
     */
    protected const CACHE_TTL = 300; // 5 minutes

    /**
     * Check if a workspace can use a feature.
     */
    public function can(Workspace $workspace, string $featureCode, int $quantity = 1): EntitlementResult
    {
        $feature = $this->getFeature($featureCode);

        if (! $feature) {
            return EntitlementResult::denied(
                reason: "Feature '{$featureCode}' does not exist.",
                featureCode: $featureCode
            );
        }

        // Get the pool feature code (parent if hierarchical)
        $poolFeatureCode = $feature->getPoolFeatureCode();

        // Get total limit from all active packages + boosts
        $totalLimit = $this->getTotalLimit($workspace, $poolFeatureCode);

        if ($totalLimit === null) {
            // Feature not included in any package
            return EntitlementResult::denied(
                reason: "Your plan does not include {$feature->name}.",
                featureCode: $featureCode
            );
        }

        // Check for unlimited
        if ($totalLimit === -1) {
            return EntitlementResult::unlimited($featureCode);
        }

        // For boolean features, just check if enabled
        if ($feature->isBoolean()) {
            return EntitlementResult::allowed(featureCode: $featureCode);
        }

        // Get current usage
        $currentUsage = $this->getCurrentUsage($workspace, $poolFeatureCode, $feature);

        // Check if quantity would exceed limit
        if ($currentUsage + $quantity > $totalLimit) {
            return EntitlementResult::denied(
                reason: "You've reached your {$feature->name} limit ({$totalLimit}).",
                limit: $totalLimit,
                used: $currentUsage,
                featureCode: $featureCode
            );
        }

        return EntitlementResult::allowed(
            limit: $totalLimit,
            used: $currentUsage,
            featureCode: $featureCode
        );
    }

    /**
     * Check if a namespace can use a feature.
     *
     * Entitlement cascade:
     * 1. Check namespace-level packages first
     * 2. Fall back to workspace pool (if namespace has workspace context)
     * 3. Fall back to user tier (for user-owned namespaces without workspace)
     */
    public function canForNamespace(Namespace_ $namespace, string $featureCode, int $quantity = 1): EntitlementResult
    {
        $feature = $this->getFeature($featureCode);

        if (! $feature) {
            return EntitlementResult::denied(
                reason: "Feature '{$featureCode}' does not exist.",
                featureCode: $featureCode
            );
        }

        // Get the pool feature code (parent if hierarchical)
        $poolFeatureCode = $feature->getPoolFeatureCode();

        // Try namespace-level limit first
        $totalLimit = $this->getNamespaceTotalLimit($namespace, $poolFeatureCode);

        // If not found at namespace level, try workspace fallback
        if ($totalLimit === null && $namespace->workspace_id) {
            $workspace = $namespace->workspace;
            if ($workspace) {
                $totalLimit = $this->getTotalLimit($workspace, $poolFeatureCode);
            }
        }

        // If still not found, try user tier fallback for user-owned namespaces
        if ($totalLimit === null && $namespace->isOwnedByUser()) {
            $user = $namespace->getOwnerUser();
            if ($user) {
                // Check if user's tier includes this feature
                if ($feature->isBoolean()) {
                    $hasFeature = $user->hasFeature($featureCode);
                    if ($hasFeature) {
                        return EntitlementResult::allowed(featureCode: $featureCode);
                    }
                }
            }
        }

        if ($totalLimit === null) {
            return EntitlementResult::denied(
                reason: "Your plan does not include {$feature->name}.",
                featureCode: $featureCode
            );
        }

        // Check for unlimited
        if ($totalLimit === -1) {
            return EntitlementResult::unlimited($featureCode);
        }

        // For boolean features, just check if enabled
        if ($feature->isBoolean()) {
            return EntitlementResult::allowed(featureCode: $featureCode);
        }

        // Get current usage
        $currentUsage = $this->getNamespaceCurrentUsage($namespace, $poolFeatureCode, $feature);

        // Check if quantity would exceed limit
        if ($currentUsage + $quantity > $totalLimit) {
            return EntitlementResult::denied(
                reason: "You've reached your {$feature->name} limit ({$totalLimit}).",
                limit: $totalLimit,
                used: $currentUsage,
                featureCode: $featureCode
            );
        }

        return EntitlementResult::allowed(
            limit: $totalLimit,
            used: $currentUsage,
            featureCode: $featureCode
        );
    }

    /**
     * Record usage of a feature for a namespace.
     */
    public function recordNamespaceUsage(
        Namespace_ $namespace,
        string $featureCode,
        int $quantity = 1,
        ?User $user = null,
        ?array $metadata = null
    ): UsageRecord {
        $feature = $this->getFeature($featureCode);
        $poolFeatureCode = $feature?->getPoolFeatureCode() ?? $featureCode;

        $record = UsageRecord::create([
            'namespace_id' => $namespace->id,
            'workspace_id' => $namespace->workspace_id,
            'feature_code' => $poolFeatureCode,
            'quantity' => $quantity,
            'user_id' => $user?->id,
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);

        // Invalidate cache
        $this->invalidateNamespaceCache($namespace);

        return $record;
    }

    /**
     * Record usage of a feature.
     */
    public function recordUsage(
        Workspace $workspace,
        string $featureCode,
        int $quantity = 1,
        ?User $user = null,
        ?array $metadata = null
    ): UsageRecord {
        $feature = $this->getFeature($featureCode);
        $poolFeatureCode = $feature?->getPoolFeatureCode() ?? $featureCode;

        $record = UsageRecord::create([
            'workspace_id' => $workspace->id,
            'feature_code' => $poolFeatureCode,
            'quantity' => $quantity,
            'user_id' => $user?->id,
            'metadata' => $metadata,
            'recorded_at' => now(),
        ]);

        // Invalidate cache
        $this->invalidateCache($workspace);

        return $record;
    }

    /**
     * Provision a package for a workspace.
     */
    public function provisionPackage(
        Workspace $workspace,
        string $packageCode,
        array $options = []
    ): WorkspacePackage {
        $package = Package::where('code', $packageCode)->firstOrFail();

        // Check if this is a base package and workspace already has one
        if ($package->is_base_package) {
            $existingBase = $workspace->workspacePackages()
                ->whereHas('package', fn ($q) => $q->where('is_base_package', true))
                ->active()
                ->first();

            if ($existingBase) {
                // Cancel existing base package
                $existingBase->cancel(now());

                EntitlementLog::logPackageAction(
                    $workspace,
                    EntitlementLog::ACTION_PACKAGE_CANCELLED,
                    $existingBase,
                    source: $options['source'] ?? EntitlementLog::SOURCE_SYSTEM,
                    metadata: ['reason' => 'Replaced by new base package']
                );
            }
        }

        $workspacePackage = WorkspacePackage::create([
            'workspace_id' => $workspace->id,
            'package_id' => $package->id,
            'status' => WorkspacePackage::STATUS_ACTIVE,
            'starts_at' => $options['starts_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'billing_cycle_anchor' => $options['billing_cycle_anchor'] ?? now(),
            'blesta_service_id' => $options['blesta_service_id'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);

        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_PROVISIONED,
            $workspacePackage,
            source: $options['source'] ?? EntitlementLog::SOURCE_SYSTEM,
            newValues: $workspacePackage->toArray()
        );

        $this->invalidateCache($workspace);

        return $workspacePackage;
    }

    /**
     * Provision a boost for a workspace.
     */
    public function provisionBoost(
        Workspace $workspace,
        string $featureCode,
        array $options = []
    ): Boost {
        $boost = Boost::create([
            'workspace_id' => $workspace->id,
            'feature_code' => $featureCode,
            'boost_type' => $options['boost_type'] ?? Boost::BOOST_TYPE_ADD_LIMIT,
            'duration_type' => $options['duration_type'] ?? Boost::DURATION_CYCLE_BOUND,
            'limit_value' => $options['limit_value'] ?? null,
            'consumed_quantity' => 0,
            'status' => Boost::STATUS_ACTIVE,
            'starts_at' => $options['starts_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'blesta_addon_id' => $options['blesta_addon_id'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);

        EntitlementLog::logBoostAction(
            $workspace,
            EntitlementLog::ACTION_BOOST_PROVISIONED,
            $boost,
            source: $options['source'] ?? EntitlementLog::SOURCE_SYSTEM,
            newValues: $boost->toArray()
        );

        $this->invalidateCache($workspace);

        return $boost;
    }

    /**
     * Get usage summary for a workspace.
     */
    public function getUsageSummary(Workspace $workspace): Collection
    {
        $features = Feature::active()->orderBy('category')->orderBy('sort_order')->get();
        $summary = collect();

        foreach ($features as $feature) {
            $result = $this->can($workspace, $feature->code);

            $summary->push([
                'feature' => $feature,
                'code' => $feature->code,
                'name' => $feature->name,
                'category' => $feature->category,
                'type' => $feature->type,
                'allowed' => $result->isAllowed(),
                'limit' => $result->limit,
                'used' => $result->used,
                'remaining' => $result->remaining,
                'unlimited' => $result->isUnlimited(),
                'percentage' => $result->getUsagePercentage(),
                'near_limit' => $result->isNearLimit(),
            ]);
        }

        return $summary->groupBy('category');
    }

    /**
     * Get all active packages for a workspace.
     */
    public function getActivePackages(Workspace $workspace): Collection
    {
        return $workspace->workspacePackages()
            ->with('package.features')
            ->active()
            ->notExpired()
            ->get();
    }

    /**
     * Get all active boosts for a workspace.
     */
    public function getActiveBoosts(Workspace $workspace): Collection
    {
        return $workspace->boosts()
            ->usable()
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Suspend a workspace's packages (e.g. for non-payment).
     */
    public function suspendWorkspace(Workspace $workspace, ?string $source = null): void
    {
        $packages = $workspace->workspacePackages()->active()->get();

        foreach ($packages as $workspacePackage) {
            $workspacePackage->suspend();

            EntitlementLog::logPackageAction(
                $workspace,
                EntitlementLog::ACTION_PACKAGE_SUSPENDED,
                $workspacePackage,
                source: $source ?? EntitlementLog::SOURCE_SYSTEM
            );
        }

        $this->invalidateCache($workspace);
    }

    /**
     * Reactivate a workspace's packages.
     */
    public function reactivateWorkspace(Workspace $workspace, ?string $source = null): void
    {
        $packages = $workspace->workspacePackages()
            ->where('status', WorkspacePackage::STATUS_SUSPENDED)
            ->get();

        foreach ($packages as $workspacePackage) {
            $workspacePackage->reactivate();

            EntitlementLog::logPackageAction(
                $workspace,
                EntitlementLog::ACTION_PACKAGE_REACTIVATED,
                $workspacePackage,
                source: $source ?? EntitlementLog::SOURCE_SYSTEM
            );
        }

        $this->invalidateCache($workspace);
    }

    /**
     * Revoke a package from a workspace (e.g. subscription cancelled).
     */
    public function revokePackage(Workspace $workspace, string $packageCode, ?string $source = null): void
    {
        $workspacePackage = $workspace->workspacePackages()
            ->whereHas('package', fn ($q) => $q->where('code', $packageCode))
            ->active()
            ->first();

        if (! $workspacePackage) {
            return;
        }

        $workspacePackage->update([
            'status' => WorkspacePackage::STATUS_CANCELLED,
            'expires_at' => now(),
        ]);

        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_CANCELLED,
            $workspacePackage,
            source: $source ?? EntitlementLog::SOURCE_SYSTEM,
            metadata: ['reason' => 'Package revoked']
        );

        $this->invalidateCache($workspace);
    }

    /**
     * Get the total limit for a feature across all packages + boosts.
     *
     * Returns null if feature not included, -1 if unlimited.
     */
    protected function getTotalLimit(Workspace $workspace, string $featureCode): ?int
    {
        $cacheKey = "entitlement:{$workspace->id}:limit:{$featureCode}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($workspace, $featureCode) {
            $feature = $this->getFeature($featureCode);

            if (! $feature) {
                return null;
            }

            $totalLimit = 0;
            $hasFeature = false;

            // Sum limits from active packages
            $packages = $this->getActivePackages($workspace);

            foreach ($packages as $workspacePackage) {
                $packageFeature = $workspacePackage->package->features
                    ->where('code', $featureCode)
                    ->first();

                if ($packageFeature) {
                    $hasFeature = true;

                    // Check if unlimited in this package
                    if ($packageFeature->type === Feature::TYPE_UNLIMITED) {
                        return -1;
                    }

                    // Add limit value (null = boolean, no limit to add)
                    $limitValue = $packageFeature->pivot->limit_value;
                    if ($limitValue !== null) {
                        $totalLimit += $limitValue;
                    }
                }
            }

            // Add limits from active boosts
            $boosts = $workspace->boosts()
                ->forFeature($featureCode)
                ->usable()
                ->get();

            foreach ($boosts as $boost) {
                $hasFeature = true;

                if ($boost->boost_type === Boost::BOOST_TYPE_UNLIMITED) {
                    return -1;
                }

                if ($boost->boost_type === Boost::BOOST_TYPE_ADD_LIMIT) {
                    $remaining = $boost->getRemainingLimit();
                    if ($remaining !== null) {
                        $totalLimit += $remaining;
                    }
                }
            }

            return $hasFeature ? $totalLimit : null;
        });
    }

    /**
     * Get current usage for a feature.
     */
    protected function getCurrentUsage(Workspace $workspace, string $featureCode, Feature $feature): int
    {
        $cacheKey = "entitlement:{$workspace->id}:usage:{$featureCode}";

        return Cache::remember($cacheKey, 60, function () use ($workspace, $featureCode, $feature) {
            // Determine the time window for usage calculation
            if ($feature->resetsMonthly()) {
                // Get billing cycle anchor from the primary package
                $primaryPackage = $workspace->workspacePackages()
                    ->whereHas('package', fn ($q) => $q->where('is_base_package', true))
                    ->active()
                    ->first();

                $cycleStart = $primaryPackage
                    ? $primaryPackage->getCurrentCycleStart()
                    : now()->startOfMonth();

                return UsageRecord::getTotalUsage($workspace->id, $featureCode, $cycleStart);
            }

            if ($feature->resetsRolling()) {
                $days = $feature->rolling_window_days ?? 30;

                return UsageRecord::getRollingUsage($workspace->id, $featureCode, $days);
            }

            // No reset - all time usage
            return UsageRecord::getTotalUsage($workspace->id, $featureCode);
        });
    }

    /**
     * Get a feature by code.
     */
    protected function getFeature(string $code): ?Feature
    {
        return Cache::remember("feature:{$code}", self::CACHE_TTL, function () use ($code) {
            return Feature::where('code', $code)->first();
        });
    }

    /**
     * Invalidate all entitlement caches for a workspace.
     */
    public function invalidateCache(Workspace $workspace): void
    {
        // We can't easily clear pattern-based cache keys with all drivers,
        // so we use a version tag approach
        Cache::forget("entitlement:{$workspace->id}:version");
        Cache::increment("entitlement:{$workspace->id}:version");

        // For now, just clear specific known keys
        $features = Feature::pluck('code');
        foreach ($features as $code) {
            Cache::forget("entitlement:{$workspace->id}:limit:{$code}");
            Cache::forget("entitlement:{$workspace->id}:usage:{$code}");
        }
    }

    /**
     * Expire cycle-bound boosts at billing cycle end.
     */
    public function expireCycleBoundBoosts(Workspace $workspace): void
    {
        $boosts = $workspace->boosts()
            ->where('duration_type', Boost::DURATION_CYCLE_BOUND)
            ->where('status', Boost::STATUS_ACTIVE)
            ->get();

        foreach ($boosts as $boost) {
            $boost->expire();

            EntitlementLog::logBoostAction(
                $workspace,
                EntitlementLog::ACTION_BOOST_EXPIRED,
                $boost,
                source: EntitlementLog::SOURCE_SYSTEM,
                metadata: ['reason' => 'Billing cycle ended']
            );
        }

        $this->invalidateCache($workspace);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Namespace-specific methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the total limit for a feature from namespace-level packages + boosts.
     *
     * Returns null if feature not included, -1 if unlimited.
     */
    protected function getNamespaceTotalLimit(Namespace_ $namespace, string $featureCode): ?int
    {
        $cacheKey = "entitlement:ns:{$namespace->id}:limit:{$featureCode}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($namespace, $featureCode) {
            $feature = $this->getFeature($featureCode);

            if (! $feature) {
                return null;
            }

            $totalLimit = 0;
            $hasFeature = false;

            // Sum limits from active namespace packages
            $packages = $namespace->namespacePackages()
                ->with('package.features')
                ->active()
                ->notExpired()
                ->get();

            foreach ($packages as $namespacePackage) {
                $packageFeature = $namespacePackage->package->features
                    ->where('code', $featureCode)
                    ->first();

                if ($packageFeature) {
                    $hasFeature = true;

                    // Check if unlimited in this package
                    if ($packageFeature->type === Feature::TYPE_UNLIMITED) {
                        return -1;
                    }

                    // Add limit value (null = boolean, no limit to add)
                    $limitValue = $packageFeature->pivot->limit_value;
                    if ($limitValue !== null) {
                        $totalLimit += $limitValue;
                    }
                }
            }

            // Add limits from active namespace-level boosts
            $boosts = $namespace->boosts()
                ->forFeature($featureCode)
                ->usable()
                ->get();

            foreach ($boosts as $boost) {
                $hasFeature = true;

                if ($boost->boost_type === Boost::BOOST_TYPE_UNLIMITED) {
                    return -1;
                }

                if ($boost->boost_type === Boost::BOOST_TYPE_ADD_LIMIT) {
                    $remaining = $boost->getRemainingLimit();
                    if ($remaining !== null) {
                        $totalLimit += $remaining;
                    }
                }
            }

            return $hasFeature ? $totalLimit : null;
        });
    }

    /**
     * Get current usage for a feature at namespace level.
     */
    protected function getNamespaceCurrentUsage(Namespace_ $namespace, string $featureCode, Feature $feature): int
    {
        $cacheKey = "entitlement:ns:{$namespace->id}:usage:{$featureCode}";

        return Cache::remember($cacheKey, 60, function () use ($namespace, $featureCode, $feature) {
            // Determine the time window for usage calculation
            if ($feature->resetsMonthly()) {
                // Get billing cycle anchor from the primary package
                $primaryPackage = $namespace->namespacePackages()
                    ->whereHas('package', fn ($q) => $q->where('is_base_package', true))
                    ->active()
                    ->first();

                $cycleStart = $primaryPackage
                    ? $primaryPackage->getCurrentCycleStart()
                    : now()->startOfMonth();

                return UsageRecord::where('namespace_id', $namespace->id)
                    ->where('feature_code', $featureCode)
                    ->where('recorded_at', '>=', $cycleStart)
                    ->sum('quantity');
            }

            if ($feature->resetsRolling()) {
                $days = $feature->rolling_window_days ?? 30;
                $since = now()->subDays($days);

                return UsageRecord::where('namespace_id', $namespace->id)
                    ->where('feature_code', $featureCode)
                    ->where('recorded_at', '>=', $since)
                    ->sum('quantity');
            }

            // No reset - all time usage
            return UsageRecord::where('namespace_id', $namespace->id)
                ->where('feature_code', $featureCode)
                ->sum('quantity');
        });
    }

    /**
     * Get usage summary for a namespace.
     */
    public function getNamespaceUsageSummary(Namespace_ $namespace): Collection
    {
        $features = Feature::active()->orderBy('category')->orderBy('sort_order')->get();
        $summary = collect();

        foreach ($features as $feature) {
            $result = $this->canForNamespace($namespace, $feature->code);

            $summary->push([
                'feature' => $feature,
                'code' => $feature->code,
                'name' => $feature->name,
                'category' => $feature->category,
                'type' => $feature->type,
                'allowed' => $result->isAllowed(),
                'limit' => $result->limit,
                'used' => $result->used,
                'remaining' => $result->remaining,
                'unlimited' => $result->isUnlimited(),
                'percentage' => $result->getUsagePercentage(),
                'near_limit' => $result->isNearLimit(),
            ]);
        }

        return $summary->groupBy('category');
    }

    /**
     * Provision a package for a namespace.
     */
    public function provisionNamespacePackage(
        Namespace_ $namespace,
        string $packageCode,
        array $options = []
    ): NamespacePackage {
        $package = Package::where('code', $packageCode)->firstOrFail();

        // Check if this is a base package and namespace already has one
        if ($package->is_base_package) {
            $existingBase = $namespace->namespacePackages()
                ->whereHas('package', fn ($q) => $q->where('is_base_package', true))
                ->active()
                ->first();

            if ($existingBase) {
                // Cancel existing base package
                $existingBase->cancel(now());
            }
        }

        $namespacePackage = NamespacePackage::create([
            'namespace_id' => $namespace->id,
            'package_id' => $package->id,
            'status' => NamespacePackage::STATUS_ACTIVE,
            'starts_at' => $options['starts_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'billing_cycle_anchor' => $options['billing_cycle_anchor'] ?? now(),
            'metadata' => $options['metadata'] ?? null,
        ]);

        $this->invalidateNamespaceCache($namespace);

        return $namespacePackage;
    }

    /**
     * Provision a boost for a namespace.
     */
    public function provisionNamespaceBoost(
        Namespace_ $namespace,
        string $featureCode,
        array $options = []
    ): Boost {
        $boost = Boost::create([
            'namespace_id' => $namespace->id,
            'workspace_id' => $namespace->workspace_id,
            'feature_code' => $featureCode,
            'boost_type' => $options['boost_type'] ?? Boost::BOOST_TYPE_ADD_LIMIT,
            'duration_type' => $options['duration_type'] ?? Boost::DURATION_CYCLE_BOUND,
            'limit_value' => $options['limit_value'] ?? null,
            'consumed_quantity' => 0,
            'status' => Boost::STATUS_ACTIVE,
            'starts_at' => $options['starts_at'] ?? now(),
            'expires_at' => $options['expires_at'] ?? null,
            'metadata' => $options['metadata'] ?? null,
        ]);

        $this->invalidateNamespaceCache($namespace);

        return $boost;
    }

    /**
     * Invalidate all entitlement caches for a namespace.
     */
    public function invalidateNamespaceCache(Namespace_ $namespace): void
    {
        $features = Feature::pluck('code');
        foreach ($features as $code) {
            Cache::forget("entitlement:ns:{$namespace->id}:limit:{$code}");
            Cache::forget("entitlement:ns:{$namespace->id}:usage:{$code}");
        }
    }
}
