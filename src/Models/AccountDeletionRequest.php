<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccountDeletionRequest extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'reason',
        'expires_at',
        'confirmed_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a new deletion request for a user.
     * Account WILL be deleted in 7 days unless cancelled.
     * Clicking the email link deletes immediately after re-auth.
     */
    public static function createForUser(User $user, ?string $reason = null): self
    {
        // Cancel any existing pending requests
        static::where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->delete();

        return static::create([
            'user_id' => $user->id,
            'token' => Str::random(64),
            'reason' => $reason,
            'expires_at' => now()->addDays(7),
        ]);
    }

    /**
     * Find a valid request by token (for immediate deletion via email link).
     */
    public static function findValidByToken(string $token): ?self
    {
        return static::where('token', $token)
            ->whereNull('completed_at')
            ->whereNull('cancelled_at')
            ->first();
    }

    /**
     * Get all pending requests that should be auto-deleted (past expiry).
     */
    public static function pendingAutoDelete()
    {
        return static::where('expires_at', '<=', now())
            ->whereNull('completed_at')
            ->whereNull('cancelled_at');
    }

    /**
     * Check if the request is still active (not completed or cancelled).
     */
    public function isActive(): bool
    {
        return is_null($this->completed_at) && is_null($this->cancelled_at);
    }

    /**
     * Check if the request is pending deletion (scheduled but not executed).
     */
    public function isPending(): bool
    {
        return $this->isActive() && $this->expires_at->isFuture();
    }

    /**
     * Check if the request is ready for auto-deletion (past expiry).
     */
    public function isReadyForAutoDeletion(): bool
    {
        return $this->isActive() && $this->expires_at->isPast();
    }

    /**
     * Mark the request as confirmed (user clicked email link).
     */
    public function confirm(): self
    {
        $this->update(['confirmed_at' => now()]);

        return $this;
    }

    /**
     * Mark the request as completed (account deleted).
     */
    public function complete(): self
    {
        $this->update(['completed_at' => now()]);

        return $this;
    }

    /**
     * Cancel the deletion request.
     */
    public function cancel(): self
    {
        $this->update(['cancelled_at' => now()]);

        return $this;
    }

    /**
     * Get days remaining until auto-deletion.
     */
    public function daysRemaining(): int
    {
        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }

    /**
     * Get hours remaining until auto-deletion.
     */
    public function hoursRemaining(): int
    {
        return max(0, (int) now()->diffInHours($this->expires_at, false));
    }

    /**
     * Get the immediate deletion URL (for email).
     */
    public function confirmationUrl(): string
    {
        return route('account.delete.confirm', ['token' => $this->token]);
    }

    /**
     * Get the cancel URL.
     */
    public function cancelUrl(): string
    {
        return route('account.delete.cancel', ['token' => $this->token]);
    }
}
