<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Features;

use Core\Mod\Tenant\Enums\UserTier;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\EntitlementService;

class UnlimitedWorkspaces
{
    public function __construct(
        protected EntitlementService $entitlements
    ) {}

    /**
     * Resolve the feature's initial value.
     * Unlimited workspaces if:
     * - User's workspace has 'tier.hades' feature, OR
     * - User has Hades tier on their profile (legacy fallback)
     */
    public function resolve(mixed $scope): bool
    {
        // Check workspace entitlements first
        if ($scope instanceof Workspace) {
            return $this->checkWorkspaceEntitlement($scope);
        }

        if ($scope instanceof User) {
            // Check user's owner workspace
            $workspace = $scope->ownedWorkspaces()->first();
            if ($workspace && $this->checkWorkspaceEntitlement($workspace)) {
                return true;
            }

            // Legacy fallback: check user tier
            return $this->checkUserTier($scope);
        }

        return false;
    }

    /**
     * Check if workspace has Hades tier entitlement (unlimited workspaces).
     */
    protected function checkWorkspaceEntitlement(Workspace $workspace): bool
    {
        $result = $this->entitlements->can($workspace, 'tier.hades');

        return $result->isAllowed();
    }

    /**
     * Legacy fallback: check user's tier attribute.
     */
    protected function checkUserTier(mixed $scope): bool
    {
        if (method_exists($scope, 'getTier')) {
            return $scope->getTier() === UserTier::HADES;
        }

        if (isset($scope->tier)) {
            $tier = $scope->tier;
            if (is_string($tier)) {
                return $tier === UserTier::HADES->value;
            }

            return $tier === UserTier::HADES;
        }

        return false;
    }
}
