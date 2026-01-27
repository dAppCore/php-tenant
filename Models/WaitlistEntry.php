<?php

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class WaitlistEntry extends Model
{
    use HasFactory;
    use Notifiable;

    protected static function newFactory(): \Core\Mod\Tenant\Database\Factories\WaitlistEntryFactory
    {
        return \Core\Mod\Tenant\Database\Factories\WaitlistEntryFactory::new();
    }

    protected $fillable = [
        'email',
        'name',
        'source',
        'interest',
        'invite_code',
        'invited_at',
        'registered_at',
        'user_id',
        'notes',
        'bonus_code',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    /**
     * Get the user this waitlist entry converted to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to entries that haven't been invited yet.
     */
    public function scopePending($query)
    {
        return $query->whereNull('invited_at');
    }

    /**
     * Scope to entries that have been invited but not registered.
     */
    public function scopeInvited($query)
    {
        return $query->whereNotNull('invited_at')->whereNull('registered_at');
    }

    /**
     * Scope to entries that have converted to users.
     */
    public function scopeConverted($query)
    {
        return $query->whereNotNull('registered_at');
    }

    /**
     * Generate a unique invite code for this entry.
     */
    public function generateInviteCode(): string
    {
        $code = strtoupper(Str::random(8));

        // Ensure uniqueness
        while (static::where('invite_code', $code)->exists()) {
            $code = strtoupper(Str::random(8));
        }

        $this->update([
            'invite_code' => $code,
            'invited_at' => now(),
            'bonus_code' => 'LAUNCH50', // Default launch bonus
        ]);

        return $code;
    }

    /**
     * Mark this entry as registered.
     */
    public function markAsRegistered(User $user): void
    {
        $this->update([
            'registered_at' => now(),
            'user_id' => $user->id,
        ]);
    }

    /**
     * Check if this entry has been invited.
     */
    public function isInvited(): bool
    {
        return $this->invited_at !== null;
    }

    /**
     * Check if this entry has converted to a user.
     */
    public function hasConverted(): bool
    {
        return $this->registered_at !== null;
    }

    /**
     * Find entry by invite code.
     */
    public static function findByInviteCode(string $code): ?self
    {
        return static::where('invite_code', strtoupper($code))->first();
    }
}
