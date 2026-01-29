<?php

declare(strict_types=1);

namespace Core\Tenant\Exceptions;

use Exception;

/**
 * Exception thrown when a webhook URL fails SSRF validation.
 *
 * This exception is thrown when attempting to send webhooks to:
 * - Localhost or loopback addresses
 * - Private network ranges
 * - Local domain names (.local, .localhost, .internal)
 * - URLs that resolve to internal IP addresses
 */
class InvalidWebhookUrlException extends Exception
{
    public function __construct(
        string $message = 'The webhook URL is not allowed.',
        public readonly ?string $url = null,
        public readonly ?string $reason = null,
        int $code = 422
    ) {
        parent::__construct($message, $code);
    }

    /**
     * Create exception for SSRF validation failure.
     */
    public static function ssrfViolation(string $url, string $reason): self
    {
        return new self(
            message: "The webhook URL failed security validation: {$reason}",
            url: $url,
            reason: $reason
        );
    }

    /**
     * Create exception for missing HTTPS.
     */
    public static function requiresHttps(string $url): self
    {
        return new self(
            message: 'Webhook URLs must use HTTPS.',
            url: $url,
            reason: 'URL must use HTTPS'
        );
    }

    /**
     * Create exception for internal network access.
     */
    public static function internalNetwork(string $url): self
    {
        return new self(
            message: 'Webhook URLs cannot target internal network addresses.',
            url: $url,
            reason: 'URL resolves to internal or private network'
        );
    }
}
