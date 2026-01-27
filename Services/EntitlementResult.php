<?php

namespace Core\Mod\Tenant\Services;

/**
 * Value object representing the result of an entitlement check.
 */
class EntitlementResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?int $limit = null,
        public readonly ?int $used = null,
        public readonly ?int $remaining = null,
        public readonly ?string $featureCode = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Create an allowed result.
     */
    public static function allowed(
        ?int $limit = null,
        ?int $used = null,
        ?string $featureCode = null,
        array $metadata = []
    ): self {
        return new self(
            allowed: true,
            limit: $limit,
            used: $used,
            remaining: $limit !== null ? max(0, $limit - ($used ?? 0)) : null,
            featureCode: $featureCode,
            metadata: $metadata,
        );
    }

    /**
     * Create a denied result.
     */
    public static function denied(
        string $reason,
        ?int $limit = null,
        ?int $used = null,
        ?string $featureCode = null,
        array $metadata = []
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            limit: $limit,
            used: $used,
            remaining: $limit !== null ? max(0, $limit - ($used ?? 0)) : null,
            featureCode: $featureCode,
            metadata: $metadata,
        );
    }

    /**
     * Create an unlimited result (feature has no limit).
     */
    public static function unlimited(?string $featureCode = null, array $metadata = []): self
    {
        return new self(
            allowed: true,
            featureCode: $featureCode,
            metadata: array_merge($metadata, ['unlimited' => true]),
        );
    }

    /**
     * Check if the request is allowed.
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Check if the request is denied.
     */
    public function isDenied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * Get the denial message.
     */
    public function getMessage(): ?string
    {
        return $this->reason;
    }

    /**
     * Check if this is an unlimited feature.
     */
    public function isUnlimited(): bool
    {
        return $this->metadata['unlimited'] ?? false;
    }

    /**
     * Get usage percentage (0-100).
     */
    public function getUsagePercentage(): ?float
    {
        if ($this->limit === null || $this->limit === 0) {
            return null;
        }

        return min(100, ($this->used ?? 0) / $this->limit * 100);
    }

    /**
     * Check if usage is near the limit (> 80%).
     */
    public function isNearLimit(): bool
    {
        $percentage = $this->getUsagePercentage();

        return $percentage !== null && $percentage >= 80;
    }

    /**
     * Check if usage is at the limit.
     */
    public function isAtLimit(): bool
    {
        return $this->remaining === 0;
    }

    /**
     * Get the limit value.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Get the used value.
     */
    public function getUsed(): ?int
    {
        return $this->used;
    }

    /**
     * Get the remaining value.
     */
    public function getRemaining(): ?int
    {
        return $this->remaining;
    }

    /**
     * Convert to array for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'limit' => $this->limit,
            'used' => $this->used,
            'remaining' => $this->remaining,
            'feature_code' => $this->featureCode,
            'unlimited' => $this->isUnlimited(),
            'usage_percentage' => $this->getUsagePercentage(),
        ];
    }
}
