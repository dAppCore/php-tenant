<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User two-factor authentication record.
 *
 * Stores TOTP secrets and recovery codes for 2FA.
 */
class UserTwoFactorAuth extends Model
{
    protected $table = 'user_two_factor_auth';

    protected $fillable = [
        'user_id',
        'secret_key',
        'recovery_codes',
        'confirmed_at',
    ];

    protected $casts = [
        'recovery_codes' => 'collection',
        'confirmed_at' => 'datetime',
    ];

    /**
     * Get the user this 2FA belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
