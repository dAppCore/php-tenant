<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Core\Mod\Tenant\Contracts\EntitlementWebhookEvent;
use Core\Mod\Tenant\Enums\WebhookDeliveryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Webhook configuration for entitlement events.
 *
 * Allows external systems to receive notifications about
 * usage alerts, package changes, and boost activity.
 */
class EntitlementWebhook extends Model
{
    use HasFactory;

    protected $table = 'entitlement_webhooks';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'max_attempts',
        'last_delivery_status',
        'last_triggered_at',
        'failure_count',
        'metadata',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'max_attempts' => 'integer',
        'last_delivery_status' => WebhookDeliveryStatus::class,
        'last_triggered_at' => 'datetime',
        'failure_count' => 'integer',
        'secret' => 'encrypted',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'secret',
    ];

    /**
     * Available webhook event types.
     */
    public const EVENTS = [
        'limit_warning',
        'limit_reached',
        'package_changed',
        'boost_activated',
        'boost_expired',
    ];

    /**
     * Maximum consecutive failures before auto-disable (circuit breaker).
     */
    public const MAX_FAILURES = 5;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $webhook) {
            if (empty($webhook->uuid)) {
                $webhook->uuid = (string) Str::uuid();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EntitlementWebhookDelivery::class, 'webhook_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->whereJsonContains('events', $event);
    }

    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    // -------------------------------------------------------------------------
    // State checks
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    public function hasEvent(string $event): bool
    {
        return in_array($event, $this->events ?? []);
    }

    public function isCircuitBroken(): bool
    {
        return $this->failure_count >= self::MAX_FAILURES;
    }

    // -------------------------------------------------------------------------
    // Status management
    // -------------------------------------------------------------------------

    public function incrementFailureCount(): void
    {
        $this->increment('failure_count');

        // Auto-disable after too many failures (circuit breaker)
        if ($this->failure_count >= self::MAX_FAILURES) {
            $this->update(['is_active' => false]);
        }
    }

    public function resetFailureCount(): void
    {
        $this->update([
            'failure_count' => 0,
            'last_triggered_at' => now(),
        ]);
    }

    public function updateLastDeliveryStatus(WebhookDeliveryStatus $status): void
    {
        $this->update(['last_delivery_status' => $status]);
    }

    /**
     * Trigger webhook and create delivery record.
     */
    public function trigger(EntitlementWebhookEvent $event): EntitlementWebhookDelivery
    {
        $data = [
            'event' => $event::name(),
            'data' => $event->payload(),
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Request-Source' => config('app.name'),
                'User-Agent' => config('app.name').' Entitlement Webhook',
            ];

            if ($this->secret) {
                $headers['X-Signature'] = hash_hmac('sha256', json_encode($data), $this->secret);
            }

            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post($this->url, $data);

            $status = match ($response->status()) {
                200, 201, 202, 204 => WebhookDeliveryStatus::SUCCESS,
                default => WebhookDeliveryStatus::FAILED,
            };

            if ($status === WebhookDeliveryStatus::SUCCESS) {
                $this->resetFailureCount();
            } else {
                $this->incrementFailureCount();
            }

            $this->updateLastDeliveryStatus($status);

            return $this->deliveries()->create([
                'uuid' => Str::uuid(),
                'event' => $event::name(),
                'status' => $status,
                'http_status' => $response->status(),
                'payload' => $data,
                'response' => $response->json() ?: ['body' => $response->body()],
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            $this->incrementFailureCount();
            $this->updateLastDeliveryStatus(WebhookDeliveryStatus::FAILED);

            return $this->deliveries()->create([
                'uuid' => Str::uuid(),
                'event' => $event::name(),
                'status' => WebhookDeliveryStatus::FAILED,
                'payload' => $data,
                'response' => ['error' => $e->getMessage()],
                'created_at' => now(),
            ]);
        }
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Generate a new secret for this webhook.
     */
    public function regenerateSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->update(['secret' => $secret]);

        return $secret;
    }
}
