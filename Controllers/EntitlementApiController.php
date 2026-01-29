<?php

declare(strict_types=1);

namespace Core\Tenant\Controllers;

use Core\Api\RateLimit\RateLimit;
use Core\Front\Controller;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Core\Tenant\Models\EntitlementLog;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Core\Tenant\Services\EntitlementService;

/**
 * API controller for entitlement management.
 *
 * SECURITY: The Blesta API endpoints (store, suspend, unsuspend, cancel, renew, show)
 * require API key authentication and should only be registered behind the
 * 'api.auth' middleware with appropriate scopes.
 *
 * Recommended route configuration:
 * ```php
 * Route::middleware(['api.auth:entitlements.write', 'api.rate', 'throttle:60,1'])
 *     ->prefix('provisioning/entitlements')
 *     ->group(function () {
 *         Route::post('/', [EntitlementApiController::class, 'store']);
 *         Route::get('/{id}', [EntitlementApiController::class, 'show']);
 *         Route::post('/{id}/suspend', [EntitlementApiController::class, 'suspend']);
 *         Route::post('/{id}/unsuspend', [EntitlementApiController::class, 'unsuspend']);
 *         Route::post('/{id}/cancel', [EntitlementApiController::class, 'cancel']);
 *         Route::post('/{id}/renew', [EntitlementApiController::class, 'renew']);
 *     });
 * ```
 *
 * Rate limits are enforced at 60 requests/minute per API key for provisioning endpoints.
 */
#[RateLimit(limit: 60, window: 60, key: 'entitlement-api')]
class EntitlementApiController extends Controller
{
    public function __construct(
        protected EntitlementService $entitlements
    ) {}

    /**
     * Create a new entitlement for a workspace.
     *
     * Expected payload:
     * - email: string (client email to find/create user)
     * - name: string (client name)
     * - product_code: string (package code)
     * - billing_cycle_anchor: string|null (ISO date)
     * - expires_at: string|null (ISO date)
     * - blesta_service_id: string|null
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'product_code' => 'required|string',
            'billing_cycle_anchor' => 'nullable|date',
            'expires_at' => 'nullable|date',
            'blesta_service_id' => 'nullable|string',
        ]);

        // Find or create the user
        $user = User::where('email', $validated['email'])->first();
        $isNewUser = false;

        if (! $user) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => bcrypt(Str::random(32)), // Random password, user can reset
            ]);
            $isNewUser = true;

            // Trigger email verification notification
            event(new Registered($user));
        }

        // Find the package
        $package = Package::where('code', $validated['product_code'])->first();

        if (! $package) {
            return response()->json([
                'success' => false,
                'error' => "Package '{$validated['product_code']}' not found",
            ], 404);
        }

        // Get or create the user's primary workspace
        $workspace = $user->ownedWorkspaces()->first();

        if (! $workspace) {
            $workspace = Workspace::create([
                'name' => $user->name."'s Workspace",
                'slug' => Str::slug($user->name).'-'.Str::random(6),
                'domain' => 'hub.host.uk.com',
                'type' => 'custom',
            ]);

            // Attach user as owner
            $workspace->users()->attach($user->id, [
                'role' => 'owner',
                'is_default' => true,
            ]);
        }

        // Provision the package
        $workspacePackage = $this->entitlements->provisionPackage(
            $workspace,
            $package->code,
            [
                'source' => EntitlementLog::SOURCE_BLESTA,
                'billing_cycle_anchor' => $validated['billing_cycle_anchor']
                    ? now()->parse($validated['billing_cycle_anchor'])
                    : now(),
                'expires_at' => $validated['expires_at']
                    ? now()->parse($validated['expires_at'])
                    : null,
                'blesta_service_id' => $validated['blesta_service_id'],
                'metadata' => [
                    'created_via' => 'blesta_api',
                    'client_email' => $validated['email'],
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'entitlement_id' => $workspacePackage->id,
            'workspace_id' => $workspace->id,
            'workspace_slug' => $workspace->slug,
            'package' => $package->code,
            'status' => $workspacePackage->status,
        ], 201);
    }

    /**
     * Suspend an entitlement.
     */
    public function suspend(Request $request, int $id): JsonResponse
    {
        $workspacePackage = WorkspacePackage::find($id);

        if (! $workspacePackage) {
            return response()->json([
                'success' => false,
                'error' => 'Entitlement not found',
            ], 404);
        }

        $workspace = $workspacePackage->workspace;
        $workspacePackage->suspend();

        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_SUSPENDED,
            $workspacePackage,
            source: EntitlementLog::SOURCE_BLESTA,
            metadata: ['reason' => $request->input('reason', 'Suspended via Blesta')]
        );

        $this->entitlements->invalidateCache($workspace);

        return response()->json([
            'success' => true,
            'entitlement_id' => $workspacePackage->id,
            'status' => $workspacePackage->fresh()->status,
        ]);
    }

    /**
     * Unsuspend (reactivate) an entitlement.
     */
    public function unsuspend(Request $request, int $id): JsonResponse
    {
        $workspacePackage = WorkspacePackage::find($id);

        if (! $workspacePackage) {
            return response()->json([
                'success' => false,
                'error' => 'Entitlement not found',
            ], 404);
        }

        $workspace = $workspacePackage->workspace;
        $workspacePackage->reactivate();

        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_REACTIVATED,
            $workspacePackage,
            source: EntitlementLog::SOURCE_BLESTA
        );

        $this->entitlements->invalidateCache($workspace);

        return response()->json([
            'success' => true,
            'entitlement_id' => $workspacePackage->id,
            'status' => $workspacePackage->fresh()->status,
        ]);
    }

    /**
     * Cancel an entitlement.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $workspacePackage = WorkspacePackage::find($id);

        if (! $workspacePackage) {
            return response()->json([
                'success' => false,
                'error' => 'Entitlement not found',
            ], 404);
        }

        $workspace = $workspacePackage->workspace;
        $workspacePackage->cancel(now());

        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_CANCELLED,
            $workspacePackage,
            source: EntitlementLog::SOURCE_BLESTA,
            metadata: ['reason' => $request->input('reason', 'Cancelled via Blesta')]
        );

        $this->entitlements->invalidateCache($workspace);

        return response()->json([
            'success' => true,
            'entitlement_id' => $workspacePackage->id,
            'status' => $workspacePackage->fresh()->status,
        ]);
    }

    /**
     * Renew an entitlement (extend expiry, reset usage).
     */
    public function renew(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'expires_at' => 'nullable|date',
            'billing_cycle_anchor' => 'nullable|date',
        ]);

        $workspacePackage = WorkspacePackage::find($id);

        if (! $workspacePackage) {
            return response()->json([
                'success' => false,
                'error' => 'Entitlement not found',
            ], 404);
        }

        $workspace = $workspacePackage->workspace;

        // Update dates
        $updates = [];
        if (isset($validated['expires_at'])) {
            $updates['expires_at'] = now()->parse($validated['expires_at']);
        }
        if (isset($validated['billing_cycle_anchor'])) {
            $updates['billing_cycle_anchor'] = now()->parse($validated['billing_cycle_anchor']);
        }

        if (! empty($updates)) {
            $workspacePackage->update($updates);
        }

        // Expire cycle-bound boosts from the previous cycle
        $this->entitlements->expireCycleBoundBoosts($workspace);

        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_RENEWED,
            $workspacePackage,
            source: EntitlementLog::SOURCE_BLESTA,
            newValues: $updates
        );

        $this->entitlements->invalidateCache($workspace);

        return response()->json([
            'success' => true,
            'entitlement_id' => $workspacePackage->id,
            'status' => $workspacePackage->fresh()->status,
            'expires_at' => $workspacePackage->fresh()->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Get entitlement details.
     */
    public function show(int $id): JsonResponse
    {
        $workspacePackage = WorkspacePackage::with(['package', 'workspace'])->find($id);

        if (! $workspacePackage) {
            return response()->json([
                'success' => false,
                'error' => 'Entitlement not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'entitlement' => [
                'id' => $workspacePackage->id,
                'workspace_id' => $workspacePackage->workspace_id,
                'workspace_slug' => $workspacePackage->workspace->slug,
                'package_code' => $workspacePackage->package->code,
                'package_name' => $workspacePackage->package->name,
                'status' => $workspacePackage->status,
                'starts_at' => $workspacePackage->starts_at?->toIso8601String(),
                'expires_at' => $workspacePackage->expires_at?->toIso8601String(),
                'billing_cycle_anchor' => $workspacePackage->billing_cycle_anchor?->toIso8601String(),
                'blesta_service_id' => $workspacePackage->blesta_service_id,
            ],
        ]);
    }

    // ==========================================================================
    // Cross-App Entitlement API (for external services like BioHost)
    // ==========================================================================

    /**
     * Check if a feature is allowed for a user/workspace.
     *
     * Used by external apps (BioHost, etc.) to check entitlements.
     *
     * Query params:
     * - email: User email to lookup workspace
     * - feature: Feature code to check
     * - quantity: Optional quantity to check (default 1)
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'feature' => 'required|string',
            'quantity' => 'nullable|integer|min:1',
        ]);

        // Find user by email
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'allowed' => false,
                'reason' => 'User not found',
                'feature_code' => $validated['feature'],
            ], 404);
        }

        // Get user's primary workspace
        $workspace = $user->defaultHostWorkspace();

        if (! $workspace) {
            return response()->json([
                'allowed' => false,
                'reason' => 'No workspace found for user',
                'feature_code' => $validated['feature'],
            ], 404);
        }

        // Check entitlement
        $result = $this->entitlements->can(
            $workspace,
            $validated['feature'],
            (int) ($validated['quantity'] ?? 1)
        );

        return response()->json([
            'allowed' => $result->isAllowed(),
            'limit' => $result->limit,
            'used' => $result->used,
            'remaining' => $result->remaining,
            'unlimited' => $result->isUnlimited(),
            'usage_percentage' => $result->getUsagePercentage(),
            'feature_code' => $validated['feature'],
            'workspace_id' => $workspace->id,
        ]);
    }

    /**
     * Record usage for a feature.
     *
     * Used by external apps to record usage after an action is performed.
     */
    public function recordUsage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'feature' => 'required|string',
            'quantity' => 'nullable|integer|min:1',
            'metadata' => 'nullable|array',
        ]);

        // Find user by email
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
            ], 404);
        }

        // Get user's primary workspace
        $workspace = $user->defaultHostWorkspace();

        if (! $workspace) {
            return response()->json([
                'success' => false,
                'error' => 'No workspace found for user',
            ], 404);
        }

        // Record usage
        $record = $this->entitlements->recordUsage(
            $workspace,
            $validated['feature'],
            $validated['quantity'] ?? 1,
            $user,
            $validated['metadata'] ?? null
        );

        return response()->json([
            'success' => true,
            'usage_record_id' => $record->id,
            'feature_code' => $validated['feature'],
            'quantity' => $validated['quantity'] ?? 1,
        ], 201);
    }

    /**
     * Get usage summary for a workspace.
     *
     * Returns all features with their current usage for dashboard display.
     */
    public function summary(Request $request, Workspace $workspace): JsonResponse
    {
        // Get active packages
        $packages = $this->entitlements->getActivePackages($workspace);

        // Get active boosts
        $boosts = $this->entitlements->getActiveBoosts($workspace);

        // Get usage summary grouped by category
        $usageSummary = $this->entitlements->getUsageSummary($workspace);

        // Format features for response
        $features = [];
        foreach ($usageSummary as $category => $categoryFeatures) {
            $features[$category] = collect($categoryFeatures)->map(fn ($f) => [
                'code' => $f['code'],
                'name' => $f['name'],
                'limit' => $f['limit'],
                'used' => $f['used'],
                'remaining' => $f['remaining'],
                'unlimited' => $f['unlimited'],
                'percentage' => $f['percentage'],
            ])->values()->toArray();
        }

        return response()->json([
            'workspace_id' => $workspace->id,
            'packages' => $packages->map(fn ($wp) => [
                'code' => $wp->package->code,
                'name' => $wp->package->name,
                'status' => $wp->status,
                'expires_at' => $wp->expires_at?->toIso8601String(),
            ])->values(),
            'features' => $features,
            'boosts' => $boosts->map(fn ($b) => [
                'feature' => $b->feature_code,
                'value' => $b->limit_value,
                'type' => $b->boost_type,
                'expires_at' => $b->expires_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Get usage summary for the authenticated user's workspace.
     */
    public function mySummary(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'Unauthenticated',
            ], 401);
        }

        $workspace = $user->defaultHostWorkspace();

        if (! $workspace) {
            return response()->json([
                'error' => 'No workspace found',
            ], 404);
        }

        return $this->summary($request, $workspace);
    }
}
