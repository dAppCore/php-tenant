<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Events\Webhook;

use Core\Mod\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Mod\Tenant\Models\Boost;
use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Workspace;

/**
 * Event fired when a boost expires for a workspace.
 */
class BoostExpiredEvent implements EntitlementWebhookEvent
{
    public function __construct(
        protected Workspace $workspace,
        protected Boost $boost,
        protected ?Feature $feature = null
    ) {}

    public static function name(): string
    {
        return 'boost_expired';
    }

    public static function nameLocalised(): string
    {
        return __('Boost Expired');
    }

    public function payload(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'workspace_slug' => $this->workspace->slug,
            'boost' => [
                'id' => $this->boost->id,
                'feature_code' => $this->boost->feature_code,
                'feature_name' => $this->feature?->name ?? ucwords(str_replace(['.', '_', '-'], ' ', $this->boost->feature_code)),
                'boost_type' => $this->boost->boost_type,
                'limit_value' => $this->boost->limit_value,
                'consumed_quantity' => $this->boost->consumed_quantity,
                'duration_type' => $this->boost->duration_type,
                'expired_at' => $this->boost->expires_at?->toIso8601String() ?? now()->toIso8601String(),
            ],
        ];
    }

    public function message(): string
    {
        $featureName = $this->feature?->name ?? $this->boost->feature_code;

        return "Boost expired: {$featureName} for workspace {$this->workspace->name}";
    }
}
