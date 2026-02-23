<?php

declare(strict_types=1);

namespace Core\Tenant\Services;

use Core\Tenant\Events\EntitlementCacheInvalidated;
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
use Illuminate\Cache\TaggableStore;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Core service for managing feature entitlements, usage tracking, and package provisioning.
 *
 * The EntitlementService is the primary API for checking whether workspaces or namespaces
 * have access to specific features, tracking their usage, and managing packages and boosts.
 * It supports a hierarchical entitlement model where namespaces can inherit entitlements
 * from their parent workspace or the owning user's tier.
 *
 * ## Key Concepts
 *
 * - **Features**: Capabilities that can be enabled or limited (e.g., 'pages', 'api_calls', 'custom_domains')
 * - **Packages**: Bundles of features with defined limits (e.g., 'starter', 'professional', 'enterprise')
 * - **Boosts**: Temporary or permanent additions to feature limits (e.g., promotional extras)
 * - **Usage Records**: Tracked consumption of limit-based features
 *
 * ## Feature Types
 *
 * Features can be one of three types:
 * - `boolean`: Either enabled or disabled (no quantity tracking)
 * - `limit`: Has a numeric cap that can be consumed (e.g., 10 pages, 1000 API calls)
 * - `unlimited`: Feature is available without any limits
 *
 * ## Entitlement Cascade (Namespaces)
 *
 * When checking namespace entitlements, the service follows this priority:
 * 1. Namespace-level packages and boosts (most specific)
 * 2. Workspace-level packages and boosts (if namespace has workspace context)
 * 3. User tier entitlements (for user-owned namespaces without workspace)
 *
 * ## Usage Examples
 *
 * ```php
 * // Check if a workspace can create a new page
 * $result = $entitlementService->can($workspace, 'pages');
 * if ($result->isDenied()) {
 *     throw new LimitExceededException($result->getMessage());
 * }
 *
 * // Record usage after creating the page
 * $entitlementService->recordUsage($workspace, 'pages', 1, $user);
 *
 * // Check remaining capacity
 * $result = $entitlementService->can($workspace, 'pages');
 * echo "Remaining pages: " . $result->getRemaining();
 *
 * // Get full usage summary for dashboard
 * $summary = $entitlementService->getUsageSummary($workspace);
 * ```
 *
 * ## Caching
 *
 * Entitlement checks and limits are cached for performance (5 minute TTL by default).
 * Cache is automatically invalidated when:
 * - Usage is recorded
 * - Packages are provisioned, suspended, or revoked
 * - Boosts are provisioned or expired
 *
 * @see EntitlementResult Value object returned by entitlement checks
 * @see Feature Model defining available features
 * @see Package Model defining feature bundles
 * @see Boost Model for temporary limit increases
 */
class EntitlementService
{
    /**
     * Cache TTL in seconds for entitlement data.
     *
     * Limits and feature availability are cached for this duration.
     * Usage data uses a shorter 60-second cache.
     */
    protected const CACHE_TTL = 300; // 5 minutes

    /**
     * Cache TTL in seconds for usage data.
     *
     * Usage data is more volatile and uses a shorter cache duration.
     */
    protected const USAGE_CACHE_TTL = 60;

    /**
     * Cache tag prefix for workspace entitlements.
     */
    protected const CACHE_TAG_WORKSPACE = 'entitlement:ws';

    /**
     * Cache tag prefix for namespace entitlements.
     */
    protected const CACHE_TAG_NAMESPACE = 'entitlement:ns';

    /**
     * Cache tag for limit data.
     */
    protected const CACHE_TAG_LIMITS = 'entitlement:limits';

    /**
     * Cache tag for usage data.
     */
    protected const CACHE_TAG_USAGE = 'entitlement:usage';

    /**
     * Check if the cache store supports tags.
     *
     * Cache tags enable O(1) invalidation instead of O(n) where n = feature count.
     * Supported by Redis and Memcached drivers.
     */
    protected function supportsCacheTags(): bool
    {
        try {
            return Cache::getStore() instanceof TaggableStore;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get cache tags for workspace entitlements.
     *
     * @param  Workspace  $workspace  The workspace
     * @param  string  $type  The cache type ('limit' or 'usage')
     * @return array<string> Cache tags
     */
    protected function getWorkspaceCacheTags(Workspace $workspace, string $type = 'limit'): array
    {
        $tags = [
            self::CACHE_TAG_WORKSPACE.':'.$workspace->id,
        ];

        if ($type === 'limit') {
            $tags[] = self::CACHE_TAG_LIMITS;
        } else {
            $tags[] = self::CACHE_TAG_USAGE;
        }

        return $tags;
    }

    /**
     * Get cache tags for namespace entitlements.
     *
     * @param  Namespace_  $namespace  The namespace
     * @param  string  $type  The cache type ('limit' or 'usage')
     * @return array<string> Cache tags
     */
    protected function getNamespaceCacheTags(Namespace_ $namespace, string $type = 'limit'): array
    {
        $tags = [
            self::CACHE_TAG_NAMESPACE.':'.$namespace->id,
        ];

        if ($type === 'limit') {
            $tags[] = self::CACHE_TAG_LIMITS;
        } else {
            $tags[] = self::CACHE_TAG_USAGE;
        }

        return $tags;
    }

    /**
     * Check if a workspace can use a feature.
     *
     * This is the primary method for checking workspace entitlements. It evaluates
     * whether the workspace has access to the specified feature and, for limit-based
     * features, whether sufficient capacity remains for the requested quantity.
     *
     * The method aggregates limits from:
     * - All active packages assigned to the workspace
     * - All active boosts for the specified feature
     *
     * For hierarchical features (e.g., 'pages.bio' under 'pages'), usage is pooled
     * at the parent feature level.
     *
     * ## Example Usage
     *
     * ```php
     * // Simple boolean check
     * $result = $entitlementService->can($workspace, 'custom_domains');
     * if ($result->isAllowed()) {
     *     // Feature is enabled
     * }
     *
     * // Check with quantity (e.g., bulk operations)
     * $result = $entitlementService->can($workspace, 'api_calls', quantity: 100);
     * if ($result->isDenied()) {
     *     return response()->json(['error' => $result->getMessage()], 403);
     * }
     *
     * // Access usage information
     * $result = $entitlementService->can($workspace, 'pages');
     * echo "Used: {$result->getUsed()} / {$result->getLimit()}";
     * echo "Remaining: {$result->getRemaining()}";
     * ```
     *
     * @param  Workspace  $workspace  The workspace to check entitlements for
     * @param  string  $featureCode  The feature code to check (e.g., 'pages', 'api_calls', 'custom_domains')
     * @param  int  $quantity  The quantity being requested (default: 1). For limit-based features,
     *                         checks if current usage plus this quantity exceeds the limit.
     * @return EntitlementResult Contains:
     *                           - `isAllowed()`: Whether the feature can be used
     *                           - `isDenied()`: Inverse of isAllowed
     *                           - `getMessage()`: Human-readable denial reason (if denied)
     *                           - `getLimit()`: Total limit from packages + boosts (null for boolean features)
     *                           - `getUsed()`: Current usage count (null for boolean features)
     *                           - `getRemaining()`: Remaining capacity (null for boolean features)
     *                           - `isUnlimited()`: Whether feature has no limit
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
     * Similar to `can()` but for namespace-scoped entitlement checks. This method
     * implements a cascading entitlement model that checks multiple levels to
     * determine feature access.
     *
     * ## Entitlement Cascade Priority
     *
     * 1. **Namespace-level packages/boosts** (highest priority)
     *    - Packages and boosts directly assigned to the namespace
     *
     * 2. **Workspace-level packages/boosts** (fallback)
     *    - If the namespace belongs to a workspace, inherits workspace entitlements
     *
     * 3. **User tier** (final fallback)
     *    - For user-owned namespaces without workspace context
     *    - Checks the owning user's subscription tier
     *
     * ## Example Usage
     *
     * ```php
     * // Check namespace entitlement
     * $result = $entitlementService->canForNamespace($namespace, 'links');
     * if ($result->isDenied()) {
     *     throw new LimitExceededException($result->getMessage());
     * }
     *
     * // Namespace inherits from workspace if no direct packages
     * $namespace = Namespace_::create(['workspace_id' => $workspace->id, ...]);
     * $result = $entitlementService->canForNamespace($namespace, 'pages');
     * // Uses workspace's 'pages' limit if namespace has no direct package
     * ```
     *
     * @param  Namespace_  $namespace  The namespace to check entitlements for
     * @param  string  $featureCode  The feature code to check
     * @param  int  $quantity  The quantity being requested (default: 1)
     * @return EntitlementResult Contains allowed status, limits, and usage information
     *
     * @see self::can() For workspace-level checks
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
     *
     * Creates a usage record for namespace-scoped feature consumption. Usage records
     * are used to track consumption against limits and determine remaining capacity.
     *
     * For hierarchical features, usage is automatically recorded against the pool
     * feature code (parent feature).
     *
     * ## Example Usage
     *
     * ```php
     * // Record a single link creation
     * $entitlementService->recordNamespaceUsage($namespace, 'links');
     *
     * // Record bulk operation with user attribution
     * $entitlementService->recordNamespaceUsage(
     *     $namespace,
     *     'api_calls',
     *     quantity: 50,
     *     user: $user
     * );
     *
     * // Record with metadata for audit trail
     * $entitlementService->recordNamespaceUsage(
     *     $namespace,
     *     'page_views',
     *     quantity: 1,
     *     metadata: ['page_id' => $page->id, 'referrer' => $referrer]
     * );
     * ```
     *
     * @param  Namespace_  $namespace  The namespace to record usage for
     * @param  string  $featureCode  The feature code being consumed
     * @param  int  $quantity  The amount to record (default: 1)
     * @param  User|null  $user  Optional user who triggered the usage (for attribution)
     * @param  array<string, mixed>|null  $metadata  Optional metadata for audit/debugging
     * @return UsageRecord The created usage record
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

        // Invalidate only usage cache for this feature (granular invalidation)
        $this->invalidateNamespaceUsageCache($namespace, $poolFeatureCode);

        return $record;
    }

    /**
     * Record usage of a feature for a workspace.
     *
     * Creates a usage record for workspace-scoped feature consumption. This method
     * should be called after successfully using a limited feature to track consumption
     * against the workspace's entitlement limits.
     *
     * Usage records support:
     * - **Monthly reset**: Tracked from billing cycle anchor date
     * - **Rolling window**: Tracked over a configurable number of days
     * - **Cumulative**: All-time usage (no reset)
     *
     * The reset behaviour is determined by the feature's configuration.
     *
     * ## Example Usage
     *
     * ```php
     * // Check entitlement first, then record usage
     * $result = $entitlementService->can($workspace, 'pages');
     * if ($result->isAllowed()) {
     *     $page = $workspace->pages()->create($data);
     *     $entitlementService->recordUsage($workspace, 'pages', 1, $user);
     * }
     *
     * // Record API call usage in middleware
     * $entitlementService->recordUsage(
     *     $workspace,
     *     'api_calls',
     *     quantity: 1,
     *     user: $request->user(),
     *     metadata: ['endpoint' => $request->path()]
     * );
     * ```
     *
     * @param  Workspace  $workspace  The workspace to record usage for
     * @param  string  $featureCode  The feature code being consumed
     * @param  int  $quantity  The amount to record (default: 1)
     * @param  User|null  $user  Optional user who triggered the usage
     * @param  array<string, mixed>|null  $metadata  Optional metadata for audit/debugging
     * @return UsageRecord The created usage record
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

        // Invalidate only usage cache for this feature (granular invalidation)
        $this->invalidateUsageCache($workspace, $poolFeatureCode);

        return $record;
    }

    /**
     * Provision a package for a workspace.
     *
     * Assigns a package to a workspace, granting access to all features defined
     * in that package. For base packages (primary subscription), any existing
     * base package is automatically cancelled before the new one is activated.
     *
     * This method is typically called by:
     * - Billing system webhooks (Stripe, Blesta)
     * - Admin provisioning tools
     * - Self-service upgrade flows
     *
     * ## Package Types
     *
     * - **Base packages** (`is_base_package = true`): Primary subscription tier.
     *   Only one base package can be active at a time per workspace.
     * - **Add-on packages**: Supplementary feature bundles that stack with base.
     *
     * ## Example Usage
     *
     * ```php
     * // Provision a subscription package
     * $workspacePackage = $entitlementService->provisionPackage(
     *     $workspace,
     *     'professional',
     *     [
     *         'source' => EntitlementLog::SOURCE_STRIPE,
     *         'blesta_service_id' => $blestaServiceId,
     *         'billing_cycle_anchor' => now(),
     *     ]
     * );
     *
     * // Provision a trial package with expiry
     * $entitlementService->provisionPackage(
     *     $workspace,
     *     'professional',
     *     [
     *         'expires_at' => now()->addDays(14),
     *         'metadata' => ['trial' => true],
     *     ]
     * );
     * ```
     *
     * @param  Workspace  $workspace  The workspace to provision the package for
     * @param  string  $packageCode  The unique code of the package to provision
     * @param array{
     *     source?: string,
     *     starts_at?: \DateTimeInterface,
     *     expires_at?: \DateTimeInterface|null,
     *     billing_cycle_anchor?: \DateTimeInterface,
     *     blesta_service_id?: string|null,
     *     metadata?: array<string, mixed>|null
     * } $options Provisioning options:
     *     - `source`: Origin of the provisioning (e.g., 'stripe', 'blesta', 'admin')
     *     - `starts_at`: When the package becomes active (default: now)
     *     - `expires_at`: When the package expires (null for indefinite)
     *     - `billing_cycle_anchor`: Date for monthly usage resets
     *     - `blesta_service_id`: External billing system reference
     *     - `metadata`: Additional data to store with the package
     * @return WorkspacePackage The created workspace package record
     *
     * @throws ModelNotFoundException If the package code does not exist
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

        $this->invalidateCache(
            $workspace,
            reason: EntitlementCacheInvalidated::REASON_PACKAGE_PROVISIONED
        );

        return $workspacePackage;
    }

    /**
     * Provision a boost for a workspace.
     *
     * Creates a boost that adds extra capacity or enables features for a workspace.
     * Boosts are useful for:
     * - Promotional extras ("Get 100 free API calls")
     * - Temporary upgrades
     * - One-time capacity additions
     * - Overage handling
     *
     * ## Boost Types
     *
     * - `add_limit`: Adds a fixed amount to the feature limit
     * - `unlimited`: Removes the limit entirely for the feature
     *
     * ## Duration Types
     *
     * - `cycle_bound`: Expires at the end of the billing cycle
     * - `fixed_duration`: Expires after a set time period
     * - `permanent`: Never expires (until manually removed)
     * - `consumable`: Active until the boosted quantity is consumed
     *
     * ## Example Usage
     *
     * ```php
     * // Add 1000 extra API calls for the billing cycle
     * $entitlementService->provisionBoost(
     *     $workspace,
     *     'api_calls',
     *     [
     *         'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
     *         'duration_type' => Boost::DURATION_CYCLE_BOUND,
     *         'limit_value' => 1000,
     *         'source' => EntitlementLog::SOURCE_PROMOTIONAL,
     *     ]
     * );
     *
     * // Grant unlimited pages for 30 days
     * $entitlementService->provisionBoost(
     *     $workspace,
     *     'pages',
     *     [
     *         'boost_type' => Boost::BOOST_TYPE_UNLIMITED,
     *         'duration_type' => Boost::DURATION_FIXED,
     *         'expires_at' => now()->addDays(30),
     *     ]
     * );
     * ```
     *
     * @param  Workspace  $workspace  The workspace to provision the boost for
     * @param  string  $featureCode  The feature code to boost
     * @param array{
     *     boost_type?: string,
     *     duration_type?: string,
     *     limit_value?: int|null,
     *     source?: string,
     *     starts_at?: \DateTimeInterface,
     *     expires_at?: \DateTimeInterface|null,
     *     blesta_addon_id?: string|null,
     *     metadata?: array<string, mixed>|null
     * } $options Boost options:
     *     - `boost_type`: Type of boost (default: 'add_limit')
     *     - `duration_type`: How the boost expires (default: 'cycle_bound')
     *     - `limit_value`: Amount to add for 'add_limit' type
     *     - `source`: Origin of the boost for audit logging
     *     - `starts_at`: When the boost becomes active (default: now)
     *     - `expires_at`: When the boost expires
     *     - `blesta_addon_id`: External billing reference
     *     - `metadata`: Additional data to store
     * @return Boost The created boost record
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

        $this->invalidateCache(
            $workspace,
            featureCodes: [$featureCode],
            reason: EntitlementCacheInvalidated::REASON_BOOST_PROVISIONED
        );

        return $boost;
    }

    /**
     * Get a comprehensive usage summary for a workspace.
     *
     * Returns detailed information about all features, including current usage,
     * limits, and status for each. Results are grouped by feature category for
     * easy display in dashboards and reports.
     *
     * ## Return Structure
     *
     * Returns a Collection grouped by category, where each item contains:
     * - `feature`: The Feature model instance
     * - `code`: Feature code
     * - `name`: Human-readable feature name
     * - `category`: Feature category
     * - `type`: Feature type (boolean/limit/unlimited)
     * - `allowed`: Whether the feature can be used
     * - `limit`: Total limit (null for boolean)
     * - `used`: Current usage (null for boolean)
     * - `remaining`: Remaining capacity
     * - `unlimited`: Whether feature has no limit
     * - `percentage`: Usage as percentage (0-100)
     * - `near_limit`: Whether usage exceeds 80%
     *
     * ## Example Usage
     *
     * ```php
     * $summary = $entitlementService->getUsageSummary($workspace);
     *
     * // Display by category
     * foreach ($summary as $category => $features) {
     *     echo "<h3>{$category}</h3>";
     *     foreach ($features as $feature) {
     *         if ($feature['near_limit']) {
     *             echo "⚠️ ";
     *         }
     *         echo "{$feature['name']}: {$feature['used']}/{$feature['limit']}";
     *     }
     * }
     * ```
     *
     * @param  Workspace  $workspace  The workspace to get the summary for
     * @return Collection<string, Collection<int, array{
     *     feature: Feature,
     *     code: string,
     *     name: string,
     *     category: string,
     *     type: string,
     *     allowed: bool,
     *     limit: int|null,
     *     used: int|null,
     *     remaining: int|null,
     *     unlimited: bool,
     *     percentage: float|null,
     *     near_limit: bool
     * }>> Usage summary grouped by feature category
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
     *
     * Returns a collection of WorkspacePackage models that are currently active
     * and not expired. Each package includes its associated Package model with
     * feature definitions eager-loaded.
     *
     * ## Example Usage
     *
     * ```php
     * $packages = $entitlementService->getActivePackages($workspace);
     *
     * foreach ($packages as $workspacePackage) {
     *     echo "Package: " . $workspacePackage->package->name;
     *     echo "Started: " . $workspacePackage->starts_at->format('Y-m-d');
     *
     *     // List included features
     *     foreach ($workspacePackage->package->features as $feature) {
     *         echo "- {$feature->name}: {$feature->pivot->limit_value}";
     *     }
     * }
     * ```
     *
     * @param  Workspace  $workspace  The workspace to get packages for
     * @return Collection<int, WorkspacePackage> Active workspace packages with
     *                                           Package and Feature relations loaded
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
     *
     * Returns a collection of usable Boost models ordered by expiry date.
     * "Usable" means the boost is active, not expired, and (for consumable boosts)
     * has remaining capacity.
     *
     * ## Example Usage
     *
     * ```php
     * $boosts = $entitlementService->getActiveBoosts($workspace);
     *
     * foreach ($boosts as $boost) {
     *     echo "Feature: {$boost->feature_code}";
     *     echo "Type: {$boost->boost_type}";
     *
     *     if ($boost->expires_at) {
     *         echo "Expires: " . $boost->expires_at->diffForHumans();
     *     }
     *
     *     if ($boost->boost_type === Boost::BOOST_TYPE_ADD_LIMIT) {
     *         echo "Remaining: " . $boost->getRemainingLimit();
     *     }
     * }
     * ```
     *
     * @param  Workspace  $workspace  The workspace to get boosts for
     * @return Collection<int, Boost> Active, usable boosts ordered by expiry (soonest first)
     */
    public function getActiveBoosts(Workspace $workspace): Collection
    {
        return $workspace->boosts()
            ->usable()
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Suspend a workspace's packages (e.g., for non-payment).
     *
     * Marks all active packages as suspended, effectively disabling feature access
     * until reactivated. Suspended workspaces typically lose access to premium
     * features but retain read access to their data.
     *
     * This method is typically called by:
     * - Billing system webhooks when payment fails
     * - Admin moderation actions
     * - Automated dunning processes
     *
     * Each package suspension is logged to the EntitlementLog for audit purposes.
     *
     * ## Example Usage
     *
     * ```php
     * // Suspend for non-payment (from Stripe webhook)
     * $entitlementService->suspendWorkspace(
     *     $workspace,
     *     EntitlementLog::SOURCE_STRIPE
     * );
     *
     * // Suspend for ToS violation (admin action)
     * $entitlementService->suspendWorkspace(
     *     $workspace,
     *     EntitlementLog::SOURCE_ADMIN
     * );
     * ```
     *
     * @param  Workspace  $workspace  The workspace to suspend
     * @param  string|null  $source  The source of the suspension for audit logging
     *                               (e.g., 'stripe', 'admin', 'system')
     *
     * @see self::reactivateWorkspace() To lift the suspension
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

        $this->invalidateCache(
            $workspace,
            reason: EntitlementCacheInvalidated::REASON_PACKAGE_SUSPENDED
        );
    }

    /**
     * Reactivate a workspace's suspended packages.
     *
     * Restores all suspended packages to active status, re-enabling feature access.
     * Only packages with 'suspended' status are affected; cancelled or expired
     * packages are not changed.
     *
     * This method is typically called by:
     * - Billing system webhooks when payment succeeds after suspension
     * - Admin actions to lift moderation suspensions
     *
     * Each package reactivation is logged to the EntitlementLog for audit purposes.
     *
     * ## Example Usage
     *
     * ```php
     * // Reactivate after successful payment
     * $entitlementService->reactivateWorkspace(
     *     $workspace,
     *     EntitlementLog::SOURCE_STRIPE
     * );
     * ```
     *
     * @param  Workspace  $workspace  The workspace to reactivate
     * @param  string|null  $source  The source of the reactivation for audit logging
     *
     * @see self::suspendWorkspace() To suspend packages
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

        $this->invalidateCache(
            $workspace,
            reason: EntitlementCacheInvalidated::REASON_PACKAGE_REACTIVATED
        );
    }

    /**
     * Revoke a package from a workspace (e.g., subscription cancelled).
     *
     * Immediately cancels the specified package, setting its status to 'cancelled'
     * and expiry to now. This removes the features granted by the package from
     * the workspace's entitlements.
     *
     * If the workspace does not have an active package with the specified code,
     * this method returns silently (no-op).
     *
     * This method is typically called by:
     * - Billing system webhooks when subscription is cancelled
     * - Admin actions to remove packages
     * - Self-service downgrade flows
     *
     * ## Example Usage
     *
     * ```php
     * // Cancel subscription from Stripe webhook
     * $entitlementService->revokePackage(
     *     $workspace,
     *     'professional',
     *     EntitlementLog::SOURCE_STRIPE
     * );
     *
     * // Admin removal
     * $entitlementService->revokePackage(
     *     $workspace,
     *     'add-on-analytics',
     *     EntitlementLog::SOURCE_ADMIN
     * );
     * ```
     *
     * @param  Workspace  $workspace  The workspace to revoke the package from
     * @param  string  $packageCode  The unique code of the package to revoke
     * @param  string|null  $source  The source of the revocation for audit logging
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

        $this->invalidateCache(
            $workspace,
            reason: EntitlementCacheInvalidated::REASON_PACKAGE_REVOKED
        );
    }

    /**
     * Get the total limit for a feature across all packages and boosts.
     *
     * Aggregates limits from all active packages and usable boosts to determine
     * the workspace's total capacity for a feature. This is an internal method
     * used by `can()` and is cached for performance.
     *
     * @param  Workspace  $workspace  The workspace to calculate limits for
     * @param  string  $featureCode  The feature code to get the limit for
     * @return int|null Returns:
     *                  - `null` if the feature is not included in any package
     *                  - `-1` if the feature is unlimited
     *                  - A positive integer representing the total limit
     */
    protected function getTotalLimit(Workspace $workspace, string $featureCode): ?int
    {
        $cacheKey = "entitlement:{$workspace->id}:limit:{$featureCode}";
        $callback = function () use ($workspace, $featureCode) {
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
        };

        // Use tagged cache if available for O(1) invalidation
        if ($this->supportsCacheTags()) {
            return Cache::tags($this->getWorkspaceCacheTags($workspace, 'limit'))
                ->remember($cacheKey, self::CACHE_TTL, $callback);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, $callback);
    }

    /**
     * Get the current usage for a feature.
     *
     * Calculates the current consumption of a feature based on usage records.
     * The time window for calculation depends on the feature's reset configuration:
     * - Monthly: From billing cycle anchor to now
     * - Rolling: Over the configured rolling window (e.g., last 30 days)
     * - None: All-time cumulative usage
     *
     * Results are cached for 60 seconds to reduce database load.
     *
     * @param  Workspace  $workspace  The workspace to get usage for
     * @param  string  $featureCode  The feature code to get usage for
     * @param  Feature  $feature  The feature model (for reset configuration)
     * @return int The current usage count
     */
    protected function getCurrentUsage(Workspace $workspace, string $featureCode, Feature $feature): int
    {
        $cacheKey = "entitlement:{$workspace->id}:usage:{$featureCode}";

        $callback = function () use ($workspace, $featureCode, $feature) {
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
        };

        // Use tagged cache if available for O(1) invalidation
        if ($this->supportsCacheTags()) {
            return Cache::tags($this->getWorkspaceCacheTags($workspace, 'usage'))
                ->remember($cacheKey, self::USAGE_CACHE_TTL, $callback);
        }

        return Cache::remember($cacheKey, self::USAGE_CACHE_TTL, $callback);
    }

    /**
     * Get a feature by its unique code.
     *
     * Retrieves the Feature model from the database, with results cached
     * for the standard cache TTL (5 minutes).
     *
     * @param  string  $code  The unique feature code (e.g., 'pages', 'api_calls')
     * @return Feature|null The feature model, or null if not found
     */
    protected function getFeature(string $code): ?Feature
    {
        return Cache::remember("feature:{$code}", self::CACHE_TTL, function () use ($code) {
            return Feature::where('code', $code)->first();
        });
    }

    /**
     * Invalidate all entitlement caches for a workspace.
     *
     * Clears all cached limit and usage data for the workspace, forcing fresh
     * calculations on the next entitlement check. This is called automatically
     * when packages, boosts, or usage records change.
     *
     * ## Performance
     *
     * When cache tags are supported (Redis, Memcached), this is an O(1) operation.
     * For other cache drivers, falls back to O(n) iteration where n = feature count.
     *
     * ## When Called Automatically
     *
     * - After `recordUsage()` or `recordNamespaceUsage()`
     * - After `provisionPackage()` or `provisionBoost()`
     * - After `suspendWorkspace()` or `reactivateWorkspace()`
     * - After `revokePackage()`
     * - After `expireCycleBoundBoosts()`
     *
     * ## Manual Usage
     *
     * Call this method manually if you modify entitlement-related data directly
     * (outside of this service) to ensure consistency.
     *
     * ```php
     * // After manually modifying a package
     * $workspacePackage->update(['expires_at' => now()]);
     * $entitlementService->invalidateCache($workspace);
     * ```
     *
     * @param  Workspace  $workspace  The workspace to invalidate caches for
     * @param  array<string>  $featureCodes  Specific features to invalidate (empty = all)
     * @param  string  $reason  The reason for invalidation (for event dispatch)
     */
    public function invalidateCache(
        Workspace $workspace,
        array $featureCodes = [],
        string $reason = EntitlementCacheInvalidated::REASON_MANUAL
    ): void {
        // Use cache tags if available for O(1) invalidation
        if ($this->supportsCacheTags()) {
            $this->invalidateCacheWithTags($workspace, $featureCodes);
        } else {
            $this->invalidateCacheWithoutTags($workspace, $featureCodes);
        }

        // Dispatch event for external listeners
        EntitlementCacheInvalidated::dispatch(
            $workspace,
            null,
            $featureCodes,
            $reason
        );
    }

    /**
     * Invalidate cache using cache tags (O(1) operation).
     *
     * @param  Workspace  $workspace  The workspace to invalidate
     * @param  array<string>  $featureCodes  Specific features (empty = all)
     */
    protected function invalidateCacheWithTags(Workspace $workspace, array $featureCodes = []): void
    {
        $workspaceTag = self::CACHE_TAG_WORKSPACE.':'.$workspace->id;

        if (empty($featureCodes)) {
            // Flush all cache for this workspace - O(1) with tags
            Cache::tags([$workspaceTag])->flush();

            return;
        }

        // Granular invalidation for specific features
        foreach ($featureCodes as $featureCode) {
            $limitKey = "entitlement:{$workspace->id}:limit:{$featureCode}";
            $usageKey = "entitlement:{$workspace->id}:usage:{$featureCode}";

            Cache::tags([$workspaceTag, self::CACHE_TAG_LIMITS])->forget($limitKey);
            Cache::tags([$workspaceTag, self::CACHE_TAG_USAGE])->forget($usageKey);
        }
    }

    /**
     * Invalidate cache without tags (fallback for non-taggable stores).
     *
     * This is O(n) where n = number of features when no specific features
     * are provided.
     *
     * @param  Workspace  $workspace  The workspace to invalidate
     * @param  array<string>  $featureCodes  Specific features (empty = all)
     */
    protected function invalidateCacheWithoutTags(Workspace $workspace, array $featureCodes = []): void
    {
        // Determine which features to clear
        $codesToClear = empty($featureCodes)
            ? Feature::pluck('code')->all()
            : $featureCodes;

        foreach ($codesToClear as $code) {
            Cache::forget("entitlement:{$workspace->id}:limit:{$code}");
            Cache::forget("entitlement:{$workspace->id}:usage:{$code}");
        }
    }

    /**
     * Invalidate only usage cache for a workspace (limits remain cached).
     *
     * Use this for performance when only usage has changed (e.g., after recording
     * usage) and limits are known to be unchanged.
     *
     * @param  Workspace  $workspace  The workspace to invalidate usage cache for
     * @param  string  $featureCode  The specific feature code to invalidate
     */
    public function invalidateUsageCache(Workspace $workspace, string $featureCode): void
    {
        $cacheKey = "entitlement:{$workspace->id}:usage:{$featureCode}";

        if ($this->supportsCacheTags()) {
            Cache::tags($this->getWorkspaceCacheTags($workspace, 'usage'))->forget($cacheKey);
        } else {
            Cache::forget($cacheKey);
        }

        // Dispatch granular event
        EntitlementCacheInvalidated::dispatch(
            $workspace,
            null,
            [$featureCode],
            EntitlementCacheInvalidated::REASON_USAGE_RECORDED
        );
    }

    /**
     * Invalidate only limit cache for a workspace (usage remains cached).
     *
     * Use this for performance when only limits have changed (e.g., after
     * provisioning a package or boost) and usage data is unchanged.
     *
     * @param  Workspace  $workspace  The workspace to invalidate limit cache for
     * @param  array<string>  $featureCodes  Specific features (empty = all limit caches)
     */
    public function invalidateLimitCache(Workspace $workspace, array $featureCodes = []): void
    {
        $codesToClear = empty($featureCodes)
            ? Feature::pluck('code')->all()
            : $featureCodes;

        if ($this->supportsCacheTags()) {
            $workspaceTag = self::CACHE_TAG_WORKSPACE.':'.$workspace->id;

            if (empty($featureCodes)) {
                // Flush all limit caches for this workspace
                Cache::tags([$workspaceTag, self::CACHE_TAG_LIMITS])->flush();
            } else {
                foreach ($codesToClear as $code) {
                    $cacheKey = "entitlement:{$workspace->id}:limit:{$code}";
                    Cache::tags([$workspaceTag, self::CACHE_TAG_LIMITS])->forget($cacheKey);
                }
            }
        } else {
            foreach ($codesToClear as $code) {
                Cache::forget("entitlement:{$workspace->id}:limit:{$code}");
            }
        }
    }

    /**
     * Expire cycle-bound boosts at billing cycle end.
     *
     * Marks all active boosts with `duration_type = 'cycle_bound'` as expired.
     * This should be called at the start of a new billing cycle to clean up
     * promotional or cycle-specific boosts.
     *
     * Each boost expiration is logged to the EntitlementLog for audit purposes.
     *
     * ## Typical Usage
     *
     * Called from a scheduled job or billing webhook when the billing cycle resets:
     *
     * ```php
     * // In a scheduled command or Stripe webhook handler
     * public function handle(Workspace $workspace): void
     * {
     *     // Expire old cycle-bound boosts
     *     $this->entitlementService->expireCycleBoundBoosts($workspace);
     *
     *     // Reset usage counters (handled separately by UsageRecord)
     *     // The billing cycle anchor determines the new period
     * }
     * ```
     *
     * @param  Workspace  $workspace  The workspace to expire boosts for
     */
    public function expireCycleBoundBoosts(Workspace $workspace): void
    {
        $boosts = $workspace->boosts()
            ->where('duration_type', Boost::DURATION_CYCLE_BOUND)
            ->where('status', Boost::STATUS_ACTIVE)
            ->get();

        $expiredFeatureCodes = [];

        foreach ($boosts as $boost) {
            $boost->expire();
            $expiredFeatureCodes[] = $boost->feature_code;

            EntitlementLog::logBoostAction(
                $workspace,
                EntitlementLog::ACTION_BOOST_EXPIRED,
                $boost,
                source: EntitlementLog::SOURCE_SYSTEM,
                metadata: ['reason' => 'Billing cycle ended']
            );
        }

        // Only invalidate cache for affected features
        if (! empty($expiredFeatureCodes)) {
            $this->invalidateCache(
                $workspace,
                featureCodes: array_unique($expiredFeatureCodes),
                reason: EntitlementCacheInvalidated::REASON_BOOST_EXPIRED
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Namespace-specific methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the total limit for a feature from namespace-level packages and boosts.
     *
     * Similar to `getTotalLimit()` but scoped to namespace-level packages only.
     * Does not include workspace-level entitlements (that cascade is handled
     * by `canForNamespace()`).
     *
     * @param  Namespace_  $namespace  The namespace to calculate limits for
     * @param  string  $featureCode  The feature code to get the limit for
     * @return int|null Returns:
     *                  - `null` if the feature is not included in any namespace package
     *                  - `-1` if the feature is unlimited
     *                  - A positive integer representing the total limit
     */
    protected function getNamespaceTotalLimit(Namespace_ $namespace, string $featureCode): ?int
    {
        $cacheKey = "entitlement:ns:{$namespace->id}:limit:{$featureCode}";

        $callback = function () use ($namespace, $featureCode) {
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
        };

        // Use tagged cache if available for O(1) invalidation
        if ($this->supportsCacheTags()) {
            return Cache::tags($this->getNamespaceCacheTags($namespace, 'limit'))
                ->remember($cacheKey, self::CACHE_TTL, $callback);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, $callback);
    }

    /**
     * Get current usage for a feature at namespace level.
     *
     * Similar to `getCurrentUsage()` but scoped to namespace-level usage records.
     * The time window for calculation follows the same rules based on feature
     * reset configuration.
     *
     * @param  Namespace_  $namespace  The namespace to get usage for
     * @param  string  $featureCode  The feature code to get usage for
     * @param  Feature  $feature  The feature model (for reset configuration)
     * @return int The current usage count for the namespace
     */
    protected function getNamespaceCurrentUsage(Namespace_ $namespace, string $featureCode, Feature $feature): int
    {
        $cacheKey = "entitlement:ns:{$namespace->id}:usage:{$featureCode}";

        $callback = function () use ($namespace, $featureCode, $feature) {
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
        };

        // Use tagged cache if available for O(1) invalidation
        if ($this->supportsCacheTags()) {
            return Cache::tags($this->getNamespaceCacheTags($namespace, 'usage'))
                ->remember($cacheKey, self::USAGE_CACHE_TTL, $callback);
        }

        return Cache::remember($cacheKey, self::USAGE_CACHE_TTL, $callback);
    }

    /**
     * Get a comprehensive usage summary for a namespace.
     *
     * Similar to `getUsageSummary()` but for namespace-scoped entitlements.
     * Uses the entitlement cascade (namespace -> workspace -> user tier) to
     * determine effective limits for each feature.
     *
     * ## Example Usage
     *
     * ```php
     * $summary = $entitlementService->getNamespaceUsageSummary($namespace);
     *
     * // Check if namespace is approaching limits
     * $linksFeature = $summary->flatten(1)->firstWhere('code', 'links');
     * if ($linksFeature['near_limit']) {
     *     // Show upgrade prompt
     * }
     * ```
     *
     * @param  Namespace_  $namespace  The namespace to get the summary for
     * @return Collection<string, Collection<int, array{
     *     feature: Feature,
     *     code: string,
     *     name: string,
     *     category: string,
     *     type: string,
     *     allowed: bool,
     *     limit: int|null,
     *     used: int|null,
     *     remaining: int|null,
     *     unlimited: bool,
     *     percentage: float|null,
     *     near_limit: bool
     * }>> Usage summary grouped by feature category
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
     *
     * Assigns a package to a namespace, granting access to all features defined
     * in that package. For base packages (primary subscription), any existing
     * base package is automatically cancelled before the new one is activated.
     *
     * Namespace packages take precedence over workspace packages in entitlement
     * checks, allowing individual namespaces to have different feature levels
     * than their parent workspace.
     *
     * ## Package Types
     *
     * - **Base packages** (`is_base_package = true`): Primary subscription tier.
     *   Only one base package can be active at a time per namespace.
     * - **Add-on packages**: Supplementary feature bundles that stack with base.
     *
     * ## Example Usage
     *
     * ```php
     * // Provision a subscription package for a namespace
     * $namespacePackage = $entitlementService->provisionNamespacePackage(
     *     $namespace,
     *     'bio-pro',
     *     [
     *         'billing_cycle_anchor' => now(),
     *         'metadata' => ['upgraded_from' => 'bio-free'],
     *     ]
     * );
     *
     * // Provision a trial package with expiry
     * $entitlementService->provisionNamespacePackage(
     *     $namespace,
     *     'bio-pro',
     *     [
     *         'expires_at' => now()->addDays(14),
     *         'metadata' => ['trial' => true],
     *     ]
     * );
     * ```
     *
     * @param  Namespace_  $namespace  The namespace to provision the package for
     * @param  string  $packageCode  The unique code of the package to provision
     * @param array{
     *     starts_at?: \DateTimeInterface,
     *     expires_at?: \DateTimeInterface|null,
     *     billing_cycle_anchor?: \DateTimeInterface,
     *     metadata?: array<string, mixed>|null
     * } $options Provisioning options:
     *     - `starts_at`: When the package becomes active (default: now)
     *     - `expires_at`: When the package expires (null for indefinite)
     *     - `billing_cycle_anchor`: Date for monthly usage resets
     *     - `metadata`: Additional data to store with the package
     * @return NamespacePackage The created namespace package record
     *
     * @throws ModelNotFoundException If the package code does not exist
     *
     * @see self::provisionPackage() For workspace-level package provisioning
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
     *
     * Creates a boost that adds extra capacity or enables features for a namespace.
     * Namespace boosts take precedence over workspace boosts in entitlement checks,
     * allowing targeted capacity increases for specific namespaces.
     *
     * ## Boost Types
     *
     * - `add_limit`: Adds a fixed amount to the feature limit
     * - `unlimited`: Removes the limit entirely for the feature
     *
     * ## Duration Types
     *
     * - `cycle_bound`: Expires at the end of the billing cycle
     * - `fixed_duration`: Expires after a set time period
     * - `permanent`: Never expires (until manually removed)
     * - `consumable`: Active until the boosted quantity is consumed
     *
     * ## Example Usage
     *
     * ```php
     * // Add 100 extra links for a bio namespace
     * $entitlementService->provisionNamespaceBoost(
     *     $namespace,
     *     'links',
     *     [
     *         'boost_type' => Boost::BOOST_TYPE_ADD_LIMIT,
     *         'duration_type' => Boost::DURATION_PERMANENT,
     *         'limit_value' => 100,
     *         'metadata' => ['reason' => 'Promotional giveaway'],
     *     ]
     * );
     *
     * // Grant unlimited page views for 7 days
     * $entitlementService->provisionNamespaceBoost(
     *     $namespace,
     *     'page_views',
     *     [
     *         'boost_type' => Boost::BOOST_TYPE_UNLIMITED,
     *         'duration_type' => Boost::DURATION_FIXED,
     *         'expires_at' => now()->addDays(7),
     *     ]
     * );
     * ```
     *
     * @param  Namespace_  $namespace  The namespace to provision the boost for
     * @param  string  $featureCode  The feature code to boost
     * @param array{
     *     boost_type?: string,
     *     duration_type?: string,
     *     limit_value?: int|null,
     *     starts_at?: \DateTimeInterface,
     *     expires_at?: \DateTimeInterface|null,
     *     metadata?: array<string, mixed>|null
     * } $options Boost options:
     *     - `boost_type`: Type of boost (default: 'add_limit')
     *     - `duration_type`: How the boost expires (default: 'cycle_bound')
     *     - `limit_value`: Amount to add for 'add_limit' type
     *     - `starts_at`: When the boost becomes active (default: now)
     *     - `expires_at`: When the boost expires
     *     - `metadata`: Additional data to store
     * @return Boost The created boost record
     *
     * @see self::provisionBoost() For workspace-level boost provisioning
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
     *
     * Clears all cached limit and usage data for the namespace, forcing fresh
     * calculations on the next entitlement check. This is called automatically
     * when namespace packages, boosts, or usage records change.
     *
     * ## Performance
     *
     * When cache tags are supported (Redis, Memcached), this is an O(1) operation.
     * For other cache drivers, falls back to O(n) iteration where n = feature count.
     *
     * ## When Called Automatically
     *
     * - After `recordNamespaceUsage()`
     * - After `provisionNamespacePackage()`
     * - After `provisionNamespaceBoost()`
     *
     * ## Manual Usage
     *
     * Call this method manually if you modify namespace entitlement-related data
     * directly (outside of this service) to ensure consistency.
     *
     * ```php
     * // After manually modifying a namespace package
     * $namespacePackage->update(['expires_at' => now()]);
     * $entitlementService->invalidateNamespaceCache($namespace);
     * ```
     *
     * @param  Namespace_  $namespace  The namespace to invalidate caches for
     * @param  array<string>  $featureCodes  Specific features to invalidate (empty = all)
     * @param  string  $reason  The reason for invalidation (for event dispatch)
     *
     * @see self::invalidateCache() For workspace-level cache invalidation
     */
    public function invalidateNamespaceCache(
        Namespace_ $namespace,
        array $featureCodes = [],
        string $reason = EntitlementCacheInvalidated::REASON_MANUAL
    ): void {
        // Use cache tags if available for O(1) invalidation
        if ($this->supportsCacheTags()) {
            $this->invalidateNamespaceCacheWithTags($namespace, $featureCodes);
        } else {
            $this->invalidateNamespaceCacheWithoutTags($namespace, $featureCodes);
        }

        // Dispatch event for external listeners
        EntitlementCacheInvalidated::dispatch(
            null,
            $namespace,
            $featureCodes,
            $reason
        );
    }

    /**
     * Invalidate namespace cache using cache tags (O(1) operation).
     *
     * @param  Namespace_  $namespace  The namespace to invalidate
     * @param  array<string>  $featureCodes  Specific features (empty = all)
     */
    protected function invalidateNamespaceCacheWithTags(Namespace_ $namespace, array $featureCodes = []): void
    {
        $namespaceTag = self::CACHE_TAG_NAMESPACE.':'.$namespace->id;

        if (empty($featureCodes)) {
            // Flush all cache for this namespace - O(1) with tags
            Cache::tags([$namespaceTag])->flush();

            return;
        }

        // Granular invalidation for specific features
        foreach ($featureCodes as $featureCode) {
            $limitKey = "entitlement:ns:{$namespace->id}:limit:{$featureCode}";
            $usageKey = "entitlement:ns:{$namespace->id}:usage:{$featureCode}";

            Cache::tags([$namespaceTag, self::CACHE_TAG_LIMITS])->forget($limitKey);
            Cache::tags([$namespaceTag, self::CACHE_TAG_USAGE])->forget($usageKey);
        }
    }

    /**
     * Invalidate namespace cache without tags (fallback for non-taggable stores).
     *
     * This is O(n) where n = number of features when no specific features
     * are provided.
     *
     * @param  Namespace_  $namespace  The namespace to invalidate
     * @param  array<string>  $featureCodes  Specific features (empty = all)
     */
    protected function invalidateNamespaceCacheWithoutTags(Namespace_ $namespace, array $featureCodes = []): void
    {
        // Determine which features to clear
        $codesToClear = empty($featureCodes)
            ? Feature::pluck('code')->all()
            : $featureCodes;

        foreach ($codesToClear as $code) {
            Cache::forget("entitlement:ns:{$namespace->id}:limit:{$code}");
            Cache::forget("entitlement:ns:{$namespace->id}:usage:{$code}");
        }
    }

    /**
     * Invalidate only usage cache for a namespace (limits remain cached).
     *
     * Use this for performance when only usage has changed (e.g., after recording
     * usage) and limits are known to be unchanged.
     *
     * @param  Namespace_  $namespace  The namespace to invalidate usage cache for
     * @param  string  $featureCode  The specific feature code to invalidate
     */
    public function invalidateNamespaceUsageCache(Namespace_ $namespace, string $featureCode): void
    {
        $cacheKey = "entitlement:ns:{$namespace->id}:usage:{$featureCode}";

        if ($this->supportsCacheTags()) {
            Cache::tags($this->getNamespaceCacheTags($namespace, 'usage'))->forget($cacheKey);
        } else {
            Cache::forget($cacheKey);
        }

        // Dispatch granular event
        EntitlementCacheInvalidated::dispatch(
            null,
            $namespace,
            [$featureCode],
            EntitlementCacheInvalidated::REASON_USAGE_RECORDED
        );
    }
}
