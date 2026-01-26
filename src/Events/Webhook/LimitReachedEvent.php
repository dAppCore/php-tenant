<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Events\Webhook;

use Core\Mod\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Workspace;

/**
 * Event fired when workspace usage reaches 100% of the limit.
 */
class LimitReachedEvent implements EntitlementWebhookEvent
{
    public function __construct(
        protected Workspace $workspace,
        protected Feature $feature,
        protected int $used,
        protected int $limit
    ) {}

    public static function name(): string
    {
        return 'limit_reached';
    }

    public static function nameLocalised(): string
    {
        return __('Limit Reached');
    }

    public function payload(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'workspace_slug' => $this->workspace->slug,
            'feature_code' => $this->feature->code,
            'feature_name' => $this->feature->name,
            'used' => $this->used,
            'limit' => $this->limit,
            'percentage' => 100,
            'remaining' => 0,
        ];
    }

    public function message(): string
    {
        return "Limit reached: {$this->feature->name} at 100% ({$this->used}/{$this->limit}) for workspace {$this->workspace->name}";
    }
}
