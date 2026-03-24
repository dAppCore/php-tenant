<?php

declare(strict_types=1);

namespace Core\Tenant\Services;

use Core\Tenant\Concerns\PreventsSSRF;
use Core\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Tenant\Enums\WebhookDeliveryStatus;
use Core\Tenant\Events\Webhook\BoostActivatedEvent;
use Core\Tenant\Events\Webhook\BoostExpiredEvent;
use Core\Tenant\Events\Webhook\LimitReachedEvent;
use Core\Tenant\Events\Webhook\LimitWarningEvent;
use Core\Tenant\Events\Webhook\PackageChangedEvent;
use Core\Tenant\Exceptions\InvalidWebhookUrlException;
use Core\Tenant\Jobs\DispatchEntitlementWebhook;
use Core\Tenant\Models\EntitlementWebhook;
use Core\Tenant\Models\EntitlementWebhookDelivery;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing and dispatching entitlement webhooks.
 *
 * Handles webhook registration, event dispatch, payload signing, and delivery tracking.
 *
 * SECURITY: All outbound webhook requests are validated against SSRF attacks.
 * URLs targeting localhost, private networks, or local domains are blocked.
 */
class EntitlementWebhookService
{
    use PreventsSSRF;

    /**
     * Dispatch an event to all matching webhooks for a workspace.
     *
     * @param  bool  $async  Whether to dispatch asynchronously via job queue
     * @return array<int, array{webhook_id: int, success: bool, delivery_id?: int, error?: string}>
     */
    public function dispatch(Workspace $workspace, EntitlementWebhookEvent $event, bool $async = true): array
    {
        $eventName = $event::name();
        $results = [];

        $webhooks = EntitlementWebhook::query()
            ->forWorkspace($workspace)
            ->active()
            ->forEvent($eventName)
            ->get();

        foreach ($webhooks as $webhook) {
            if ($async) {
                // Dispatch via job for async processing
                DispatchEntitlementWebhook::dispatch($webhook->id, $eventName, $event->payload());

                $results[] = [
                    'webhook_id' => $webhook->id,
                    'success' => true,
                    'queued' => true,
                ];
            } else {
                // Synchronous dispatch
                try {
                    $delivery = $webhook->trigger($event);
                    $results[] = [
                        'webhook_id' => $webhook->id,
                        'success' => $delivery->isSucceeded(),
                        'delivery_id' => $delivery->id,
                    ];
                } catch (\Exception $e) {
                    Log::error('Webhook dispatch failed', [
                        'webhook_id' => $webhook->id,
                        'event' => $eventName,
                        'error' => $e->getMessage(),
                    ]);

                    $results[] = [
                        'webhook_id' => $webhook->id,
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Register a new webhook for a workspace.
     *
     * @throws InvalidWebhookUrlException When the webhook URL fails SSRF validation
     */
    public function register(
        Workspace $workspace,
        string $name,
        string $url,
        array $events,
        ?string $secret = null,
        array $metadata = []
    ): EntitlementWebhook {
        // SECURITY: Validate URL against SSRF before registration
        $ssrfValidation = $this->validateUrlForSSRF($url);
        if (! $ssrfValidation['valid']) {
            Log::warning('Webhook registration blocked due to SSRF validation failure', [
                'workspace_id' => $workspace->id,
                'url' => $url,
                'reason' => $ssrfValidation['error'],
            ]);

            throw InvalidWebhookUrlException::ssrfViolation(
                $url,
                $ssrfValidation['error'] ?? 'Unknown validation error'
            );
        }

        // Generate secret if not provided
        $secret ??= bin2hex(random_bytes(32));

        return EntitlementWebhook::create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'url' => $url,
            'secret' => $secret,
            'events' => array_intersect($events, EntitlementWebhook::EVENTS),
            'is_active' => true,
            'max_attempts' => 3,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Unregister (delete) a webhook.
     */
    public function unregister(EntitlementWebhook $webhook): bool
    {
        return $webhook->delete();
    }

    /**
     * Update webhook configuration.
     *
     * @throws InvalidWebhookUrlException When the updated webhook URL fails SSRF validation
     */
    public function update(
        EntitlementWebhook $webhook,
        array $attributes
    ): EntitlementWebhook {
        // SECURITY: Validate new URL against SSRF if being updated
        if (isset($attributes['url']) && $attributes['url'] !== $webhook->url) {
            $ssrfValidation = $this->validateUrlForSSRF($attributes['url']);
            if (! $ssrfValidation['valid']) {
                Log::warning('Webhook update blocked due to SSRF validation failure', [
                    'webhook_id' => $webhook->id,
                    'workspace_id' => $webhook->workspace_id,
                    'old_url' => $webhook->url,
                    'new_url' => $attributes['url'],
                    'reason' => $ssrfValidation['error'],
                ]);

                throw InvalidWebhookUrlException::ssrfViolation(
                    $attributes['url'],
                    $ssrfValidation['error'] ?? 'Unknown validation error'
                );
            }
        }

        // Filter events to only allowed values
        if (isset($attributes['events'])) {
            $attributes['events'] = array_intersect($attributes['events'], EntitlementWebhook::EVENTS);
        }

        $webhook->update($attributes);

        return $webhook->refresh();
    }

    /**
     * Sign a payload with HMAC-SHA256.
     */
    public function sign(array $payload, string $secret): string
    {
        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Verify a webhook signature.
     */
    public function verifySignature(array $payload, string $signature, string $secret): bool
    {
        $expected = $this->sign($payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Get all available event types with descriptions.
     *
     * @return array<string, array{name: string, description: string, class: class-string<EntitlementWebhookEvent>}>
     */
    public function getAvailableEvents(): array
    {
        return [
            'limit_warning' => [
                'name' => LimitWarningEvent::nameLocalised(),
                'description' => __('Triggered when usage reaches 80% or 90% of a feature limit'),
                'class' => LimitWarningEvent::class,
            ],
            'limit_reached' => [
                'name' => LimitReachedEvent::nameLocalised(),
                'description' => __('Triggered when usage reaches 100% of a feature limit'),
                'class' => LimitReachedEvent::class,
            ],
            'package_changed' => [
                'name' => PackageChangedEvent::nameLocalised(),
                'description' => __('Triggered when a workspace package is added, changed, or removed'),
                'class' => PackageChangedEvent::class,
            ],
            'boost_activated' => [
                'name' => BoostActivatedEvent::nameLocalised(),
                'description' => __('Triggered when a boost is activated for a workspace'),
                'class' => BoostActivatedEvent::class,
            ],
            'boost_expired' => [
                'name' => BoostExpiredEvent::nameLocalised(),
                'description' => __('Triggered when a boost expires'),
                'class' => BoostExpiredEvent::class,
            ],
        ];
    }

    /**
     * Get event names as a simple array for forms.
     *
     * @return array<string, string>
     */
    public function getEventOptions(): array
    {
        $events = $this->getAvailableEvents();
        $options = [];

        foreach ($events as $key => $event) {
            $options[$key] = $event['name'];
        }

        return $options;
    }

    /**
     * Test a webhook by sending a test event.
     *
     * @throws InvalidWebhookUrlException When the webhook URL fails SSRF validation
     */
    public function testWebhook(EntitlementWebhook $webhook): EntitlementWebhookDelivery
    {
        // SECURITY: Validate URL against SSRF before making request
        $ssrfValidation = $this->validateUrlForSSRF($webhook->url);
        if (! $ssrfValidation['valid']) {
            Log::warning('Webhook test blocked due to SSRF validation failure', [
                'webhook_id' => $webhook->id,
                'workspace_id' => $webhook->workspace_id,
                'url' => $webhook->url,
                'reason' => $ssrfValidation['error'],
            ]);

            throw InvalidWebhookUrlException::ssrfViolation(
                $webhook->url,
                $ssrfValidation['error'] ?? 'Unknown validation error'
            );
        }

        $testPayload = [
            'event' => 'test',
            'data' => [
                'webhook_id' => $webhook->id,
                'webhook_name' => $webhook->name,
                'message' => 'This is a test webhook delivery from '.$webhook->workspace->name,
                'subscribed_events' => $webhook->events,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Request-Source' => config('app.name'),
                'User-Agent' => config('app.name').' Entitlement Webhook',
                'X-Test-Webhook' => 'true',
            ];

            if ($webhook->secret) {
                $headers['X-Signature'] = $this->sign($testPayload, $webhook->secret);
            }

            // Build HTTP client with optional IP override for DNS rebinding protection
            $httpClient = Http::withHeaders($headers)
                ->timeout(10)
                ->connectTimeout(5);

            $response = $httpClient->post($webhook->url, $testPayload);

            $status = in_array($response->status(), [200, 201, 202, 204])
                ? WebhookDeliveryStatus::SUCCESS
                : WebhookDeliveryStatus::FAILED;

            return $webhook->deliveries()->create([
                'uuid' => Str::uuid(),
                'event' => 'test',
                'status' => $status,
                'http_status' => $response->status(),
                'payload' => $testPayload,
                'response' => $response->json() ?: ['body' => $response->body()],
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            return $webhook->deliveries()->create([
                'uuid' => Str::uuid(),
                'event' => 'test',
                'status' => WebhookDeliveryStatus::FAILED,
                'payload' => $testPayload,
                'response' => ['error' => $e->getMessage()],
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Retry a failed delivery.
     *
     * @throws InvalidWebhookUrlException When the webhook URL fails SSRF validation
     */
    public function retryDelivery(EntitlementWebhookDelivery $delivery): EntitlementWebhookDelivery
    {
        $webhook = $delivery->webhook;

        if (! $webhook->isActive()) {
            throw new \RuntimeException('Cannot retry delivery for inactive webhook');
        }

        // SECURITY: Re-validate URL against SSRF before retry (URL may have been updated)
        $ssrfValidation = $this->validateUrlForSSRF($webhook->url);
        if (! $ssrfValidation['valid']) {
            Log::warning('Webhook retry blocked due to SSRF validation failure', [
                'webhook_id' => $webhook->id,
                'delivery_id' => $delivery->id,
                'workspace_id' => $webhook->workspace_id,
                'url' => $webhook->url,
                'reason' => $ssrfValidation['error'],
            ]);

            throw InvalidWebhookUrlException::ssrfViolation(
                $webhook->url,
                $ssrfValidation['error'] ?? 'Unknown validation error'
            );
        }

        $payload = $delivery->payload;

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Request-Source' => config('app.name'),
                'User-Agent' => config('app.name').' Entitlement Webhook',
                'X-Retry-Attempt' => (string) ($delivery->attempts + 1),
            ];

            if ($webhook->secret) {
                $headers['X-Signature'] = $this->sign($payload, $webhook->secret);
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->connectTimeout(5)
                ->post($webhook->url, $payload);

            $status = in_array($response->status(), [200, 201, 202, 204])
                ? WebhookDeliveryStatus::SUCCESS
                : WebhookDeliveryStatus::FAILED;

            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'status' => $status,
                'http_status' => $response->status(),
                'response' => $response->json() ?: ['body' => $response->body()],
                'resent_manually' => true,
            ]);

            if ($status === WebhookDeliveryStatus::SUCCESS) {
                $webhook->resetFailureCount();
            } else {
                $webhook->incrementFailureCount();
            }

            $webhook->updateLastDeliveryStatus($status);

            return $delivery;
        } catch (\Exception $e) {
            $delivery->update([
                'attempts' => $delivery->attempts + 1,
                'status' => WebhookDeliveryStatus::FAILED,
                'response' => ['error' => $e->getMessage()],
                'resent_manually' => true,
            ]);

            $webhook->incrementFailureCount();
            $webhook->updateLastDeliveryStatus(WebhookDeliveryStatus::FAILED);

            return $delivery;
        }
    }

    /**
     * Re-enable a circuit-broken webhook after fixing the issue.
     */
    public function resetCircuitBreaker(EntitlementWebhook $webhook): void
    {
        $webhook->update([
            'is_active' => true,
            'failure_count' => 0,
        ]);
    }

    /**
     * Get webhooks for a workspace.
     */
    public function getWebhooksForWorkspace(Workspace $workspace): Collection
    {
        return EntitlementWebhook::query()
            ->forWorkspace($workspace)
            ->with(['deliveries' => fn ($q) => $q->latest('created_at')->limit(5)])
            ->latest()
            ->get();
    }

    /**
     * Get delivery history for a webhook.
     */
    public function getDeliveryHistory(EntitlementWebhook $webhook, int $limit = 50): Collection
    {
        return $webhook->deliveries()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }
}
