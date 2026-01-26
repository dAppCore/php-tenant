<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Concerns;

use Core\Mod\Tenant\Contracts\TwoFactorAuthenticationProvider;
use Core\Mod\Tenant\Models\UserTwoFactorAuth;
use Core\Mod\Tenant\Services\TotpService;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Trait for two-factor authentication support.
 *
 * Provides TOTP-based 2FA using the TotpService.
 */
trait TwoFactorAuthenticatable
{
    /**
     * Get the user's two-factor authentication record.
     */
    public function twoFactorAuth(): HasOne
    {
        return $this->hasOne(UserTwoFactorAuth::class, 'user_id');
    }

    /**
     * Check if two-factor authentication is enabled.
     */
    public function hasTwoFactorAuthEnabled(): bool
    {
        if ($this->twoFactorAuth) {
            return ! is_null($this->twoFactorAuth->secret_key)
                && ! is_null($this->twoFactorAuth->confirmed_at);
        }

        return false;
    }

    /**
     * Get the two-factor authentication secret key.
     */
    public function twoFactorAuthSecretKey(): ?string
    {
        return $this->twoFactorAuth?->secret_key;
    }

    /**
     * Get the two-factor recovery codes.
     */
    public function twoFactorRecoveryCodes(): array
    {
        return $this->twoFactorAuth?->recovery_codes?->toArray() ?? [];
    }

    /**
     * Replace a used recovery code with a new one.
     */
    public function twoFactorReplaceRecoveryCode(string $code): void
    {
        if (! $this->twoFactorAuth) {
            return;
        }

        $codes = $this->twoFactorRecoveryCodes();
        $index = array_search($code, $codes);

        if ($index !== false) {
            $codes[$index] = $this->generateRecoveryCode();
            $this->twoFactorAuth->update(['recovery_codes' => $codes]);
        }
    }

    /**
     * Generate a QR code SVG for two-factor setup.
     */
    public function twoFactorQrCodeSvg(): string
    {
        $secret = $this->twoFactorAuthSecretKey();
        if (! $secret) {
            return '';
        }

        $url = $this->twoFactorQrCodeUrl();

        return $this->getTotpService()->qrCodeSvg($url);
    }

    /**
     * Generate the TOTP URL for QR code.
     */
    public function twoFactorQrCodeUrl(): string
    {
        return $this->getTotpService()->qrCodeUrl(
            config('app.name'),
            $this->email,
            $this->twoFactorAuthSecretKey()
        );
    }

    /**
     * Verify a TOTP code.
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        $secret = $this->twoFactorAuthSecretKey();
        if (! $secret) {
            return false;
        }

        return $this->getTotpService()->verify($secret, $code);
    }

    /**
     * Generate a new two-factor secret.
     */
    public function generateTwoFactorSecret(): string
    {
        return $this->getTotpService()->generateSecretKey();
    }

    /**
     * Verify a recovery code.
     *
     * @return bool True if the recovery code was valid and used
     */
    public function verifyRecoveryCode(string $code): bool
    {
        $codes = $this->twoFactorRecoveryCodes();
        $code = strtoupper(trim($code));

        $index = array_search($code, $codes);

        if ($index !== false) {
            $this->twoFactorReplaceRecoveryCode($code);

            return true;
        }

        return false;
    }

    /**
     * Generate a random recovery code.
     */
    protected function generateRecoveryCode(): string
    {
        return strtoupper(bin2hex(random_bytes(5))).'-'.strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * Generate a set of recovery codes.
     *
     * @param  int  $count  Number of codes to generate
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateRecoveryCode();
        }

        return $codes;
    }

    /**
     * Enable two-factor authentication for this user.
     *
     * Creates the 2FA record with a new secret but does not confirm it yet.
     * The user must verify a code before 2FA is fully enabled.
     *
     * @return string The secret key for QR code generation
     */
    public function enableTwoFactorAuth(): string
    {
        $secret = $this->generateTwoFactorSecret();

        $this->twoFactorAuth()->updateOrCreate(
            ['user_id' => $this->id],
            [
                'secret_key' => $secret,
                'recovery_codes' => null,
                'confirmed_at' => null,
            ]
        );

        $this->load('twoFactorAuth');

        return $secret;
    }

    /**
     * Confirm two-factor authentication after verifying a code.
     *
     * @return array The recovery codes
     */
    public function confirmTwoFactorAuth(): array
    {
        if (! $this->twoFactorAuth || ! $this->twoFactorAuth->secret_key) {
            throw new \RuntimeException('Two-factor authentication has not been initialised.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $this->twoFactorAuth->update([
            'recovery_codes' => $recoveryCodes,
            'confirmed_at' => now(),
        ]);

        return $recoveryCodes;
    }

    /**
     * Disable two-factor authentication for this user.
     */
    public function disableTwoFactorAuth(): void
    {
        $this->twoFactorAuth?->delete();
        $this->unsetRelation('twoFactorAuth');
    }

    /**
     * Regenerate recovery codes.
     *
     * @return array The new recovery codes
     */
    public function regenerateTwoFactorRecoveryCodes(): array
    {
        if (! $this->hasTwoFactorAuthEnabled()) {
            throw new \RuntimeException('Two-factor authentication is not enabled.');
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $this->twoFactorAuth->update([
            'recovery_codes' => $recoveryCodes,
        ]);

        return $recoveryCodes;
    }

    /**
     * Get the TOTP service instance.
     */
    protected function getTotpService(): TwoFactorAuthenticationProvider
    {
        return app(TwoFactorAuthenticationProvider::class);
    }
}
