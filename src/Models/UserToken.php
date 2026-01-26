<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal access token for API authentication.
 *
 * Provides stateful API authentication using long-lived tokens.
 * Tokens are hashed using SHA-256 before storage for security.
 */
class UserToken extends Model
{
    use HasFactory;

    protected static function newFactory(): \Core\Mod\Tenant\Database\Factories\UserTokenFactory
    {
        return \Core\Mod\Tenant\Database\Factories\UserTokenFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_tokens';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'token',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Find a token by its plain-text value.
     *
     * Tokens are stored as SHA-256 hashes, so we hash the input
     * before querying the database.
     *
     * @param  string  $token  Plain-text token value
     */
    public static function findToken(string $token): ?UserToken
    {
        return static::where('token', hash('sha256', $token))->first();
    }

    /**
     * Get the user that owns the token.
     *
     * @return BelongsTo<User, UserToken>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Determine if the token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Determine if the token is valid (not expired).
     */
    public function isValid(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * Update the last used timestamp.
     *
     * Preserves the hasModifiedRecords state to avoid triggering
     * model events when only updating usage tracking.
     */
    public function recordUsage(): void
    {
        $connection = $this->getConnection();

        // Preserve modification state if the connection supports it
        if (method_exists($connection, 'hasModifiedRecords') &&
            method_exists($connection, 'setRecordModificationState')) {

            $hasModifiedRecords = $connection->hasModifiedRecords();

            $this->forceFill(['last_used_at' => now()])->save();

            $connection->setRecordModificationState($hasModifiedRecords);
        } else {
            // Fallback for connections that don't support modification state
            $this->forceFill(['last_used_at' => now()])->save();
        }
    }
}
