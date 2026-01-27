<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Jobs;

use Core\Mod\Tenant\Enums\WebhookDeliveryStatus;
use Core\Mod\Tenant\Models\EntitlementWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Job to dispatch entitlement webhook deliveries asynchronously.
 *
 * Handles retry logic with exponential backoff.
 */
class DispatchEntitlementWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 900]; // 1min, 5min, 15min

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $webhookId,
        public string $eventName,
        public array $eventPayload
    ) {
        $this->onQueue('webhooks');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $webhook = EntitlementWebhook::find($this->webhookId);

        if (! $webhook) {
            Log::warning('Entitlement webhook not found', ['webhook_id' => $this->webhookId]);

            return;
        }

        // Skip if webhook is inactive (circuit breaker may have triggered)
        if (! $webhook->isActive()) {
            Log::info('Entitlement webhook is inactive, skipping', [
                'webhook_id' => $this->webhookId,
                'event' => $this->eventName,
            ]);

            return;
        }

        $data = [
            'event' => $this->eventName,
            'data' => $this->eventPayload,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Request-Source' => config('app.name'),
                'User-Agent' => config('app.name').' Entitlement Webhook',
            ];

            if ($webhook->secret) {
                $headers['X-Signature'] = hash_hmac('sha256', json_encode($data), $webhook->secret);
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post($webhook->url, $data);

            $status = match ($response->status()) {
                200, 201, 202, 204 => WebhookDeliveryStatus::SUCCESS,
                default => WebhookDeliveryStatus::FAILED,
            };

            // Create delivery record
            $webhook->deliveries()->create([
                'uuid' => Str::uuid(),
                'event' => $this->eventName,
                'attempts' => $this->attempts(),
                'status' => $status,
                'http_status' => $response->status(),
                'payload' => $data,
                'response' => $response->json() ?: ['body' => substr($response->body(), 0, 1000)],
                'created_at' => now(),
            ]);

            if ($status === WebhookDeliveryStatus::SUCCESS) {
                $webhook->resetFailureCount();
                Log::info('Entitlement webhook delivered successfully', [
                    'webhook_id' => $webhook->id,
                    'event' => $this->eventName,
                    'http_status' => $response->status(),
                ]);
            } else {
                $webhook->incrementFailureCount();
                $webhook->updateLastDeliveryStatus($status);

                Log::warning('Entitlement webhook delivery failed', [
                    'webhook_id' => $webhook->id,
                    'event' => $this->eventName,
                    'http_status' => $response->status(),
                    'response' => substr($response->body(), 0, 500),
                ]);

                // Throw exception to trigger retry
                throw new \RuntimeException("Webhook returned {$response->status()}");
            }

            $webhook->updateLastDeliveryStatus($status);
        } catch (\Exception $e) {
            $webhook->incrementFailureCount();
            $webhook->updateLastDeliveryStatus(WebhookDeliveryStatus::FAILED);

            // Create failure delivery record
            $webhook->deliveries()->create([
                'uuid' => Str::uuid(),
                'event' => $this->eventName,
                'attempts' => $this->attempts(),
                'status' => WebhookDeliveryStatus::FAILED,
                'payload' => $data,
                'response' => ['error' => $e->getMessage()],
                'created_at' => now(),
            ]);

            Log::error('Entitlement webhook dispatch exception', [
                'webhook_id' => $webhook->id,
                'event' => $this->eventName,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        $webhook = EntitlementWebhook::find($this->webhookId);

        Log::error('Entitlement webhook job failed permanently', [
            'webhook_id' => $this->webhookId,
            'event' => $this->eventName,
            'error' => $exception->getMessage(),
            'circuit_broken' => $webhook?->isCircuitBroken() ?? false,
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'entitlement-webhook',
            "webhook:{$this->webhookId}",
            "event:{$this->eventName}",
        ];
    }
}
