<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Contracts;

/**
 * Contract for entitlement webhook events.
 *
 * Defines structure for webhook event types that can be
 * dispatched to external endpoints when entitlement-related
 * events occur (usage alerts, package changes, boost expiry).
 */
interface EntitlementWebhookEvent
{
    /**
     * Get the event name/identifier (e.g., 'limit_warning', 'package_changed').
     */
    public static function name(): string;

    /**
     * Get the localised event name for display.
     */
    public static function nameLocalised(): string;

    /**
     * Get the event payload data.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * Get a human-readable message for this event.
     */
    public function message(): string;
}
