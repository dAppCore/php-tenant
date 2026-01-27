<?php

declare(strict_types=1);

namespace Core\Core\Tenant\Controllers\Api;

use Core\Core\Tenant\Models\EntitlementWebhook;
use Core\Core\Tenant\Models\EntitlementWebhookDelivery;
use Core\Core\Tenant\Models\Workspace;
use Core\Core\Tenant\Services\EntitlementWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * API controller for entitlement webhook management.
 *
 * Provides CRUD operations for webhooks and delivery history.
 */
class EntitlementWebhookController extends Controller
{
    public function __construct(
        protected EntitlementWebhookService $webhookService
    ) {}

    /**
     * List webhooks for the current workspace.
     */
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $webhooks = EntitlementWebhook::query()
            ->forWorkspace($workspace)
            ->withCount('deliveries')
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($webhooks);
    }

    /**
     * Create a new webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(EntitlementWebhook::EVENTS)],
            'secret' => ['nullable', 'string', 'min:32'],
            'metadata' => ['nullable', 'array'],
        ]);

        $webhook = $this->webhookService->register(
            workspace: $workspace,
            name: $validated['name'],
            url: $validated['url'],
            events: $validated['events'],
            secret: $validated['secret'] ?? null,
            metadata: $validated['metadata'] ?? []
        );

        return response()->json([
            'message' => __('Webhook created successfully'),
            'webhook' => $webhook,
            'secret' => $webhook->secret, // Return secret on creation only
        ], 201);
    }

    /**
     * Get a specific webhook.
     */
    public function show(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $webhook->loadCount('deliveries');
        $webhook->load(['deliveries' => fn ($q) => $q->latest('created_at')->limit(10)]);

        return response()->json([
            'webhook' => $webhook,
            'available_events' => $this->webhookService->getAvailableEvents(),
        ]);
    }

    /**
     * Update a webhook.
     */
    public function update(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(EntitlementWebhook::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $webhook = $this->webhookService->update($webhook, $validated);

        return response()->json([
            'message' => __('Webhook updated successfully'),
            'webhook' => $webhook,
        ]);
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $this->webhookService->unregister($webhook);

        return response()->json([
            'message' => __('Webhook deleted successfully'),
        ]);
    }

    /**
     * Regenerate webhook secret.
     */
    public function regenerateSecret(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $secret = $webhook->regenerateSecret();

        return response()->json([
            'message' => __('Secret regenerated successfully'),
            'secret' => $secret,
        ]);
    }

    /**
     * Send a test webhook.
     */
    public function test(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $delivery = $this->webhookService->testWebhook($webhook);

        return response()->json([
            'message' => $delivery->isSucceeded()
                ? __('Test webhook sent successfully')
                : __('Test webhook failed'),
            'delivery' => $delivery,
        ]);
    }

    /**
     * Reset circuit breaker for a webhook.
     */
    public function resetCircuitBreaker(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $this->webhookService->resetCircuitBreaker($webhook);

        return response()->json([
            'message' => __('Webhook re-enabled successfully'),
            'webhook' => $webhook->refresh(),
        ]);
    }

    /**
     * Get delivery history for a webhook.
     */
    public function deliveries(Request $request, EntitlementWebhook $webhook): JsonResponse
    {
        $this->authorizeWebhook($request, $webhook);

        $deliveries = $webhook->deliveries()
            ->latest('created_at')
            ->paginate($request->integer('per_page', 50));

        return response()->json($deliveries);
    }

    /**
     * Retry a failed delivery.
     */
    public function retryDelivery(Request $request, EntitlementWebhookDelivery $delivery): JsonResponse
    {
        $this->authorizeWebhook($request, $delivery->webhook);

        if ($delivery->isSucceeded()) {
            return response()->json([
                'message' => __('Cannot retry a successful delivery'),
            ], 422);
        }

        $delivery = $this->webhookService->retryDelivery($delivery);

        return response()->json([
            'message' => $delivery->isSucceeded()
                ? __('Delivery retried successfully')
                : __('Delivery retry failed'),
            'delivery' => $delivery,
        ]);
    }

    /**
     * Get available event types.
     */
    public function events(): JsonResponse
    {
        return response()->json([
            'events' => $this->webhookService->getAvailableEvents(),
        ]);
    }

    /**
     * Resolve the workspace from the request.
     */
    protected function resolveWorkspace(Request $request): Workspace
    {
        // First try explicit workspace_id parameter
        if ($request->has('workspace_id')) {
            $workspace = Workspace::findOrFail($request->integer('workspace_id'));

            // Verify user has access
            if (! $request->user()->workspaces->contains($workspace)) {
                abort(403, 'You do not have access to this workspace');
            }

            return $workspace;
        }

        // Fall back to user's default workspace
        return $request->user()->defaultHostWorkspace()
            ?? abort(400, 'No workspace specified and user has no default workspace');
    }

    /**
     * Authorize that the user can access this webhook.
     */
    protected function authorizeWebhook(Request $request, EntitlementWebhook $webhook): void
    {
        if (! $request->user()->workspaces->contains($webhook->workspace)) {
            abort(403, 'You do not have access to this webhook');
        }
    }
}
