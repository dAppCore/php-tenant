<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Core\Mod\Tenant\Contracts\TwoFactorAuthenticationProvider;

/**
 * TOTP (Time-based One-Time Password) service.
 *
 * Implements RFC 6238 TOTP algorithm for two-factor authentication.
 * Uses chillerlan/php-qrcode for QR generation.
 */
class TotpService implements TwoFactorAuthenticationProvider
{
    /**
     * The number of seconds a TOTP code is valid.
     */
    protected const int TIME_STEP = 30;

    /**
     * The number of digits in a TOTP code.
     */
    protected const int CODE_LENGTH = 6;

    /**
     * The hash algorithm to use.
     */
    protected const string ALGORITHM = 'sha1';

    /**
     * Number of time periods to check in each direction for clock drift.
     */
    protected const int WINDOW = 1;

    /**
     * Generate a new secret key for TOTP.
     *
     * Generates a 160-bit secret encoded in base32.
     */
    public function generateSecretKey(): string
    {
        $secret = random_bytes(20); // 160 bits

        return $this->base32Encode($secret);
    }

    /**
     * Generate QR code URL for authenticator app setup.
     *
     * @param  string  $name  Application/account name
     * @param  string  $email  User email
     * @param  string  $secret  TOTP secret key
     */
    public function qrCodeUrl(string $name, string $email, string $secret): string
    {
        $encodedName = rawurlencode($name);
        $encodedEmail = rawurlencode($email);

        return "otpauth://totp/{$encodedName}:{$encodedEmail}?secret={$secret}&issuer={$encodedName}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Generate a QR code SVG for the given URL.
     */
    public function qrCodeSvg(string $url): string
    {
        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'imageBase64' => false,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
            'drawLightModules' => false,
            'svgViewBoxSize' => 200,
        ]);

        return (new QRCode($options))->render($url);
    }

    /**
     * Verify a TOTP code against the secret.
     *
     * @param  string  $secret  TOTP secret key (base32 encoded)
     * @param  string  $code  User-provided 6-digit code
     */
    public function verify(string $secret, string $code): bool
    {
        // Remove any spaces or dashes from the code
        $code = preg_replace('/[^0-9]/', '', $code);

        if (strlen($code) !== self::CODE_LENGTH) {
            return false;
        }

        $secretBytes = $this->base32Decode($secret);
        $timestamp = time();

        // Check current time and adjacent windows for clock drift
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $calculatedCode = $this->generateCode($secretBytes, $timestamp + ($i * self::TIME_STEP));

            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a TOTP code for a given timestamp.
     */
    protected function generateCode(string $secretBytes, int $timestamp): string
    {
        $counter = (int) floor($timestamp / self::TIME_STEP);

        // Pack counter as 64-bit big-endian
        $counterBytes = pack('N*', 0, $counter);

        // Generate HMAC
        $hash = hash_hmac(self::ALGORITHM, $counterBytes, $secretBytes, true);

        // Dynamic truncation
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** self::CODE_LENGTH);

        return str_pad((string) $otp, self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Encode bytes as base32.
     */
    protected function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = str_split($binary, 5);

        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Decode base32 to bytes.
     */
    protected function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper($data);
        $data = rtrim($data, '=');

        $binary = '';
        foreach (str_split($data) as $char) {
            $index = strpos($alphabet, $char);
            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        $chunks = str_split($binary, 8);

        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
