<?php

declare(strict_types=1);

namespace Core\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User two-factor authentication record.
 *
 * Stores TOTP secrets and recovery codes for 2FA.
 * Sensitive fields are encrypted at rest using Laravel's encryption.
 *
 * Note: The database column is 'secret' but the codebase uses 'secret_key'.
 * Accessor/mutator methods handle the translation transparently.
 */
class UserTwoFactorAuth extends Model
{
    protected $table = 'user_two_factor_auth';

    /**
     * Fillable attributes.
     *
     * Note: secret_key is an alias for the 'secret' column, handled by mutator.
     */
    protected $fillable = [
        'user_id',
        'secret',
        'secret_key', // Alias handled by setSecretKeyAttribute
        'recovery_codes',
        'confirmed_at',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'recovery_codes' => 'encrypted:collection',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Accessor for backward compatibility with code using secret_key.
     */
    public function getSecretKeyAttribute(): ?string
    {
        return $this->secret;
    }

    /**
     * Mutator for backward compatibility with code using secret_key.
     *
     * Translates secret_key writes to the actual 'secret' column.
     */
    public function setSecretKeyAttribute(?string $value): void
    {
        $this->secret = $value;
    }

    /**
     * Get the user this 2FA belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
