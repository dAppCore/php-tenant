<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class WorkspaceInvitation extends Model
{
    use HasFactory;
    use Notifiable;

    protected static function newFactory(): \Core\Mod\Tenant\Database\Factories\WorkspaceInvitationFactory
    {
        return \Core\Mod\Tenant\Database\Factories\WorkspaceInvitationFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'email',
        'token',
        'role',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Get the workspace this invitation is for.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the user who sent this invitation.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Scope to pending invitations (not accepted, not expired).
     */
    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to expired invitations.
     */
    public function scopeExpired($query)
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope to accepted invitations.
     */
    public function scopeAccepted($query)
    {
        return $query->whereNotNull('accepted_at');
    }

    /**
     * Check if invitation is pending (not accepted and not expired).
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /**
     * Check if invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }

    /**
     * Check if invitation has been accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Generate a unique token for this invitation.
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::where('token', $token)->exists());

        return $token;
    }

    /**
     * Find invitation by token.
     */
    public static function findByToken(string $token): ?self
    {
        return static::where('token', $token)->first();
    }

    /**
     * Find pending invitation by token.
     */
    public static function findPendingByToken(string $token): ?self
    {
        return static::where('token', $token)->pending()->first();
    }

    /**
     * Accept the invitation for a user.
     */
    public function accept(User $user): bool
    {
        if (! $this->isPending()) {
            return false;
        }

        // Check if user already belongs to this workspace
        if ($this->workspace->users()->where('user_id', $user->id)->exists()) {
            // Mark as accepted but don't add again
            $this->update(['accepted_at' => now()]);

            return true;
        }

        // Add user to workspace with the invited role
        $this->workspace->users()->attach($user->id, [
            'role' => $this->role,
            'is_default' => false,
        ]);

        // Mark invitation as accepted
        $this->update(['accepted_at' => now()]);

        return true;
    }

    /**
     * Get the notification routing for mail.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }
}
