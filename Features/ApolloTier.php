<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Features;

use Core\Mod\Tenant\Enums\UserTier;
use Core\Mod\Tenant\Models\User;
use Core\Mod\Tenant\Models\Workspace;
use Core\Mod\Tenant\Services\EntitlementService;

class ApolloTier
{
    public function __construct(
        protected EntitlementService $entitlements
    ) {}

    /**
     * Resolve the feature's initial value.
     * Apollo tier is active if:
     * - User's default workspace has 'tier.apollo' or 'tier.hades' feature, OR
     * - User has Apollo or Hades tier on their profile (legacy fallback)
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
     * Check if workspace has Apollo or Hades tier entitlement.
     */
    protected function checkWorkspaceEntitlement(Workspace $workspace): bool
    {
        // Apollo is active if workspace has Apollo OR Hades tier
        $apolloResult = $this->entitlements->can($workspace, 'tier.apollo');
        $hadesResult = $this->entitlements->can($workspace, 'tier.hades');

        return $apolloResult->isAllowed() || $hadesResult->isAllowed();
    }

    /**
     * Legacy fallback: check user's tier attribute.
     */
    protected function checkUserTier(mixed $scope): bool
    {
        if (method_exists($scope, 'getTier')) {
            $tier = $scope->getTier();

            return $tier === UserTier::APOLLO || $tier === UserTier::HADES;
        }

        if (isset($scope->tier)) {
            $tier = $scope->tier;
            if (is_string($tier)) {
                return in_array($tier, [UserTier::APOLLO->value, UserTier::HADES->value]);
            }

            return $tier === UserTier::APOLLO || $tier === UserTier::HADES;
        }

        return false;
    }
}
