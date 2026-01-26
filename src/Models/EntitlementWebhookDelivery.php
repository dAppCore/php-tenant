<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Core\Mod\Tenant\Enums\WebhookDeliveryStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record of an entitlement webhook delivery attempt.
 *
 * Tracks successful and failed deliveries for debugging
 * and retry purposes.
 */
class EntitlementWebhookDelivery extends Model
{
    use HasFactory;
    use MassPrunable;

    protected $table = 'entitlement_webhook_deliveries';

    public $timestamps = false;

    protected $fillable = [
        'webhook_id',
        'uuid',
        'event',
        'attempts',
        'status',
        'http_status',
        'resend_at',
        'resent_manually',
        'payload',
        'response',
        'created_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'status' => WebhookDeliveryStatus::class,
        'http_status' => 'integer',
        'resend_at' => 'datetime',
        'resent_manually' => 'boolean',
        'payload' => 'array',
        'response' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Prune deliveries older than 30 days.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', Carbon::now()->subMonth());
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(EntitlementWebhook::class, 'webhook_id');
    }

    public function isSucceeded(): bool
    {
        return $this->status === WebhookDeliveryStatus::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->status === WebhookDeliveryStatus::FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === WebhookDeliveryStatus::PENDING;
    }

    public function isAttemptLimitReached(): bool
    {
        return $this->attempts >= $this->webhook->max_attempts;
    }

    public function attempt(): void
    {
        $this->increment('attempts');
    }

    public function setAsResentManually(): void
    {
        $this->resent_manually = true;
        $this->save();
    }

    public function updateResendAt(Carbon|DateTimeInterface|null $datetime = null): void
    {
        $this->resend_at = $datetime;
        $this->save();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the event name in a human-readable format.
     */
    public function getEventDisplayName(): string
    {
        return match ($this->event) {
            'limit_warning' => 'Limit Warning',
            'limit_reached' => 'Limit Reached',
            'package_changed' => 'Package Changed',
            'boost_activated' => 'Boost Activated',
            'boost_expired' => 'Boost Expired',
            'test' => 'Test',
            default => ucwords(str_replace('_', ' ', $this->event)),
        };
    }

    /**
     * Get status badge colour for display.
     */
    public function getStatusColour(): string
    {
        return match ($this->status) {
            WebhookDeliveryStatus::SUCCESS => 'green',
            WebhookDeliveryStatus::FAILED => 'red',
            WebhookDeliveryStatus::PENDING => 'amber',
            default => 'gray',
        };
    }
}
