<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Events\Webhook;

use Core\Mod\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Mod\Tenant\Models\Package;
use Core\Mod\Tenant\Models\Workspace;

/**
 * Event fired when a workspace's package changes (upgrade, downgrade, or new assignment).
 */
class PackageChangedEvent implements EntitlementWebhookEvent
{
    public function __construct(
        protected Workspace $workspace,
        protected ?Package $previousPackage,
        protected Package $newPackage,
        protected string $changeType = 'changed' // 'added', 'changed', 'removed'
    ) {}

    public static function name(): string
    {
        return 'package_changed';
    }

    public static function nameLocalised(): string
    {
        return __('Package Changed');
    }

    public function payload(): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'workspace_slug' => $this->workspace->slug,
            'change_type' => $this->changeType,
            'previous_package' => $this->previousPackage ? [
                'id' => $this->previousPackage->id,
                'code' => $this->previousPackage->code,
                'name' => $this->previousPackage->name,
            ] : null,
            'new_package' => [
                'id' => $this->newPackage->id,
                'code' => $this->newPackage->code,
                'name' => $this->newPackage->name,
            ],
        ];
    }

    public function message(): string
    {
        if ($this->changeType === 'added') {
            return "Package added: {$this->newPackage->name} assigned to workspace {$this->workspace->name}";
        }

        if ($this->changeType === 'removed') {
            return "Package removed from workspace {$this->workspace->name}";
        }

        $from = $this->previousPackage?->name ?? 'none';

        return "Package changed: {$from} to {$this->newPackage->name} for workspace {$this->workspace->name}";
    }
}
