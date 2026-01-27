<?php

declare(strict_types=1);

namespace Core\Core\Tenant\Events\Webhook;

use Core\Core\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Core\Tenant\Models\Boost;
use Core\Core\Tenant\Models\Feature;
use Core\Core\Tenant\Models\Workspace;

/**
 * Event fired when a boost is activated for a workspace.
 */
class BoostActivatedEvent implements EntitlementWebhookEvent
{
    public function __construct(
        protected Workspace $workspace,
        protected Boost $boost,
        protected ?Feature $feature = null
    ) {}

    public static function name(): string
    {
        return 'boost_activated';
    }

    public static function nameLocalised(): string
    {
        return __('Boost Activated');
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
                'duration_type' => $this->boost->duration_type,
                'starts_at' => $this->boost->starts_at?->toIso8601String(),
                'expires_at' => $this->boost->expires_at?->toIso8601String(),
            ],
        ];
    }

    public function message(): string
    {
        $featureName = $this->feature?->name ?? $this->boost->feature_code;

        return "Boost activated: {$featureName} for workspace {$this->workspace->name}";
    }
}
