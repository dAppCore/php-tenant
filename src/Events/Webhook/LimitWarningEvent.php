<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Events\Webhook;

use Core\Mod\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Mod\Tenant\Models\Feature;
use Core\Mod\Tenant\Models\Workspace;

/**
 * Event fired when workspace usage reaches the warning threshold (80%).
 */
class LimitWarningEvent implements EntitlementWebhookEvent
{
    public function __construct(
        protected Workspace $workspace,
        protected Feature $feature,
        protected int $used,
        protected int $limit,
        protected int $threshold = 80
    ) {}

    public static function name(): string
    {
        return 'limit_warning';
    }

    public static function nameLocalised(): string
    {
        return __('Limit Warning');
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
            'percentage' => round(($this->used / $this->limit) * 100),
            'remaining' => max(0, $this->limit - $this->used),
            'threshold' => $this->threshold,
        ];
    }

    public function message(): string
    {
        $percentage = round(($this->used / $this->limit) * 100);

        return "Usage warning: {$this->feature->name} at {$percentage}% ({$this->used}/{$this->limit}) for workspace {$this->workspace->name}";
    }
}
