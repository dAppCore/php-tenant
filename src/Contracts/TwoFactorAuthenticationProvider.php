<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Contracts;

/**
 * Contract for two-factor authentication providers.
 *
 * Handles TOTP (Time-based One-Time Password) generation and verification
 * for user accounts. Typically implemented using libraries like Google Authenticator.
 */
interface TwoFactorAuthenticationProvider
{
    /**
     * Generate a new secret key for TOTP.
     */
    public function generateSecretKey(): string;

    /**
     * Generate QR code URL for authenticator app setup.
     *
     * @param  string  $name  Application/account name
     * @param  string  $email  User email
     * @param  string  $secret  TOTP secret key
     */
    public function qrCodeUrl(string $name, string $email, string $secret): string;

    /**
     * Verify a TOTP code against the secret.
     *
     * @param  string  $secret  TOTP secret key
     * @param  string  $code  User-provided 6-digit code
     */
    public function verify(string $secret, string $code): bool;
}
