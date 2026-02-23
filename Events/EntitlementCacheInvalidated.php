<?php

declare(strict_types=1);

namespace Core\Tenant\Events;

use Core\Tenant\Models\Namespace_;
use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event dispatched when entitlement cache is invalidated.
 *
 * This event enables external systems to react to cache invalidation,
 * such as broadcasting updates to connected clients or triggering
 * downstream cache refreshes.
 *
 * ## Event Payload
 *
 * - `workspace`: The affected Workspace model (if workspace-level invalidation)
 * - `namespace`: The affected Namespace_ model (if namespace-level invalidation)
 * - `featureCodes`: Array of specific feature codes invalidated (empty = all features)
 * - `reason`: Human-readable reason for the invalidation
 *
 * ## Usage
 *
 * ```php
 * // Listen for cache invalidation events
 * Event::listen(EntitlementCacheInvalidated::class, function ($event) {
 *     if ($event->workspace) {
 *         broadcast(new EntitlementUpdated($event->workspace));
 *     }
 * });
 * ```
 */
class EntitlementCacheInvalidated
{
    use Dispatchable;

    /**
     * Reason constants for invalidation.
     */
    public const REASON_USAGE_RECORDED = 'usage_recorded';

    public const REASON_PACKAGE_PROVISIONED = 'package_provisioned';

    public const REASON_PACKAGE_SUSPENDED = 'package_suspended';

    public const REASON_PACKAGE_REACTIVATED = 'package_reactivated';

    public const REASON_PACKAGE_REVOKED = 'package_revoked';

    public const REASON_BOOST_PROVISIONED = 'boost_provisioned';

    public const REASON_BOOST_EXPIRED = 'boost_expired';

    public const REASON_MANUAL = 'manual';

    /**
     * Create a new event instance.
     *
     * @param  Workspace|null  $workspace  The affected workspace (null for namespace-only invalidation)
     * @param  Namespace_|null  $namespace  The affected namespace (null for workspace-only invalidation)
     * @param  array<string>  $featureCodes  Specific feature codes invalidated (empty = all features)
     * @param  string  $reason  The reason for invalidation
     */
    public function __construct(
        public readonly ?Workspace $workspace,
        public readonly ?Namespace_ $namespace,
        public readonly array $featureCodes,
        public readonly string $reason
    ) {}

    /**
     * Create an event for workspace cache invalidation.
     *
     * @param  Workspace  $workspace  The workspace whose cache was invalidated
     * @param  array<string>  $featureCodes  Specific feature codes (empty = all)
     * @param  string  $reason  The reason for invalidation
     */
    public static function forWorkspace(
        Workspace $workspace,
        array $featureCodes = [],
        string $reason = self::REASON_MANUAL
    ): self {
        return new self($workspace, null, $featureCodes, $reason);
    }

    /**
     * Create an event for namespace cache invalidation.
     *
     * @param  Namespace_  $namespace  The namespace whose cache was invalidated
     * @param  array<string>  $featureCodes  Specific feature codes (empty = all)
     * @param  string  $reason  The reason for invalidation
     */
    public static function forNamespace(
        Namespace_ $namespace,
        array $featureCodes = [],
        string $reason = self::REASON_MANUAL
    ): self {
        return new self(null, $namespace, $featureCodes, $reason);
    }

    /**
     * Check if this was a full cache flush (all features).
     */
    public function isFullFlush(): bool
    {
        return empty($this->featureCodes);
    }

    /**
     * Check if a specific feature was invalidated.
     */
    public function affectsFeature(string $featureCode): bool
    {
        return $this->isFullFlush() || in_array($featureCode, $this->featureCodes, true);
    }

    /**
     * Get the target identifier for logging.
     */
    public function getTargetIdentifier(): string
    {
        if ($this->workspace) {
            return "workspace:{$this->workspace->id}";
        }

        if ($this->namespace) {
            return "namespace:{$this->namespace->id}";
        }

        return 'unknown';
    }
}
