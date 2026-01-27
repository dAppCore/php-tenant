<?php

declare(strict_types=1);

namespace Core\Mod\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace Member - enhanced pivot model for user-workspace relationship.
 *
 * This model wraps the user_workspace pivot table to provide team-based
 * access control with custom permission overrides.
 *
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property string $role
 * @property int|null $team_id
 * @property array|null $custom_permissions
 * @property bool $is_default
 * @property \Carbon\Carbon|null $joined_at
 * @property int|null $invited_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WorkspaceMember extends Model
{
    protected $table = 'user_workspace';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'role',
        'team_id',
        'custom_permissions',
        'is_default',
        'joined_at',
        'invited_by',
    ];

    protected $casts = [
        'custom_permissions' => 'array',
        'is_default' => 'boolean',
        'joined_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Role Constants (legacy, for backwards compatibility)
    // ─────────────────────────────────────────────────────────────────────────

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the user for this membership.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the workspace for this membership.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the team for this membership.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(WorkspaceTeam::class, 'team_id');
    }

    /**
     * Get the user who invited this member.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scope to a specific workspace.
     */
    public function scopeForWorkspace($query, Workspace|int $workspace)
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to a specific user.
     */
    public function scopeForUser($query, User|int $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * Scope to members with a specific role.
     */
    public function scopeWithRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope to members in a specific team.
     */
    public function scopeInTeam($query, WorkspaceTeam|int $team)
    {
        $teamId = $team instanceof WorkspaceTeam ? $team->id : $team;

        return $query->where('team_id', $teamId);
    }

    /**
     * Scope to owners only.
     */
    public function scopeOwners($query)
    {
        return $query->where('role', self::ROLE_OWNER);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Permission Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get all effective permissions for this member.
     *
     * Merges team permissions with custom permission overrides.
     */
    public function getEffectivePermissions(): array
    {
        // Start with team permissions
        $permissions = $this->team?->permissions ?? [];

        // Merge custom permissions (overrides)
        $customPermissions = $this->custom_permissions ?? [];

        // Custom permissions can grant (+permission) or revoke (-permission)
        foreach ($customPermissions as $permission) {
            if (str_starts_with($permission, '-')) {
                // Remove permission
                $toRemove = substr($permission, 1);
                $permissions = array_values(array_filter(
                    $permissions,
                    fn ($p) => $p !== $toRemove
                ));
            } elseif (str_starts_with($permission, '+')) {
                // Add permission (explicit add)
                $toAdd = substr($permission, 1);
                if (! in_array($toAdd, $permissions, true)) {
                    $permissions[] = $toAdd;
                }
            } else {
                // Treat as add if no prefix
                if (! in_array($permission, $permissions, true)) {
                    $permissions[] = $permission;
                }
            }
        }

        // Legacy fallback: if no team, derive from role
        if (! $this->team_id) {
            $rolePermissions = match ($this->role) {
                self::ROLE_OWNER => WorkspaceTeam::getDefaultPermissionsFor(WorkspaceTeam::TEAM_OWNER),
                self::ROLE_ADMIN => WorkspaceTeam::getDefaultPermissionsFor(WorkspaceTeam::TEAM_ADMIN),
                default => WorkspaceTeam::getDefaultPermissionsFor(WorkspaceTeam::TEAM_MEMBER),
            };
            $permissions = array_unique(array_merge($permissions, $rolePermissions));
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Check if this member has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getEffectivePermissions();

        // Check for exact match
        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // Check for wildcard permissions
        foreach ($permissions as $perm) {
            if (str_ends_with($perm, '.*')) {
                $prefix = substr($perm, 0, -1);
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if this member has any of the given permissions.
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this member has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add a custom permission override.
     */
    public function grantCustomPermission(string $permission): self
    {
        $custom = $this->custom_permissions ?? [];

        // Remove any revocation of this permission
        $custom = array_filter($custom, fn ($p) => $p !== '-'.$permission);

        // Add the permission if not already present
        if (! in_array($permission, $custom, true) && ! in_array('+'.$permission, $custom, true)) {
            $custom[] = '+'.$permission;
        }

        $this->update(['custom_permissions' => array_values($custom)]);

        return $this;
    }

    /**
     * Revoke a permission via custom override.
     */
    public function revokeCustomPermission(string $permission): self
    {
        $custom = $this->custom_permissions ?? [];

        // Remove any grant of this permission
        $custom = array_filter($custom, fn ($p) => $p !== $permission && $p !== '+'.$permission);

        // Add revocation
        if (! in_array('-'.$permission, $custom, true)) {
            $custom[] = '-'.$permission;
        }

        $this->update(['custom_permissions' => array_values($custom)]);

        return $this;
    }

    /**
     * Clear all custom permission overrides.
     */
    public function clearCustomPermissions(): self
    {
        $this->update(['custom_permissions' => null]);

        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if this member is the workspace owner.
     */
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER
            || $this->team?->slug === WorkspaceTeam::TEAM_OWNER;
    }

    /**
     * Check if this member is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->isOwner()
            || $this->role === self::ROLE_ADMIN
            || $this->team?->slug === WorkspaceTeam::TEAM_ADMIN;
    }

    /**
     * Assign this member to a team.
     */
    public function assignToTeam(WorkspaceTeam|int $team): self
    {
        $teamId = $team instanceof WorkspaceTeam ? $team->id : $team;

        $this->update(['team_id' => $teamId]);

        return $this;
    }

    /**
     * Remove this member from their team.
     */
    public function removeFromTeam(): self
    {
        $this->update(['team_id' => null]);

        return $this;
    }

    /**
     * Get the display name for this membership (team name or role).
     */
    public function getDisplayRole(): string
    {
        if ($this->team) {
            return $this->team->name;
        }

        return match ($this->role) {
            self::ROLE_OWNER => 'Owner',
            self::ROLE_ADMIN => 'Admin',
            default => 'Member',
        };
    }

    /**
     * Get the colour for this membership's role badge.
     */
    public function getRoleColour(): string
    {
        if ($this->team) {
            return $this->team->colour;
        }

        return match ($this->role) {
            self::ROLE_OWNER => 'violet',
            self::ROLE_ADMIN => 'blue',
            default => 'zinc',
        };
    }
}
